package collectorhost

import (
	"context"
	"errors"
	"path/filepath"
	"sort"
	"strings"
	"unicode/utf16"
	"unsafe"

	"golang.org/x/sys/windows"
)

// JOB_LIST assigns the process atomically at creation, avoiding an orphaned
// suspended process if the host dies between CreateProcess and job assignment.
const procThreadAttributeJobList = 0x0002000d

var errProcess = errors.New("collector process could not be run safely")

type invocation struct {
	executable, directory  string
	arguments, environment []string
	input                  []byte // Optional bounded enrolment secret; never a command-line or environment value.
}

// Only fixed, protected package paths reach this internal launcher. No shell,
// inherited environment or child output is passed to the service. Only enrolment
// supplies a bounded private pipe; normal service cycles read NUL.
func runChild(ctx context.Context, call invocation) (uint32, error) {
	if err := ctx.Err(); err != nil {
		return 0, err
	}
	if !filepath.IsAbs(call.executable) || !filepath.IsAbs(call.directory) || len(call.input) > 4096 {
		return 0, errProcess
	}
	app, err := windows.UTF16PtrFromString(call.executable)
	if err != nil {
		return 0, errProcess
	}
	command, err := windows.UTF16PtrFromString(windows.ComposeCommandLine(append([]string{call.executable}, call.arguments...)))
	if err != nil {
		return 0, errProcess
	}
	directory, err := windows.UTF16PtrFromString(call.directory)
	if err != nil {
		return 0, errProcess
	}
	environment, err := environmentBlock(call.environment)
	if err != nil {
		return 0, err
	}
	job, err := windows.CreateJobObject(nil, nil)
	if err != nil {
		return 0, errProcess
	}
	defer windows.CloseHandle(job)
	limits := windows.JOBOBJECT_EXTENDED_LIMIT_INFORMATION{}
	limits.BasicLimitInformation.LimitFlags = windows.JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
	if _, err = windows.SetInformationJobObject(job, windows.JobObjectExtendedLimitInformation, uintptr(unsafe.Pointer(&limits)), uint32(unsafe.Sizeof(limits))); err != nil {
		return 0, errProcess
	}
	nullName, _ := windows.UTF16PtrFromString("NUL")
	attributes := windows.SecurityAttributes{Length: uint32(unsafe.Sizeof(windows.SecurityAttributes{})), InheritHandle: 1}
	null, err := windows.CreateFile(nullName, windows.GENERIC_READ|windows.GENERIC_WRITE, windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, &attributes, windows.OPEN_EXISTING, 0, 0)
	if err != nil {
		return 0, errProcess
	}
	defer windows.CloseHandle(null)
	input := null
	handles := []windows.Handle{null}
	var pipeRead, pipeWrite windows.Handle
	if len(call.input) > 0 {
		if windows.CreatePipe(&pipeRead, &pipeWrite, &attributes, 4096) != nil {
			return 0, errProcess
		}
		defer func() {
			if pipeRead != 0 {
				windows.CloseHandle(pipeRead)
			}
			if pipeWrite != 0 {
				windows.CloseHandle(pipeWrite)
			}
		}()
		if windows.SetHandleInformation(pipeWrite, windows.HANDLE_FLAG_INHERIT, 0) != nil {
			return 0, errProcess
		}
		input = pipeRead
		handles = append(handles, pipeRead)
	}
	list, err := windows.NewProcThreadAttributeList(2)
	if err != nil {
		return 0, errProcess
	}
	defer list.Delete()
	if err = list.Update(windows.PROC_THREAD_ATTRIBUTE_HANDLE_LIST, unsafe.Pointer(&handles[0]), uintptr(len(handles))*unsafe.Sizeof(handles[0])); err != nil {
		return 0, errProcess
	}
	if err = list.Update(procThreadAttributeJobList, unsafe.Pointer(&job), unsafe.Sizeof(job)); err != nil {
		return 0, errProcess
	}
	start := windows.StartupInfoEx{}
	start.Cb = uint32(unsafe.Sizeof(start))
	start.Flags = windows.STARTF_USESTDHANDLES | windows.STARTF_USESHOWWINDOW
	start.ShowWindow = windows.SW_HIDE
	start.StdInput, start.StdOutput, start.StdErr = input, null, null
	start.ProcThreadAttributeList = list.List()
	var process windows.ProcessInformation
	flags := uint32(windows.EXTENDED_STARTUPINFO_PRESENT | windows.CREATE_UNICODE_ENVIRONMENT | windows.CREATE_NO_WINDOW | windows.BELOW_NORMAL_PRIORITY_CLASS)
	if err = windows.CreateProcess(app, command, nil, nil, true, flags, &environment[0], directory, &start.StartupInfo, &process); err != nil {
		return 0, errProcess
	}
	var inputDone chan error
	if pipeRead != 0 {
		// Parent must close its read end so child exit unblocks a pending write.
		windows.CloseHandle(pipeRead)
		pipeRead = 0
		inputDone = make(chan error, 1)
		writeHandle := pipeWrite
		pipeWrite = 0
		secret := append([]byte(nil), call.input...)
		go func() {
			defer clear(secret)
			defer windows.CloseHandle(writeHandle)
			var written uint32
			err := windows.WriteFile(writeHandle, secret, &written, nil)
			if err == nil && int(written) != len(secret) {
				err = errProcess
			}
			inputDone <- err
		}()
	}
	defer windows.CloseHandle(process.Process)
	windows.CloseHandle(process.Thread)
	terminate := func() {
		_ = windows.TerminateJobObject(job, 1)
		_, _ = windows.WaitForSingleObject(process.Process, 5000)
	}
	for {
		if err := ctx.Err(); err != nil {
			terminate()
			return 0, err
		}
		state, waitErr := windows.WaitForSingleObject(process.Process, 50)
		if waitErr != nil {
			terminate()
			return 0, errProcess
		}
		if state == uint32(windows.WAIT_TIMEOUT) {
			continue
		}
		if state != windows.WAIT_OBJECT_0 {
			terminate()
			return 0, errProcess
		}
		var code uint32
		if windows.GetExitCodeProcess(process.Process, &code) != nil {
			return 0, errProcess
		}
		// End descendants before awaiting the input writer; otherwise a child
		// could keep its inherited input open after the parent has exited.
		_ = windows.TerminateJobObject(job, 1)
		if inputDone != nil {
			select {
			case inputErr := <-inputDone:
				if inputErr != nil {
					return 0, errProcess
				}
			case <-ctx.Done():
				return 0, ctx.Err()
			}
		}
		return code, nil
	}
}

func environmentBlock(values []string) ([]uint16, error) {
	entries := append([]string{}, values...)
	seen := map[string]bool{}
	for _, entry := range entries {
		key, _, ok := strings.Cut(entry, "=")
		folded := strings.ToUpper(key)
		if !ok || key == "" || strings.ContainsRune(entry, 0) || seen[folded] {
			return nil, errProcess
		}
		seen[folded] = true
	}
	sort.Slice(entries, func(i, j int) bool { return strings.ToUpper(entries[i]) < strings.ToUpper(entries[j]) })
	block := utf16.Encode([]rune(strings.Join(entries, "\x00") + "\x00\x00"))
	if len(block) > 32767 {
		return nil, errProcess
	}
	return block, nil
}
