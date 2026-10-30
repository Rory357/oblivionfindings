package setup

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"strings"
	"testing"
	"time"
)

func installationProfiles() Profiles {
	key := base64.StdEncoding.EncodeToString(make([]byte, 32))
	expiry := time.Now().Add(15 * time.Minute).Format(time.RFC3339Nano)
	return Profiles{Role: "both",
		Computer: &agent.Bootstrap{Version: 1, Platform: "windows", ServerURL: "https://care.example.test", ServerKey: key,
			AgentUUID: "018f0000-0000-7000-8000-000000000091", Token: strings.Repeat("a", 64), ExpiresAt: expiry, Organisation: "Example Care", DeviceLabel: "Reception PC", DeviceUID: "reception-1"},
		Collector: &collectorhost.Bootstrap{Version: 1, Component: "site", Platform: "windows", DeploymentURL: "https://care.example.test",
			ServerURL: "https://collectors.example.test", ServerKey: key, TLSPin: "sha256//" + key, Organisation: "Example Care", SiteID: 9, SiteLabel: "Main office",
			CollectorUUID: "018f0000-0000-7000-8000-000000000092", Token: "ofc_enrol_" + strings.Repeat("A", 64), ExpiresAt: expiry}}
}
func TestInstallationRolesBindOnlyRequiredProfilesAndExactServers(t *testing.T) {
	both := installationProfiles()
	for _, role := range []string{"computer", "site", "both"} {
		p := both
		p.Role = role
		collectorServer := "https://collectors.example.test"
		if role == "computer" {
			p.Collector = nil
			collectorServer = ""
		}
		if role == "site" {
			p.Computer = nil
		}
		if err := p.Validate("https://care.example.test", collectorServer, time.Now()); err != nil {
			t.Fatal(role, err)
		}
		if err := p.Validate("https://other.example.test", collectorServer, time.Now()); err == nil {
			t.Fatal("wrong deployment accepted")
		}
		if role != "computer" && p.Validate("https://care.example.test", "", time.Now()) == nil {
			t.Fatal("dedicated transport unconfirmed")
		}
	}
	for _, mutate := range []func(*Profiles){
		func(p *Profiles) { p.Collector.DeploymentURL = "https://other.example.test" },
		func(p *Profiles) { p.Role = "computer" },
		func(p *Profiles) { p.Collector = nil },
		func(p *Profiles) { p.Role = "site" },
	} {
		p := installationProfiles()
		mutate(&p)
		if p.Validate("https://care.example.test", "https://collectors.example.test", time.Now()) == nil {
			t.Fatal("mismatched profiles accepted")
		}
	}
	p := installationProfiles()
	p.Collector.ServerURL = p.ServerURL()
	if err := p.Validate(p.ServerURL(), "", time.Now()); err != nil {
		t.Fatal("shared server rejected", err)
	}
}
func TestCollectorReceiptBindsIdentitySiteAndProfileWithoutStoringSecrets(t *testing.T) {
	p := installationProfiles()
	j := newJournal()
	j.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	p.bind(&j)
	data, _ := json.Marshal(j)
	if bytes.Contains(data, []byte(p.Collector.Token)) || bytes.Contains(data, []byte(p.Computer.Token)) {
		t.Fatal("secret persisted")
	}
	if _, err := decodeJournal(data, j); err != nil {
		t.Fatal(err)
	}
	for _, mutate := range []func(*Journal){
		func(j *Journal) { j.CollectorUUID = "another" },
		func(j *Journal) { j.CollectorSiteID++ },
		func(j *Journal) { j.CollectorProfileHash = strings.Repeat("0", 64) },
		func(j *Journal) { j.Role = "computer" },
	} {
		changed := j
		mutate(&changed)
		bad, _ := json.Marshal(changed)
		if _, err := decodeJournal(bad, j); err == nil {
			t.Fatal("collector binding changed")
		}
	}
}
