package main

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"time"

	"github.com/redis/go-redis/v9"
)

const (
	lastGoodKey      = "edge/blackhole"
	lastGoodTimeout  = 5 * time.Second
	lastGoodMaxBytes = 64 << 20
)

// lastGoodKV is the little of Redis the store needs. Get answers nil, nil for a key that isn't there.
type lastGoodKV interface {
	Get(ctx context.Context, key string) ([]byte, error)
	Set(ctx context.Context, key string, value []byte) error
}

// lastGood keeps the newest good list in Redis, as edge/blackhole, so a new edge can start dropping
// even if the app can't be reached. A running edge doesn't need it: the kernel table outlives the agent.
// The format is the Caddy module's (nicetry-capture/lastgood.go). Only an edge told to write does
// (NICETRY_EDGE_REDIS_WRITE), and a nil *lastGood does nothing, which is what running without Redis is.
type lastGood struct {
	kv     lastGoodKV
	writes bool
	saved  [sha256.Size]byte
}

func lastGoodFromEnv() *lastGood {
	addr := os.Getenv("NICETRY_EDGE_REDIS")
	if addr == "" {
		return nil
	}
	return &lastGood{
		kv: redisLastGood{redis.NewClient(&redis.Options{
			Addr:         addr,
			DialTimeout:  2 * time.Second,
			ReadTimeout:  3 * time.Second,
			WriteTimeout: 3 * time.Second,
			MaxRetries:   1,
		})},
		writes: os.Getenv("NICETRY_EDGE_REDIS_WRITE") == "1",
	}
}

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

// save stores a list the app just sent, unless it's what was stored last.
func (s *lastGood) save(body []byte) {
	if s == nil || !s.writes {
		return
	}
	sum := sha256.Sum256(body)
	if sum == s.saved {
		return
	}

	value, err := encodeLastGood(body, time.Now())
	if err == nil {
		ctx, cancel := context.WithTimeout(context.Background(), lastGoodTimeout)
		defer cancel()
		err = s.kv.Set(ctx, lastGoodKey, value)
	}
	if err != nil {
		log.Printf("couldn't save the list to Redis: %v", err)
		return
	}
	s.saved = sum
}

// restore gets the stored list and how long ago it last changed.
func (s *lastGood) restore() ([]byte, time.Duration, bool) {
	if s == nil {
		return nil, 0, false
	}
	ctx, cancel := context.WithTimeout(context.Background(), lastGoodTimeout)
	defer cancel()

	value, err := s.kv.Get(ctx, lastGoodKey)
	if err == nil && value == nil {
		return nil, 0, false
	}
	var body []byte
	var savedAt time.Time
	if err == nil {
		body, savedAt, err = decodeLastGood(value)
	}
	if err != nil {
		log.Printf("couldn't read the list from Redis: %v", err)
		return nil, 0, false
	}
	return body, time.Since(savedAt), true
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
