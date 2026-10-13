package agent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"

	"net"
	"net/url"
	"strconv"
	"strings"
	"time"
)

var errTicketComputer = errors.New("computer context could not be confirmed")

type TicketComputerView struct {
	Protocol int    `json:"protocol"`
	Status   string `json:"status"`
}
type ticketComputerAck struct {
	Type          string `json:"type"`
	ChallengeHash string `json:"challenge_hash"`
	AgentUUID     string `json:"agent_uuid"`
	Status        string `json:"status"`
	IssuedAt      int64  `json:"issued_at"`
	ExpiresAt     int64  `json:"expires_at"`
}

// CanonicalOriginHash is shared with the tray: HTTPS ASCII host, no default port or slash.
func CanonicalOriginHash(origin string) (string, error) {
	u, e := url.Parse(origin)
	if e != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.Opaque != "" || u.RawQuery != "" || u.ForceQuery || u.Fragment != "" || u.RawPath != "" || (u.Path != "" && u.Path != "/") || strings.ContainsAny(origin, "?#") {
		return "", errTicketComputer
	}
	host := u.Hostname()
	if ip := net.ParseIP(host); ip != nil {
		if strings.Contains(host, ":") {
			parts := make([]string, 8)
			for i := range parts {
				parts[i] = fmt.Sprintf("%02x%02x", ip[2*i], ip[2*i+1])
			}
			host = "[" + strings.Join(parts, ":") + "]"
		}
	} else {
		for _, r := range host {
			if r > 127 {
				return "", errTicketComputer
			}
		}
		host = strings.ToLower(host)
	}
	port := u.Port()
	if port != "" {
		n, e := strconv.Atoi(port)
		if e != nil || n < 1 || n > 65535 {
			return "", errTicketComputer
		}
		if n != 443 {
			host += ":" + strconv.Itoa(n)
		}
	}
	sum := sha256.Sum256([]byte("https://" + host))
	return hex.EncodeToString(sum[:]), nil
}
func (c *Client) confirmTicketComputer(ctx context.Context, credential, agentUUID, challenge, expectedOriginHash string) error {
	if !hexCredential(challenge) || !hexCredential(expectedOriginHash) || !uuidPattern.MatchString(agentUUID) {
		return errTicketComputer
	}
	actual, e := CanonicalOriginHash(c.base)
	if e != nil || actual != expectedOriginHash {
		return errTicketComputer
	}
	var envelope Envelope
	if e = c.remoteRequestLimited(ctx, "POST", "/api/endpoints/agent/obli/ticket-computer-context", credential, struct {
		Challenge string `json:"challenge"`
	}{challenge}, &envelope, 512, 2048); e != nil {
		return errTicketComputer
	}
	return verifyTicketComputerAck(envelope, c.key, agentUUID, challenge, time.Now())
}
func (m *StatusMonitor) TicketComputer(challenge, originHash string) TicketComputerView {
	view := TicketComputerView{Protocol: 1, Status: "unavailable"}
	m.mu.RLock()
	proof := m.ticketComputer
	m.mu.RUnlock()
	if proof != nil && proof(challenge, originHash) == nil {
		view.Status = "confirmed"
	}
	return view
}
func (m *StatusMonitor) bindTicketComputer(ctx context.Context, c *Client, agentUUID, credential string) func() {
	slots := make(chan struct{}, 2)
	proof := func(challenge, originHash string) error {
		select {
		case slots <- struct{}{}:
			defer func() { <-slots }()
		default:
			return errTicketComputer
		}
		if ctx.Err() != nil {
			return errTicketComputer
		}
		return c.confirmTicketComputer(ctx, credential, agentUUID, challenge, originHash)
	}
	m.mu.Lock()
	m.ticketComputer = proof
	m.mu.Unlock()
	return func() { m.mu.Lock(); m.ticketComputer = nil; m.mu.Unlock() }
}
