package agent

import (
	"encoding/base64"
	"encoding/json"
	"os"
	"testing"
	"time"
)

func TestTicketComputerPHPFixture(t *testing.T) {
	path := os.Getenv("OBLI_COMPUTER_EXPORT")
	if path == "" {
		t.Skip("isolated PHP fixture not supplied")
	}
	data, e := os.ReadFile(path)
	if e != nil {
		t.Fatal(e)
	}
	var fixture struct {
		Envelope  Envelope `json:"envelope"`
		PublicKey string   `json:"public_key"`
		Challenge string   `json:"challenge"`
		AgentUUID string   `json:"agent_uuid"`
		Origin    string   `json:"origin"`
	}
	if json.Unmarshal(data, &fixture) != nil {
		t.Fatal("invalid fixture")
	}
	key, e := base64.StdEncoding.Strict().DecodeString(fixture.PublicKey)
	if e != nil {
		t.Fatal(e)
	}
	payload, e := base64.StdEncoding.Strict().DecodeString(fixture.Envelope.Payload)
	if e != nil {
		t.Fatal(e)
	}
	var ack ticketComputerAck
	if json.Unmarshal(payload, &ack) != nil {
		t.Fatal("invalid ack")
	}
	if verifyTicketComputerAck(fixture.Envelope, key, fixture.AgentUUID, fixture.Challenge, time.Unix(ack.IssuedAt+1, 0)) != nil {
		t.Fatal("PHP ack differs from Go contract")
	}
	if _, e := CanonicalOriginHash(fixture.Origin); e != nil {
		t.Fatal("fixture origin invalid")
	}
}
