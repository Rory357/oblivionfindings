//go:build windows

package agent

import (
	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
	"strings"
)

type windowsServiceBackend struct{}
type windowsServiceHandle struct{ service *mgr.Service }

func NewWindowsServiceActions(check func(RemotePeer) bool) *ServiceActionController {
	return newServiceActionController(check, boundedServiceExecutor{windowsServiceBackend{}})
}
func (w windowsServiceBackend) Open(name, operation string) (serviceHandle, error) {
	manager, e := windows.OpenSCManager(nil, nil, windows.SC_MANAGER_CONNECT)
	if e != nil {
		return nil, e
	}
	defer windows.CloseServiceHandle(manager)
	serviceName, e := windows.UTF16PtrFromString(name)
	if e != nil {
		return nil, e
	}
	access := uint32(windows.SERVICE_QUERY_STATUS)
	if operation != "observe" {
		access |= windows.SERVICE_QUERY_CONFIG
	}
	if operation == "start" || operation == "restart" {
		access |= windows.SERVICE_START
	}
	if operation == "stop" || operation == "restart" {
		access |= windows.SERVICE_STOP | windows.SERVICE_ENUMERATE_DEPENDENTS
	}
	handle, e := windows.OpenService(manager, serviceName, access)
	if e != nil {
		return nil, e
	}
	return windowsServiceHandle{&mgr.Service{Name: name, Handle: handle}}, nil
}
func (w windowsServiceHandle) Close() error { return w.service.Close() }
func (w windowsServiceHandle) Config() (bool, bool, error) {
	c, e := w.service.Config()
	supported := c.ServiceType&(windows.SERVICE_WIN32_OWN_PROCESS|windows.SERVICE_WIN32_SHARE_PROCESS) != 0 && c.ServiceType&(windows.SERVICE_KERNEL_DRIVER|windows.SERVICE_FILE_SYSTEM_DRIVER) == 0 && !strings.Contains(strings.ToLower(c.BinaryPathName), "oblivion-agent.exe")
	return supported, c.StartType == mgr.StartDisabled, e
}
func (w windowsServiceHandle) State() (string, error) {
	s, e := w.service.Query()
	if e != nil {
		return "unknown", e
	}
	if s.State == svc.Running {
		return "running", nil
	}
	if s.State == svc.Stopped {
		return "stopped", nil
	}
	return "unknown", nil
}
func (w windowsServiceHandle) Dependents() (bool, error) {
	d, e := w.service.ListDependentServices(svc.Active)
	return len(d) != 0, e
}
func (w windowsServiceHandle) Start() error { return w.service.Start() }
func (w windowsServiceHandle) Stop() error  { _, e := w.service.Control(svc.Stop); return e }
