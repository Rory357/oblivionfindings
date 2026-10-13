package agent

import (
	"context"
	"errors"
	"os/exec"
)

type boundedBuffer struct {
	data []byte
	max  int
}

func (b *boundedBuffer) Write(p []byte) (int, error) {
	n := len(p)
	if len(b.data)+n > b.max {
		return 0, errors.New("probe output exceeded its limit")
	}
	b.data = append(b.data, p...)
	return n, nil
}
func readCommand(ctx context.Context, executable string, args ...string) ([]byte, error) {
	command := exec.CommandContext(ctx, executable, args...)
	out := &boundedBuffer{max: 900000}
	command.Stdout = out
	// Stderr may contain private host details and is deliberately discarded.
	if err := command.Run(); err != nil {
		return nil, errors.New("inventory probe failed")
	}
	return out.data, nil
}
