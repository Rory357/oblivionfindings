package main

import (
	"encoding/binary"
	"errors"
	"net"
	"unsafe"

	"golang.org/x/sys/windows"
)

// "Checking it can see 172.16.0.0/24" (windows tab, screen 5): the hub's own network is the
// subnet of the adapter that carries the default route, and "can see" means the router there
// answers on the local network (an ARP reply). It only looks; it changes nothing.

var sendARP = windows.NewLazySystemDLL("iphlpapi.dll").NewProc("SendARP")

type localNetwork struct {
	subnet  string
	gateway net.IP
	address net.IP
}

func findLocalNetwork() (localNetwork, error) {
	size := uint32(16 * 1024)
	for attempt := 0; attempt < 3; attempt++ {
		buffer := make([]byte, size)
		first := (*windows.IpAdapterAddresses)(unsafe.Pointer(&buffer[0]))
		err := windows.GetAdaptersAddresses(windows.AF_INET, windows.GAA_FLAG_INCLUDE_GATEWAYS|windows.GAA_FLAG_SKIP_ANYCAST|windows.GAA_FLAG_SKIP_MULTICAST|windows.GAA_FLAG_SKIP_DNS_SERVER, 0, first, &size)
		if errors.Is(err, windows.ERROR_BUFFER_OVERFLOW) {
			continue
		}
		if err != nil {
			return localNetwork{}, err
		}
		for adapter := first; adapter != nil; adapter = adapter.Next {
			if adapter.OperStatus != windows.IfOperStatusUp || adapter.IfType == windows.IF_TYPE_SOFTWARE_LOOPBACK || adapter.FirstGatewayAddress == nil {
				continue
			}
			gateway := adapter.FirstGatewayAddress.Address.IP().To4()
			for unicast := adapter.FirstUnicastAddress; unicast != nil; unicast = unicast.Next {
				ip := unicast.Address.IP().To4()
				if ip == nil || gateway == nil || unicast.OnLinkPrefixLength == 0 || unicast.OnLinkPrefixLength > 32 {
					continue
				}
				network := &net.IPNet{IP: ip.Mask(net.CIDRMask(int(unicast.OnLinkPrefixLength), 32)), Mask: net.CIDRMask(int(unicast.OnLinkPrefixLength), 32)}
				return localNetwork{subnet: network.String(), gateway: gateway, address: ip}, nil
			}
		}
		return localNetwork{}, errors.New("no network with a router")
	}
	return localNetwork{}, errors.New("cannot read this computer's networks")
}

// canSee asks for the router's hardware address on the local network.
func canSee(n localNetwork) bool {
	var mac [8]byte
	length := uint32(len(mac))
	destination := binary.LittleEndian.Uint32(n.gateway.To4())
	source := binary.LittleEndian.Uint32(n.address.To4())
	result, _, _ := sendARP.Call(uintptr(destination), uintptr(source), uintptr(unsafe.Pointer(&mac[0])), uintptr(unsafe.Pointer(&length)))
	return result == 0 && length > 0
}
