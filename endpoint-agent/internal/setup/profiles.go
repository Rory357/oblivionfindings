package setup

import (
	"errors"
	"time"

	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/collectorhost"
)

type Profiles struct {
	Role      string
	Computer  *agent.Bootstrap
	Collector *collectorhost.Bootstrap
}

func (p Profiles) HasComputer() bool  { return p.Role == "computer" || p.Role == "both" }
func (p Profiles) HasCollector() bool { return p.Role == "site" || p.Role == "both" }
func (p Profiles) ServerURL() string {
	if p.Computer != nil {
		return p.Computer.ServerURL
	}
	if p.Collector != nil {
		return p.Collector.DeploymentURL
	}
	return ""
}
func (p Profiles) Validate(server, collectorServer string, now time.Time) error {
	if (p.Role != "computer" && p.Role != "site" && p.Role != "both") || p.HasComputer() != (p.Computer != nil) ||
		p.HasCollector() != (p.Collector != nil) {
		return errors.New("supply exactly the installation profiles required by the selected role")
	}
	if p.Computer != nil {
		if err := agent.ValidateBootstrap(*p.Computer, now); err != nil {
			return err
		}
	}
	if p.Collector != nil {
		if err := collectorhost.ValidateBootstrap(*p.Collector, now); err != nil {
			return err
		}
		if p.Computer != nil && p.Computer.ServerURL != p.Collector.DeploymentURL {
			return errors.New("computer and Site profiles belong to different Oblivion deployments")
		}
		if p.Collector.ServerURL != p.ServerURL() && collectorServer != p.Collector.ServerURL {
			return errors.New("confirm the profile's dedicated collector server before installing")
		}
		if collectorServer != "" && collectorServer != p.Collector.ServerURL {
			return errors.New("collector server confirmation does not match")
		}
	} else if collectorServer != "" {
		return errors.New("computer-only installation has no collector server")
	}
	if server == "" || server != p.ServerURL() {
		return errors.New("confirm the installation profile's exact Oblivion server before installing")
	}
	return nil
}
func (p Profiles) bind(j *Journal) {
	j.Role = p.Role
	j.ServerURL = p.ServerURL()
	if p.Computer != nil {
		j.AgentUUID = p.Computer.AgentUUID
	}
	if p.Collector != nil {
		j.CollectorUUID = p.Collector.CollectorUUID
		j.CollectorSiteID = p.Collector.SiteID
		j.CollectorProfileHash = p.Collector.BindingSHA256()
	}
}
