package cctv

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"testing"
	"time"
)

func TestCCTVPHPFixture(t *testing.T) {
	path := os.Getenv("OBLI_CCTV_EXPORT")
	if path == "" {
		t.Skip("actual PHP producer export required")
	}
	raw, e := os.ReadFile(path)
	if e != nil {
		t.Fatal(e)
	}
	var f struct {
		Operation struct {
			Operation string   `json:"operation"`
			Request   string   `json:"request_uuid"`
			Grant     Envelope `json:"grant"`
		} `json:"operation"`
		Key       string `json:"server_public_key"`
		Collector string `json:"collector_uuid"`
		Source    string `json:"source_json"`
		Turn      string `json:"turn_json"`
		Offer     string `json:"offer_sdp"`
		Result    struct {
			Results []Reply `json:"results"`
		} `json:"result_input"`
	}
	if json.Unmarshal(raw, &f) != nil || f.Operation.Operation != "open" || len(f.Result.Results) != 1 {
		t.Fatal("actual producer shape")
	}
	grantRaw, e := base64.StdEncoding.Strict().DecodeString(f.Operation.Grant.Payload)
	if e != nil {
		t.Fatal("actual grant encoding")
	}
	var g Grant
	if json.Unmarshal(grantRaw, &g) != nil {
		t.Fatal("actual grant")
	}
	captured, clockErr := instant(g.Issued)
	if clockErr != nil {
		t.Fatal(clockErr)
	}
	var bound bool
	expected := f.Result.Results[0]
	worker := New(func(_ context.Context, actual Grant, s Source, turn Turn, offer Description) (Media, Description, string) {
		if actual != g || offer.SDP != f.Offer || expected.Answer == nil {
			t.Fatal("actual immutable binding changed")
		}
		if validateOffer(offer.SDP, turn) != nil {
			t.Fatal("actual PHP offer rejected by real media parser")
		}
		bound = true
		return &fakeMedia{}, *expected.Answer, ""
	})
	defer worker.Close()
	worker.now = func() time.Time { return captured }
	config, _ := json.Marshal(map[string]any{"version": 1, "operation": "configure", "collector_uuid": f.Collector, "site_id": g.Site, "server_public_key": f.Key})
	if worker.Handle(t.Context(), config).Status != "requested" {
		t.Fatal("actual key/config rejected")
	}
	envelope, _ := json.Marshal(f.Operation.Grant)
	request, _ := json.Marshal(f.Operation.Request)
	offer, _ := json.Marshal(Description{"offer", f.Offer})
	// Original sealed material remains byte-exact; do not re-encode RawMessages.
	input := []byte(fmt.Sprintf(`{"version":1,"operation":"open","request_uuid":%s,"grant":%s,"source":%s,"turn":%s,"offer":%s}`, request, envelope, f.Source, f.Turn, offer))
	reply := worker.Handle(t.Context(), input)
	first, _ := json.Marshal(reply)
	want, _ := json.Marshal(expected)
	if !bound || string(first) != string(want) {
		t.Fatal("actual PHP→Go signed result contract mismatch", reply.Status)
	}
	originalDeadline := worker.sessions[g.Session].deadline
	if repeated := worker.Handle(t.Context(), input); repeated.Status != "ready" || worker.sessions[g.Session].deadline != originalDeadline {
		t.Fatal("actual signed replay extended/reopened")
	}
	if output := os.Getenv("OBLI_CCTV_LOCAL_VIEW"); output != "" {
		if os.WriteFile(output, first, 0600) != nil {
			t.Fatal("interop output")
		}
	}
	t.Log("actual PHP signature/scope/exact material hashes/offer/result/replay verified; network factory injected")
}
