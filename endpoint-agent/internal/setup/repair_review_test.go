package setup

import (
	"context"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"
)

func TestRepairReviewBindsReceiptAuthoritiesAndExpiryWithoutPublishingSecrets(t *testing.T) {
	j, m := repairFixture("both")
	now := time.Now().UTC()
	m.ExpiresAt = now.Add(time.Hour).Format(time.RFC3339)
	create := func(j Journal, computer, collector, expiry string) RepairReview {
		t.Helper()
		r, e := repairReviewFor(j, m, "RECEPTION-01", "https://collectors.example.test", computer, collector, expiry, now)
		if e != nil {
			t.Fatal(e)
		}
		return r
	}
	computer, collector := strings.Repeat("1", 64), strings.Repeat("2", 64)
	original := create(j, computer, collector, "")
	encoded, _ := json.Marshal(original)
	if strings.Contains(string(encoded), computer) || strings.Contains(string(encoded), collector) {
		t.Fatal("private identity fingerprint exposed")
	}
	if original.ExpiresAt != now.Add(10*time.Minute).Truncate(time.Second).Format(time.RFC3339) {
		t.Fatal("review expiry incorrect")
	}
	for _, change := range []func(*Journal){func(v *Journal) { v.Attempt = strings.Repeat("e", 32) }, func(v *Journal) { v.Stage = "r-7-" + strings.Repeat("c", 32) }, func(v *Journal) { v.ServerURL = "https://other.example.test" }, func(v *Journal) { v.AgentUUID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa" }, func(v *Journal) { v.CollectorSiteID++ }, func(v *Journal) { v.ManifestSHA256 = strings.Repeat("a", 64) }, func(v *Journal) { v.UpdatedAt = now.Add(time.Second).Format(time.RFC3339) }} {
		changed := j
		change(&changed)
		if create(changed, computer, collector, original.ExpiresAt).ReviewDigest == original.ReviewDigest {
			t.Fatal("changed installed review retained confirmation")
		}
	}
	if create(j, strings.Repeat("3", 64), collector, original.ExpiresAt).ReviewDigest == original.ReviewDigest || create(j, computer, strings.Repeat("4", 64), original.ExpiresAt).ReviewDigest == original.ReviewDigest {
		t.Fatal("changed credential authority retained confirmation")
	}
	if create(j, computer, collector, now.Add(5*time.Minute).Format(time.RFC3339Nano)).ReviewDigest == original.ReviewDigest {
		t.Fatal("changed validity retained confirmation")
	}
	if err := func() error {
		_, e := repairReviewFor(j, m, "RECEPTION-01", "https://collectors.example.test", computer, collector, original.ExpiresAt, now.Add(11*time.Minute))
		return e
	}(); err == nil {
		t.Fatal("expired review accepted")
	}
	for _, expiry := range []string{"broken", now.Add(11 * time.Minute).Format(time.RFC3339), now.Add(-time.Second).Format(time.RFC3339)} {
		if _, e := repairReviewFor(j, m, "RECEPTION-01", "https://collectors.example.test", computer, collector, expiry, now); e == nil {
			t.Fatal("bad review expiry accepted")
		}
	}
	// Native UI consumes the actual Go review and transaction receipt shape.
	if path := os.Getenv("OBLI_SETUP_REPAIR_EXPORT"); path != "" {
		if err := os.WriteFile(path, encoded, 0600); err != nil {
			t.Fatal(err)
		}
		receipt, err := RunServiceRepair(context.Background(), j, &repairHostFake{expected: j})
		if err != nil {
			t.Fatal(err)
		}
		data, _ := json.Marshal(receipt)
		if err = os.WriteFile(path+".result.json", data, 0600); err != nil {
			t.Fatal(err)
		}
	}
}
func TestRepairReviewDoesNotExtendSignedReleaseValidity(t *testing.T) {
	j, m := repairFixture("computer")
	now := time.Now().UTC()
	m.ExpiresAt = now.Add(3 * time.Minute).Truncate(time.Second).Format(time.RFC3339)
	r, err := repairReviewFor(j, m, "RECEPTION-01", "", strings.Repeat("1", 64), "", "", now)
	if err != nil || r.ExpiresAt != m.ExpiresAt {
		t.Fatal("release expiry was extended", err)
	}
	if _, err = repairReviewFor(j, m, "RECEPTION-01", "", strings.Repeat("1", 64), "", now.Add(5*time.Minute).Format(time.RFC3339), now); err == nil {
		t.Fatal("confirmation outlived release")
	}
}
