package cctv

import (
	"context"
	"encoding/binary"
	"errors"
	"io"
	"os/exec"
	"path/filepath"
	"runtime"
	"sync"
	"time"
)

var ErrSupervisor = errors.New("media supervisor unavailable")

// Supervise runs only fixed, verified package paths supplied by the Site host.
// PHP owns central authentication; the media child receives only framed IPC.
func Supervise(ctx context.Context, php, script, worker, identity, directory string, environment []string) error {
	for _, p := range []string{php, script, worker, identity, directory} {
		if !filepath.IsAbs(p) {
			return ErrSupervisor
		}
	}
	run, cancel := context.WithCancel(ctx)
	defer cancel()
	args := []string{"-n", "-d", "date.timezone=UTC", "-d", "log_errors=0", "-d", "display_errors=stderr"}
	runtimeDirectory := filepath.Dir(php)
	if runtime.GOOS == "windows" {
		args = append(args, "-d", "extension_dir="+filepath.Join(runtimeDirectory, "ext"))
		for _, extension := range []string{"curl", "openssl", "sodium", "sockets"} {
			args = append(args, "-d", "extension=php_"+extension+".dll")
		}
	} else {
		for _, extension := range []string{"curl", "openssl", "sodium", "sockets"} {
			args = append(args, "-d", "extension="+filepath.Join(runtimeDirectory, "ext", extension+".so"))
		}
	}
	args = append(args, "-d", "curl.cainfo="+filepath.Join(runtimeDirectory, "cacert.pem"), "-d", "openssl.cafile="+filepath.Join(runtimeDirectory, "cacert.pem"), "-f", script, "--", "media-run", "--identity="+identity)
	lane := exec.CommandContext(run, php, args...)
	video := exec.CommandContext(run, worker)
	for _, c := range []*exec.Cmd{lane, video} {
		hideProcess(c)
		c.Dir = directory
		c.Env = append([]string{}, environment...)
		c.Stderr = io.Discard
		c.WaitDelay = 2 * time.Second
	}
	laneIn, e := lane.StdinPipe()
	if e != nil {
		return ErrSupervisor
	}
	laneOut, e := lane.StdoutPipe()
	if e != nil {
		return ErrSupervisor
	}
	videoIn, e := video.StdinPipe()
	if e != nil {
		return ErrSupervisor
	}
	videoOut, e := video.StdoutPipe()
	if e != nil {
		return ErrSupervisor
	}
	if video.Start() != nil {
		return ErrSupervisor
	}
	if lane.Start() != nil {
		cancel()
		_ = video.Wait()
		return ErrSupervisor
	}
	var closeOnce sync.Once
	closeAll := func() {
		closeOnce.Do(func() { cancel(); _ = laneIn.Close(); _ = laneOut.Close(); _ = videoIn.Close(); _ = videoOut.Close() })
	}
	defer closeAll()
	finished := make(chan error, 4)
	waited := make(chan struct{}, 2)
	// Reading PHP has a bounded independent idle deadline. A stalled auth lane
	// cannot leave the worker renewing itself; EOF also closes both children.
	go func() {
		defer closeAll()
		for {
			read := make(chan struct {
				b []byte
				e error
			}, 1)
			go func() {
				b, e := ReadFrame(laneOut)
				read <- struct {
					b []byte
					e error
				}{b, e}
			}()
			select {
			case <-run.Done():
				finished <- ErrSupervisor
				return
			case <-time.After(12 * time.Second):
				finished <- ErrSupervisor
				return
			case v := <-read:
				if v.e != nil {
					finished <- ErrSupervisor
					return
				}
				if _, e := decodeMessage(v.b); e != nil {
					clear(v.b)
					finished <- ErrSupervisor
					return
				}
				if e = writeBytes(run, videoIn, v.b); e != nil {
					clear(v.b)
					finished <- e
					return
				}
				clear(v.b)
			}
		}
	}()
	go func() {
		defer closeAll()
		for {
			b, e := ReadFrame(videoOut)
			if e != nil {
				finished <- ErrSupervisor
				return
			}
			if validatePrivateWorkerReply(b) != nil {
				clear(b)
				finished <- ErrSupervisor
				return
			}
			if e = writeBytes(run, laneIn, b); e != nil {
				clear(b)
				finished <- e
				return
			}
			clear(b)
		}
	}()
	go func() { e := lane.Wait(); waited <- struct{}{}; closeAll(); finished <- e }()
	go func() { e := video.Wait(); waited <- struct{}{}; closeAll(); finished <- e }()
	select {
	case <-ctx.Done():
		closeAll()
	case <-finished:
		closeAll()
	}
	// The command wait goroutines retain handles until children really exit.
	// Kill is safe for this media-only pipeline: it closes streams, no host action.
	deadline := time.NewTimer(3 * time.Second)
	defer deadline.Stop()
	for i := 0; i < 2; i++ {
		select {
		case <-waited:
		case <-deadline.C:
			return ErrSupervisor
		}
	}
	if ctx.Err() != nil {
		return nil
	}
	return ErrSupervisor
}
func writeBytes(ctx context.Context, w io.Writer, b []byte) error {
	owned := make([]byte, 4+len(b))
	binary.BigEndian.PutUint32(owned, uint32(len(b)))
	copy(owned[4:], b)
	done := make(chan error, 1)
	go func() {
		defer clear(owned)
		data := owned
		for len(data) > 0 {
			n, e := w.Write(data)
			if e != nil {
				done <- ErrSupervisor
				return
			}
			if n <= 0 {
				done <- ErrSupervisor
				return
			}
			data = data[n:]
		}
		done <- nil
	}()
	select {
	case e := <-done:
		return e
	case <-ctx.Done():
		return ErrSupervisor
	case <-time.After(3 * time.Second):
		return ErrSupervisor
	}
}
