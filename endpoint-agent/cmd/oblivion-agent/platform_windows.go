package main

import (
	"oblivion.local/endpoint-agent/internal/servicehost"
)

func defaultStateDirectory() (string, error) { return servicehost.StateDirectory() }
func runService(version string) error        { return servicehost.Run(version) }
