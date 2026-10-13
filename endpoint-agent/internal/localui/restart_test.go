package localui

import (
	"bytes"
	"strings"
	"testing"
)

func TestRestartPipeContract(t *testing.T) {
	hash := strings.Repeat("a", 64)
	base := `{"version":1,"operation":"restart_poll","expected_origin_hash":"` + hash + `"}`
	if _, e := readRequest(bytes.NewReader(frame(base))); e != nil {
		t.Fatal(e)
	}
	for _, pair := range [][2]string{{"restart_visible", "visible"}, {"restart_visible", "hidden"}, {"restart_decide", "allow"}, {"restart_decide", "decline"}, {"restart_decide", "cancel"}} {
		raw := `{"version":1,"operation":"` + pair[0] + `","expected_origin_hash":"` + hash + `","attempt_uuid":"cccccccc-cccc-4ccc-8ccc-cccccccccccc","payload_hash":"` + hash + `","consent_token":"` + hash + `","decision":"` + pair[1] + `"}`
		if _, e := readRequest(bytes.NewReader(frame(raw))); e != nil {
			t.Fatal(e)
		}
		for _, bad := range []string{strings.Replace(raw, `"decision":"`+pair[1]+`"`, `"decision":"reboot_now"`, 1), strings.Replace(raw, `"version":1`, `"version":null`, 1), strings.Replace(raw, `"version":1`, `"version":1,"version":1`, 1), strings.Replace(raw, `"version":1`, `"version":1,"staff_token":"secret"`, 1)} {
			if _, e := readRequest(bytes.NewReader(frame(bad))); e == nil {
				t.Fatal("unsafe restart IPC accepted")
			}
		}
	}
}
