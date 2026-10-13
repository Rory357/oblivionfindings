package agent

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/hex"
	"time"
)

func verifyTicketComputerAck(envelope Envelope, key ed25519.PublicKey, agentUUID, challenge string, now time.Time) error {
	var ack ticketComputerAck
	if remotePayloadLimited(envelope, key, []string{"type", "challenge_hash", "agent_uuid", "status", "issued_at", "expires_at"}, &ack, 1024) != nil {
		return errTicketComputer
	}
	sum := sha256.Sum256([]byte(challenge))
	if ack.Type != "obli_ticket_computer_context" || ack.Status != "confirmed" || ack.AgentUUID != agentUUID || ack.ChallengeHash != hex.EncodeToString(sum[:]) || ack.IssuedAt > now.Unix()+5 || ack.ExpiresAt <= now.Unix() || ack.ExpiresAt <= ack.IssuedAt || ack.ExpiresAt-ack.IssuedAt > 30 {
		return errTicketComputer
	}
	return nil
}
