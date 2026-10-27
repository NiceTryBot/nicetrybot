package nicetrycapture

import (
	"net"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"time"

	"github.com/oschwald/maxminddb-golang"
)

// The edge's IP database: the DB-IP Lite City and ASN files dbip-refresh keeps in /var/lib/dbip, the
// ones Vector enriches the access log with. Fields are read as Vector reads them (country.iso_code,
// subdivisions[0].names.en, city.names.en, autonomous_system_number and _organization), so a rule judged
// live sees what it would see in the imported log. dbip-refresh swaps the files monthly; they're
// reopened when they change.

const geoRecheck = 5 * time.Minute

type geoRecord struct {
	country, region, city, network string
	asn                            uint
}

type cityRecord struct {
	Country struct {
		IsoCode string `maxminddb:"iso_code"`
	} `maxminddb:"country"`
	Subdivisions []struct {
		Names map[string]string `maxminddb:"names"`
	} `maxminddb:"subdivisions"`
	City struct {
		Names map[string]string `maxminddb:"names"`
	} `maxminddb:"city"`
}

type asnRecord struct {
	Number       uint   `maxminddb:"autonomous_system_number"`
	Organization string `maxminddb:"autonomous_system_organization"`
}

type geoDB struct {
	dir string

	mu       sync.RWMutex
	city     *maxminddb.Reader
	asn      *maxminddb.Reader
	checked  atomic.Int64
	reloads  sync.Mutex
	modified [2]time.Time
}

var currentGeo atomic.Pointer[geoDB]

// sharedGeo is the edge's IP database, or nil when none is set up.
func sharedGeo() *geoDB {
	return currentGeo.Load()
}

// useGeo makes the databases in dir the edge's, opening them if they aren't already.
func useGeo(dir string) {
	if g := currentGeo.Load(); g != nil && g.dir == dir {
		return
	}
	g := &geoDB{dir: dir}
	g.reload()
	currentGeo.Store(g)
}

func (g *geoDB) paths() [2]string {
	return [2]string{filepath.Join(g.dir, "dbip-city-lite.mmdb"), filepath.Join(g.dir, "dbip-asn-lite.mmdb")}
}

// reload opens whichever file changed since it was last opened. One reload runs at a time; requests
// arriving meanwhile carry on with the files already open.
func (g *geoDB) reload() {
	if !g.reloads.TryLock() {
		return
	}
	defer g.reloads.Unlock()
	g.checked.Store(time.Now().UnixNano())
	paths := g.paths()

	for i, path := range paths {
		info, err := os.Stat(path)
		if err != nil || info.ModTime().Equal(g.modified[i]) {
			continue
		}
		reader, err := maxminddb.Open(path)
		if err != nil {
			continue
		}

		g.mu.Lock()
		old := g.city
		if i == 0 {
			g.city = reader
		} else {
			old = g.asn
			g.asn = reader
		}
		g.modified[i] = info.ModTime()
		g.mu.Unlock()

		if old != nil {
			_ = old.Close()
		}
	}
}

// lookup places an address. ok is false when the databases aren't open, so where it comes from is
// unknown; an address they don't list is known to be nowhere in particular.
func (g *geoDB) lookup(ip string) (geoRecord, bool) {
	if time.Since(time.Unix(0, g.checked.Load())) > geoRecheck {
		g.reload()
	}

	g.mu.RLock()
	defer g.mu.RUnlock()

	if g.city == nil || g.asn == nil {
		return geoRecord{}, false
	}

	var rec geoRecord
	addr := net.ParseIP(ip)
	if addr == nil {
		return rec, true
	}

	var city cityRecord
	if g.city.Lookup(addr, &city) == nil {
		rec.country = city.Country.IsoCode
		if len(city.Subdivisions) > 0 {
			rec.region = city.Subdivisions[0].Names["en"]
		}
		rec.city = city.City.Names["en"]
	}

	var asn asnRecord
	if g.asn.Lookup(addr, &asn) == nil {
		rec.asn = asn.Number
		rec.network = asn.Organization
	}
	return rec, true
}
