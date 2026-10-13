package cctv

import (
	"context"
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"github.com/bluenviron/gortsplib/v5/pkg/headers"
)

// Synthetic examples based on official DeviceInfo and StreamingChannel schemas;
// not an original export or firmware/hardware acceptance fixture.
const hikvisionIdentityFixture = `<DeviceInfo xmlns="http://www.hikvision.com/ver20/XMLSchema" version="2.0"><model>DS-2CD2347G3-LIS2UY/SL</model><serialNumber>fixture-camera-serial</serialNumber><deviceID>fixture-device-id</deviceID><firmwareVersion>fixture-version</firmwareVersion><firmwareReleasedDate>fixture-build</firmwareReleasedDate><alternateURL>https://foreign.example.test/never-follow</alternateURL></DeviceInfo>`
const hikvisionChannelFixture = `<StreamingChannel version="2.0"><id>102</id><channelName>Entry camera</channelName><enabled>true</enabled><Video><enabled>true</enabled><videoInputChannelID>1</videoInputChannelID><videoCodecType>H.264</videoCodecType><videoResolutionWidth>640</videoResolutionWidth><videoResolutionHeight>360</videoResolutionHeight><maxFrameRate>2500</maxFrameRate></Video><Audio><enabled>false</enabled></Audio></StreamingChannel>`

func hikvisionChannelsFixture(channel string) string {
	return `<StreamingChannelList xmlns="http://www.isapi.org/ver20/XMLSchema" version="2.0">` + channel + `</StreamingChannelList>`
}

func TestHikvisionXMLIdentityAndChannelObservations(t *testing.T) {
	identity, err := parseHikvisionIdentity([]byte(hikvisionIdentityFixture))
	if err != nil || identity.Model != "DS-2CD2347G3-LIS2UY/SL" || identity.Serial != "fixture-camera-serial" || identity.DeviceID != "fixture-device-id" || identity.Firmware != "fixture-version" || identity.FirmwareBuild != "fixture-build" {
		t.Fatal("exact observed identity missing", err)
	}
	for _, namespace := range []string{"http://www.isapi.org/ver20/XMLSchema", "http://www.hikvision.com/ver20/XMLSchema"} {
		body := strings.ReplaceAll(hikvisionChannelsFixture(hikvisionChannelFixture), "http://www.isapi.org/ver20/XMLSchema", namespace)
		channels, err := parseHikvisionChannels([]byte(body))
		if err != nil || len(channels) != 1 {
			t.Fatal("channel observation missing", err)
		}
		channel := channels[0]
		if channel.ID != "102" || channel.InputID != "1" || channel.Name != "Entry camera" || !channel.Enabled || !channel.VideoEnabled || channel.Codec != "H.264" || channel.Width != 640 || channel.Height != 360 || channel.FPS100 != 2500 || channel.AudioEnabled == nil || *channel.AudioEnabled {
			t.Fatal("channel identity/profile changed")
		}
	}
	// Observe unsupported video and explicit/unknown audio honestly; do not turn
	// discovery into codec support, a transport decision or audio configuration.
	body := hikvisionChannelsFixture(strings.ReplaceAll(strings.ReplaceAll(hikvisionChannelFixture, "H.264", "H.265"), "videoInputChannelID", "dynVideoInputChannelID"))
	channels, err := parseHikvisionChannels([]byte(body))
	if err != nil || channels[0].Codec != "H.265" || channels[0].InputID != "1" {
		t.Fatal("unsupported/dynamic profile observation lost")
	}
	body = hikvisionChannelsFixture(strings.ReplaceAll(hikvisionChannelFixture, `<Audio><enabled>false</enabled></Audio>`, ""))
	channels, err = parseHikvisionChannels([]byte(body))
	if err != nil || channels[0].AudioEnabled != nil {
		t.Fatal("missing audio was fabricated as disabled")
	}
}

func TestHikvisionXMLRejectsAmbiguousIdentityAndHostileDocuments(t *testing.T) {
	for _, body := range []string{
		`<!DOCTYPE DeviceInfo [<!ENTITY xxe SYSTEM "file:///private">]>` + hikvisionIdentityFixture,
		`<?outside value?>` + hikvisionIdentityFixture,
		hikvisionIdentityFixture + hikvisionIdentityFixture,
		strings.Replace(hikvisionIdentityFixture, `<model>`, `<model><nested>`, 1),
		strings.Replace(hikvisionIdentityFixture, `</model>`, `</model><model>other</model>`, 1),
		strings.Replace(hikvisionIdentityFixture, `<serialNumber>fixture-camera-serial</serialNumber>`, "", 1),
		strings.Replace(hikvisionIdentityFixture, `version="2.0"`, `version="1.0"`, 1),
		strings.Replace(hikvisionIdentityFixture, `version="2.0"`, `version="2.0" version="2.0"`, 1),
		strings.Replace(hikvisionIdentityFixture, `http://www.hikvision.com/ver20/XMLSchema`, `https://foreign.example.test`, 1),
		strings.Replace(hikvisionIdentityFixture, `<model>`, `<model xmlns="https://foreign.example.test">`, 1),
		strings.Replace(hikvisionIdentityFixture, `fixture-camera-serial`, "fixture\x00serial", 1),
		strings.Replace(hikvisionIdentityFixture, `fixture-version`, strings.Repeat("x", 4097), 1),
		strings.Repeat("x", hikvisionXMLLimit+1),
		`<DeviceInfo xmlns="http://www.isapi.org/ver20/XMLSchema" version="2.0">` + strings.Repeat("<nested>", 17) + strings.Repeat("</nested>", 17) + `</DeviceInfo>`,
		`<DeviceInfo xmlns="http://www.isapi.org/ver20/XMLSchema" version="2.0">` + strings.Repeat("<extra/>", 8193) + `</DeviceInfo>`,
	} {
		identity, err := parseHikvisionIdentity([]byte(body))
		if err == nil || identity != (hikvisionIdentity{}) {
			t.Fatal("ambiguous or hostile identity accepted")
		}
	}
}

func TestHikvisionXMLRejectsAmbiguousChannels(t *testing.T) {
	for _, channel := range []string{
		hikvisionChannelFixture + hikvisionChannelFixture,
		strings.Replace(hikvisionChannelFixture, `<id>102</id>`, `<id>102</id><id>202</id>`, 1),
		strings.Replace(hikvisionChannelFixture, `<id>102</id>`, `<id>0102</id>`, 1),
		strings.Replace(hikvisionChannelFixture, `<enabled>true</enabled>`, `<enabled>maybe</enabled>`, 1),
		strings.Replace(hikvisionChannelFixture, `<videoInputChannelID>1</videoInputChannelID>`, `<videoInputChannelID>1</videoInputChannelID><dynVideoInputChannelID>2</dynVideoInputChannelID>`, 1),
		strings.Replace(hikvisionChannelFixture, `<videoInputChannelID>1</videoInputChannelID>`, "", 1),
		strings.Replace(hikvisionChannelFixture, `<videoResolutionWidth>640</videoResolutionWidth>`, `<videoResolutionWidth>0</videoResolutionWidth>`, 1),
		strings.Replace(hikvisionChannelFixture, `<videoResolutionHeight>360</videoResolutionHeight>`, `<videoResolutionHeight>99999999</videoResolutionHeight>`, 1),
		strings.Replace(hikvisionChannelFixture, `<maxFrameRate>2500</maxFrameRate>`, `<maxFrameRate>-1</maxFrameRate>`, 1),
		strings.Replace(hikvisionChannelFixture, `<Audio>`, `<Audio><enabled>true</enabled>`, 1),
		strings.Replace(hikvisionChannelFixture, `</StreamingChannel>`, `<Video/></StreamingChannel>`, 1),
	} {
		if channels, err := parseHikvisionChannels([]byte(hikvisionChannelsFixture(channel))); err == nil || channels != nil {
			t.Fatal("ambiguous channel accepted")
		}
	}
	var many strings.Builder
	for id := 1; id <= 257; id++ {
		many.WriteString(strings.Replace(hikvisionChannelFixture, `<id>102</id>`, fmt.Sprintf("<id>%d</id>", id), 1))
	}
	if channels, err := parseHikvisionChannels([]byte(hikvisionChannelsFixture(many.String()))); err == nil || channels != nil {
		t.Fatal("channel count unbounded")
	}
}

type hikvisionISAPIFixture struct {
	mu                   sync.Mutex
	requests, authorized map[string]int
	mode                 string
}

func newHikvisionISAPIFixture(t *testing.T, mode string) (httpsTunnelSource, *x509.CertPool, *hikvisionISAPIFixture) {
	t.Helper()
	fixture := &hikvisionISAPIFixture{mode: mode, requests: map[string]int{}, authorized: map[string]int{}}
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fixture.mu.Lock()
		fixture.requests[r.URL.Path]++
		fixture.mu.Unlock()
		if r.Method != "GET" || r.URL.RawQuery != "" || (r.URL.Path != "/ISAPI/System/deviceInfo" && r.URL.Path != "/ISAPI/Streaming/channels") {
			t.Error("unexpected discovery request")
			w.WriteHeader(404)
			return
		}
		if mode == "redirect" {
			w.Header().Set("Location", "https://foreign.example.test/secret")
			w.WriteHeader(302)
			return
		}
		if mode == "slow" {
			select {
			case <-r.Context().Done():
			case <-time.After(time.Second):
			}
			return
		}
		algorithm := "SHA-256"
		if mode == "md5" {
			algorithm = "MD5"
		}
		if r.Header.Get("Authorization") == "" && mode != "unchallenged" || mode == "repeat_401" {
			challenge := `Digest realm="isapi-fixture",nonce="isapi-nonce",algorithm=` + algorithm + `,qop="auth"`
			if mode == "basic" {
				challenge = `Basic realm="isapi-fixture"`
			}
			if mode == "duplicate" {
				challenge += `,nonce="another"`
			}
			w.Header().Set("WWW-Authenticate", challenge)
			w.Header().Set("Content-Length", "0")
			w.WriteHeader(401)
			return
		}
		if mode != "unchallenged" {
			var parsed headers.Authorization
			if parsed.Unmarshal(base.HeaderValue{r.Header.Get("Authorization")}) != nil || parsed.Method != headers.AuthMethodDigest || parsed.Username != "fixture-isapi-user" || parsed.URI != r.URL.EscapedPath() || parsed.Realm != "isapi-fixture" || parsed.Nonce != "isapi-nonce" || parsed.Cnonce == nil || parsed.Nc == nil || *parsed.Nc != "00000001" || parsed.Qop == nil || *parsed.Qop != "auth" {
				w.Header().Set("Content-Length", "0")
				w.WriteHeader(401)
				return
			}
			expected := fixtureHash(algorithm, fixtureHash(algorithm, "fixture-isapi-user:isapi-fixture:fixture-isapi-secret")+":isapi-nonce:00000001:"+*parsed.Cnonce+":auth:"+fixtureHash(algorithm, "GET:"+r.URL.EscapedPath()))
			if parsed.Response != expected {
				w.Header().Set("Content-Length", "0")
				w.WriteHeader(401)
				return
			}
			fixture.mu.Lock()
			fixture.authorized[r.URL.Path]++
			fixture.mu.Unlock()
		}
		body := hikvisionIdentityFixture
		if r.URL.Path == "/ISAPI/Streaming/channels" {
			body = hikvisionChannelsFixture(hikvisionChannelFixture)
		}
		if mode == "bad_identity" && r.URL.Path == "/ISAPI/System/deviceInfo" {
			body = `<untrusted/>`
		}
		if mode == "bad_channel" && r.URL.Path == "/ISAPI/Streaming/channels" {
			body = hikvisionChannelsFixture(hikvisionChannelFixture + hikvisionChannelFixture)
		}
		w.Header().Set("Content-Type", "application/xml; charset=utf-8")
		if mode == "wrong_type" {
			w.Header().Set("Content-Type", "text/html")
		}
		if mode == "compressed" {
			w.Header().Set("Content-Encoding", "gzip")
		}
		if mode == "oversized_header" {
			w.Header().Set("Oversized", strings.Repeat("x", 9000))
		}
		if mode == "oversized_body" {
			body = strings.Repeat("x", hikvisionXMLLimit+1)
		}
		if mode == "truncated" {
			w.Header().Set("Content-Length", strconv.Itoa(len(body)+5))
		}
		_, _ = io.WriteString(w, body)
	}))
	server.Config.ErrorLog = log.New(io.Discard, "", 0)
	server.StartTLS()
	t.Cleanup(server.Close)
	parsed, _ := url.Parse(server.URL)
	port, _ := strconv.Atoi(parsed.Port())
	pin := sha256.Sum256(server.Certificate().Raw)
	roots := x509.NewCertPool()
	roots.AddCert(server.Certificate())
	source := httpsTunnelSource{IP: parsed.Hostname(), ServerName: parsed.Hostname(), Port: port, Pin: hex.EncodeToString(pin[:]), Username: "fixture-isapi-user", Password: "fixture-isapi-secret"}
	return source, roots, fixture
}

func TestHikvisionISAPIReadOnlyAuthenticatedDiscovery(t *testing.T) {
	for _, mode := range []string{"sha256", "md5"} {
		t.Run(mode, func(t *testing.T) {
			source, roots, fixture := newHikvisionISAPIFixture(t, mode)
			inventory, err := discoverHikvision(t.Context(), source, roots)
			if err != nil || inventory.Identity.Serial != "fixture-camera-serial" || len(inventory.Channels) != 1 || inventory.Channels[0].ID != "102" {
				t.Fatal("authenticated discovery failed", err)
			}
			fixture.mu.Lock()
			defer fixture.mu.Unlock()
			if len(fixture.requests) != 2 || fixture.requests["/ISAPI/System/deviceInfo"] != 2 || fixture.requests["/ISAPI/Streaming/channels"] != 2 || fixture.authorized["/ISAPI/System/deviceInfo"] != 1 || fixture.authorized["/ISAPI/Streaming/channels"] != 1 {
				t.Fatal("read-only bounded paths/authentication witness missing")
			}
		})
	}
}

func TestHikvisionISAPIDenialsDoNotExposeSecretsOrPartialInventory(t *testing.T) {
	for _, mode := range []string{"basic", "duplicate", "redirect", "unchallenged", "repeat_401", "bad_identity", "bad_channel", "wrong_type", "compressed", "oversized_header", "oversized_body", "truncated", "wrong_password", "wrong_pin", "wrong_name", "untrusted", "slow"} {
		t.Run(mode, func(t *testing.T) {
			source, roots, fixture := newHikvisionISAPIFixture(t, mode)
			if mode == "wrong_password" {
				source.Password = "fixture-wrong-secret"
			}
			if mode == "wrong_pin" {
				source.Pin = strings.Repeat("0", 64)
			}
			if mode == "wrong_name" {
				source.ServerName = "non-resolving.example.test"
			}
			if mode == "untrusted" {
				roots = x509.NewCertPool()
			}
			parent, cancel := context.WithTimeout(t.Context(), 200*time.Millisecond)
			defer cancel()
			inventory, err := discoverHikvision(parent, source, roots)
			if err == nil || inventory.Identity != (hikvisionIdentity{}) || inventory.Channels != nil {
				t.Fatal("unsafe/partial inventory accepted")
			}
			if strings.Contains(err.Error(), source.Username) || strings.Contains(err.Error(), source.Password) || strings.Contains(err.Error(), source.IP) {
				t.Fatal("discovery error leaked private source material")
			}
			fixture.mu.Lock()
			defer fixture.mu.Unlock()
			count := 0
			for _, requests := range fixture.requests {
				count += requests
			}
			if count > 4 || mode == "bad_identity" && fixture.requests["/ISAPI/Streaming/channels"] != 0 {
				t.Fatal("unbounded retry or identity failure did not stop channel query")
			}
		})
	}
}

func TestHikvisionISAPIBoundsBeforeOutboundAndCancellation(t *testing.T) {
	source, roots, fixture := newHikvisionISAPIFixture(t, "sha256")
	for _, resource := range []string{"https://foreign.example.test/path", "/ISAPI/System/deviceInfo?secret=x", "/ISAPI/System/reboot", "/ISAPI/ContentMgmt/StreamingProxy/channels"} {
		if body, err := readHikvisionISAPI(t.Context(), source, roots, resource); err == nil || body != nil {
			t.Fatal("undeclared target/resource accepted")
		}
	}
	for _, mutate := range []func(*httpsTunnelSource){func(s *httpsTunnelSource) { s.IP = "camera.example.test" }, func(s *httpsTunnelSource) { s.Password = "bad\r\nheader" }, func(s *httpsTunnelSource) { s.Port = 0 }} {
		candidate := source
		mutate(&candidate)
		if _, err := discoverHikvision(t.Context(), candidate, roots); err == nil {
			t.Fatal("unbound source accepted")
		}
	}
	cancelled, cancel := context.WithCancel(t.Context())
	cancel()
	if _, err := discoverHikvision(cancelled, source, roots); err == nil {
		t.Fatal("cancelled discovery accepted")
	}
	fixture.mu.Lock()
	defer fixture.mu.Unlock()
	if len(fixture.requests) != 0 {
		t.Fatal("invalid/cancelled discovery made an outbound request")
	}
	// There is no DNS/network fallback in the TLS target; the only used address
	// is the validated numeric source IP, never a locator in response XML.
	if net.ParseIP(source.IP) == nil {
		t.Fatal("fixture must use numeric fixed IP")
	}
}
