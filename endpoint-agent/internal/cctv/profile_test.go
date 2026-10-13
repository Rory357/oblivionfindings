package cctv

import (
	"fmt"
	"testing"
)

func TestH264SubprofileNegotiation(t *testing.T) {
	for _, c := range []struct{ source, receiver, mode, want string }{
		{"42c00a", "42e01f", "1", "42e00a"},
		{"4d800a", "42e01f", "1", "42e00a"},
		{"42000a", "42e01f", "1", ""},
		{"4d000a", "42e01f", "1", ""},
		{"640028", "64001f", "1", ""},
		{"42c00a", "42e01f", "0", ""},
		{"42c00a", "zzzzzz", "1", ""},
	} {
		offer := fmt.Sprintf("v=0\r\no=- 1 1 IN IP4 0.0.0.0\r\ns=-\r\nt=0 0\r\nm=video 9 UDP/TLS/RTP/SAVPF 102\r\na=fmtp:102 level-asymmetry-allowed=1;packetization-mode=%s;profile-level-id=%s\r\n", c.mode, c.receiver)
		selected, ok := offerProfile(offer, c.source)
		if selected != c.want || ok != (c.want != "") {
			t.Fatalf("profile %s/%s negotiated incorrectly", c.source, c.receiver)
		}
	}
}
