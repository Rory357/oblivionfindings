package cctv

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"net"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/bluenviron/gortsplib/v5/pkg/base"
)

const hikvisionLiveType = "collector_hikvision_live"
const hikvisionLiveCapability = "cctv:hikvision:live:read_only"
const hikvisionLiveProfile = "hikvision_rtsp_https_digest_v1"

// Comparable normalized fields protect unchanged identity on replay/renew.
// This is not serialized into the original UniFi grant schema.
type hikvisionLiveBinding struct {
	SourceID                                                     int64
	Fingerprint, Context, Credential, Support, Result, Transport string
	Identity                                                     hikvisionIdentity
	ChannelID, InputID, Name, Codec, AudioObservation            string
	Width, Height, FPS100                                        int
}

func noNull(raw []byte, permitted ...string) bool {
	var fields map[string]json.RawMessage
	if json.Unmarshal(raw, &fields) != nil {
		return false
	}
	for key, value := range fields {
		if !bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			continue
		}
		allowed := false
		for _, candidate := range permitted {
			if candidate == key {
				allowed = true
			}
		}
		if !allowed {
			return false
		}
	}
	return true
}

func verifyHikvisionLivePayload(raw []byte, collector string, site int64, now time.Time) (Grant, error) {
	var g Grant
	keys := []string{"version", "type", "capability", "collector_uuid", "site_id", "camera_id", "recorder_device_id", "provider", "source_kind", "source_id", "source_revision", "source_fingerprint", "binding_sha256", "credential_binding_sha256", "support_sha256", "discovery_result_sha256", "identity", "channel", "transport_profile", "session_uuid", "negotiation_uuid", "generation", "source_sha256", "turn_sha256", "offer_sha256", "quality", "video_codec", "audio", "discovery_scope_id", "allowed_ip", "allowed_port", "tls_server_name", "tls_pin", "issued_at", "expires_at", "session_expires_at"}
	if Closed(raw, keys...) != nil || !noNull(raw, "recorder_device_id") || json.Unmarshal(raw, &g) != nil {
		return g, ErrProtocol
	}
	var fields map[string]json.RawMessage
	_ = json.Unmarshal(raw, &fields)
	var extra struct {
		Capability  string            `json:"capability"`
		Kind        string            `json:"source_kind"`
		SourceID    int64             `json:"source_id"`
		Fingerprint string            `json:"source_fingerprint"`
		Context     string            `json:"binding_sha256"`
		Credential  string            `json:"credential_binding_sha256"`
		Support     string            `json:"support_sha256"`
		Result      string            `json:"discovery_result_sha256"`
		Transport   string            `json:"transport_profile"`
		Identity    hikvisionIdentity `json:"identity"`
		Channel     hikvisionChannel  `json:"channel"`
	}
	if json.Unmarshal(raw, &extra) != nil || string(bytes.TrimSpace(fields["recorder_device_id"])) != "null" ||
		Closed(fields["identity"], "model", "serial", "device_id", "firmware", "firmware_build") != nil || !noNull(fields["identity"]) ||
		Closed(fields["channel"], "id", "input_id", "name", "codec", "enabled", "video_enabled", "width", "height", "fps100", "audio_enabled") != nil || !noNull(fields["channel"], "audio_enabled") {
		return g, ErrProtocol
	}
	id, c := extra.Identity, extra.Channel
	if !observationText(id.Model, true, 128) || !observationText(id.Serial, true, 128) || !observationText(id.DeviceID, true, 128) || !observationText(id.Firmware, true, 128) || !observationText(id.FirmwareBuild, false, 128) ||
		!hikvisionIdentifier.MatchString(c.ID) || !hikvisionIdentifier.MatchString(c.InputID) || !observationText(c.Name, false, 128) || (c.Codec != "H264" && c.Codec != "H.264") || !c.Enabled || !c.VideoEnabled ||
		c.Width < 1 || c.Width > 16384 || c.Height < 1 || c.Height > 16384 || c.FPS100 < 1 || c.FPS100 > 12000 {
		return g, ErrProtocol
	}
	for _, n := range []int64{g.Site, g.Camera, g.Scope, g.Revision, extra.SourceID} {
		if n < 1 || n > 9007199254740991 {
			return g, ErrProtocol
		}
	}
	for _, h := range []string{g.SourceHash, g.TurnHash, g.OfferHash, g.Pin, extra.Fingerprint, extra.Context, extra.Credential, extra.Support, extra.Result} {
		if !digest.MatchString(h) {
			return g, ErrProtocol
		}
	}
	endpoint := httpsTunnelSource{IP: g.IP, Port: g.Port, ServerName: g.ServerName, Pin: g.Pin, Username: "validation", Password: "validation"}
	issued, ie := instant(g.Issued)
	expiry, xe := instant(g.Expires)
	absolute, ae := instant(g.SessionExpires)
	if g.Version != 1 || g.Type != hikvisionLiveType || extra.Capability != hikvisionLiveCapability || g.Provider != "hikvision" || extra.Kind != "direct_camera" || extra.Transport != hikvisionLiveProfile ||
		g.Collector != collector || !uuid.MatchString(g.Collector) || g.Site != site || !uuid.MatchString(g.Session) || !uuid.MatchString(g.Negotiation) || g.Generation < 1 || g.Generation > 9007199254740991 ||
		g.Quality != "selected_channel" || g.Codec != "H264" || g.Audio || !endpoint.validEndpoint() || ie != nil || xe != nil || ae != nil || issued.After(now) || !expiry.After(now) || expiry.After(issued.Add(15*time.Second)) || expiry.After(absolute) || absolute.After(issued.Add(time.Hour)) {
		return g, ErrProtocol
	}
	audio := "unknown"
	if c.AudioEnabled != nil {
		if *c.AudioEnabled {
			audio = "enabled"
		} else {
			audio = "disabled"
		}
	}
	g.Hikvision = hikvisionLiveBinding{SourceID: extra.SourceID, Fingerprint: extra.Fingerprint, Context: extra.Context, Credential: extra.Credential, Support: extra.Support, Result: extra.Result, Transport: extra.Transport, Identity: id, ChannelID: c.ID, InputID: c.InputID, Name: c.Name, Codec: c.Codec, AudioObservation: audio, Width: c.Width, Height: c.Height, FPS100: c.FPS100}
	return g, nil
}

func decodeMediaSource(raw []byte, g Grant) (Source, error) {
	var s Source
	if g.Type != hikvisionLiveType {
		if Closed(raw, "url", "username", "password") != nil || json.Unmarshal(raw, &s) != nil {
			return s, ErrProtocol
		}
		return s, nil
	}
	if Closed(raw, "allowed_ip", "allowed_port", "tls_server_name", "tls_pin", "username", "password") != nil || !noNull(raw) {
		return s, ErrProtocol
	}
	var private hikvisionDiscoverySource
	if json.Unmarshal(raw, &private) != nil || !private.endpoint().validEndpoint() || private.IP != g.IP || private.Port != g.Port || private.ServerName != g.ServerName || private.Pin != g.Pin {
		return s, ErrProtocol
	}
	s.Hikvision = &private
	return s, nil
}

func hikvisionSourceURL(s Source, g Grant) (*url.URL, error) {
	if s.Hikvision == nil || s.URL != "" || s.Username != nil || s.Password != nil || g.Hikvision.Transport != hikvisionLiveProfile || !hikvisionIdentifier.MatchString(g.Hikvision.ChannelID) {
		return nil, ErrProtocol
	}
	private := s.Hikvision
	if !private.endpoint().validEndpoint() || private.IP != g.IP || private.Port != g.Port || private.ServerName != g.ServerName || private.Pin != g.Pin {
		return nil, ErrProtocol
	}
	// The control URI is RTSP; every byte is carried by the explicitly selected,
	// authenticated HTTPS tunnel. The library never receives a default dialer.
	return &url.URL{Scheme: "rtsp", Host: net.JoinHostPort(g.ServerName, strconv.Itoa(g.Port)), Path: "/Streaming/channels/" + g.Hikvision.ChannelID}, nil
}

func hikvisionInventoryMatches(inventory hikvisionInventory, expected hikvisionLiveBinding) bool {
	if inventory.Identity != expected.Identity {
		return false
	}
	found := 0
	for _, c := range inventory.Channels {
		if c.ID != expected.ChannelID {
			continue
		}
		audio := "unknown"
		if c.AudioEnabled != nil {
			if *c.AudioEnabled {
				audio = "enabled"
			} else {
				audio = "disabled"
			}
		}
		if !c.Enabled || !c.VideoEnabled || c.InputID != expected.InputID || c.Name != expected.Name || c.Codec != expected.Codec || c.Width != expected.Width || c.Height != expected.Height || c.FPS100 != expected.FPS100 || audio != expected.AudioObservation {
			return false
		}
		found++
	}
	return found == 1
}

func hikvisionControlTarget(target, selected *base.URL, track bool) bool {
	if target == nil || selected == nil || target.Scheme != selected.Scheme || target.Host != selected.Host || target.Fragment != "" || target.RawQuery != "" || target.RawPath != "" {
		return false
	}
	if target.User != nil && (selected.User == nil || target.User.String() != selected.User.String()) {
		return false
	}
	root := selected.Path
	if !track {
		return target.Path == root || target.Path == root+"/"
	}
	if !strings.HasPrefix(target.Path, root+"/") {
		return false
	}
	suffix := strings.TrimPrefix(target.Path, root+"/")
	if !strings.HasPrefix(suffix, "trackID=") {
		return false
	}
	n := strings.TrimPrefix(suffix, "trackID=")
	if len(n) < 1 || len(n) > 3 {
		return false
	}
	for _, c := range n {
		if c < '0' || c > '9' {
			return false
		}
	}
	return true
}

func canonicalHikvisionEnvelope(e Envelope) bool {
	raw, err := base64.StdEncoding.Strict().DecodeString(e.Payload)
	signature, se := base64.StdEncoding.Strict().DecodeString(e.Signature)
	return err == nil && se == nil && base64.StdEncoding.EncodeToString(raw) == e.Payload && base64.StdEncoding.EncodeToString(signature) == e.Signature
}

func hikvisionRawControl(control string, selected *base.URL) bool {
	// The dependency replaces host/user on absolute media controls. Inspect the
	// originally received control before calling Media.URL; never bless a
	// foreign authority through that normalization.
	if strings.HasPrefix(control, "rtsp://") || strings.HasPrefix(control, "rtsps://") {
		raw, err := base.ParseURL(control)
		return err == nil && hikvisionControlTarget(raw, selected, true)
	}
	if !strings.HasPrefix(control, "trackID=") || selected == nil {
		return false
	}
	target := *selected
	target.Path = selected.Path + "/" + control
	return hikvisionControlTarget(&target, selected, true)
}

// Select one bounded, unambiguous RTSP Digest challenge before the dependency
// can choose Basic. Legacy RTSP Digest without qop is explicit in this profile;
// HTTP tunnel authentication separately requires qop=auth on both connections.
func selectHikvisionRTSPDigest(values base.HeaderValue) (base.HeaderValue, error) {
	if len(values) < 1 || len(values) > 4 {
		return nil, ErrProtocol
	}
	var selected string
	strongest := false
	for _, value := range values {
		if len(value) > 8192 {
			return nil, ErrProtocol
		}
		method, parameters, found := strings.Cut(value, " ")
		if !found || method != "Digest" {
			continue
		}
		fields, e := digestParameters(parameters)
		if e != nil {
			return nil, ErrProtocol
		}
		algorithm := strings.ToUpper(fields["algorithm"])
		if algorithm == "" {
			algorithm = "MD5"
		}
		if algorithm != "MD5" && algorithm != "SHA-256" {
			continue
		}
		if fields["realm"] == "" || fields["nonce"] == "" {
			continue
		}
		if qop := fields["qop"]; qop != "" {
			auth := false
			for _, v := range strings.Split(qop, ",") {
				if strings.TrimSpace(v) == "auth" {
					auth = true
				}
			}
			if !auth {
				continue
			}
		}
		if fields["charset"] != "" && !strings.EqualFold(fields["charset"], "UTF-8") || fields["userhash"] != "" && !strings.EqualFold(fields["userhash"], "false") {
			continue
		}
		if selected == "" || algorithm == "SHA-256" && !strongest {
			selected = value
			strongest = algorithm == "SHA-256"
		}
	}
	if selected == "" {
		return nil, ErrProtocol
	}
	return base.HeaderValue{selected}, nil
}
