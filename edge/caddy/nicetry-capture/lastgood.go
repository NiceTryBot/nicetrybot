package nicetrycapture

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"os"
	"sync"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

// lastGoodTimeout bounds every Redis call: a list is never worth holding up a poller for.
const lastGoodTimeout = 5 * time.Second

// lastGoodMaxBytes is the most a stored list may unpack to, the same limit the pollers read a feed with.
const lastGoodMaxBytes = 16 << 20

// lastGoodKV is the little of Redis the store needs. Get answers nil, nil for a key that isn't there.
type lastGoodKV interface {
	Get(ctx context.Context, key string) ([]byte, error)
	Set(ctx context.Context, key string, value []byte) error
}

// lastGood keeps the newest good copy of each list the app sends the edge, in Redis as edge/<name>. A
// poller starts with an empty list and otherwise only has what the app last told this process, so a
// restart while the app is slow or down would leave the edge with nothing: no gym, no routing. With the
// copy, an edge that restarts, or a brand-new one, starts from the last list any edge got.
//
// The app stays the source of truth: a copy is only ever read when a poller starts, and is replaced as
// soon as the app answers. Only an edge told to write does (NICETRY_EDGE_REDIS_WRITE), so an unproven
// build on staging can read production's copies but can't replace them. Nothing depends on Redis: with
// it unset or unreachable, the edge polls as if the store weren't there. A nil *lastGood is that store.
type lastGood struct {
	kv     lastGoodKV
	writes bool

	mu    sync.Mutex
	saved map[string][sha256.Size]byte
}

func newLastGood(kv lastGoodKV, writes bool) *lastGood {
	return &lastGood{kv: kv, writes: writes, saved: map[string][sha256.Size]byte{}}
}

// sharedLastGood is this process's store, set up from the environment the first time a poller asks.
var sharedLastGood = sync.OnceValue(func() *lastGood {
	addr := os.Getenv("NICETRY_EDGE_REDIS")
	if addr == "" {
		return nil
	}
	writes := os.Getenv("NICETRY_EDGE_REDIS_WRITE") == "1"
	caddy.Log().Named("nicetry_lastgood").Info("keeping the last good lists in Redis", zap.String("addr", addr), zap.Bool("writes", writes))

	return newLastGood(redisLastGood{redis.NewClient(&redis.Options{
		Addr:         addr,
		DialTimeout:  2 * time.Second,
		ReadTimeout:  3 * time.Second,
		WriteTimeout: 3 * time.Second,
		MaxRetries:   1,
	})}, writes)
})

type redisLastGood struct{ client *redis.Client }

func (r redisLastGood) Get(ctx context.Context, key string) ([]byte, error) {
	value, err := r.client.Get(ctx, key).Bytes()
	if errors.Is(err, redis.Nil) {
		return nil, nil
	}
	return value, err
}

func (r redisLastGood) Set(ctx context.Context, key string, value []byte) error {
	return r.client.Set(ctx, key, value, 0).Err()
}

// save stores a list the app just sent and the poller parsed, unless it's what was stored last.
func (s *lastGood) save(name string, body []byte) {
	if s == nil || !s.writes {
		return
	}
	sum := sha256.Sum256(body)

	s.mu.Lock()
	same := s.saved[name] == sum
	s.mu.Unlock()
	if same {
		return
	}

	value, err := encodeLastGood(body, time.Now())
	if err == nil {
		ctx, cancel := context.WithTimeout(context.Background(), lastGoodTimeout)
		defer cancel()
		err = s.kv.Set(ctx, "edge/"+name, value)
	}
	if err != nil {
		caddy.Log().Named("nicetry_lastgood").Warn("couldn't save the list", zap.String("list", name), zap.Error(err))
		return
	}

	s.mu.Lock()
	s.saved[name] = sum
	s.mu.Unlock()
}

// restore gets the stored copy of a list and how long ago it last changed.
func (s *lastGood) restore(name string) ([]byte, time.Duration, bool) {
	if s == nil {
		return nil, 0, false
	}
	ctx, cancel := context.WithTimeout(context.Background(), lastGoodTimeout)
	defer cancel()

	value, err := s.kv.Get(ctx, "edge/"+name)
	if err == nil && value == nil {
		return nil, 0, false
	}
	var body []byte
	var savedAt time.Time
	if err == nil {
		body, savedAt, err = decodeLastGood(value)
	}
	if err != nil {
		caddy.Log().Named("nicetry_lastgood").Warn("couldn't read the list", zap.String("list", name), zap.Error(err))
		return nil, 0, false
	}
	return body, time.Since(savedAt), true
}

// restoreList gets the stored copy of a list and parses it the way its poller parses the app's answer,
// reporting whether there was one that could be used.
func restoreList[T any](store *lastGood, name string, parse func([]byte) (T, error)) (T, bool) {
	var none T
	logger := caddy.Log().Named("nicetry_lastgood")

	body, age, ok := store.restore(name)
	if !ok {
		return none, false
	}
	list, err := parse(body)
	if err != nil {
		logger.Warn("couldn't use the stored list", zap.String("list", name), zap.Error(err))
		return none, false
	}
	logger.Info("list restored", zap.String("list", name), zap.Int("bytes", len(body)), zap.Duration("last_changed", age))
	return list, true
}

// encodeLastGood packs a list as eight bytes of when it was saved, then the list gzipped.
func encodeLastGood(body []byte, savedAt time.Time) ([]byte, error) {
	var out bytes.Buffer
	if err := binary.Write(&out, binary.BigEndian, savedAt.Unix()); err != nil {
		return nil, err
	}
	zw := gzip.NewWriter(&out)
	if _, err := zw.Write(body); err != nil {
		return nil, err
	}
	if err := zw.Close(); err != nil {
		return nil, err
	}
	return out.Bytes(), nil
}

func decodeLastGood(value []byte) ([]byte, time.Time, error) {
	if len(value) < 8 {
		return nil, time.Time{}, fmt.Errorf("stored list is only %d bytes", len(value))
	}
	savedAt := time.Unix(int64(binary.BigEndian.Uint64(value)), 0)

	zr, err := gzip.NewReader(bytes.NewReader(value[8:]))
	if err != nil {
		return nil, time.Time{}, err
	}
	body, err := io.ReadAll(io.LimitReader(zr, lastGoodMaxBytes))
	if err != nil {
		return nil, time.Time{}, err
	}
	return body, savedAt, nil
}
