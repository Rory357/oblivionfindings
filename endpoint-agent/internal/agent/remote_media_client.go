package agent

import (
	"context"
	"crypto/ed25519"
	"net/http"
	"net/url"
	"strings"
	"time"
)

type RemoteMediaAnswer struct {
	Type           string `json:"type"`
	Version        int    `json:"version"`
	AgentUUID      string `json:"agent_uuid"`
	SessionUUID    string `json:"session_uuid"`
	DesktopSession string `json:"desktop_session"`
	OfferUUID      string `json:"offer_uuid"`
	ViewerUUID     string `json:"viewer_uuid"`
	AccessMode     string `json:"access_mode"`
	SDP            string `json:"sdp"`
	IssuedAt       string `json:"issued_at"`
	ExpiresAt      string `json:"expires_at"`
}

func VerifyRemoteMediaAnswer(envelope Envelope, key ed25519.PublicKey, offer RemoteOffer, desktop, offerID string, now time.Time) (RemoteMediaAnswer, error) {
	var answer RemoteMediaAnswer
	fields := []string{"type", "version", "agent_uuid", "session_uuid", "desktop_session", "offer_uuid", "viewer_uuid", "access_mode", "sdp", "issued_at", "expires_at"}
	if remotePayloadLimited(envelope, key, fields, &answer, 40000) != nil {
		return RemoteMediaAnswer{}, errRemote
	}
	if answer.Type != "remote_media_answer" || answer.Version != 1 || answer.AgentUUID != offer.AgentUUID || answer.SessionUUID != offer.SessionUUID ||
		answer.DesktopSession != desktop || answer.OfferUUID != offerID || !uuidPattern.MatchString(desktop) || !uuidPattern.MatchString(offerID) || !uuidPattern.MatchString(answer.ViewerUUID) ||
		answer.AccessMode != offer.AccessMode || !boundedSDP(answer.SDP) || !remoteWindow(answer.IssuedAt, answer.ExpiresAt, now, 15*time.Second) {
		return RemoteMediaAnswer{}, errRemote
	}
	return answer, nil
}
func boundedSDP(sdp string) bool {
	if len(sdp) < 100 || len(sdp) > 32768 || !strings.HasPrefix(sdp, "v=0\r\n") || !strings.HasSuffix(sdp, "\r\n") {
		return false
	}
	for _, c := range []byte(sdp) {
		if (c < 32 && c != '\r' && c != '\n') || c > 126 {
			return false
		}
	}
	return true
}
func (t remoteTransport) PublishMedia(ctx context.Context, offer RemoteOffer, desktop, offerID, sdp string) error {
	if !uuidPattern.MatchString(offer.SessionUUID) || !uuidPattern.MatchString(desktop) || !uuidPattern.MatchString(offerID) || !boundedSDP(sdp) {
		return errRemote
	}
	var reply struct {
		Status    string `json:"status"`
		OfferUUID string `json:"offer_uuid"`
	}
	err := t.client.remoteRequestLimited(ctx, http.MethodPost, "/api/endpoints/agent/remote/"+offer.SessionUUID+"/media/offer", t.credential, map[string]string{"desktop_session": desktop, "offer_uuid": offerID, "sdp": sdp}, &reply, 65536, 1024)
	if err != nil {
		return err
	}
	if reply.OfferUUID != offerID || (reply.Status != "allowed" && reply.Status != "active") {
		return errRemote
	}
	return nil
}
func (t remoteTransport) MediaAnswer(ctx context.Context, offer RemoteOffer, desktop, offerID string) (*RemoteMediaAnswer, error) {
	if !uuidPattern.MatchString(offer.SessionUUID) || !uuidPattern.MatchString(desktop) || !uuidPattern.MatchString(offerID) {
		return nil, errRemote
	}
	var reply struct {
		Status string    `json:"status"`
		Signal *Envelope `json:"signal"`
	}
	err := t.client.remoteRequestLimited(ctx, http.MethodGet, "/api/endpoints/agent/remote/"+offer.SessionUUID+"/media?desktop_session="+url.QueryEscape(desktop), t.credential, nil, &reply, 8192, 65536)
	if err != nil {
		return nil, err
	}
	if reply.Status != "allowed" && reply.Status != "active" {
		return nil, errRemote
	}
	if reply.Signal == nil {
		return nil, nil
	}
	answer, err := VerifyRemoteMediaAnswer(*reply.Signal, t.client.key, offer, desktop, offerID, time.Now())
	if err != nil {
		return nil, err
	}
	return &answer, nil
}
