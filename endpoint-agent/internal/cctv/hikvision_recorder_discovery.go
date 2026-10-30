package cctv

import (
	"context"
	"crypto/x509"
	"encoding/json"
	"net/netip"
	"regexp"
	"strconv"
)

const hikvisionRecorderDiscoveryOperation = "discover_hikvision_recorder"
const hikvisionRecorderDiscoveryType = "collector_hikvision_recorder_discovery"
const hikvisionRecorderDiscoveryCapability = "cctv:hikvision:recorder:discovery:read_only"
const hikvisionRecorderDiscoveryResourceSet = "recorder_identity_streams_input_status_v2"
const hikvisionRecorderDiscoveryProfile = "hikvision_recorder_isapi_https_v2"

var recorderPortNumber = regexp.MustCompile(`^[0-9]{1,5}$`)
var recorderSourceInputID = regexp.MustCompile(`^(0|[1-9][0-9]{0,8})$`)
var recorderHostname = regexp.MustCompile(`^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$`)

type hikvisionRecorderChannel struct {
	hikvisionChannel
	InputKind string `json:"input_kind"`
}

type hikvisionRecorderInput struct {
	ID             string          `json:"id"`
	ProxyProtocol  string          `json:"proxy_protocol"`
	AddressingType string          `json:"addressing_type"`
	Address        *string         `json:"address"`
	ManagementPort *int            `json:"management_port"`
	SourceInputID  *string         `json:"source_input_id"`
	Online         *bool           `json:"online"`
	CameraIdentity json.RawMessage `json:"camera_identity"`
	Association    string          `json:"association"`
}

type hikvisionRecorderInventory struct {
	Identity       hikvisionIdentity          `json:"identity"`
	Channels       []hikvisionRecorderChannel `json:"channels"`
	Inputs         []hikvisionRecorderInput   `json:"inputs"`
	ResponseSHA256 map[string]string          `json:"response_sha256"`
}

func readHikvisionRecorderISAPI(parent context.Context, source httpsTunnelSource, roots *x509.CertPool, resource string) ([]byte, error) {
	if resource != "/ISAPI/System/deviceInfo" && resource != "/ISAPI/ContentMgmt/StreamingProxy/channels" && resource != "/ISAPI/ContentMgmt/InputProxy/channels/status" {
		return nil, ErrProtocol
	}
	return readHikvisionFixedISAPI(parent, source, roots, resource)
}

func parseHikvisionRecorderChannels(body []byte) ([]hikvisionRecorderChannel, error) {
	channels, err := parseHikvisionChannels(body)
	if err != nil {
		return nil, ErrProtocol
	}
	root, err := parseHikvisionXML(body, "StreamingChannelList")
	if err != nil {
		return nil, ErrProtocol
	}
	result := make([]hikvisionRecorderChannel, 0, len(channels))
	for i, child := range root.children {
		video, err := child.one("Video", true)
		if err != nil {
			return nil, ErrProtocol
		}
		dynamic, err := video.scalar("dynVideoInputChannelID", false, 9)
		if err != nil {
			return nil, ErrProtocol
		}
		kind := "local_video"
		if dynamic != "" {
			kind = "digital_proxy"
		}
		result = append(result, hikvisionRecorderChannel{hikvisionChannel: channels[i], InputKind: kind})
	}
	return result, nil
}

func parseHikvisionRecorderInputs(body []byte) ([]hikvisionRecorderInput, error) {
	root, err := parseHikvisionXML(body, "InputProxyChannelStatusList")
	if err != nil || len(root.children) > 256 {
		return nil, ErrProtocol
	}
	result := make([]hikvisionRecorderInput, 0, len(root.children))
	seen := map[string]bool{}
	for _, child := range root.children {
		if child.name != "InputProxyChannelStatus" || child.version != "" && child.version != "2.0" {
			return nil, ErrProtocol
		}
		item := hikvisionRecorderInput{Association: "unverified", CameraIdentity: json.RawMessage("null")}
		item.ID, err = child.scalar("id", true, 9)
		if err != nil || !hikvisionIdentifier.MatchString(item.ID) || seen[item.ID] {
			return nil, ErrProtocol
		}
		seen[item.ID] = true
		descriptor, err := child.one("sourceInputPortDescriptor", true)
		if err != nil {
			return nil, ErrProtocol
		}
		item.ProxyProtocol, err = descriptor.scalar("proxyProtocol", true, 32)
		if err != nil || !observationText(item.ProxyProtocol, true, 32) {
			return nil, ErrProtocol
		}
		item.AddressingType, err = descriptor.scalar("addressingFormatType", true, 16)
		if err != nil || (item.AddressingType != "ipaddress" && item.AddressingType != "hostname") {
			return nil, ErrProtocol
		}
		addressField := "ipAddress"
		if item.AddressingType == "hostname" {
			addressField = "hostName"
		}
		address, err := descriptor.scalar(addressField, false, 253)
		if err != nil {
			return nil, ErrProtocol
		}
		if address != "" {
			if item.AddressingType == "ipaddress" {
				parsed, err := netip.ParseAddr(address)
				if err != nil || parsed.Zone() != "" {
					return nil, ErrProtocol
				}
			} else if !recorderHostname.MatchString(address) {
				return nil, ErrProtocol
			}
			item.Address = &address
		}
		port, err := descriptor.scalar("managePortNo", false, 5)
		if err != nil {
			return nil, ErrProtocol
		}
		if port != "" {
			if !recorderPortNumber.MatchString(port) {
				return nil, ErrProtocol
			}
			parsed, err := strconv.Atoi(port)
			if err != nil || parsed > 65535 {
				return nil, ErrProtocol
			}
			item.ManagementPort = &parsed
		}
		sourceInput, err := descriptor.scalar("srcInputPort", false, 9)
		if err != nil {
			return nil, ErrProtocol
		}
		if sourceInput != "" {
			if !recorderSourceInputID.MatchString(sourceInput) {
				return nil, ErrProtocol
			}
			item.SourceInputID = &sourceInput
		}
		item.Online, err = descriptor.boolean("online", false)
		if err != nil {
			return nil, ErrProtocol
		}
		// No reported username, secret, URL or guessed serial/name is projected.
		// A reported address/online flag does not establish camera identity.
		result = append(result, item)
	}
	return result, nil
}

func discoverHikvisionRecorder(parent context.Context, source httpsTunnelSource, roots *x509.CertPool) (hikvisionRecorderInventory, error) {
	var result hikvisionRecorderInventory
	identityRaw, err := readHikvisionRecorderISAPI(parent, source, roots, "/ISAPI/System/deviceInfo")
	if err != nil {
		return result, ErrProtocol
	}
	defer clear(identityRaw)
	result.Identity, err = parseHikvisionIdentity(identityRaw)
	if err != nil {
		return result, ErrProtocol
	}
	channelsRaw, err := readHikvisionRecorderISAPI(parent, source, roots, "/ISAPI/ContentMgmt/StreamingProxy/channels")
	if err != nil {
		return result, ErrProtocol
	}
	defer clear(channelsRaw)
	result.Channels, err = parseHikvisionRecorderChannels(channelsRaw)
	if err != nil {
		return result, ErrProtocol
	}
	inputsRaw, err := readHikvisionRecorderISAPI(parent, source, roots, "/ISAPI/ContentMgmt/InputProxy/channels/status")
	if err != nil {
		return result, ErrProtocol
	}
	defer clear(inputsRaw)
	result.Inputs, err = parseHikvisionRecorderInputs(inputsRaw)
	if err != nil || parent.Err() != nil {
		return result, ErrProtocol
	}
	result.ResponseSHA256 = map[string]string{"device_info": hash(identityRaw), "streaming_proxy": hash(channelsRaw), "input_status": hash(inputsRaw)}
	return result, nil
}
