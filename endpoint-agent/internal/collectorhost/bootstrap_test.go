package collectorhost

import (
	"encoding/base64"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func validBootstrap() Bootstrap {
	return Bootstrap{Version: 1, Component: "site", Platform: "windows", DeploymentURL: "https://care.example.test",
		ServerURL: "https://collector.example.test:8443", ServerKey: base64.StdEncoding.EncodeToString(make([]byte, 32)),
		TLSPin: "sha256//" + base64.StdEncoding.EncodeToString(make([]byte, 32)), Organisation: "Example Care",
		SiteID: 9, SiteLabel: "Main office", CollectorUUID: "018f0000-0000-7000-8000-000000000091",
		Token: "ofc_enrol_" + strings.Repeat("A", 64), ExpiresAt: time.Now().Add(15 * time.Minute).UTC().Format(time.RFC3339Nano)}
}
func TestCollectorProfilePreservesSeparateDeploymentAndTransport(t *testing.T) {
	p := validBootstrap()
	data, _ := json.Marshal(p)
	got, err := ReadBootstrap(strings.NewReader(string(data)), time.Now())
	if err != nil || got != p {
		t.Fatal("valid profile changed", err)
	}
	first := p.BindingSHA256()
	p.Token = "ofc_enrol_" + strings.Repeat("B", 64)
	if len(first) != 64 || first == p.BindingSHA256() {
		t.Fatal("receipt did not bind the grant")
	}
}
func TestCollectorProfileRejectsAmbiguousOrInvalidMaterial(t *testing.T) {
	p := validBootstrap()
	data, _ := json.Marshal(p)
	raw := string(data)
	samples := []string{raw + raw, raw[:len(raw)-1] + ",\"site_id\":9}", strings.Replace(raw, "\"version\"", "\"Version\"", 1),
		strings.Replace(raw, "\"site_id\":9", "\"site_id\":null", 1), strings.Replace(raw, "\"site_id\":9", "\"site_id\":9.0", 1),
		strings.Replace(raw, "\"component\":\"site\",", "", 1), raw[:len(raw)-1] + ",\"tenant_id\":1}", strings.Repeat(" ", MaxBootstrapBytes+1)}
	for i, s := range samples {
		if _, err := ReadBootstrap(strings.NewReader(s), time.Now()); err == nil {
			t.Fatalf("accepted malformed profile %d", i)
		}
	}
	mutations := map[string]func(*Bootstrap){
		"expired":    func(b *Bootstrap) { b.ExpiresAt = time.Now().Add(-time.Minute).Format(time.RFC3339) },
		"long grant": func(b *Bootstrap) { b.ExpiresAt = time.Now().Add(time.Hour).Format(time.RFC3339) },
		"linux":      func(b *Bootstrap) { b.Platform = "linux" },
		"token":      func(b *Bootstrap) { b.Token = "raw-secret" },
		"uuid":       func(b *Bootstrap) { b.CollectorUUID = strings.ToUpper(b.CollectorUUID) + "x" },
		"site":       func(b *Bootstrap) { b.SiteID = 0 },
		"http":       func(b *Bootstrap) { b.ServerURL = "http://care.example.test" },
		"path":       func(b *Bootstrap) { b.ServerURL = "https://care.example.test/path" },
		"userinfo":   func(b *Bootstrap) { b.DeploymentURL = "https://user:password@care.example.test" },
		"pin":        func(b *Bootstrap) { b.TLSPin = "sha256//short" },
		"key":        func(b *Bootstrap) { b.ServerKey = "bad" },
		"label":      func(b *Bootstrap) { b.SiteLabel = "Main\u202eoffice" },
	}
	for name, change := range mutations {
		t.Run(name, func(t *testing.T) {
			b := p
			change(&b)
			if ValidateBootstrap(b, time.Now()) == nil {
				t.Fatal("invalid profile accepted")
			}
		})
	}
}
