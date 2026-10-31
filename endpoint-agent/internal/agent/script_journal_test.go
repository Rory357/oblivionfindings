package agent

import (
	"bytes"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type scriptJournalFixture struct {
	journal                  *scriptJournal
	store                    *Store
	wire                     scriptHTTPFixture
	at                       time.Time
	key                      ed25519.PublicKey
	id, boot, desktop, nonce string
}

func scriptJournalTest(t *testing.T) *scriptJournalFixture {
	t.Helper()
	f := &scriptJournalFixture{}
	scriptLoadHTTPFixture(t, "OBLI_SCRIPT_TRANSPORT_EXPORT", &f.wire)
	f.at, _ = time.Parse(time.RFC3339, f.wire.Now)
	f.key, _ = base64.StdEncoding.DecodeString(f.wire.PublicKey)
	input := scriptRawObject(t, f.wire.ClaimRequest)
	f.boot = scriptString(input["boot_id"])
	consent := input["consent"].(scriptObject)
	f.desktop = scriptString(consent["desktop_session"])
	f.nonce = scriptString(consent["consent_nonce"])
	command := scriptEnvelopePayload(t, f.wire.Command)
	f.id = scriptString(command["attempt_uuid"])
	var err error
	f.store, err = OpenStore(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { f.store.Close() })
	f.journal, err = createScriptJournal(f.store, f.wire.AgentUUID, f.key, func() time.Time { return f.at })
	if err != nil {
		t.Fatal(err)
	}
	if err = f.journal.Prepare(f.wire.Command, f.boot); err != nil {
		t.Fatal(err)
	}
	return f
}
func (f *scriptJournalFixture) advance(t *testing.T, phase string) {
	t.Helper()
	if phase == "prepared" {
		return
	}
	if err := f.journal.BeginClaim(f.id, f.boot, &f.desktop, &f.nonce); err != nil {
		t.Fatal(err)
	}
	if phase == "claiming" {
		return
	}
	if err := f.journal.AcceptClaim(f.id, f.wire.Claim); err != nil {
		t.Fatal(err)
	}
	if phase == "claimed" {
		return
	}
	if err := f.journal.Enter(f.id, "11111111-1111-4111-8111-111111111111"); err != nil {
		t.Fatal(err)
	}
	if phase == "entered" {
		return
	}
	if err := f.journal.Suspended(f.id, 123, 133777777777777777); err != nil {
		t.Fatal(err)
	}
	if phase == "suspended" {
		return
	}
	if err := f.journal.Running(f.id); err != nil {
		t.Fatal(err)
	}
}
func (f *scriptJournalFixture) reload(t *testing.T) *scriptJournal {
	t.Helper()
	j, err := loadScriptJournal(f.store, f.wire.AgentUUID, f.key, func() time.Time { return f.at })
	if err != nil {
		t.Fatal(err)
	}
	return j
}
func scriptJournalBytes(t *testing.T, s *Store) []byte {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(s.dir, scriptJournalName))
	if err != nil {
		t.Fatal(err)
	}
	return b
}
func (f *scriptJournalFixture) result(status string, entered bool) scriptObject {
	command := scriptEnvelopePayloadNoTest(f.wire.Command)
	raw, _ := base64.StdEncoding.DecodeString(f.wire.Command.Payload)
	reason := status
	var exit any
	if status == "completed" {
		exit = json.Number("0")
	}
	if status == "uncertain" {
		reason = "interrupted"
	}
	return scriptObject{"command_uuid": command["command_uuid"], "payload_hash": scriptHash(raw), "status": status, "mutation_started": entered, "reason_code": reason, "exit_code": exit, "boot_id": f.boot, "output_sha256": nil, "output_bytes": json.Number("0"), "output_truncated": false}
}
func scriptEnvelopePayloadNoTest(e Envelope) scriptObject {
	raw, _ := base64.StdEncoding.DecodeString(e.Payload)
	x, _ := scriptDecode(raw, 32768)
	return x
}
func (f *scriptJournalFixture) ack() []byte {
	x := f.result("declined", false)
	raw, _ := json.Marshal(map[string]any{"command_uuid": x["command_uuid"], "attempt_uuid": f.id, "payload_hash": x["payload_hash"], "accepted": true})
	return raw
}

func TestScriptJournalCrashBoundaries(t *testing.T) {
	for _, phase := range []string{"prepared", "claiming", "claimed", "entered", "suspended", "running"} {
		t.Run(phase, func(t *testing.T) {
			f := scriptJournalTest(t)
			f.advance(t, phase)
			before := scriptJournalBytes(t, f.store)
			j := f.reload(t)
			rows, held, err := j.Snapshot()
			if err != nil {
				t.Fatal(err)
			}
			r := rows[f.id]
			if r.Original != f.wire.Command || r.BootID != f.boot {
				t.Fatal("historical identity lost")
			}
			if phase == "prepared" {
				if held || r.Phase != "prepared" || !bytes.Equal(before, scriptJournalBytes(t, f.store)) {
					t.Fatal("prepared recovery changed")
				}
				return
			}
			result := scriptRawObject(t, r.Result)
			entered := phase == "entered" || phase == "suspended" || phase == "running"
			if !held || r.Phase != "terminal" || result["status"] != "uncertain" || result["mutation_started"] != entered {
				t.Fatal("crash uncertainty lost")
			}
			if entered && r.JobID == "" {
				t.Fatal("job identity lost")
			}
			if (phase == "suspended" || phase == "running") && (r.Process == nil || r.Process.Created != "133777777777777777") {
				t.Fatal("exact child identity lost")
			}
			if err = j.Prepare(f.wire.Command, "22222222-2222-4222-8222-222222222222"); err != nil {
				t.Fatal(err)
			}
			if err = j.BeginClaim(f.id, f.boot, &f.desktop, &f.nonce); err == nil {
				t.Fatal("recovery granted another claim")
			}
			if err = j.Acknowledge(f.id, f.ack()); err != nil {
				t.Fatal(err)
			}
			f.at = f.at.Add(365 * 24 * time.Hour)
			if err = j.Retire(); err != nil {
				t.Fatal(err)
			}
			rows, held, err = j.Snapshot()
			if err != nil || !held || len(rows) != 1 {
				t.Fatal("uncertainty retired")
			}
			// Mutating a returned snapshot never changes authoritative memory or disk.
			rows[f.id].BootID = "changed"
			rows, _, _ = j.Snapshot()
			if rows[f.id].BootID != f.boot {
				t.Fatal("mutable snapshot escaped")
			}
			stable := scriptJournalBytes(t, f.store)
			_ = f.reload(t)
			if !bytes.Equal(stable, scriptJournalBytes(t, f.store)) {
				t.Fatal("terminal reload rewrote evidence")
			}
		})
	}
}
func TestScriptJournalTransitionAndReceiptInvariants(t *testing.T) {
	f := scriptJournalTest(t)
	if err := f.journal.Enter(f.id, "11111111-1111-4111-8111-111111111111"); err == nil {
		t.Fatal("entered without claim")
	}
	if err := f.journal.Finish(f.id, f.result("completed", true)); err == nil {
		t.Fatal("success before entry")
	}
	f.advance(t, "claimed")
	before := scriptJournalBytes(t, f.store)
	f.at = f.at.Add(time.Minute)
	if err := f.journal.Enter(f.id, "11111111-1111-4111-8111-111111111111"); err == nil || !bytes.Equal(before, scriptJournalBytes(t, f.store)) {
		t.Fatal("expired claim entered")
	}
	j := f.reload(t)
	if err := j.Finish(f.id, f.result("declined", false)); err == nil {
		t.Fatal("uncertainty replaced")
	}
	f2 := scriptJournalTest(t)
	f2.advance(t, "entered")
	if err := f2.journal.Finish(f2.id, f2.result("completed", true)); err == nil {
		t.Fatal("success without child identity")
	}
	if err := f2.journal.Suspended(f2.id, 456, 133777777777777778); err != nil {
		t.Fatal(err)
	}
	if err := f2.journal.Running(f2.id); err != nil {
		t.Fatal(err)
	}
	result := f2.result("completed", true)
	if err := f2.journal.Finish(f2.id, result); err != nil {
		t.Fatal(err)
	}
	before = scriptJournalBytes(t, f2.store)
	if err := f2.journal.Finish(f2.id, result); err != nil || !bytes.Equal(before, scriptJournalBytes(t, f2.store)) {
		t.Fatal("terminal retry changed")
	}
	if err := f2.journal.Finish(f2.id, f2.result("uncertain", true)); err == nil {
		t.Fatal("terminal evidence replaced")
	}
	if err := f2.journal.Acknowledge(f2.id, f2.ack()); err != nil {
		t.Fatal(err)
	}
	f2.at = f2.at.Add(365 * 24 * time.Hour)
	if err := f2.journal.Retire(); err != nil {
		t.Fatal(err)
	}
	rows, held, err := f2.journal.Snapshot()
	if err != nil || !held || len(rows) != 1 {
		t.Fatal("entered result treated as quiescence")
	}
}
func TestScriptJournalDefiniteNonentryRetirement(t *testing.T) {
	f := scriptJournalTest(t)
	if err := f.journal.Finish(f.id, f.result("declined", false)); err != nil {
		t.Fatal(err)
	}
	rows, held, err := f.journal.Snapshot()
	if err != nil || held || len(rows) != 1 {
		t.Fatal("definite nonentry gate")
	}
	bad := scriptRawObject(t, f.ack())
	bad["accepted"] = false
	raw, _ := json.Marshal(bad)
	if err := f.journal.Acknowledge(f.id, raw); err == nil {
		t.Fatal("false acknowledgement")
	}
	if err := f.journal.Retire(); err != nil {
		t.Fatal(err)
	}
	rows, _, _ = f.journal.Snapshot()
	if len(rows) != 1 {
		t.Fatal("unreported result retired")
	}
	if err := f.journal.Acknowledge(f.id, f.ack()); err != nil {
		t.Fatal(err)
	}
	stable := scriptJournalBytes(t, f.store)
	f.at = f.at.Add(time.Second)
	if err := f.journal.Acknowledge(f.id, f.ack()); err != nil || !bytes.Equal(stable, scriptJournalBytes(t, f.store)) {
		t.Fatal("ack time changed")
	}
	if err := f.journal.Retire(); err != nil {
		t.Fatal(err)
	}
	rows, _, _ = f.journal.Snapshot()
	if len(rows) != 1 {
		t.Fatal("lease window ignored")
	}
	initial := f.at
	f.at = f.at.Add(2 * time.Hour)
	if err := f.journal.Retire(); err != nil {
		t.Fatal(err)
	}
	j := f.reload(t)
	rows, held, err = j.Snapshot()
	if err != nil || held || len(rows) != 0 {
		t.Fatal("definite nonentry not retired")
	}
	f.at = initial
	if err = j.Prepare(f.wire.Command, f.boot); err == nil {
		t.Fatal("retirement watermark replayed under clock rollback")
	}
}
func TestScriptJournalWriteFailurePoisonsUntilReload(t *testing.T) {
	for _, committed := range []bool{false, true} {
		t.Run(fmt.Sprint(committed), func(t *testing.T) {
			f := scriptJournalTest(t)
			writes := 0
			f.store.writeProtected = func(name string, data []byte) error {
				writes++
				if committed {
					if err := os.WriteFile(filepath.Join(f.store.dir, name), data, 0600); err != nil {
						return err
					}
				}
				return errors.New("synthetic private failure")
			}
			if err := f.journal.BeginClaim(f.id, f.boot, &f.desktop, &f.nonce); err == nil {
				t.Fatal("write failure accepted")
			}
			if _, held, err := f.journal.Snapshot(); err == nil || !held {
				t.Fatal("failed writer not fenced")
			}
			if err := f.journal.BeginClaim(f.id, f.boot, &f.desktop, &f.nonce); err == nil || writes != 1 {
				t.Fatal("failed writer retried")
			}
			f.store.writeProtected = nil
			j := f.reload(t)
			rows, held, err := j.Snapshot()
			if err != nil {
				t.Fatal(err)
			}
			if committed {
				if !held || rows[f.id].Phase != "terminal" {
					t.Fatal("committed ambiguous claim reset")
				}
			} else if held || rows[f.id].Phase != "prepared" {
				t.Fatal("failed pre-claim write misclassified")
			}
		})
	}
}
func TestScriptJournalMissingCorruptOrForeignStateFailsClosed(t *testing.T) {
	for _, kind := range []string{"missing journal", "missing guard", "guard mismatch", "duplicate root", "unknown root", "foreign agent", "wrong signing key", "protected read", "stale writer"} {
		t.Run(kind, func(t *testing.T) {
			f := scriptJournalTest(t)
			key := f.key
			agent := f.wire.AgentUUID
			switch kind {
			case "missing journal":
				os.Remove(filepath.Join(f.store.dir, scriptJournalName))
			case "missing guard":
				os.Remove(filepath.Join(f.store.dir, scriptJournalGuard))
			case "guard mismatch":
				os.WriteFile(filepath.Join(f.store.dir, scriptJournalGuard), []byte(`{"version":1,"agent_uuid":"other","guard":"other"}`), 0600)
			case "duplicate root":
				raw := scriptJournalBytes(t, f.store)
				os.WriteFile(filepath.Join(f.store.dir, scriptJournalName), append([]byte(`{"version":1,`), raw[1:]...), 0600)
			case "unknown root":
				x := scriptRawObject(t, scriptJournalBytes(t, f.store))
				x["extra"] = true
				raw, _ := json.Marshal(x)
				os.WriteFile(filepath.Join(f.store.dir, scriptJournalName), raw, 0600)
			case "foreign agent":
				agent = "11111111-1111-4111-8111-111111111111"
			case "wrong signing key":
				key = bytes.Repeat([]byte{1}, 32)
			case "protected read":
				f.store.validateProtected = func(string) error { return errors.New("denied") }
			case "stale writer":
				other := f.reload(t)
				if err := other.BeginClaim(f.id, f.boot, &f.desktop, &f.nonce); err != nil {
					t.Fatal(err)
				}
				if err := f.journal.BeginClaim(f.id, f.boot, &f.desktop, &f.nonce); err == nil {
					t.Fatal("stale writer overwrote current state")
				}
				if _, held, err := f.journal.Snapshot(); err == nil || !held {
					t.Fatal("stale reader granted release")
				}
				return
			}
			if _, err := loadScriptJournal(f.store, agent, key, func() time.Time { return f.at }); err == nil {
				t.Fatal("unsafe state loaded")
			}
			if _, err := createScriptJournal(f.store, agent, key, func() time.Time { return f.at }); err == nil {
				t.Fatal("unsafe state reset")
			}
		})
	}
}
func TestScriptJournalPartialInitializationAndCapacity(t *testing.T) {
	f := scriptJournalTest(t)
	second, err := OpenStore(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	defer second.Close()
	second.writeProtected = func(name string, raw []byte) error {
		if name == scriptJournalName {
			return errors.New("interrupted creation")
		}
		return os.WriteFile(filepath.Join(second.dir, name), raw, 0600)
	}
	if _, err = createScriptJournal(second, f.wire.AgentUUID, f.key, func() time.Time { return f.at }); err == nil {
		t.Fatal("partial creation accepted")
	}
	second.writeProtected = nil
	if _, err = loadScriptJournal(second, f.wire.AgentUUID, f.key, func() time.Time { return f.at }); err == nil {
		t.Fatal("missing initial journal reset")
	}
	if _, err = createScriptJournal(second, f.wire.AgentUUID, f.key, func() time.Time { return f.at }); err == nil {
		t.Fatal("initial guard replaced")
	}
	private := ed25519.NewKeyFromSeed(bytes.Repeat([]byte{7}, ed25519.SeedSize))
	third, err := OpenStore(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	defer third.Close()
	j, err := createScriptJournal(third, f.wire.AgentUUID, private.Public().(ed25519.PublicKey), func() time.Time { return f.at })
	if err != nil {
		t.Fatal(err)
	}
	makeCommand := func(i int) Envelope {
		x := scriptEnvelopePayloadNoTest(f.wire.Command)
		x["attempt_uuid"] = fmt.Sprintf("aaaaaaaa-aaaa-4aaa-8aaa-%012x", i)
		raw, _ := json.Marshal(x)
		return Envelope{Payload: base64.StdEncoding.EncodeToString(raw), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(private, raw))}
	}
	if err = j.change(func(next *scriptJournalState) error {
		for i := 0; i < 256; i++ {
			e := makeCommand(i)
			x := scriptEnvelopePayloadNoTest(e)
			raw, _ := base64.StdEncoding.DecodeString(e.Payload)
			next.Entries[scriptString(x["attempt_uuid"])] = &scriptRecord{Original: e, Hash: scriptHash(raw), Phase: "prepared", BootID: f.boot}
		}
		return nil
	}); err != nil {
		t.Fatal(err)
	}
	before := scriptJournalBytes(t, third)
	if err = j.Prepare(makeCommand(256), f.boot); err == nil || !bytes.Equal(before, scriptJournalBytes(t, third)) {
		t.Fatal("full journal evicted work")
	}
	if err = j.Prepare(makeCommand(0), f.boot); err != nil {
		t.Fatal("exact full-journal retry failed", err)
	}
	if _, err = loadScriptJournal(third, f.wire.AgentUUID, private.Public().(ed25519.PublicKey), func() time.Time { return f.at }); err != nil {
		t.Fatal(err)
	}
	// No output transcript, provider credential or arbitrary filename is accepted.
	if strings.Contains(string(before), "encrypted_content") {
		t.Fatal("unexpected output storage in journal")
	}
}
