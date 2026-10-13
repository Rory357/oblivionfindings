package cctv

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/xml"
	"io"
	"mime"
	"net"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode"
)

// Internal read-only observations, not an enrolled source, transport-support
// declaration, canonical camera association or readiness grant.
const hikvisionXMLLimit = 262144

var hikvisionIdentifier = regexp.MustCompile(`^[1-9][0-9]{0,8}$`)

type hikvisionIdentity struct {
	Model         string `json:"model"`
	Serial        string `json:"serial"`
	DeviceID      string `json:"device_id"`
	Firmware      string `json:"firmware"`
	FirmwareBuild string `json:"firmware_build"`
}

type hikvisionChannel struct {
	ID           string `json:"id"`
	InputID      string `json:"input_id"`
	Name         string `json:"name"`
	Codec        string `json:"codec"`
	Enabled      bool   `json:"enabled"`
	VideoEnabled bool   `json:"video_enabled"`
	Width        int    `json:"width"`
	Height       int    `json:"height"`
	FPS100       int    `json:"fps100"`
	AudioEnabled *bool  `json:"audio_enabled"`
}

type hikvisionInventory struct {
	Identity hikvisionIdentity  `json:"identity"`
	Channels []hikvisionChannel `json:"channels"`
}

func readHikvisionISAPI(parent context.Context, source httpsTunnelSource, roots *x509.CertPool, resource string) ([]byte, error) {
	if resource != "/ISAPI/System/deviceInfo" && resource != "/ISAPI/Streaming/channels" {
		return nil, ErrProtocol
	}
	return readHikvisionFixedISAPI(parent, source, roots, resource)
}

// Each strategy owns a narrower allowlist. This shared transport still accepts
// only fixed, read-only resources on the original verified TLS authority.
func readHikvisionFixedISAPI(parent context.Context, source httpsTunnelSource, roots *x509.CertPool, resource string) ([]byte, error) {
	allowed := resource == "/ISAPI/System/deviceInfo" || resource == "/ISAPI/Streaming/channels" || resource == "/ISAPI/ContentMgmt/StreamingProxy/channels" || resource == "/ISAPI/ContentMgmt/InputProxy/channels/status"
	if !source.validEndpoint() || !allowed || parent.Err() != nil {
		return nil, ErrProtocol
	}
	ctx, cancel := context.WithTimeout(parent, 3*time.Second)
	defer cancel()
	host := net.JoinHostPort(source.ServerName, strconv.Itoa(source.Port))
	config := &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots, ServerName: source.ServerName, NextProtos: []string{"http/1.1"}, VerifyConnection: func(state tls.ConnectionState) error {
		if len(state.PeerCertificates) == 0 {
			return ErrProtocol
		}
		pin := sha256.Sum256(state.PeerCertificates[0].Raw)
		if hex.EncodeToString(pin[:]) != source.Pin {
			return ErrProtocol
		}
		return nil
	}}
	transport := &http.Transport{DisableCompression: true, DisableKeepAlives: true, MaxConnsPerHost: 1, MaxResponseHeaderBytes: 8192, TLSNextProto: map[string]func(string, *tls.Conn) http.RoundTripper{}, DialTLSContext: func(ctx context.Context, network, address string) (net.Conn, error) {
		if network != "tcp" || address != host {
			return nil, ErrProtocol
		}
		return (&tls.Dialer{NetDialer: &net.Dialer{Timeout: 3 * time.Second}, Config: config}).DialContext(ctx, "tcp", net.JoinHostPort(source.IP, strconv.Itoa(source.Port)))
	}}
	defer transport.CloseIdleConnections()
	client := &http.Client{Transport: transport, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	var challenge httpDigestChallenge
	var random [16]byte
	if _, err := rand.Read(random[:]); err != nil {
		return nil, ErrProtocol
	}
	requestSource := source
	requestSource.Path = resource
	for attempt := 0; attempt < 2; attempt++ {
		request, err := http.NewRequestWithContext(ctx, http.MethodGet, "https://"+host+resource, nil)
		if err != nil {
			return nil, ErrProtocol
		}
		request.Header.Set("Accept", "application/xml, text/xml")
		if attempt == 1 {
			request.Header.Set("Authorization", httpDigestAuthorization(challenge, requestSource, "GET", "00000001", hex.EncodeToString(random[:])))
		}
		response, err := client.Do(request)
		if err != nil {
			return nil, ErrProtocol
		}
		if response.StatusCode == http.StatusUnauthorized && attempt == 0 {
			challenge, err = selectHTTPDigest(response.Header.Values("WWW-Authenticate"))
			_ = response.Body.Close() // never consume an untrusted challenge body.
			if err != nil {
				return nil, ErrProtocol
			}
			continue
		}
		contentType, parameters, typeErr := mime.ParseMediaType(response.Header.Get("Content-Type"))
		if attempt != 1 || response.StatusCode != http.StatusOK || response.Proto != "HTTP/1.1" || typeErr != nil ||
			(contentType != "application/xml" && contentType != "text/xml") ||
			(parameters["charset"] != "" && !strings.EqualFold(parameters["charset"], "utf-8")) ||
			response.Header.Get("Content-Encoding") != "" || response.ContentLength > hikvisionXMLLimit {
			_ = response.Body.Close()
			return nil, ErrProtocol
		}
		body, err := io.ReadAll(io.LimitReader(response.Body, hikvisionXMLLimit+1))
		_ = response.Body.Close()
		if err != nil || len(body) == 0 || len(body) > hikvisionXMLLimit || ctx.Err() != nil {
			return nil, ErrProtocol
		}
		return body, nil
	}
	return nil, ErrProtocol
}

type hikvisionXMLNode struct {
	name     string
	text     strings.Builder
	children []*hikvisionXMLNode
	version  string
}

func parseHikvisionXML(body []byte, expected string) (*hikvisionXMLNode, error) {
	if len(body) == 0 || len(body) > hikvisionXMLLimit {
		return nil, ErrProtocol
	}
	decoder := xml.NewDecoder(bytes.NewReader(body)) // strict UTF-8; no entity resolver.
	var root *hikvisionXMLNode
	var stack []*hikvisionXMLNode
	elements, declaration := 0, false
	namespace := ""
	for {
		token, err := decoder.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			return nil, ErrProtocol
		}
		switch value := token.(type) {
		case xml.Directive:
			return nil, ErrProtocol // DTD/internal/external entity declarations denied.
		case xml.ProcInst:
			if value.Target != "xml" || declaration || root != nil {
				return nil, ErrProtocol
			}
			declaration = true
		case xml.StartElement:
			elements++
			if elements > 8192 || len(stack) >= 16 || len(value.Attr) > 8 {
				return nil, ErrProtocol
			}
			if root == nil {
				namespace = value.Name.Space
				if value.Name.Local != expected || (namespace != "http://www.hikvision.com/ver20/XMLSchema" && namespace != "http://www.isapi.org/ver20/XMLSchema") {
					return nil, ErrProtocol
				}
			} else if len(stack) == 0 {
				return nil, ErrProtocol // exactly one complete XML document.
			}
			if value.Name.Space != namespace {
				return nil, ErrProtocol
			}
			node := &hikvisionXMLNode{name: value.Name.Local}
			attributes := map[xml.Name]bool{}
			for _, attribute := range value.Attr {
				if attributes[attribute.Name] || len(attribute.Value) > 1024 {
					return nil, ErrProtocol
				}
				attributes[attribute.Name] = true
				if attribute.Name.Space == "" && attribute.Name.Local == "version" {
					node.version = attribute.Value
				}
			}
			if root == nil {
				root = node
				if root.version != "2.0" {
					return nil, ErrProtocol
				}
			} else {
				parent := stack[len(stack)-1]
				parent.children = append(parent.children, node)
			}
			stack = append(stack, node)
		case xml.CharData:
			if len(stack) == 0 {
				if strings.TrimSpace(string(value)) != "" {
					return nil, ErrProtocol
				}
			} else {
				node := stack[len(stack)-1]
				if node.text.Len()+len(value) > 4096 {
					return nil, ErrProtocol
				}
				node.text.Write(value)
			}
		case xml.EndElement:
			stack = stack[:len(stack)-1]
		}
	}
	if root == nil || len(stack) != 0 {
		return nil, ErrProtocol
	}
	return root, nil
}

func (n *hikvisionXMLNode) one(name string, required bool) (*hikvisionXMLNode, error) {
	var selected *hikvisionXMLNode
	for _, child := range n.children {
		if child.name == name {
			if selected != nil {
				return nil, ErrProtocol
			}
			selected = child
		}
	}
	if required && selected == nil {
		return nil, ErrProtocol
	}
	return selected, nil
}

func (n *hikvisionXMLNode) scalar(name string, required bool, maximum int) (string, error) {
	child, err := n.one(name, required)
	if err != nil || child == nil {
		return "", err
	}
	value := strings.TrimSpace(child.text.String())
	if len(child.children) != 0 || len(value) > maximum || required && value == "" {
		return "", ErrProtocol
	}
	for _, char := range value {
		if unicode.IsControl(char) {
			return "", ErrProtocol
		}
	}
	return value, nil
}

func (n *hikvisionXMLNode) boolean(name string, required bool) (*bool, error) {
	value, err := n.scalar(name, required, 5)
	if err != nil || !required && value == "" {
		return nil, err
	}
	if value != "true" && value != "false" && value != "1" && value != "0" {
		return nil, ErrProtocol
	}
	result := value == "true" || value == "1"
	return &result, nil
}

func (n *hikvisionXMLNode) number(name string, maximum int) (int, error) {
	value, err := n.scalar(name, true, 9)
	if err != nil || value == "" || strings.Trim(value, "0123456789") != "" {
		return 0, ErrProtocol
	}
	number, err := strconv.Atoi(value)
	if err != nil || number > maximum {
		return 0, ErrProtocol
	}
	return number, nil
}

func parseHikvisionIdentity(body []byte) (hikvisionIdentity, error) {
	var result hikvisionIdentity
	root, err := parseHikvisionXML(body, "DeviceInfo")
	if err != nil {
		return result, ErrProtocol
	}
	for _, field := range []struct {
		name string
		to   *string
		req  bool
	}{
		{"model", &result.Model, true}, {"serialNumber", &result.Serial, true},
		{"deviceID", &result.DeviceID, true}, {"firmwareVersion", &result.Firmware, true},
		{"firmwareReleasedDate", &result.FirmwareBuild, false},
	} {
		*field.to, err = root.scalar(field.name, field.req, 128)
		if err != nil {
			return hikvisionIdentity{}, ErrProtocol
		}
	}
	return result, nil
}

func parseHikvisionChannels(body []byte) ([]hikvisionChannel, error) {
	root, err := parseHikvisionXML(body, "StreamingChannelList")
	if err != nil || len(root.children) == 0 || len(root.children) > 256 {
		return nil, ErrProtocol
	}
	seen := map[string]bool{}
	result := make([]hikvisionChannel, 0, len(root.children))
	for _, child := range root.children {
		if child.name != "StreamingChannel" || child.version != "" && child.version != "2.0" {
			return nil, ErrProtocol
		}
		var channel hikvisionChannel
		channel.ID, err = child.scalar("id", true, 9)
		if err != nil || !hikvisionIdentifier.MatchString(channel.ID) || seen[channel.ID] {
			return nil, ErrProtocol
		}
		seen[channel.ID] = true
		channel.Name, err = child.scalar("channelName", false, 128)
		if err != nil {
			return nil, ErrProtocol
		}
		enabled, err := child.boolean("enabled", true)
		if err != nil {
			return nil, ErrProtocol
		}
		channel.Enabled = *enabled
		video, err := child.one("Video", true)
		if err != nil {
			return nil, ErrProtocol
		}
		enabled, err = video.boolean("enabled", true)
		if err != nil {
			return nil, ErrProtocol
		}
		channel.VideoEnabled = *enabled
		channel.InputID, err = video.scalar("videoInputChannelID", false, 9)
		if err != nil {
			return nil, ErrProtocol
		}
		dynamic, err := video.scalar("dynVideoInputChannelID", false, 9)
		if err != nil || channel.InputID != "" && dynamic != "" {
			return nil, ErrProtocol
		}
		if channel.InputID == "" {
			channel.InputID = dynamic
		}
		if !hikvisionIdentifier.MatchString(channel.InputID) {
			return nil, ErrProtocol
		}
		channel.Codec, err = video.scalar("videoCodecType", true, 32)
		if err != nil {
			return nil, ErrProtocol
		}
		channel.Width, err = video.number("videoResolutionWidth", 16384)
		if err != nil || channel.Width == 0 {
			return nil, ErrProtocol
		}
		channel.Height, err = video.number("videoResolutionHeight", 16384)
		if err != nil || channel.Height == 0 {
			return nil, ErrProtocol
		}
		channel.FPS100, err = video.number("maxFrameRate", 12000)
		if err != nil {
			return nil, ErrProtocol
		}
		audio, err := child.one("Audio", false)
		if err != nil {
			return nil, ErrProtocol
		}
		if audio != nil {
			channel.AudioEnabled, err = audio.boolean("enabled", true)
			if err != nil {
				return nil, ErrProtocol
			}
		}
		result = append(result, channel)
	}
	return result, nil
}

func discoverHikvision(parent context.Context, source httpsTunnelSource, roots *x509.CertPool) (hikvisionInventory, error) {
	ctx, cancel := context.WithTimeout(parent, 3*time.Second)
	defer cancel()
	var inventory hikvisionInventory
	body, err := readHikvisionISAPI(ctx, source, roots, "/ISAPI/System/deviceInfo")
	if err != nil {
		return inventory, ErrProtocol
	}
	inventory.Identity, err = parseHikvisionIdentity(body)
	if err != nil {
		return hikvisionInventory{}, ErrProtocol
	}
	body, err = readHikvisionISAPI(ctx, source, roots, "/ISAPI/Streaming/channels")
	if err != nil {
		return hikvisionInventory{}, ErrProtocol
	}
	inventory.Channels, err = parseHikvisionChannels(body)
	if err != nil || ctx.Err() != nil {
		return hikvisionInventory{}, ErrProtocol
	}
	return inventory, nil
}
