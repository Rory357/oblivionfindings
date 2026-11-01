package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
	"unicode/utf16"
)

func TestInstallerNameCarriesServerAndCode(t *testing.T) {
	cases := map[string][2]string{
		"Oblivion-Setup (Tōtara House) [oblivionfindings.com T6WD-3KQN].exe":                     {"oblivionfindings.com", "T6WD-3KQN"},
		"Oblivion-Setup (Tōtara House) [oblivionfindings.com T6WD-3KQN] (1).exe":                 {"oblivionfindings.com", "T6WD-3KQN"},
		`C:\Users\aroha\Downloads\Oblivion-Setup (Aurora) [Care.Example.test:8443 t6wd3kqn].exe`: {"care.example.test:8443", "T6WD-3KQN"},
		"Oblivion-Setup (Aurora) [oblivionfindings.com 7Q2M-K9TO].exe":                           {"oblivionfindings.com", "7Q2M-K9T0"}, // O read as 0
		"Oblivion-Setup.exe":                                    {"", ""},
		"Oblivion-Setup [oblivionfindings.com].exe":             {"", ""},
		"Oblivion-Setup [bad_host!.com T6WD-3KQN].exe":          {"", ""},
		"Oblivion-Setup [oblivionfindings.com T6WD-3KQU].exe":   {"oblivionfindings.com", ""}, // U isn't in the alphabet
		"Oblivion-Setup [oblivionfindings.com:0 T6WD-3KQN].exe": {"", "T6WD-3KQN"},
	}
	for name, want := range cases {
		server, code := parseInstallerName(name)
		if server != want[0] || code != want[1] {
			t.Errorf("%s: got %q %q, want %q %q", name, server, code, want[0], want[1])
		}
	}
	if normaliseCode(" t6wd 3kqn ") != "T6WD-3KQN" || normaliseCode("T6WD-3KQ") != "" || normaliseCode("T6WD-3KQNN") != "" {
		t.Fatal("typed codes are not normalised like the server does")
	}
}

func TestRenamedFileFindsServerInDownloadRecordButStillNeedsTheCode(t *testing.T) {
	dir := t.TempDir()
	exe := filepath.Join(dir, "Oblivion-Setup (1).exe")
	if err := os.WriteFile(exe, []byte("x"), 0o600); err != nil {
		t.Fatal(err)
	}
	zone := "[ZoneTransfer]\r\nZoneId=3\r\nReferrerUrl=https://oblivionfindings.com/security-devices/setup\r\nHostUrl=https://oblivionfindings.com/security-devices/hubs/setup-codes/4/download\r\n"
	if err := os.WriteFile(exe+":Zone.Identifier", []byte(zone), 0o600); err != nil {
		t.Skip("no alternate data streams on this file system")
	}
	if got := zoneServer(exe); got != "oblivionfindings.com" {
		t.Fatalf("server from download record: %q", got)
	}
	r, code := redeem(context.Background(), redeemOptions{installer: exe, out: dir})
	if code != exitRedeemNoCode || r.state != "no_code" || r.section["server_known"] != "1" || r.server != "oblivionfindings.com" {
		t.Fatalf("renamed file: %+v %d", r, code)
	}
}

func TestRedeemNeverSkipsWindowsCertificateChecks(t *testing.T) {
	// A server Windows doesn't trust (httptest's own certificate) must be refused.
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		t.Error("the request reached an untrusted server")
	}))
	defer server.Close()
	u, _ := url.Parse(server.URL)
	dir := t.TempDir()
	r, code := redeem(context.Background(), redeemOptions{server: "localhost:" + u.Port(), code: "T6WD-3KQN", out: dir})
	if code != exitRedeemUntrusted || r.state != "untrusted" {
		t.Fatalf("untrusted certificate: %+v %d", r, code)
	}
	if _, err := os.Stat(filepath.Join(dir, "site-profile.json")); err == nil {
		t.Fatal("a profile was written")
	}
}

func TestUnreachableServerSaysWhatToAskIT(t *testing.T) {
	r, code := redeem(context.Background(), redeemOptions{server: "127.0.0.1:1", code: "T6WD-3KQN", out: t.TempDir()})
	if code != exitRedeemUnreachable || !strings.Contains(r.message, "Ask IT to allow 127.0.0.1 on port 443") || r.section["detail"] == "" {
		t.Fatalf("%+v %d", r, code)
	}
}

func siteProfileFixture(t *testing.T, deployment, transport string) []byte {
	t.Helper()
	data, err := json.Marshal(map[string]any{
		"version": 1, "component": "site", "platform": "windows",
		"deployment_url": deployment, "server_url": transport,
		"server_public_key":  base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{1}, 32)),
		"tls_public_key_pin": "sha256//" + base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{2}, 32)),
		"organisation":       "Oblivion Living", "site_id": 4, "site_label": "Tōtara House",
		"collector_uuid":  "0f8fad5b-d9cb-469f-a165-70867728950e",
		"enrolment_token": "ofc_enrol_" + strings.Repeat("a", 64),
		"expires_at":      time.Now().Add(23 * time.Hour).UTC().Format(time.RFC3339),
	})
	if err != nil {
		t.Fatal(err)
	}
	return data
}

func computerProfileFixture(t *testing.T, server string) []byte {
	t.Helper()
	data, err := json.Marshal(map[string]any{
		"version": 1, "platform": "windows", "server_url": server,
		"server_public_key": base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{3}, 32)),
		"agent_uuid":        "7c9e6679-7425-40de-944b-e07fc1f90ae7", "enrolment_token": strings.Repeat("b", 64),
		"expires_at":   time.Now().Add(20 * time.Minute).UTC().Format(time.RFC3339),
		"organisation": "Oblivion Living", "device_label": "TOTARA-HUB", "device_uid": "dev-17",
	})
	if err != nil {
		t.Fatal(err)
	}
	return data
}

func TestRedeemAnswerMustBelongToTheServerAsked(t *testing.T) {
	mode := "full"
	display := map[string]string{"site_name": "Tōtara House", "made_by": "Jordan Peri", "made_at": "2026-10-28T21:14:00Z", "expires_at": "2026-10-29T21:14:00Z"}
	base := redeemResult{server: "oblivionfindings.com", code: "T6WD-3KQN"}
	fresh := func() redeemResult {
		r := base
		r.section, r.display = map[string]string{}, map[string]string{}
		return r
	}

	dir := t.TempDir()
	hub := redeemResponse{State: "ok", Role: "hub", Profile: siteProfileFixture(t, "https://oblivionfindings.com", "https://hubs.oblivionfindings.com"),
		ComputerProfile: computerProfileFixture(t, "https://oblivionfindings.com"), Display: display}
	r, code := acceptRedeem(fresh(), hub, nil, "TOTARA-HUB", dir)
	if code != 0 || r.section["has_site_profile"] != "1" || r.section["has_computer_profile"] != "1" || r.display["site_name"] != "Tōtara House" {
		t.Fatalf("hub with Obli (D4): %+v %d", r, code)
	}
	for _, name := range []string{"site-profile.json", "computer-profile.json"} {
		if _, err := os.Stat(filepath.Join(dir, name)); err != nil {
			t.Fatal(name, err)
		}
	}

	elsewhere := hub
	elsewhere.Profile = siteProfileFixture(t, "https://attacker.example", "https://attacker.example")
	if _, code := acceptRedeem(fresh(), elsewhere, nil, "", t.TempDir()); code != exitRedeemUntrusted {
		t.Fatal("a profile for another server was accepted")
	}

	// The hub transport is this server, so the key we talked to must be the pinned one.
	pinned := hub
	pinned.Profile = siteProfileFixture(t, "https://oblivionfindings.com", "https://oblivionfindings.com")
	if _, code := acceptRedeem(fresh(), pinned, &x509.Certificate{RawSubjectPublicKeyInfo: []byte("other key")}, "", t.TempDir()); code != exitRedeemUntrusted {
		t.Fatal("a key that doesn't match the profile's pin was accepted")
	}
	if !pinMatches(&x509.Certificate{RawSubjectPublicKeyInfo: []byte("k")}, "sha256//"+base64.StdEncoding.EncodeToString(sha256Of("k"))) {
		t.Fatal("matching pin refused")
	}

	staff := "staff"
	computer := redeemResponse{State: "ok", Role: "computer", InstallMode: &staff, Profile: computerProfileFixture(t, "https://oblivionfindings.com"), Display: display}
	if r, code := acceptRedeem(fresh(), computer, nil, "AURORA-PC", t.TempDir()); code != 0 || r.section["install_mode"] != "staff" {
		t.Fatalf("staff computer: %+v %d", r, code)
	}
	computer.InstallMode = nil
	if _, code := acceptRedeem(fresh(), computer, nil, "AURORA-PC", t.TempDir()); code != exitRedeemUntrusted {
		t.Fatal("a computer answer without an install mode was accepted")
	}
	computer.InstallMode = &mode
	computer.Role = "something-else"
	if _, code := acceptRedeem(fresh(), computer, nil, "AURORA-PC", t.TempDir()); code != exitRedeemUntrusted {
		t.Fatal("an unknown role was accepted")
	}
}

func sha256Of(text string) []byte {
	sum := sha256.Sum256([]byte(text))
	return sum[:]
}

func TestWindowsProxySettingIsReadLikeWindowsDoes(t *testing.T) {
	u, bypass, ok := parseProxySetting("http=proxy.house.local:8080;https=secure.house.local:3128", "<local>;*.house.local;10.*")
	if !ok || u.Host != "secure.house.local:3128" {
		t.Fatalf("%v %v", u, ok)
	}
	for host, want := range map[string]bool{"intranet": true, "printer.house.local": true, "10.1.2.3": true, "oblivionfindings.com": false} {
		if bypassed(host, bypass) != want {
			t.Errorf("bypass %s: want %v", host, want)
		}
	}
	if u, _, ok := parseProxySetting("proxy.house.local:8080", ""); !ok || u.String() != "http://proxy.house.local:8080" {
		t.Fatal("plain proxy setting misread", u)
	}
	for _, bad := range []string{"", "user:pass@proxy:8080", "http://proxy:8080/path", "ftp://proxy:21"} {
		if _, err := parseProxyAddress(bad); err == nil {
			t.Errorf("accepted proxy %q", bad)
		}
	}
	if u, err := parseProxyAddress("proxy.house.local"); err != nil || u.Host != "proxy.house.local:80" {
		t.Fatal("proxy without a port", u, err)
	}
}

func TestSetupResultsAreUTF16SoInnoReadsMacrons(t *testing.T) {
	data := encodeINI([]iniSection{{"display", map[string]string{"site_name": "Tōtara House", "message": "line one\r\nline two", "quoted": `"x"`}}})
	if !bytes.HasPrefix(data, []byte{0xff, 0xfe}) || len(data)%2 != 0 {
		t.Fatal("not UTF-16 with a byte-order mark")
	}
	units := make([]uint16, (len(data)-2)/2)
	for i := range units {
		units[i] = uint16(data[2+2*i]) | uint16(data[3+2*i])<<8
	}
	text := string(utf16.Decode(units))
	for _, want := range []string{"[display]\r\n", "site_name=Tōtara House\r\n", "message=line one line two\r\n", `quoted=""x""`} {
		if !strings.Contains(text, want) {
			t.Errorf("missing %q in %q", want, text)
		}
	}
}

func TestRedeemArgumentsNeedAPrivateOutputFolder(t *testing.T) {
	if _, err := parseRedeemOptions([]string{"--server", "oblivionfindings.com", "--code", "T6WD-3KQN"}); err == nil {
		t.Fatal("no output folder accepted")
	}
	if _, err := parseRedeemOptions([]string{"--out", "relative", "--code", "T6WD-3KQN"}); err == nil {
		t.Fatal("relative output folder accepted")
	}
	if _, err := parseRedeemOptions([]string{"--out", t.TempDir(), "--insecure"}); err == nil {
		t.Fatal("an unknown switch was accepted")
	}
}
