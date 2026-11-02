package main

import "oblivion.local/endpoint-agent/internal/collectorhost"

func runService() error { return collectorhost.Run() }

func runUpdateHelper() error { return collectorhost.RunUpdateHelper() }
