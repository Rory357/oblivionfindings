package rtc

import "testing"

func TestRelayEndpointsAreExplicitAndCannotCarryOtherDestinations(t *testing.T) {
	for _, endpoint := range []string{"turn:relay.example.test:3478", "turns:relay.example.test:5349?transport=tcp", "turn:127.0.0.1:3478?transport=udp", "turn:[::1]:3478"} {
		if !ValidRelayURL(endpoint) {
			t.Fatal("valid relay rejected", endpoint)
		}
	}
	for _, endpoint := range []string{"stun:relay.example.test:3478", "https://relay.example.test/", "turn:relay.example.test", "turn:relay.example.test:0", "turn:relay.example.test:65536",
		"turn:user@relay.example.test:3478", "turn:relay.example.test:3478/path", "turn:relay.example.test:3478?transport=udp#fragment", "turns:relay.example.test:5349?transport=udp",
		"turn:relay.example.test:3478?transport=udp&x=anything", "turn:[bad]:3478", "turn:relay..test:3478", "turn:-relay.test:3478", "turn:relay.test:+3478"} {
		if ValidRelayURL(endpoint) {
			t.Fatal("unsafe relay accepted", endpoint)
		}
	}
}
