package rtc

import (
	"bytes"
	"encoding/json"
	"io"
	"regexp"
	"strings"

	"github.com/pion/ice/v4"
	"github.com/pion/sdp/v3"
)

var validID = regexp.MustCompile("^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$").MatchString

type Input struct {
	Version  int    `json:"version"`
	Sequence uint64 `json:"sequence"`
	Type     string `json:"type"`
	X        uint16 `json:"x,omitempty"`
	Y        uint16 `json:"y,omitempty"`
	Button   uint8  `json:"button,omitempty"`
	Key      uint16 `json:"key,omitempty"`
	Delta    int16  `json:"delta,omitempty"`
}

func DecodeInput(data []byte) (Input, error) {
	if len(data) == 0 || len(data) > 512 {
		return Input{}, ErrUnavailable
	}
	d := json.NewDecoder(bytes.NewReader(data))
	start, e := d.Token()
	if e != nil || start != json.Delim('{') {
		return Input{}, ErrUnavailable
	}
	fields := map[string]bool{}
	for d.More() {
		token, e := d.Token()
		key, ok := token.(string)
		if e != nil || !ok || fields[key] {
			return Input{}, ErrUnavailable
		}
		fields[key] = true
		var value json.RawMessage
		if d.Decode(&value) != nil || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return Input{}, ErrUnavailable
		}
	}
	if end, e := d.Token(); e != nil || end != json.Delim('}') {
		return Input{}, ErrUnavailable
	}
	if _, e = d.Token(); e != io.EOF {
		return Input{}, ErrUnavailable
	}
	var input Input
	if json.Unmarshal(data, &input) != nil || input.Version != 1 || input.Sequence == 0 || input.Sequence > 9007199254740991 {
		return Input{}, ErrUnavailable
	}
	required := []string{"version", "sequence", "type"}
	switch input.Type {
	case "move":
		required = append(required, "x", "y")
	case "button_down", "button_up":
		required = append(required, "button", "x", "y")
		if input.Button > 2 {
			return Input{}, ErrUnavailable
		}
	case "key_down", "key_up":
		required = append(required, "key")
		if input.Key == 0 || input.Key > 254 {
			return Input{}, ErrUnavailable
		}
	case "wheel":
		required = append(required, "delta", "x", "y")
		if input.Delta == 0 || input.Delta > 1200 || input.Delta < -1200 {
			return Input{}, ErrUnavailable
		}
	default:
		return Input{}, ErrUnavailable
	}
	if len(required) != len(fields) {
		return Input{}, ErrUnavailable
	}
	for _, key := range required {
		if !fields[key] {
			return Input{}, ErrUnavailable
		}
	}
	return input, nil
}
func validateDescription(value string, relay bool) error {
	if len(value) < 100 || len(value) > 32768 || !strings.HasPrefix(value, "v=0\r\n") || !strings.HasSuffix(value, "\r\n") {
		return ErrUnavailable
	}
	var description sdp.SessionDescription
	if description.Unmarshal([]byte(value)) != nil || len(description.MediaDescriptions) != 1 {
		return ErrUnavailable
	}
	media := description.MediaDescriptions[0]
	if media.MediaName.Media != "application" || strings.Join(media.MediaName.Protos, "/") != "UDP/DTLS/SCTP" || strings.Join(media.MediaName.Formats, " ") != "webrtc-datachannel" {
		return ErrUnavailable
	}
	fingerprints, candidates := 0, 0
	attributes := append(append([]sdp.Attribute(nil), description.Attributes...), media.Attributes...)
	for _, attribute := range attributes {
		if attribute.Key == "fingerprint" {
			fingerprints++
			if !regexp.MustCompile("^(?i:sha-256) (?:[a-fA-F0-9]{2}:){31}[a-fA-F0-9]{2}$").MatchString(attribute.Value) {
				return ErrUnavailable
			}
		}
		if attribute.Key == "candidate" {
			candidate, err := ice.UnmarshalCandidate(attribute.Value)
			if err != nil || (relay && candidate.Type() != ice.CandidateTypeRelay) {
				return ErrUnavailable
			}
			candidates++
		}
	}
	if fingerprints != 1 || candidates == 0 {
		return ErrUnavailable
	}
	return nil
}
