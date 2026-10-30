package cctv

import (
	"bufio"
	"bytes"
	"context"
	"crypto/md5"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

// This transport is not a source selector or an enrolled-provider grant. The
// future Hikvision strategy must bind every field to its verified, signed source.
// The distinct Hikvision live grant binds the endpoint and selected channel; central activation remains gated by witnessed support.
type httpsTunnelSource struct {
	IP, ServerName, Pin, Path, Username, Password string
	Port                                          int
}

var tunnelChannelPath = regexp.MustCompile(`^/(ISAPI/)?Streaming/channels/[1-9][0-9]{0,8}$`)
var tunnelServerName = regexp.MustCompile(`^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$`)

func tunnelPrintable(s string, minimum, maximum int) bool {
	if len(s) < minimum || len(s) > maximum {
		return false
	}
	for _, b := range []byte(s) {
		if b < 32 || b > 126 {
			return false
		}
	}
	return true
}

func (s httpsTunnelSource) validEndpoint() bool {
	ip := net.ParseIP(s.IP)
	return ip != nil && !ip.IsUnspecified() && !ip.IsMulticast() && s.Port > 0 && s.Port <= 65535 &&
		(net.ParseIP(s.ServerName) != nil || tunnelServerName.MatchString(s.ServerName)) &&
		digest.MatchString(s.Pin) &&
		tunnelPrintable(s.Username, 1, 128) && tunnelPrintable(s.Password, 1, 1024)
}

func (s httpsTunnelSource) valid() bool {
	return s.validEndpoint() && tunnelChannelPath.MatchString(s.Path)
}

type httpDigestChallenge struct{ realm, nonce, opaque, algorithm string }

// Reject duplicate fields and controls rather than interpreting an ambiguous
// challenge. All input has already passed the bounded HTTP-header reader.
func digestParameters(value string) (map[string]string, error) {
	fields := map[string]string{}
	for len(value) > 0 {
		value = strings.TrimLeft(value, " \t")
		split := strings.IndexByte(value, '=')
		if split < 1 {
			return nil, ErrProtocol
		}
		key := strings.ToLower(strings.TrimSpace(value[:split]))
		if len(key) == 0 || len(key) > 32 {
			return nil, ErrProtocol
		}
		for _, c := range key {
			if c < 'a' || c > 'z' {
				return nil, ErrProtocol
			}
		}
		if _, exists := fields[key]; exists {
			return nil, ErrProtocol
		}
		value = strings.TrimLeft(value[split+1:], " \t")
		var parsed strings.Builder
		if strings.HasPrefix(value, `"`) {
			value = value[1:]
			closed := false
			for len(value) > 0 {
				c := value[0]
				value = value[1:]
				if c == '"' {
					closed = true
					break
				}
				if c == '\\' {
					if len(value) == 0 {
						return nil, ErrProtocol
					}
					c = value[0]
					value = value[1:]
				}
				if c < 32 || c > 126 {
					return nil, ErrProtocol
				}
				parsed.WriteByte(c)
			}
			if !closed {
				return nil, ErrProtocol
			}
		} else {
			end := strings.IndexByte(value, ',')
			if end < 0 {
				end = len(value)
			}
			parsed.WriteString(strings.TrimSpace(value[:end]))
			value = value[end:]
		}
		if !tunnelPrintable(parsed.String(), 0, 1024) {
			return nil, ErrProtocol
		}
		fields[key] = parsed.String()
		value = strings.TrimLeft(value, " \t")
		if value != "" {
			if value[0] != ',' {
				return nil, ErrProtocol
			}
			value = value[1:]
			if strings.TrimSpace(value) == "" {
				return nil, ErrProtocol
			}
		}
		if len(fields) > 12 {
			return nil, ErrProtocol
		}
	}
	return fields, nil
}

func selectHTTPDigest(values []string) (httpDigestChallenge, error) {
	var selected httpDigestChallenge
	if len(values) == 0 || len(values) > 4 {
		return selected, ErrProtocol
	}
	for _, value := range values {
		method, parameters, found := strings.Cut(value, " ")
		if !found || !strings.EqualFold(method, "Digest") {
			continue
		}
		fields, err := digestParameters(parameters)
		if err != nil {
			return selected, ErrProtocol
		}
		algorithm := strings.ToUpper(fields["algorithm"])
		if algorithm == "" {
			algorithm = "MD5"
		}
		if algorithm != "MD5" && algorithm != "SHA-256" {
			continue
		}
		hasAuth := false
		for _, qop := range strings.Split(fields["qop"], ",") {
			if strings.TrimSpace(qop) == "auth" {
				hasAuth = true
			}
		}
		if fields["realm"] == "" || fields["nonce"] == "" || !hasAuth ||
			fields["charset"] != "" && !strings.EqualFold(fields["charset"], "UTF-8") ||
			fields["userhash"] != "" && !strings.EqualFold(fields["userhash"], "false") {
			continue
		}
		if selected.algorithm == "" || algorithm == "SHA-256" && selected.algorithm != "SHA-256" {
			selected = httpDigestChallenge{fields["realm"], fields["nonce"], fields["opaque"], algorithm}
		}
	}
	if selected.algorithm == "" {
		return selected, ErrProtocol
	}
	return selected, nil
}

func httpDigestHash(algorithm, value string) string {
	if algorithm == "SHA-256" {
		sum := sha256.Sum256([]byte(value))
		return hex.EncodeToString(sum[:])
	}
	sum := md5.Sum([]byte(value))
	return hex.EncodeToString(sum[:])
}

func httpDigestAuthorization(c httpDigestChallenge, source httpsTunnelSource, method, nonceCount, cnonce string) string {
	a1 := httpDigestHash(c.algorithm, source.Username+":"+c.realm+":"+source.Password)
	a2 := httpDigestHash(c.algorithm, method+":"+source.Path)
	response := httpDigestHash(c.algorithm, a1+":"+c.nonce+":"+nonceCount+":"+cnonce+":auth:"+a2)
	value := "Digest username=" + strconv.Quote(source.Username) + ", realm=" + strconv.Quote(c.realm) + ", nonce=" + strconv.Quote(c.nonce) +
		", uri=" + strconv.Quote(source.Path) + ", algorithm=" + c.algorithm + ", response=" + strconv.Quote(response) +
		", qop=auth, nc=" + nonceCount + ", cnonce=" + strconv.Quote(cnonce)
	if c.opaque != "" {
		value += ", opaque=" + strconv.Quote(c.opaque)
	}
	return value
}

// HTTP wrapper headers are at most 8 KiB, with each line at most 4 KiB.
// Never drain an untrusted challenge body or feed wrapper bytes into RTSP.
func readTunnelResponse(reader *bufio.Reader) (*http.Response, error) {
	var header bytes.Buffer
	for {
		line, err := reader.ReadSlice('\n')
		if err != nil || !bytes.HasSuffix(line, []byte("\r\n")) || header.Len()+len(line) > 8192 {
			return nil, ErrProtocol
		}
		header.Write(line)
		if bytes.Equal(line, []byte("\r\n")) {
			break
		}
	}
	response, err := http.ReadResponse(bufio.NewReader(bytes.NewReader(header.Bytes())), nil)
	if err != nil || response.Proto != "HTTP/1.1" {
		return nil, ErrProtocol
	}
	// This raw tunnel profile has no chunked/body-framing decoder.
	if len(response.TransferEncoding) != 0 {
		return nil, ErrProtocol
	}
	return response, nil
}

type authenticatedHTTPSRTSPTunnel struct {
	receive, send net.Conn
	reader        *bufio.Reader
	writeMu       sync.Mutex
	sent          int
	once          sync.Once
	cancel        context.CancelFunc
	lifetime      context.Context
}

func (t *authenticatedHTTPSRTSPTunnel) Read(p []byte) (int, error) {
	if t.lifetime.Err() != nil {
		return 0, ErrProtocol
	}
	n, err := t.reader.Read(p)
	if t.lifetime.Err() != nil {
		clear(p[:n])
		return 0, ErrProtocol
	}
	return n, err
}
func (t *authenticatedHTTPSRTSPTunnel) Write(p []byte) (int, error) {
	t.writeMu.Lock()
	defer t.writeMu.Unlock()
	if t.lifetime.Err() != nil || len(p) > MaxFrame {
		return 0, ErrProtocol
	}
	encoded := []byte(base64.StdEncoding.EncodeToString(p))
	if t.sent+len(encoded) > 30000 {
		_ = t.Close()
		return 0, ErrProtocol
	}
	n, err := t.send.Write(encoded)
	if n != len(encoded) {
		if err == nil {
			err = io.ErrShortWrite
		}
		_ = t.Close()
		return 0, err
	}
	t.sent += n
	return len(p), err
}
func (t *authenticatedHTTPSRTSPTunnel) Close() error {
	t.once.Do(func() { t.cancel(); _ = t.receive.Close(); _ = t.send.Close() })
	return nil
}
func (t *authenticatedHTTPSRTSPTunnel) LocalAddr() net.Addr  { return t.receive.LocalAddr() }
func (t *authenticatedHTTPSRTSPTunnel) RemoteAddr() net.Addr { return t.receive.RemoteAddr() }
func (t *authenticatedHTTPSRTSPTunnel) SetDeadline(at time.Time) error {
	if err := t.receive.SetDeadline(at); err != nil {
		return err
	}
	return t.send.SetDeadline(at)
}
func (t *authenticatedHTTPSRTSPTunnel) SetReadDeadline(at time.Time) error {
	return t.receive.SetReadDeadline(at)
}
func (t *authenticatedHTTPSRTSPTunnel) SetWriteDeadline(at time.Time) error {
	return t.send.SetWriteDeadline(at)
}

// dialContext is only for establishment; lifetimeContext owns the media session.
// gortsplib cancels its dial context after connecting, so it cannot own playback.
func openAuthenticatedHTTPSRTSPTunnel(dialContext, lifetimeContext context.Context, source httpsTunnelSource, roots *x509.CertPool) (net.Conn, error) {
	if !source.valid() || dialContext.Err() != nil || lifetimeContext.Err() != nil {
		return nil, ErrProtocol
	}
	ctx, cancel := context.WithCancel(lifetimeContext)
	openingContext, cancelOpening := context.WithTimeout(ctx, 3*time.Second)
	defer cancelOpening()
	stopDialCancellation := context.AfterFunc(dialContext, cancel)
	success := false
	defer func() {
		stopDialCancellation()
		if !success {
			cancel()
		}
	}()
	var random [32]byte
	if _, err := rand.Read(random[:]); err != nil {
		return nil, ErrProtocol
	}
	cookie, cnonce := hex.EncodeToString(random[:16]), hex.EncodeToString(random[16:])
	host := net.JoinHostPort(source.ServerName, strconv.Itoa(source.Port))
	type nonceIdentity struct{ algorithm, realm, nonce string }
	nonceCounts := map[nonceIdentity]uint32{}
	tlsConfig := &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots, ServerName: source.ServerName, VerifyConnection: func(state tls.ConnectionState) error {
		if len(state.PeerCertificates) == 0 {
			return ErrProtocol
		}
		pin := sha256.Sum256(state.PeerCertificates[0].Raw)
		if hex.EncodeToString(pin[:]) != source.Pin {
			return ErrProtocol
		}
		return nil
	}}
	establish := func(method string) (net.Conn, *bufio.Reader, error) {
		var challenge httpDigestChallenge
		for attempt := 0; attempt < 2; attempt++ {
			if openingContext.Err() != nil {
				return nil, nil, ErrProtocol
			}
			conn, err := (&tls.Dialer{NetDialer: &net.Dialer{Timeout: 3 * time.Second}, Config: tlsConfig}).DialContext(openingContext, "tcp", net.JoinHostPort(source.IP, strconv.Itoa(source.Port)))
			if err != nil {
				return nil, nil, ErrProtocol
			}
			stop := context.AfterFunc(ctx, func() { _ = conn.Close() })
			deadline, _ := openingContext.Deadline()
			if at, ok := dialContext.Deadline(); ok && at.Before(deadline) {
				deadline = at
			}
			if err = conn.SetDeadline(deadline); err != nil {
				stop()
				_ = conn.Close()
				return nil, nil, ErrProtocol
			}
			request := method + " " + source.Path + " HTTP/1.1\r\nHost: " + host + "\r\nX-Sessioncookie: " + cookie + "\r\n"
			if method == "GET" {
				request += "Accept: application/x-rtsp-tunnelled\r\n"
			} else {
				request += "Content-Type: application/x-rtsp-tunnelled\r\nContent-Length: 30000\r\n"
			}
			if attempt > 0 {
				key := nonceIdentity{challenge.algorithm, challenge.realm, challenge.nonce}
				nonceCounts[key]++
				request += "Authorization: " + httpDigestAuthorization(challenge, source, method, fmt.Sprintf("%08x", nonceCounts[key]), cnonce) + "\r\n"
			}
			request += "\r\n"
			written, err := io.WriteString(conn, request)
			if err != nil || written != len(request) {
				stop()
				_ = conn.Close()
				return nil, nil, ErrProtocol
			}
			reader := bufio.NewReaderSize(conn, 4096)
			response, err := readTunnelResponse(reader)
			if err != nil {
				stop()
				_ = conn.Close()
				return nil, nil, ErrProtocol
			}
			if response.StatusCode == http.StatusOK {
				// Authenticated wrapper confirmation is required for both connections.
				// An unchallenged endpoint or a POST without a response is unsupported.
				valid := attempt == 1 && response.ContentLength <= 0 &&
					(method != "GET" || strings.EqualFold(response.Header.Get("Content-Type"), "application/x-rtsp-tunnelled")) &&
					(method != "POST" || reader.Buffered() == 0)
				if !valid || conn.SetDeadline(time.Time{}) != nil {
					stop()
					_ = conn.Close()
					return nil, nil, ErrProtocol
				}
				stop()
				return conn, reader, nil
			}
			stop()
			_ = conn.Close()
			if response.StatusCode != http.StatusUnauthorized || attempt != 0 {
				return nil, nil, ErrProtocol
			}
			challenge, err = selectHTTPDigest(response.Header.Values("WWW-Authenticate"))
			if err != nil {
				return nil, nil, ErrProtocol
			}
		}
		return nil, nil, ErrProtocol
	}
	receive, reader, err := establish("GET")
	if err != nil {
		return nil, ErrProtocol
	}
	stopReceive := context.AfterFunc(ctx, func() { _ = receive.Close() })
	send, _, err := establish("POST")
	if err != nil {
		stopReceive()
		_ = receive.Close()
		return nil, ErrProtocol
	}
	tunnel := &authenticatedHTTPSRTSPTunnel{receive: receive, send: send, reader: reader, cancel: cancel, lifetime: ctx}
	context.AfterFunc(ctx, func() { _ = tunnel.Close() })
	stopReceive()
	if !stopDialCancellation() || openingContext.Err() != nil || dialContext.Err() != nil {
		_ = tunnel.Close()
		return nil, ErrProtocol
	}
	success = true
	return tunnel, nil
}
