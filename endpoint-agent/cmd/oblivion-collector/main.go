package main

import (
	"errors"
	"fmt"
	"os"
)

var version = "0.1.0-dev"

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
func run(args []string) error {
	if len(args) == 1 && args[0] == "version" {
		fmt.Println(version)
		return nil
	}
	if len(args) != 1 || args[0] != "service" {
		return errors.New("use: oblivion-collector service|version; installation and enrolment belong to setup")
	}
	return runService()
}
