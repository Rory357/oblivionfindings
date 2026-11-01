package hubupdate

import (
	"strings"
	"testing"
	"time"
)

var sha = strings.Repeat("a", 64)

// 2026-11-01 02:30 in Auckland (NZDT, UTC+13) is 2026-10-31 13:30 UTC.
var nightInAuckland = time.Date(2026, 10, 31, 13, 30, 0, 0, time.UTC)

func instructionsFixture(now time.Time) Instructions {
	return Instructions{
		Approval:       "approved",
		ReceivedAt:     now.Add(-time.Minute).Format(time.RFC3339),
		RunningVersion: "1.3.0",
		Update: &Update{
			Ring:                 "early",
			Window:               Window{Start: "02:00", End: "05:00", Timezone: "Pacific/Auckland"},
			RollbackAfterMinutes: 5,
			Target: &Target{Version: "1.3.1", ReleaseRevision: 41, Artifacts: []Artifact{
				{ID: "windows-setup-amd64", Architecture: "amd64", Filename: "oblivion-setup-windows-amd64.exe", Size: 10, SHA256: sha},
				{ID: "windows-package-amd64", Architecture: "amd64", Filename: "oblivion-windows-amd64.zip", Size: 30, SHA256: sha},
				{ID: "windows-site-package-amd64", Architecture: "amd64", Filename: "oblivion-site-windows-amd64.zip", Size: 20, SHA256: sha},
				{ID: "linux-site-hub-amd64", Architecture: "amd64", Filename: "oblivion-site-hub-linux-amd64.tar.gz", Size: 20, SHA256: sha},
				{ID: "linux-site-hub-arm64", Architecture: "arm64", Filename: "oblivion-site-hub-linux-arm64.tar.gz", Size: 20, SHA256: sha},
			}},
		},
	}
}

var runningFixture = Running{Stage: "r-40-" + strings.Repeat("b", 32), Version: "1.3.0", Revision: 40}

func TestDecideUpdatesInsideTheWindowAndPicksTheSitePackage(t *testing.T) {
	d := Decide(instructionsFixture(nightInAuckland), runningFixture, nil, "windows", "amd64", nightInAuckland)
	if !d.Update || d.Artifact.ID != "windows-site-package-amd64" || d.RollbackAfter != 5*time.Minute || d.Target.Version != "1.3.1" {
		t.Fatalf("%+v", d)
	}
	d = Decide(instructionsFixture(nightInAuckland), runningFixture, nil, "linux", "arm64", nightInAuckland)
	if !d.Update || d.Artifact.ID != "linux-site-hub-arm64" {
		t.Fatalf("%+v", d)
	}
}

func TestDecideWindowUsesTheServerTimeZoneAndWrapsPastMidnight(t *testing.T) {
	cases := []struct {
		start, end string
		local      string // Auckland wall clock
		inside     bool
	}{
		{"02:00", "05:00", "02:00", true},
		{"02:00", "05:00", "04:59", true},
		{"02:00", "05:00", "05:00", false},
		{"02:00", "05:00", "01:59", false},
		{"22:00", "02:00", "23:30", true},
		{"22:00", "02:00", "00:15", true},
		{"22:00", "02:00", "01:59", true},
		{"22:00", "02:00", "02:00", false},
		{"22:00", "02:00", "12:00", false},
		{"03:00", "03:00", "03:00", false},
	}
	auckland, err := time.LoadLocation("Pacific/Auckland")
	if err != nil {
		t.Fatal(err)
	}
	for _, c := range cases {
		local, _ := time.ParseInLocation("2006-01-02 15:04", "2026-11-01 "+c.local, auckland)
		in := instructionsFixture(local)
		in.Update.Window = Window{Start: c.start, End: c.end, Timezone: "Pacific/Auckland"}
		d := Decide(in, runningFixture, nil, "windows", "amd64", local)
		if d.Update != c.inside {
			t.Errorf("window %s-%s at %s: got %v (%s)", c.start, c.end, c.local, d.Update, d.Reason)
		}
	}
	// The same instant is 13:30 UTC: a UTC reading would be outside 02:00-05:00.
	if d := Decide(instructionsFixture(nightInAuckland), runningFixture, nil, "windows", "amd64", nightInAuckland); !d.Update {
		t.Fatal("window was not read in the server's time zone", d.Reason)
	}
}

func TestDecideRespectsHoldApprovalAndAMissingTarget(t *testing.T) {
	held := instructionsFixture(nightInAuckland)
	held.Update.Held = true
	waiting := instructionsFixture(nightInAuckland)
	waiting.Approval = "waiting"
	noTarget := instructionsFixture(nightInAuckland) // the "everyone" ring before its day, or nothing published
	noTarget.Update.Target = nil
	noUpdate := instructionsFixture(nightInAuckland)
	noUpdate.Update = nil
	stale := instructionsFixture(nightInAuckland)
	stale.ReceivedAt = nightInAuckland.Add(-time.Hour).Format(time.RFC3339)
	badZone := instructionsFixture(nightInAuckland)
	badZone.Update.Window.Timezone = "Nowhere/Special"
	for name, in := range map[string]Instructions{"held": held, "waiting": waiting, "no target": noTarget, "no update": noUpdate, "stale": stale, "bad zone": badZone} {
		if d := Decide(in, runningFixture, nil, "windows", "amd64", nightInAuckland); d.Update || d.Reason == "" {
			t.Errorf("%s: %+v", name, d)
		}
	}
}

func TestDecideComparesVersionsAndUsesTheRevisionOnlyToBreakTies(t *testing.T) {
	cases := []struct {
		name         string
		target       string
		targetRev    uint64
		running      string
		runningRev   uint64
		expectUpdate bool
	}{
		{"newer patch", "1.3.1", 41, "1.3.0", 40, true},
		{"numeric not text order", "1.10.0", 41, "1.9.9", 40, true},
		{"older", "1.2.9", 50, "1.3.0", 40, false},
		{"same version, newer revision", "1.3.0", 41, "1.3.0", 40, true},
		{"same version, same revision", "1.3.0", 40, "1.3.0", 40, false},
		{"same version, older revision", "1.3.0", 39, "1.3.0", 40, false},
		{"same version, running revision unknown", "1.3.0", 41, "1.3.0", 0, false},
		{"newer version, running revision unknown", "1.3.1", 41, "1.3.0", 0, true},
		{"invalid running version", "1.3.1", 41, "dev", 40, false},
	}
	for _, c := range cases {
		in := instructionsFixture(nightInAuckland)
		in.Update.Target.Version, in.Update.Target.ReleaseRevision = c.target, c.targetRev
		running := Running{Stage: runningFixture.Stage, Version: c.running, Revision: c.runningRev}
		if d := Decide(in, running, nil, "windows", "amd64", nightInAuckland); d.Update != c.expectUpdate {
			t.Errorf("%s: got %v (%s)", c.name, d.Update, d.Reason)
		}
	}
}

func TestDecideWaitsTwentyHoursAfterAFailedVersion(t *testing.T) {
	in := instructionsFixture(nightInAuckland)
	recent := &Failure{Version: "1.3.1", Revision: 41, At: nightInAuckland.Add(-19 * time.Hour)}
	if d := Decide(in, runningFixture, recent, "windows", "amd64", nightInAuckland); d.Update {
		t.Fatal("retried a version that failed 19 hours ago")
	}
	old := &Failure{Version: "1.3.1", Revision: 41, At: nightInAuckland.Add(-21 * time.Hour)}
	if d := Decide(in, runningFixture, old, "windows", "amd64", nightInAuckland); !d.Update {
		t.Fatal("did not retry the next night", d.Reason)
	}
	republished := &Failure{Version: "1.3.1", Revision: 40, At: nightInAuckland.Add(-time.Hour)}
	if d := Decide(in, runningFixture, republished, "windows", "amd64", nightInAuckland); !d.Update {
		t.Fatal("a fixed rebuild of the same version was blocked", d.Reason)
	}
}

func TestDecideNeedsAValidFileForThisMachine(t *testing.T) {
	in := instructionsFixture(nightInAuckland)
	if d := Decide(in, runningFixture, nil, "windows", "arm64", nightInAuckland); d.Update {
		t.Fatal("picked a file for another architecture")
	}
	in.Update.Target.Artifacts[2].SHA256 = "not-a-digest"
	in.Update.Target.Artifacts[1].Size = 0
	if d := Decide(in, runningFixture, nil, "windows", "amd64", nightInAuckland); d.Update {
		t.Fatal("picked an artifact with an invalid digest or size")
	}
}

func TestParseInstructionsRejectsDamagedFiles(t *testing.T) {
	for _, data := range []string{"", "{", `{"approval":"maybe","received_at":"2026-11-01T02:00:00+13:00"}`, `{"approval":"approved","received_at":"yesterday"}`} {
		if _, err := ParseInstructions([]byte(data)); err == nil {
			t.Errorf("accepted %q", data)
		}
	}
	in, err := ParseInstructions([]byte(`{"approval":"approved","update":{"held":false,"ring":"everyone","window":{"start":"02:00","end":"05:00","timezone":"Pacific/Auckland"},"rollback_after_minutes":5,"target":null},"received_at":"2026-11-01T02:00:00+13:00","running_version":"1.3.0","later_field":1}`))
	if err != nil || in.Update == nil || in.Update.Target != nil || in.RunningVersion != "1.3.0" {
		t.Fatal(in, err)
	}
}

func TestStageNames(t *testing.T) {
	if StageRevision("r-41-"+strings.Repeat("c", 32)) != 41 || StageRevision("r-0-"+strings.Repeat("c", 32)) != 0 || StageRevision("current") != 0 {
		t.Fatal("stage revision")
	}
	for _, bad := range []string{"", "r-01-" + strings.Repeat("c", 32), "r-1-" + strings.Repeat("C", 32), "../r-1-" + strings.Repeat("c", 32)} {
		if ValidStage(bad) {
			t.Errorf("accepted %q", bad)
		}
	}
}
