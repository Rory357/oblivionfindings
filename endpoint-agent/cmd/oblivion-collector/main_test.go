package main

import "testing"

func TestHostDoesNotAcceptArbitraryExecutablesStateOrEnrollment(t *testing.T) {
	for _, args := range [][]string{nil, {"run"}, {"enrol"}, {"service", "--state-dir=x"}, {"service", "php.exe"}, {"version", "--extra"}} {
		if run(args) == nil {
			t.Fatal("unsupported invocation", args)
		}
	}
}
