package agent

import (
	"strings"
	"testing"
	"time"
)

func mediaAnswerFixture() (RemoteMediaAnswer, RemoteOffer, time.Time) {
	offer, lease, now := remoteFixture()
	answer := RemoteMediaAnswer{Type: "remote_media_answer", Version: 1, AgentUUID: offer.AgentUUID, SessionUUID: offer.SessionUUID, DesktopSession: lease.DesktopSession,
		OfferUUID: "dddddddd-dddd-4ddd-8ddd-dddddddddddd", ViewerUUID: "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee", AccessMode: offer.AccessMode,
		SDP: "v=0\r\n" + strings.Repeat("a=x:fixture\r\n", 15), IssuedAt: now.Format(time.RFC3339), ExpiresAt: now.Add(15 * time.Second).Format(time.RFC3339)}
	return answer, offer, now
}
func TestMediaAnswerSignatureBindsDesktopOfferAccessAndExpiry(t *testing.T) {
	answer, offer, now := mediaAnswerFixture()
	key, _ := testKey()
	accepted, err := VerifyRemoteMediaAnswer(remoteEnvelope(t, answer), key, offer, answer.DesktopSession, answer.OfferUUID, now)
	if err != nil || accepted.SDP != answer.SDP {
		t.Fatal("valid media binding rejected", err)
	}
	for label, modify := range map[string]func(*RemoteMediaAnswer){
		"type":         func(a *RemoteMediaAnswer) { a.Type = "remote_support_lease" },
		"agent":        func(a *RemoteMediaAnswer) { a.AgentUUID = a.OfferUUID },
		"session":      func(a *RemoteMediaAnswer) { a.SessionUUID = a.OfferUUID },
		"desktop":      func(a *RemoteMediaAnswer) { a.DesktopSession = a.OfferUUID },
		"offer":        func(a *RemoteMediaAnswer) { a.OfferUUID = a.ViewerUUID },
		"viewer":       func(a *RemoteMediaAnswer) { a.ViewerUUID = "unbound" },
		"mode":         func(a *RemoteMediaAnswer) { a.AccessMode = "control" },
		"expiry":       func(a *RemoteMediaAnswer) { a.ExpiresAt = now.Format(time.RFC3339) },
		"lifetime":     func(a *RemoteMediaAnswer) { a.ExpiresAt = now.Add(30 * time.Second).Format(time.RFC3339) },
		"control-byte": func(a *RemoteMediaAnswer) { a.SDP += "\x00" },
		"bounds":       func(a *RemoteMediaAnswer) { a.SDP = strings.Repeat("x", 32769) },
	} {
		t.Run(label, func(t *testing.T) {
			changed := answer
			modify(&changed)
			if _, err := VerifyRemoteMediaAnswer(remoteEnvelope(t, changed), key, offer, answer.DesktopSession, answer.OfferUUID, now); err == nil {
				t.Fatal("unsafe media answer accepted")
			}
		})
	}
}
