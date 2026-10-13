package cctv

import (
	"encoding/json"
	"testing"
)

func TestHikvisionProfileHealthRequiresConfiguredRuntimeAndClosedProbe(t *testing.T) {
	w, _, _, _, _ := fixture(t)
	defer w.Close()
	body := map[string]any{"version": 1, "operation": "health_hikvision_live", "request_uuid": "44444444-4444-4444-8444-444444444444"}
	probe, _ := json.Marshal(body)
	if r := w.Handle(t.Context(), probe); r.Status != "requested" || r.Request != body["request_uuid"] {
		t.Fatal("configured profile probe rejected", r)
	}
	w.configured = false
	if r := w.Handle(t.Context(), probe); r.Status != "failed" {
		t.Fatal("unconfigured runtime advertised support", r)
	}
	w.configured = true
	for _, field := range []string{"source", "grant", "site_id", "collector_uuid"} {
		body[field] = nil
		bytes, _ := json.Marshal(body)
		if r := w.Handle(t.Context(), bytes); r.Status != "failed" {
			t.Fatal("extra probe field accepted", field, r)
		}
		delete(body, field)
	}
	body["request_uuid"] = nil
	bad, _ := json.Marshal(body)
	if r := w.Handle(t.Context(), bad); r.Status != "failed" {
		t.Fatal("null request accepted", r)
	}
}
