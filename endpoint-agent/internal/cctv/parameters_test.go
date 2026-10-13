package cctv

import (
	"github.com/pion/webrtc/v4/pkg/media"
	"testing"
	"time"
)

type sampleFixture struct{ samples int }

func (s *sampleFixture) WriteSample(media.Sample) error { s.samples++; return nil }
func TestChangedH264ParametersCloseBeforeForwarding(t *testing.T) {
	for _, changed := range [][]byte{{0x67, 0x64, 0, 0x28}, {0x68, 2, 3}} {
		stream := &stream{cancel: func() {}}
		writer := &sampleFixture{}
		sps, pps := []byte{0x67, 0x42, 0xc0, 0x0a}, []byte{0x68, 1, 3}
		if !stream.forwardSample(writer, [][]byte{sps, pps, {0x65, 1}}, sps, pps, true, time.Second/10) || writer.samples != 1 {
			t.Fatal("initial immutable media refused")
		}
		if stream.forwardSample(writer, [][]byte{changed, {0x65, 2}}, sps, pps, true, time.Second/10) || writer.samples != 1 || !stream.closed {
			t.Fatal("changed profile/resolution parameters reached browser")
		}
	}
}
