// Package cctv implements the private, enrolled Site collector video child.
package cctv

import (
	"bytes"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"
)

const MaxFrame = 65536

var ErrProtocol = errors.New("media protocol unavailable")
var uuid = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
var digest = regexp.MustCompile(`^[0-9a-f]{64}$`)

type Envelope struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
}
type Source struct {
	Hikvision *hikvisionDiscoverySource `json:"-"`
	URL       string                    `json:"url"`
	Username  *string                   `json:"username"`
	Password  *string                   `json:"password"`
}
type Turn struct {
	URLs         []string `json:"urls"`
	Username     string   `json:"username"`
	Credential   string   `json:"credential"`
	ExpiresAt    string   `json:"expires_at"`
	RelayCIDRs   []string `json:"relay_cidrs"`
	RelayPortMin int      `json:"relay_port_min"`
	RelayPortMax int      `json:"relay_port_max"`
}
type Description struct {
	Type string `json:"type"`
	SDP  string `json:"sdp"`
}
type Grant struct {
	Hikvision      hikvisionLiveBinding `json:"-"`
	Version        int                  `json:"version"`
	Type           string               `json:"type"`
	Collector      string               `json:"collector_uuid"`
	Site           int64                `json:"site_id"`
	Camera         int64                `json:"camera_id"`
	Recorder       int64                `json:"recorder_device_id"`
	Provider       string               `json:"provider"`
	Console        string               `json:"console_id"`
	ProviderCamera string               `json:"provider_camera_id"`
	Session        string               `json:"session_uuid"`
	Negotiation    string               `json:"negotiation_uuid"`
	Generation     uint64               `json:"generation"`
	Revision       int64                `json:"source_revision"`
	SourceHash     string               `json:"source_sha256"`
	TurnHash       string               `json:"turn_sha256"`
	OfferHash      string               `json:"offer_sha256"`
	Quality        string               `json:"quality"`
	Codec          string               `json:"video_codec"`
	Audio          bool                 `json:"audio"`
	Scope          int64                `json:"discovery_scope_id"`
	IP             string               `json:"allowed_ip"`
	Port           int                  `json:"allowed_port"`
	ServerName     string               `json:"tls_server_name"`
	Pin            string               `json:"tls_pin"`
	Issued         string               `json:"issued_at"`
	Expires        string               `json:"expires_at"`
	SessionExpires string               `json:"session_expires_at"`
}
type Message struct {
	Version    int             `json:"version"`
	Operation  string          `json:"operation"`
	Request    string          `json:"request_uuid,omitempty"`
	Collector  string          `json:"collector_uuid,omitempty"`
	Site       int64           `json:"site_id,omitempty"`
	Key        string          `json:"server_public_key,omitempty"`
	Grant      Envelope        `json:"grant,omitempty"`
	Source     json.RawMessage `json:"source,omitempty"`
	Turn       json.RawMessage `json:"turn,omitempty"`
	Offer      Description     `json:"offer,omitempty"`
	Session    string          `json:"session_uuid,omitempty"`
	Generation uint64          `json:"generation,omitempty"`
}
type Reply struct {
	Version    int          `json:"version"`
	Request    string       `json:"request_uuid"`
	Session    string       `json:"session_uuid"`
	Generation uint64       `json:"generation"`
	Status     string       `json:"status"`
	Answer     *Description `json:"answer"`
	Reason     *string      `json:"reason_code"`
}

func hash(b []byte) string { h := sha256.Sum256(b); return hex.EncodeToString(h[:]) }
func instant(s string) (time.Time, error) {
	t, e := time.Parse("2006-01-02T15:04:05Z", s)
	if e != nil || t.Format("2006-01-02T15:04:05Z") != s {
		return t, ErrProtocol
	}
	return t, nil
}

// Closed detects duplicate keys recursively before typed decoding.
func Closed(b []byte, keys ...string) error {
	if len(b) == 0 || len(b) > MaxFrame || !utf8.Valid(b) {
		return ErrProtocol
	}
	d := json.NewDecoder(bytes.NewReader(b))
	d.UseNumber()
	var walk func() error
	walk = func() error {
		t, e := d.Token()
		if e != nil {
			return ErrProtocol
		}
		if x, ok := t.(json.Delim); ok {
			switch x {
			case '{':
				seen := map[string]bool{}
				for d.More() {
					k, e := d.Token()
					s, ok := k.(string)
					if e != nil || !ok || seen[s] {
						return ErrProtocol
					}
					seen[s] = true
					if e = walk(); e != nil {
						return e
					}
				}
			case '[':
				for d.More() {
					if e = walk(); e != nil {
						return e
					}
				}
			default:
				return ErrProtocol
			}
			_, e = d.Token()
			return e
		}
		return nil
	}
	if walk() != nil {
		return ErrProtocol
	}
	if _, e := d.Token(); e != io.EOF {
		return ErrProtocol
	}
	var m map[string]json.RawMessage
	if json.Unmarshal(b, &m) != nil || len(m) != len(keys) {
		return ErrProtocol
	}
	for _, k := range keys {
		if m[k] == nil {
			return ErrProtocol
		}
	}
	return nil
}
func ReadFrame(r io.Reader) ([]byte, error) {
	var h [4]byte
	if _, e := io.ReadFull(r, h[:]); e != nil {
		return nil, e
	}
	n := binary.BigEndian.Uint32(h[:])
	if n == 0 || n > MaxFrame {
		return nil, ErrProtocol
	}
	b := make([]byte, n)
	_, e := io.ReadFull(r, b)
	return b, e
}
func WriteFrame(w io.Writer, v any) error {
	b, e := json.Marshal(v)
	if e != nil || len(b) > MaxFrame {
		return ErrProtocol
	}
	var h [4]byte
	binary.BigEndian.PutUint32(h[:], uint32(len(b)))
	if _, e = w.Write(h[:]); e != nil {
		return e
	}
	_, e = w.Write(b)
	return e
}
func decodeMessage(b []byte) (Message, error) {
	var m Message
	if json.Unmarshal(b, &m) != nil {
		return m, ErrProtocol
	}
	var keys []string
	switch m.Operation {
	case "configure":
		keys = []string{"version", "operation", "collector_uuid", "site_id", "server_public_key"}
	case "health", "health_hikvision_live", "health_hikvision_recorder_discovery":
		keys = []string{"version", "operation", "request_uuid"}
	case "open":
		keys = []string{"version", "operation", "request_uuid", "grant", "source", "turn", "offer"}
	case hikvisionDiscoveryOperation, hikvisionRecorderDiscoveryOperation:
		keys = []string{"version", "operation", "request_uuid", "grant", "source"}
	case "renew":
		keys = []string{"version", "operation", "request_uuid", "grant"}
	case "close":
		keys = []string{"version", "operation", "request_uuid", "session_uuid", "generation"}
	default:
		return m, ErrProtocol
	}
	if Closed(b, keys...) != nil || (m.Operation == hikvisionRecorderDiscoveryOperation && m.Version != 2) || (m.Operation != hikvisionRecorderDiscoveryOperation && m.Version != 1) {
		return m, ErrProtocol
	}
	if m.Operation != "configure" && !uuid.MatchString(m.Request) {
		return m, ErrProtocol
	}
	if m.Operation == "open" || m.Operation == "renew" || m.Operation == hikvisionDiscoveryOperation || m.Operation == hikvisionRecorderDiscoveryOperation {
		var fields map[string]json.RawMessage
		_ = json.Unmarshal(b, &fields)
		raw := fields["grant"]
		if Closed(raw, "payload", "signature") != nil {
			return m, ErrProtocol
		}
	}
	if m.Operation == "open" {
		var fields map[string]json.RawMessage
		_ = json.Unmarshal(b, &fields)
		if Closed(fields["offer"], "type", "sdp") != nil {
			return m, ErrProtocol
		}
	}
	return m, nil
}
func verify(e Envelope, key ed25519.PublicKey, collector string, site int64, now time.Time) (Grant, error) {
	var g Grant
	b, err := base64.StdEncoding.Strict().DecodeString(e.Payload)
	s, se := base64.StdEncoding.Strict().DecodeString(e.Signature)
	if err != nil || se != nil || len(b) > 16384 || !ed25519.Verify(key, b, s) {
		return g, ErrProtocol
	}
	var header struct {
		Type string `json:"type"`
	}
	if json.Unmarshal(b, &header) != nil {
		return g, ErrProtocol
	}
	if header.Type == hikvisionLiveType {
		if !canonicalHikvisionEnvelope(e) {
			return g, ErrProtocol
		}
		return verifyHikvisionLivePayload(b, collector, site, now)
	}
	keys := []string{"version", "type", "collector_uuid", "site_id", "camera_id", "recorder_device_id", "provider", "console_id", "provider_camera_id", "session_uuid", "negotiation_uuid", "generation", "source_revision", "source_sha256", "turn_sha256", "offer_sha256", "quality", "video_codec", "audio", "discovery_scope_id", "allowed_ip", "allowed_port", "tls_server_name", "tls_pin", "issued_at", "expires_at", "session_expires_at"}
	if Closed(b, keys...) != nil || json.Unmarshal(b, &g) != nil {
		return g, ErrProtocol
	}
	var fields map[string]json.RawMessage
	_ = json.Unmarshal(b, &fields)
	for _, v := range fields {
		if bytes.Equal(bytes.TrimSpace(v), []byte("null")) {
			return g, ErrProtocol
		}
	}
	if g.Version != 1 || g.Type != "collector_cctv_live" || g.Collector != collector || g.Site != site || g.Camera <= 0 || g.Recorder <= 0 || g.Scope <= 0 || g.Revision <= 0 || g.Provider != "unifi" || g.Console == "" || len(g.Console) > 200 || g.ProviderCamera == "" || len(g.ProviderCamera) > 200 || !uuid.MatchString(g.Session) || !uuid.MatchString(g.Negotiation) || g.Generation == 0 || g.Generation > 9007199254740991 || g.Quality != "low" || g.Codec != "H264" || g.Audio || !digest.MatchString(g.SourceHash) || !digest.MatchString(g.TurnHash) || !digest.MatchString(g.OfferHash) || !digest.MatchString(g.Pin) || net.ParseIP(g.IP) == nil || g.Port < 1 || g.Port > 65535 || g.ServerName == "" || len(g.ServerName) > 253 {
		return g, ErrProtocol
	}
	i, ie := instant(g.Issued)
	x, xe := instant(g.Expires)
	a, ae := instant(g.SessionExpires)
	if ie != nil || xe != nil || ae != nil || i.After(now) || !x.After(now) || x.After(i.Add(15*time.Second)) || x.After(a) || a.After(i.Add(time.Hour)) {
		return g, ErrProtocol
	}
	return g, nil
}
func sourceURL(s Source, g Grant) (*url.URL, error) {
	if g.Type == hikvisionLiveType {
		return hikvisionSourceURL(s, g)
	}
	if s.Hikvision != nil {
		return nil, ErrProtocol
	}
	u, e := url.Parse(s.URL)
	if e != nil || len(s.URL) > 4096 || u.Scheme != "rtsps" || u.Hostname() == "" || u.Fragment != "" || u.User != nil {
		return nil, ErrProtocol
	}
	p := u.Port()
	if p == "" {
		p = "322"
	}
	if p != strings.TrimSpace(p) || u.Hostname() != g.ServerName && u.Hostname() != g.IP || net.JoinHostPort(g.IP, p) != net.JoinHostPort(g.IP, strconv.Itoa(g.Port)) {
		return nil, ErrProtocol
	}
	if s.Username != nil || s.Password != nil {
		return nil, ErrProtocol
	}
	return u, nil
}
