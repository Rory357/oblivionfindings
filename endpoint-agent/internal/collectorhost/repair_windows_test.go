package collectorhost

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCollectorRepairIdentityIsBoundToSiteIDUUIDAndConfirmedTransport(t *testing.T) {
	root := t.TempDir()
	identity := writePHPIdentity(t, root)
	file := filepath.Join(root, "collector.identity.json")
	before, err := os.ReadFile(file)
	if err != nil {
		t.Fatal(err)
	}
	uuid, origin := identity["collector_id"].(string), identity["central_url"].(string)
	if err = checkRepairIdentity(before, uuid, 9, origin); err != nil {
		t.Fatal(err)
	}
	for _, change := range []func(map[string]any){
		func(m map[string]any) { m["collector_id"] = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa" },
		func(m map[string]any) { m["site_id"] = 10 },
		func(m map[string]any) { m["central_url"] = "https://another.example.test" },
	} {
		var changed map[string]any
		json.Unmarshal(before, &changed)
		change(changed)
		data, _ := json.Marshal(changed)
		if checkRepairIdentity(data, uuid, 9, origin) == nil {
			t.Fatal("changed identity accepted")
		}
	}

	for _, invalid := range [][]byte{
		append(append([]byte{}, before...), before...),
		[]byte(strings.Replace(string(before), "{", "{\"site_id\":9,", 1)),
		[]byte(strings.Replace(string(before), "\"collector_id\"", "\"Collector_id\"", 1)),
		[]byte(strings.Replace(string(before), "\"site_id\":9", "\"site_id\":null", 1)),
		[]byte(strings.Repeat(" ", 16385)),
	} {
		if checkRepairIdentity(invalid, uuid, 9, origin) == nil {
			t.Fatal("ambiguous collector identity accepted")
		}
	}
	after, err := os.ReadFile(file)
	if err != nil || !bytes.Equal(before, after) {
		t.Fatal("identity inspection changed stored credentials")
	}
}
