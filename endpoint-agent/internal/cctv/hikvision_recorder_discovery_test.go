package cctv

import (
	"context"
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/bluenviron/gortsplib/v5/pkg/base"
	"github.com/bluenviron/gortsplib/v5/pkg/headers"
)

// Synthetic manufacturer-schema examples. Not physical firmware acceptance.
const hikvisionRecorderIdentityFixture = `<DeviceInfo xmlns="http://www.hikvision.com/ver20/XMLSchema" version="2.0"><model>DS-7608NXI-K1/8P</model><serialNumber>fixture-recorder-serial</serialNumber><deviceID>fixture-recorder-id</deviceID><firmwareVersion>fixture-version</firmwareVersion><firmwareReleasedDate>fixture-build</firmwareReleasedDate><alternateURL>https://foreign.example.test/never-follow</alternateURL></DeviceInfo>`
const hikvisionRecorderChannelFixture = `<StreamingChannel version="2.0"><id>1301</id><channelName>Synthetic recorder input</channelName><enabled>true</enabled><Video><enabled>true</enabled><dynVideoInputChannelID>13</dynVideoInputChannelID><videoCodecType>H.265</videoCodecType><videoResolutionWidth>1920</videoResolutionWidth><videoResolutionHeight>1080</videoResolutionHeight><maxFrameRate>0</maxFrameRate></Video></StreamingChannel>`
const hikvisionRecorderInputFixture = `<InputProxyChannelStatus><id>13</id><sourceInputPortDescriptor><proxyProtocol>HIKVISION</proxyProtocol><addressingFormatType>ipaddress</addressingFormatType><ipAddress>192.0.2.20</ipAddress><managePortNo>8000</managePortNo><srcInputPort>1</srcInputPort><online>true</online><userName>private-input-user</userName><password>private-input-secret</password><serialNumber>unwitnessed-serial</serialNumber><alternateURL>https://foreign.test/never-follow</alternateURL></sourceInputPortDescriptor></InputProxyChannelStatus>`

func hikvisionRecorderInputsFixture(input string) string {
	return `<InputProxyChannelStatusList xmlns="http://www.hikvision.com/ver20/XMLSchema" version="2.0">` + input + `</InputProxyChannelStatusList>`
}

func TestHikvisionRecorderObservationsPreserveDynamicInputsUnknownFactsAndUnverifiedIdentity(t *testing.T) {
	channels, err := parseHikvisionRecorderChannels([]byte(hikvisionChannelsFixture(hikvisionRecorderChannelFixture)))
	if err != nil || len(channels) != 1 || channels[0].ID != "1301" || channels[0].InputID != "13" || channels[0].InputKind != "digital_proxy" || channels[0].Codec != "H.265" || channels[0].FPS100 != 0 {
		t.Fatalf("wrong recorder stream observation: %v", err)
	}
	local, err := parseHikvisionRecorderChannels([]byte(hikvisionChannelsFixture(hikvisionChannelFixture)))
	if err != nil || local[0].InputKind != "local_video" {
		t.Fatalf("local input was relabelled: %v", err)
	}
	inputs, err := parseHikvisionRecorderInputs([]byte(hikvisionRecorderInputsFixture(hikvisionRecorderInputFixture)))
	if err != nil || len(inputs) != 1 || inputs[0].ID != "13" || inputs[0].Online == nil || !*inputs[0].Online || inputs[0].Address == nil || *inputs[0].Address != "192.0.2.20" || inputs[0].ManagementPort == nil || *inputs[0].ManagementPort != 8000 || inputs[0].SourceInputID == nil || *inputs[0].SourceInputID != "1" || inputs[0].Association != "unverified" || string(inputs[0].CameraIdentity) != "null" {
		t.Fatalf("wrong input facts or fabricated camera: %v", err)
	}
	raw, _ := json.Marshal(inputs)
	for _, secret := range []string{"private-input-user", "private-input-secret", "unwitnessed-serial", "alternateURL"} {
		if strings.Contains(string(raw), secret) {
			t.Fatal("private/unknown XML field projected")
		}
	}
	unknown := strings.ReplaceAll(hikvisionRecorderInputFixture, "<online>true</online>", "")
	unknown = strings.ReplaceAll(unknown, "<ipAddress>192.0.2.20</ipAddress>", "")
	unknown = strings.ReplaceAll(unknown, "<managePortNo>8000</managePortNo>", "")
	unknown = strings.ReplaceAll(unknown, "<srcInputPort>1</srcInputPort>", "")
	inputs, err = parseHikvisionRecorderInputs([]byte(hikvisionRecorderInputsFixture(unknown)))
	if err != nil || inputs[0].Online != nil || inputs[0].Address != nil || inputs[0].ManagementPort != nil || inputs[0].SourceInputID != nil {
		t.Fatal("unknown input facts became false/defaults")
	}
	empty, err := parseHikvisionRecorderInputs([]byte(hikvisionRecorderInputsFixture("")))
	if err != nil || empty == nil || len(empty) != 0 {
		t.Fatal("empty digital input list changed")
	}
}

func TestHikvisionRecorderXMLRejectsAmbiguityAndHostileInputStatus(t *testing.T) {
	normal := hikvisionRecorderInputsFixture(hikvisionRecorderInputFixture)
	cases := []string{
		strings.Replace(normal, `<id>13</id>`, `<id>13</id><id>14</id>`, 1),
		hikvisionRecorderInputsFixture(hikvisionRecorderInputFixture + hikvisionRecorderInputFixture),
		strings.Replace(normal, `<online>true</online>`, `<online>true</online><online>false</online>`, 1),
		strings.Replace(normal, `<online>true</online>`, `<online>yes</online>`, 1),
		strings.Replace(normal, `<managePortNo>8000</managePortNo>`, `<managePortNo>65536</managePortNo>`, 1),
		strings.Replace(normal, `<managePortNo>8000</managePortNo>`, `<managePortNo>-1</managePortNo>`, 1),
		strings.Replace(normal, `<ipAddress>192.0.2.20</ipAddress>`, `<ipAddress>https://foreign.test/path</ipAddress>`, 1),
		strings.Replace(normal, `<srcInputPort>1</srcInputPort>`, `<srcInputPort>01</srcInputPort>`, 1),
		strings.Replace(normal, `version="2.0"`, `version="1.0"`, 1),
		`<!DOCTYPE x [<!ENTITY y SYSTEM "file:///never-read">]>` + normal,
		strings.Replace(normal, `<proxyProtocol>HIKVISION</proxyProtocol>`, "<proxyProtocol>HIK\u200bVISION</proxyProtocol>", 1),
		hikvisionRecorderInputsFixture(strings.Repeat(hikvisionRecorderInputFixture, 257)),
		normal + normal,
		strings.Repeat("x", hikvisionXMLLimit+1),
	}
	for i, body := range cases {
		if _, err := parseHikvisionRecorderInputs([]byte(body)); err == nil {
			t.Fatalf("hostile status %d accepted", i)
		}
	}
	ambiguous := strings.Replace(hikvisionRecorderChannelFixture, `<dynVideoInputChannelID>13</dynVideoInputChannelID>`, `<dynVideoInputChannelID>13</dynVideoInputChannelID><videoInputChannelID>1</videoInputChannelID>`, 1)
	if _, err := parseHikvisionRecorderChannels([]byte(hikvisionChannelsFixture(ambiguous))); err == nil {
		t.Fatal("dynamic/local stream identity mixed")
	}
}

func TestHikvisionRecorderTLSAndDigestReadOnlyFixedResourcesDoNotFollowInputs(t *testing.T) {
	source, roots, fixture := newHikvisionRecorderISAPIFixture(t, "normal")
	inventory, err := discoverHikvisionRecorder(context.Background(), source, roots)
	if err != nil || inventory.Identity.Serial != "fixture-recorder-serial" || len(inventory.Channels) != 1 || len(inventory.Inputs) != 1 {
		t.Fatalf("recorder discovery failed: %v", err)
	}
	if inventory.ResponseSHA256["device_info"] != hash([]byte(hikvisionRecorderIdentityFixture)) || inventory.ResponseSHA256["streaming_proxy"] != hash([]byte(hikvisionChannelsFixture(hikvisionRecorderChannelFixture))) || inventory.ResponseSHA256["input_status"] != hash([]byte(hikvisionRecorderInputsFixture(hikvisionRecorderInputFixture))) {
		t.Fatal("original response hashes changed")
	}
	fixture.mu.Lock()
	defer fixture.mu.Unlock()
	if len(fixture.requests) != 3 || len(fixture.authorized) != 3 {
		t.Fatal("unexpected authority/resource accessed")
	}
	for _, path := range []string{"/ISAPI/System/deviceInfo", "/ISAPI/ContentMgmt/StreamingProxy/channels", "/ISAPI/ContentMgmt/InputProxy/channels/status"} {
		if fixture.requests[path] != 2 || fixture.authorized[path] != 1 {
			t.Fatal("missing original TLS/Digest read")
		}
	}
}

func TestHikvisionRecorderTransportRefusesDowngradesWrongTrustAndForeignResources(t *testing.T) {
	for _, mode := range []string{"basic", "redirect", "repeat_401", "unchallenged", "wrong_type", "compressed", "oversized_body", "truncated", "bad_identity", "bad_channel"} {
		t.Run(mode, func(t *testing.T) {
			source, roots, _ := newHikvisionRecorderISAPIFixture(t, mode)
			if _, err := discoverHikvisionRecorder(context.Background(), source, roots); err == nil {
				t.Fatal("unsafe recorder reply accepted")
			}
		})
	}
	source, roots, fixture := newHikvisionRecorderISAPIFixture(t, "normal")
	for _, path := range []string{"/ISAPI/Streaming/channels", "/ISAPI/ContentMgmt/InputProxy/channels", "/ISAPI/System/deviceInfo?change=1", "https://foreign.test/secret"} {
		if _, err := readHikvisionRecorderISAPI(context.Background(), source, roots, path); err == nil {
			t.Fatal("foreign/extra resource accepted")
		}
	}
	if _, err := readHikvisionISAPI(context.Background(), source, roots, "/ISAPI/ContentMgmt/StreamingProxy/channels"); err == nil {
		t.Fatal("direct-camera allowlist expanded")
	}
	if _, err := discoverHikvisionRecorder(context.Background(), source, nil); err == nil {
		t.Fatal("fixture's untrusted certificate accepted")
	}
	wrong := source
	wrong.Pin = strings.Repeat("0", 64)
	if _, err := discoverHikvisionRecorder(context.Background(), wrong, roots); err == nil {
		t.Fatal("wrong certificate pin accepted")
	}
	canceled, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := discoverHikvisionRecorder(canceled, source, roots); err == nil {
		t.Fatal("cancelled discovery ran")
	}
	fixture.mu.Lock()
	defer fixture.mu.Unlock()
	if len(fixture.authorized) != 0 {
		t.Fatal("credentials sent before authority/trust approval")
	}
}
func newHikvisionRecorderISAPIFixture(t *testing.T, mode string) (httpsTunnelSource, *x509.CertPool, *hikvisionISAPIFixture) {
	t.Helper()
	fixture := &hikvisionISAPIFixture{mode: mode, requests: map[string]int{}, authorized: map[string]int{}}
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fixture.mu.Lock()
		fixture.requests[r.URL.Path]++
		fixture.mu.Unlock()
		if r.Method != "GET" || r.URL.RawQuery != "" || (r.URL.Path != "/ISAPI/System/deviceInfo" && r.URL.Path != "/ISAPI/ContentMgmt/StreamingProxy/channels" && r.URL.Path != "/ISAPI/ContentMgmt/InputProxy/channels/status") {
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
		body := hikvisionRecorderIdentityFixture
		if r.URL.Path == "/ISAPI/ContentMgmt/StreamingProxy/channels" {
			body = hikvisionChannelsFixture(hikvisionRecorderChannelFixture)
		}
		if r.URL.Path == "/ISAPI/ContentMgmt/InputProxy/channels/status" {
			body = hikvisionRecorderInputsFixture(hikvisionRecorderInputFixture)
		}
		if mode == "bad_identity" && r.URL.Path == "/ISAPI/System/deviceInfo" {
			body = `<untrusted/>`
		}
		if mode == "bad_channel" && r.URL.Path == "/ISAPI/ContentMgmt/StreamingProxy/channels" {
			body = hikvisionChannelsFixture(hikvisionRecorderChannelFixture + hikvisionRecorderChannelFixture)
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
