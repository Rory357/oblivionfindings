// Package localui exposes only approved helper operations to a Windows session.
// It is not a remote command endpoint, shell or general-purpose state reader.
package localui

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"regexp"
	"time"

	"oblivion.local/endpoint-agent/internal/agent"
)

const MaxRequestBytes = 512
const MaxResponseBytes = 4096

var localUUID = regexp.MustCompile("^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$").MatchString

var localHex = regexp.MustCompile("^[a-f0-9]{64}$").MatchString
var errProtocol = errors.New("invalid local helper request")

type request struct {
	AttemptUUID        string `json:"attempt_uuid,omitempty"`
	PayloadHash        string `json:"payload_hash,omitempty"`
	Version            int    `json:"version"`
	Operation          string `json:"operation"`
	SessionUUID        string `json:"session_uuid,omitempty"`
	ConsentToken       string `json:"consent_token,omitempty"`
	Decision           string `json:"decision,omitempty"`
	Challenge          string `json:"challenge,omitempty"`
	ExpectedOriginHash string `json:"expected_origin_hash,omitempty"`
}
type identity struct {
	SID, LogonSID string
	SessionID     uint32
}

func readRequest(reader io.Reader) (request, error) {
	var size uint32
	if binary.Read(reader, binary.LittleEndian, &size) != nil || size == 0 || size > MaxRequestBytes {
		return request{}, errProtocol
	}
	data := make([]byte, size)
	if _, err := io.ReadFull(reader, data); err != nil {
		return request{}, errProtocol
	}
	// Decode fields independently to reject duplicates and unknown names.
	decoder := json.NewDecoder(bytes.NewReader(data))
	token, err := decoder.Token()
	if err != nil || token != json.Delim('{') {
		return request{}, errProtocol
	}
	fields := map[string]json.RawMessage{}
	for decoder.More() {
		key, err := decoder.Token()
		name, ok := key.(string)
		if err != nil || !ok || (name != "version" && name != "operation" && name != "session_uuid" && name != "consent_token" && name != "decision" && name != "challenge" && name != "expected_origin_hash" && name != "attempt_uuid" && name != "payload_hash") || fields[name] != nil {
			return request{}, errProtocol
		}
		var value json.RawMessage
		if decoder.Decode(&value) != nil || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return request{}, errProtocol
		}
		fields[name] = value
	}
	if token, err = decoder.Token(); err != nil || token != json.Delim('}') {
		return request{}, errProtocol
	}
	if _, err = decoder.Token(); err != io.EOF {
		return request{}, errProtocol
	}
	var r request
	if json.Unmarshal(data, &r) != nil || r.Version != 1 {
		return request{}, errProtocol
	}
	expected := 2
	switch r.Operation {
	case "status", "diagnostics", "remote_poll":
	case "service_action_poll", "restart_poll", "software_poll":
		expected = 3
		if !localHex(r.ExpectedOriginHash) {
			return request{}, errProtocol
		}
	case "service_action_decide", "restart_decide", "restart_visible", "software_decide":
		expected = 7
		if !localHex(r.ExpectedOriginHash) || !localUUID(r.AttemptUUID) || !localHex(r.PayloadHash) || !localHex(r.ConsentToken) || (!validActionDecision(r.Operation, r.Decision)) || r.SessionUUID != "" {
			return request{}, errProtocol
		}
	case "confirm_ticket_computer":
		expected = 4
		if !localHex(r.Challenge) || !localHex(r.ExpectedOriginHash) || r.SessionUUID != "" || r.ConsentToken != "" || r.Decision != "" {
			return request{}, errProtocol
		}
	case "remote_stop":
		expected = 3
		if !localUUID(r.SessionUUID) {
			return request{}, errProtocol
		}
	case "remote_decide":
		expected = 5
		if !localUUID(r.SessionUUID) || len(r.ConsentToken) != 64 || (r.Decision != "allow" && r.Decision != "decline") {
			return request{}, errProtocol
		}
	default:
		return request{}, errProtocol
	}
	if !isActionOperation(r.Operation) && (r.AttemptUUID != "" || r.PayloadHash != "") {
		return request{}, errProtocol
	}
	if r.Operation != "confirm_ticket_computer" && !isActionOperation(r.Operation) && (r.Challenge != "" || r.ExpectedOriginHash != "") {
		return request{}, errProtocol
	}
	if isActionOperation(r.Operation) && r.Challenge != "" {
		return request{}, errProtocol
	}
	if len(fields) != expected {
		return request{}, errProtocol
	}
	if expected == 2 && (r.SessionUUID != "" || r.ConsentToken != "" || r.Decision != "") {
		return request{}, errProtocol
	}
	if expected == 3 && (r.ConsentToken != "" || r.Decision != "") {
		return request{}, errProtocol
	}

	return r, nil
}
func serveConnection(conn net.Conn, authorize func(net.Conn) (identity, error), snapshot func() agent.UIStatus, diagnostics ...func() agent.DiagnosticEnvelope) {
	serveHelperConnection(conn, authorize, snapshot, nil, diagnostics...)
}
func serveHelperConnection(conn net.Conn, authorize func(net.Conn) (identity, error), snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), diagnostics ...func() agent.DiagnosticEnvelope) {
	serveHelperConnectionWithProof(conn, authorize, snapshot, remote, nil, diagnostics...)
}
func serveHelperConnectionWithProof(conn net.Conn, authorize func(net.Conn) (identity, error), snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), proof func(string, string) agent.TicketComputerView, diagnostics ...func() agent.DiagnosticEnvelope) {
	serveHelperConnectionWithActions(conn, authorize, snapshot, remote, proof, nil, diagnostics...)
}
func serveHelperConnectionWithActions(conn net.Conn, authorize func(net.Conn) (identity, error), snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), proof func(string, string) agent.TicketComputerView, actions func(agent.RemotePeer, agent.ServiceActionDecision) (agent.ServiceActionView, error), diagnostics ...func() agent.DiagnosticEnvelope) {
	serveHelperConnectionWithRestarts(conn, authorize, snapshot, remote, proof, actions, nil, diagnostics...)
}
func serveHelperConnectionWithRestarts(conn net.Conn, authorize func(net.Conn) (identity, error), snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), proof func(string, string) agent.TicketComputerView, actions func(agent.RemotePeer, agent.ServiceActionDecision) (agent.ServiceActionView, error), restarts func(agent.RemotePeer, agent.RestartDecision) (agent.RestartView, error), diagnostics ...func() agent.DiagnosticEnvelope) {
	serveHelperConnectionWithSoftware(conn, authorize, snapshot, remote, proof, actions, restarts, nil, diagnostics...)
}
func serveHelperConnectionWithSoftware(conn net.Conn, authorize func(net.Conn) (identity, error), snapshot func() agent.UIStatus, remote func(agent.RemotePeer, agent.RemoteAction) (agent.RemoteView, error), proof func(string, string) agent.TicketComputerView, actions func(agent.RemotePeer, agent.ServiceActionDecision) (agent.ServiceActionView, error), restarts func(agent.RemotePeer, agent.RestartDecision) (agent.RestartView, error), software func(agent.RemotePeer, agent.SoftwareDecision) (agent.SoftwareView, error), diagnostics ...func() agent.DiagnosticEnvelope) {
	defer conn.Close()
	if conn.SetDeadline(time.Now().Add(4*time.Second)) != nil {
		return
	}
	request, err := readRequest(conn)
	if err != nil {
		return
	}
	who, err := authorize(conn)
	if err != nil || who.SID == "" || who.LogonSID == "" || who.SessionID == 0 {
		return
	}
	var body any
	if request.Operation == "software_poll" || request.Operation == "software_decide" {
		if software == nil {
			return
		}
		body, err = software(agent.RemotePeer{SID: who.SID, LogonSID: who.LogonSID, SessionID: who.SessionID}, agent.SoftwareDecision{Operation: request.Operation, OriginHash: request.ExpectedOriginHash, AttemptUUID: request.AttemptUUID, PayloadHash: request.PayloadHash, ConsentToken: request.ConsentToken, Decision: request.Decision})
		if err != nil {
			return
		}
	} else if request.Operation == "restart_poll" || request.Operation == "restart_visible" || request.Operation == "restart_decide" {
		if restarts == nil {
			return
		}
		body, err = restarts(agent.RemotePeer{SID: who.SID, LogonSID: who.LogonSID, SessionID: who.SessionID}, agent.RestartDecision{Operation: request.Operation, OriginHash: request.ExpectedOriginHash, AttemptUUID: request.AttemptUUID, PayloadHash: request.PayloadHash, ConsentToken: request.ConsentToken, Decision: request.Decision})
		if err != nil {
			return
		}
	} else if isActionOperation(request.Operation) {
		if actions == nil {
			return
		}
		body, err = actions(agent.RemotePeer{SID: who.SID, LogonSID: who.LogonSID, SessionID: who.SessionID}, agent.ServiceActionDecision{Operation: request.Operation, OriginHash: request.ExpectedOriginHash, AttemptUUID: request.AttemptUUID, PayloadHash: request.PayloadHash, ConsentToken: request.ConsentToken, Decision: request.Decision})
		if err != nil {
			return
		}
	} else if request.Operation == "confirm_ticket_computer" {
		if proof == nil || conn.SetDeadline(time.Now().Add(6*time.Second)) != nil {
			return
		}
		body = proof(request.Challenge, request.ExpectedOriginHash)
	} else if request.Operation == "remote_poll" || request.Operation == "remote_decide" || request.Operation == "remote_stop" {
		if remote == nil {
			return
		}
		body, err = remote(agent.RemotePeer{SID: who.SID, LogonSID: who.LogonSID, SessionID: who.SessionID}, agent.RemoteAction{Operation: request.Operation, SessionUUID: request.SessionUUID, ConsentToken: request.ConsentToken, Decision: request.Decision})
		if err != nil {
			return
		}
	} else if request.Operation == "diagnostics" {
		body = agent.DiagnosticEnvelope{Protocol: 1}
		if len(diagnostics) == 1 {
			body = diagnostics[0]()
		}
	} else {
		body = snapshot()
	}
	data, err := json.Marshal(body)
	responseLimit := MaxResponseBytes
	if request.Operation == "software_poll" || request.Operation == "software_decide" {
		responseLimit = 32768
	} else if isActionOperation(request.Operation) {
		responseLimit = 16384
	}
	if err != nil || len(data) > responseLimit {
		return
	}
	frame := make([]byte, 4+len(data))
	binary.LittleEndian.PutUint32(frame, uint32(len(data)))
	copy(frame[4:], data)
	// io.Copy handles short writes without appending unsanitized error information.
	_, _ = io.Copy(conn, bytes.NewReader(frame))
}

func isActionOperation(op string) bool {
	return op == "software_poll" || op == "software_decide" || op == "service_action_poll" || op == "service_action_decide" || op == "restart_poll" || op == "restart_visible" || op == "restart_decide"
}
func validActionDecision(op, decision string) bool {
	if op == "restart_visible" {
		return decision == "visible" || decision == "hidden"
	}
	if op == "restart_decide" {
		return decision == "allow" || decision == "decline" || decision == "cancel"
	}
	return decision == "allow" || decision == "decline"
}
