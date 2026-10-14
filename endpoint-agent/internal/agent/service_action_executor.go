package agent

import (
	"context"
	"strings"
	"time"
)

var serviceDeniedNames = []string{"OblivionAgent", "RpcSs", "RpcEptMapper", "DcomLaunch", "SamSs", "LSM", "EventLog", "Schedule", "Winmgmt", "PlugPlay", "Power", "WinDefend", "Sense", "mpssvc", "BFE", "SecurityHealthService", "KeyIso", "Netlogon", "ProfSvc", "UserManager", "Appinfo"}

func permittedServiceName(name string) bool {
	if !serviceNamePattern.MatchString(name) || strings.TrimSpace(name) != name {
		return false
	}
	for _, deny := range serviceDeniedNames {
		if strings.EqualFold(name, deny) {
			return false
		}
	}
	return true
}

type serviceHandle interface {
	State() (string, error)
	Config() (bool, bool, error)
	Dependents() (bool, error)
	Start() error
	Stop() error
	Close() error
}
type serviceBackend interface {
	Open(string, string) (serviceHandle, error)
}
type boundedServiceExecutor struct{ backend serviceBackend }

func (w boundedServiceExecutor) Execute(ctx context.Context, p ServiceActionParameters, authorized func() bool) ServiceResult {
	result := ServiceResult{Status: "failed", Operation: p.Operation, ServiceName: p.ServiceName, BeforeState: "unknown", AfterState: "unknown", ReasonCode: "unsupported_service"}
	if !permittedServiceName(p.ServiceName) || !serviceOperation(p.Operation) {
		return result
	}
	service, e := w.backend.Open(p.ServiceName, p.Operation)
	if e != nil {
		result.ReasonCode = "service_unavailable"
		return result
	}
	defer service.Close()
	supported, disabled, e := service.Config()
	if e != nil || !supported {
		return result
	}
	status, e := service.State()
	if e != nil {
		result.ReasonCode = "state_unavailable"
		return result
	}
	result.BeforeState = status
	result.AfterState = status
	if status != "running" && status != "stopped" {
		result.ReasonCode = "service_busy"
		return result
	}
	if (p.Operation == "start" && status == "running") || (p.Operation == "stop" && status == "stopped") {
		result.Status = "succeeded"
		result.ReasonCode = "already_in_state"
		return result
	}
	if p.Operation != "stop" && disabled {
		result.ReasonCode = "disabled_service"
		return result
	}
	if p.Operation != "start" && status == "running" {
		dependent, e := service.Dependents()
		if e != nil || dependent {
			result.ReasonCode = "dependent_services"
			return result
		}
	}
	mutate := func(start bool) bool {
		if ctx.Err() != nil || !authorized() {
			result.ReasonCode = "consent_lost"
			return false
		}
		result.MutationStarted = true
		if start {
			e = service.Start()
		} else {
			e = service.Stop()
		}
		if e != nil {
			result.Status = "uncertain"
			result.ReasonCode = "operation_failed"
			return false
		}
		target := "stopped"
		if start {
			target = "running"
		}
		ticker := time.NewTicker(200 * time.Millisecond)
		defer ticker.Stop()
		for {
			status, e = service.State()
			if e != nil {
				result.Status = "uncertain"
				result.ReasonCode = "state_unavailable"
				return false
			}
			result.AfterState = status
			if status == target {
				return true
			}
			select {
			case <-ctx.Done():
				result.Status = "uncertain"
				result.ReasonCode = "operation_timeout"
				return false
			case <-ticker.C:
			}
		}
	}
	if p.Operation == "stop" || (p.Operation == "restart" && status == "running") {
		if !mutate(false) {
			if result.MutationStarted {
				result.Status = "uncertain"
			}
			return result
		}
	}
	if p.Operation != "stop" {
		if !mutate(true) {
			if result.MutationStarted {
				result.Status = "uncertain"
			}
			return result
		}
	}
	result.Status = "succeeded"
	result.ReasonCode = "confirmed"
	return result
}
