package cctv

import (
	"github.com/pion/ice/v4"
	"github.com/pion/sdp/v3"
	"os"
	"strings"
	"testing"
)

func TestObservedChromeOffer(t *testing.T) {
	path := os.Getenv("OBLI_CCTV_CHROME_OFFER")
	if path == "" {
		t.Skip("explicit actual browser offer witness")
	}
	raw, e := os.ReadFile(path)
	if e != nil {
		t.Fatal(e)
	}
	policy := Turn{RelayCIDRs: []string{"127.0.0.0/24"}, RelayPortMin: 32768, RelayPortMax: 65535}
	if validateOffer(string(raw), policy) == nil {
		selected, ok := offerProfile(string(raw), "42c00a")
		if !ok || selected != "42e00a" {
			t.Fatal("actual source constrained-baseline profile not negotiated")
		}
		return
	}
	var d sdp.SessionDescription
	if e = d.Unmarshal(raw); e != nil {
		t.Fatal("SDP parse", e)
	}
	t.Log("media count", len(d.MediaDescriptions), "fallback accepted", validateFallbacks(d, policy) == nil)
	for _, m := range d.MediaDescriptions {
		t.Log("media type/protocol/port/formats", m.MediaName.Media, strings.Join(m.MediaName.Protos, "/"), m.MediaName.Port.Value, len(m.MediaName.Formats))
		for _, a := range m.Attributes {
			switch a.Key {
			case "rtpmap":
				p := strings.Fields(a.Value)
				if len(p) == 2 {
					t.Log("codec", p[1])
				}
			case "candidate":
				c, e := ice.UnmarshalCandidate(a.Value)
				if e != nil {
					t.Log("candidate parse rejected")
				} else {
					t.Log("candidate type/protocol/component/allowed", c.Type(), c.NetworkType(), c.Component(), allowedCandidate(c, policy))
				}
			case "fingerprint":
				t.Log("fingerprint length", len(a.Value))
			case "recvonly", "sendrecv", "rtcp-mux":
				t.Log("direction/mux", a.Key)
			}
		}
	}
	t.Fatal("actual browser offer rejected")
}
