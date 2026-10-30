package agent

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func remoteEnvelope(t *testing.T, value any) Envelope {
	t.Helper()
	_, key := testKey()
	payload, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}
	return Envelope{Payload: base64.StdEncoding.EncodeToString(payload), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(key, payload))}
}
func remoteFixture() (RemoteOffer, RemoteLease, time.Time) {
	now := time.Date(2026, 10, 8, 0, 0, 0, 0, time.UTC)
	offer := RemoteOffer{Type: "remote_support_offer", Version: 1, AgentUUID: testAgentUUID,
		SessionUUID: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", PolicyVersion: 1, TechnicianName: "Alex Example",
		Purpose: "Check the printer connection", AccessMode: "view", ConsentMode: "attended", Challenge: strings.Repeat("a", 64),
		IssuedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(120 * time.Second).Format(time.RFC3339)}
	lease := RemoteLease{Type: "remote_support_lease", Version: 1, AgentUUID: testAgentUUID, SessionUUID: offer.SessionUUID,
		DesktopSession: "cccccccc-cccc-4ccc-8ccc-cccccccccccc", AccessMode: "view", Status: "active",
		IssuedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(15 * time.Second).Format(time.RFC3339)}
	return offer, lease, now
}
func TestRemoteOfferAndLeaseBindDeviceDesktopAccessAndTime(t *testing.T) {
	offer, lease, now := remoteFixture()
	key, _ := testKey()
	accepted, err := VerifyRemoteOffer(remoteEnvelope(t, offer), key, testAgentUUID, now)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := VerifyRemoteLease(remoteEnvelope(t, lease), key, accepted, lease.DesktopSession, now); err != nil {
		t.Fatal(err)
	}
	cases := map[string]func(*RemoteLease){
		"wrong agent":           func(l *RemoteLease) { l.AgentUUID = l.DesktopSession },
		"wrong session":         func(l *RemoteLease) { l.SessionUUID = l.DesktopSession },
		"another desktop":       func(l *RemoteLease) { l.DesktopSession = l.AgentUUID },
		"control escalation":    func(l *RemoteLease) { l.AccessMode = "control" },
		"recording unsupported": func(l *RemoteLease) { l.Recording = true },
		"stopped":               func(l *RemoteLease) { l.Status = "stopped" },
		"overlong":              func(l *RemoteLease) { l.ExpiresAt = now.Add(16 * time.Second).Format(time.RFC3339) },
		"expired":               func(l *RemoteLease) { l.ExpiresAt = now.Format(time.RFC3339) },
		"future": func(l *RemoteLease) {
			l.IssuedAt = now.Add(time.Minute).Format(time.RFC3339)
			l.ExpiresAt = now.Add(65 * time.Second).Format(time.RFC3339)
		},
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			changed := lease
			mutate(&changed)
			if _, err := VerifyRemoteLease(remoteEnvelope(t, changed), key, offer, lease.DesktopSession, now); err == nil {
				t.Fatal("unsafe lease accepted")
			}
		})
	}
	for name, mutate := range map[string]func(*RemoteOffer){
		"unbounded request": func(o *RemoteOffer) { o.ExpiresAt = now.Add(121 * time.Second).Format(time.RFC3339) },
		"wrong device":      func(o *RemoteOffer) { o.AgentUUID = o.SessionUUID },
		"bad mode":          func(o *RemoteOffer) { o.AccessMode = "shell" },
		"hidden identity":   func(o *RemoteOffer) { o.TechnicianName = "Alex\u202E" },
		"blank purpose":     func(o *RemoteOffer) { o.Purpose = "" },
		"policy bypass":     func(o *RemoteOffer) { o.ConsentMode = "emergency" },
		"missing challenge": func(o *RemoteOffer) { o.Challenge = "" },
	} {
		t.Run(name, func(t *testing.T) {
			changed := offer
			mutate(&changed)
			if _, err := VerifyRemoteOffer(remoteEnvelope(t, changed), key, testAgentUUID, now); err == nil {
				t.Fatal("unsafe offer accepted")
			}
		})
	}
}
func TestRemoteSignaturesRejectOtherMessageTypesAndAmbiguousJSON(t *testing.T) {
	offer, lease, now := remoteFixture()
	key, private := testKey()
	if _, err := VerifyRemoteOffer(remoteEnvelope(t, lease), key, testAgentUUID, now); err == nil {
		t.Fatal("lease used as consent offer")
	}
	command, _, _ := signedFixture(t, nil)
	if _, err := VerifyRemoteOffer(command, key, testAgentUUID, now); err == nil {
		t.Fatal("command used as remote offer")
	}
	data, _ := json.Marshal(offer)
	for _, payload := range []string{
		strings.Replace(string(data), `"version":1`, `"version":1,"version":1`, 1),
		strings.Replace(string(data), `"version":1`, `"version":1,"extra":"private"`, 1),
		strings.Replace(string(data), `"recording":false`, `"recording":null`, 1),
		string(data) + "{}", strings.Repeat(" ", 4097),
	} {
		envelope := Envelope{Payload: base64.StdEncoding.EncodeToString([]byte(payload)), Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(private, []byte(payload)))}
		if _, err := VerifyRemoteOffer(envelope, key, testAgentUUID, now); err == nil {
			t.Fatal("ambiguous offer accepted")
		}
	}
	envelope := remoteEnvelope(t, offer)
	envelope.Payload = base64.StdEncoding.EncodeToString(append(data, ' '))
	if _, err := VerifyRemoteOffer(envelope, key, testAgentUUID, now); err == nil {
		t.Fatal("altered signed bytes accepted")
	}
}
