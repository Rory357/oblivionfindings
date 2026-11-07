//go:build !windows

package main

import (
	"errors"
	"net"
)

type localNetwork struct {
	subnet  string
	gateway net.IP
	address net.IP
}

func findLocalNetwork() (localNetwork, error) {
	return localNetwork{}, errors.New("the network check needs Windows")
}

func canSee(localNetwork) bool { return false }
