package cctv

import (
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"testing"
	"time"
)

// Opt-in synthetic bridge for the real application test. One organisation,
// canonical approved Site/device/scope and the actual signed producer remain
// the authority. Only the local test certificate root and camera are fixtures.
func TestHikvisionRecorderCanonicalApplicationInteropFixture(t *testing.T) {
	root := os.Getenv("OBLI_RECORDER_CANONICAL_FIXTURE_ROOT")
	if root == "" {
		t.Skip("explicit original canonical application fixture required")
	}
	if !filepath.IsAbs(root) {
		t.Fatal("absolute owned fixture root required")
	}
	info, err := os.Lstat(root)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		t.Fatal("owned fixture root unavailable")
	}
	endpoint, roots, fixture := newHikvisionRecorderISAPIFixture(t, "normal")
	ctx, cancel := context.WithTimeout(t.Context(), 15*time.Second)
	defer cancel()
	write := func(name string, raw []byte) {
		t.Helper()
		tmp := filepath.Join(root, name+".tmp")
		if os.WriteFile(tmp, raw, 0600) != nil || os.Rename(tmp, filepath.Join(root, name)) != nil {
			t.Fatal("fixture output unavailable")
		}
	}
	wait := func(name string) []byte {
		t.Helper()
		ticker := time.NewTicker(10 * time.Millisecond)
		defer ticker.Stop()
		for {
			raw, e := os.ReadFile(filepath.Join(root, name))
			if e == nil {
				if len(raw) > MaxFrame+4 {
					t.Fatal("fixture frame too large")
				}
				return raw
			}
			if !os.IsNotExist(e) {
				t.Fatal("fixture input unavailable")
			}
			select {
			case <-ctx.Done():
				t.Fatal("fixture input deadline")
			case <-ticker.C:
			}
		}
	}
	frame := func(name string) ([]byte, []byte) {
		t.Helper()
		full := wait(name)
		reader := bytes.NewReader(full)
		raw, e := ReadFrame(reader)
		if e != nil || reader.Len() != 0 {
			t.Fatal("original framed input unavailable")
		}
		return raw, full
	}
	metadata, _ := json.Marshal(map[string]any{"version": 1, "synthetic": true, "hardware_acceptance": false, "process_id": os.Getpid(), "parent_process_id": os.Getppid(), "allowed_ip": endpoint.IP, "allowed_port": endpoint.Port, "tls_server_name": endpoint.ServerName, "tls_pin": endpoint.Pin, "response_sha256": map[string]string{"device_info": hash([]byte(hikvisionRecorderIdentityFixture)), "streaming_proxy": hash([]byte(hikvisionChannelsFixture(hikvisionRecorderChannelFixture))), "input_status": hash([]byte(hikvisionRecorderInputsFixture(hikvisionRecorderInputFixture)))}})
	write("metadata.json", metadata)
	configure, _ := frame("configure.bin")
	worker := New(nil)
	defer worker.Close()
	worker.discovery.runRecorder = func(run context.Context, source httpsTunnelSource) (hikvisionRecorderInventory, error) {
		return discoverHikvisionRecorder(run, source, roots)
	}
	if worker.Handle(ctx, configure).Status != "requested" {
		t.Fatal("original application configuration denied")
	}
	request, requestFrame := frame("request.bin")
	original := worker.DiscoverHikvisionRecorder(ctx, request)
	if original.Status != "observed" || original.Inventory == nil || original.Inventory.Identity.Serial != "fixture-recorder-serial" {
		t.Fatal("original canonical recorder request denied")
	}
	result, _ := json.Marshal(original)
	if validatePrivateWorkerReply(result) != nil {
		t.Fatal("native private result invalid")
	}
	var first bytes.Buffer
	if WriteFrame(&first, original) != nil {
		t.Fatal("original result framing")
	}
	write("result.bin", first.Bytes())
	repeatedRequest, repeatedFrame := frame("request-replay.bin")
	if !bytes.Equal(requestFrame, repeatedFrame) {
		t.Fatal("application retry changed original private request bytes")
	}
	repeated := worker.DiscoverHikvisionRecorder(ctx, repeatedRequest)
	if !reflect.DeepEqual(original, repeated) {
		t.Fatal("replay refreshed/replaced observation")
	}
	var second bytes.Buffer
	if WriteFrame(&second, repeated) != nil {
		t.Fatal("replay result framing")
	}
	write("result-replay.bin", second.Bytes())
	fixture.mu.Lock()
	requests := map[string]int{}
	authorized := map[string]int{}
	for k, v := range fixture.requests {
		requests[k] = v
	}
	for k, v := range fixture.authorized {
		authorized[k] = v
	}
	fixture.mu.Unlock()
	if len(requests) != 3 || len(authorized) != 3 {
		t.Fatal("outbound resource scope changed")
	}
	for _, path := range []string{"/ISAPI/System/deviceInfo", "/ISAPI/ContentMgmt/StreamingProxy/channels", "/ISAPI/ContentMgmt/InputProxy/channels/status"} {
		if requests[path] != 2 || authorized[path] != 1 {
			t.Fatal("replay made new outbound request or Digest failed")
		}
	}
	proof, _ := json.Marshal(map[string]any{"version": 1, "synthetic": true, "hardware_acceptance": false, "configure_sha256": hash(configure), "request_sha256": hash(request), "request_frame_sha256": hash(requestFrame), "reply_sha256": hash(result), "reply_frame_sha256": hash(first.Bytes()), "replay_frame_sha256": hash(second.Bytes()), "resources": requests, "authorized_resources": authorized, "original_observed_at": original.Observed, "camera_identity": nil, "association": "unverified"})
	write("proof.json", proof)
}
