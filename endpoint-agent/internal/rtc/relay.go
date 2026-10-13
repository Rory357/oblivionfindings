package rtc

import (
	"net"
	"regexp"
	"strconv"
	"strings"
)

var relayHostLabel = regexp.MustCompile("^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$")

// ValidRelayURL accepts only explicit TURN endpoints; no user info, paths,
// arbitrary queries or implicit public STUN fallback can enter negotiation.
func ValidRelayURL(value string) bool {
	if len(value) > 512 {
		return false
	}
	scheme, address, ok := strings.Cut(value, ":")
	if !ok || (scheme != "turn" && scheme != "turns") {
		return false
	}
	address, query, hasQuery := strings.Cut(address, "?")
	if hasQuery && query != "transport=tcp" && query != "transport=udp" {
		return false
	}
	if scheme == "turns" && hasQuery && query != "transport=tcp" {
		return false
	}
	host, port, err := net.SplitHostPort(address)
	if err != nil || host == "" || port == "" || len(port) > 5 {
		return false
	}
	for _, c := range port {
		if c < '0' || c > '9' {
			return false
		}
	}
	number, err := strconv.Atoi(port)
	if err != nil || number < 1 || number > 65535 {
		return false
	}
	if strings.HasPrefix(address, "[") {
		return strings.Contains(host, ":") && net.ParseIP(host) != nil
	}
	if strings.Contains(host, ":") {
		return net.ParseIP(host) != nil
	}
	if len(host) > 253 {
		return false
	}
	for _, label := range strings.Split(host, ".") {
		if !relayHostLabel.MatchString(label) {
			return false
		}
	}
	return true
}
