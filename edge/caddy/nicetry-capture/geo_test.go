package nicetrycapture

import (
	"os"
	"testing"
)

// TestTheIPDatabaseReadsAsVectorDoes runs on a node with the DB-IP files (NICETRY_GEO_DIR=/var/lib/dbip):
// Google's public resolver is in the US, on Google's own network.
func TestTheIPDatabaseReadsAsVectorDoes(t *testing.T) {
	dir := os.Getenv("NICETRY_GEO_DIR")
	if dir == "" {
		t.Skip("set NICETRY_GEO_DIR to the DB-IP folder to read the real files")
	}

	geo := &geoDB{dir: dir}
	geo.reload()

	place, ok := geo.lookup("8.8.8.8")
	if !ok {
		t.Fatalf("expected the databases in %s to open", dir)
	}
	if place.country != "US" || place.asn != 15169 || place.network == "" {
		t.Errorf("unexpected place for 8.8.8.8: %+v", place)
	}
	t.Logf("8.8.8.8: %+v", place)
}

func TestWithoutTheFilesWhereAVisitorComesFromIsUnknown(t *testing.T) {
	geo := &geoDB{dir: t.TempDir()}
	geo.reload()

	if _, ok := geo.lookup("8.8.8.8"); ok {
		t.Fatal("expected no place without the files")
	}
}
