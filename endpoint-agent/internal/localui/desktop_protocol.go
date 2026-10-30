package localui

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"io"
	"net"
	"time"

	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/rtc"
)

const MaxDesktopRequestBytes = 720 * 1024
const MaxDesktopResponseBytes = 32 * 1024

func readDesktopRequest(reader io.Reader) (agent.DesktopAction, error) {
	var size uint32
	if binary.Read(reader, binary.LittleEndian, &size) != nil || size == 0 || size > MaxDesktopRequestBytes {
		return agent.DesktopAction{}, errProtocol
	}
	data := make([]byte, size)
	if _, err := io.ReadFull(reader, data); err != nil {
		return agent.DesktopAction{}, errProtocol
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	first, err := decoder.Token()
	if err != nil || first != json.Delim('{') {
		return agent.DesktopAction{}, errProtocol
	}
	fields := map[string]bool{}
	for decoder.More() {
		key, err := decoder.Token()
		name, ok := key.(string)
		if err != nil || !ok || fields[name] {
			return agent.DesktopAction{}, errProtocol
		}
		fields[name] = true
		var value json.RawMessage
		if decoder.Decode(&value) != nil || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return agent.DesktopAction{}, errProtocol
		}
	}
	if end, err := decoder.Token(); err != nil || end != json.Delim('}') {
		return agent.DesktopAction{}, errProtocol
	}
	if _, err = decoder.Token(); err != io.EOF {
		return agent.DesktopAction{}, errProtocol
	}
	var request agent.DesktopAction
	if json.Unmarshal(data, &request) != nil || request.Version != 1 || !localUUID(request.SessionUUID) {
		return agent.DesktopAction{}, errProtocol
	}
	required := []string{"version", "operation", "session_uuid"}
	switch request.Operation {
	case "desktop_open":
	case "desktop_poll":
		required = append(required, "worker_token")
	case "desktop_frame":
		required = append(required, "worker_token", "sequence", "width", "height", "jpeg")
		if request.Sequence == 0 || request.Sequence > 9007199254740991 || request.Width < 1 || request.Height < 1 || request.Width > 4096 || request.Height > 4096 ||
			request.Width*request.Height > 8*1024*1024 || len(request.JPEG) == 0 || len(request.JPEG) > rtc.MaxFrame {
			return agent.DesktopAction{}, errProtocol
		}
	default:
		return agent.DesktopAction{}, errProtocol
	}
	if len(fields) != len(required) {
		return agent.DesktopAction{}, errProtocol
	}
	for _, name := range required {
		if !fields[name] {
			return agent.DesktopAction{}, errProtocol
		}
	}
	if request.Operation != "desktop_open" {
		if len(request.WorkerToken) != 64 {
			return agent.DesktopAction{}, errProtocol
		}
		for _, c := range request.WorkerToken {
			if (c < '0' || c > '9') && (c < 'a' || c > 'f') {
				return agent.DesktopAction{}, errProtocol
			}
		}
	}
	return request, nil
}
func serveDesktopConnection(conn net.Conn, authorize func(net.Conn) (identity, error), desktop func(agent.RemotePeer, agent.DesktopAction) (agent.DesktopView, error)) {
	defer conn.Close()
	if conn.SetDeadline(time.Now().Add(2*time.Second)) != nil {
		return
	}
	request, err := readDesktopRequest(conn)
	if err != nil {
		return
	}
	who, err := authorize(conn)
	if err != nil || who.SID == "" || who.LogonSID == "" || who.SessionID == 0 {
		return
	}
	response, err := desktop(agent.RemotePeer{SID: who.SID, LogonSID: who.LogonSID, SessionID: who.SessionID}, request)
	if err != nil {
		return
	}
	data, err := json.Marshal(response)
	if err != nil || len(data) > MaxDesktopResponseBytes {
		return
	}
	output := make([]byte, 4+len(data))
	binary.LittleEndian.PutUint32(output, uint32(len(data)))
	copy(output[4:], data)
	_, _ = io.Copy(conn, bytes.NewReader(output))
}
