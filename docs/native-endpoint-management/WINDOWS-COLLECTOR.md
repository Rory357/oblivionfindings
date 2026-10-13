# Windows Site monitoring service host

Development source, 8 October 2026. This is the Windows service host for the existing Site collector, within the single Obli application/installer design. It is not a second organisation boundary or a computer RMM agent. One deployment owns its approved Sites, networks, devices and capabilities.

## Implemented source

The Go command at endpoint-agent/cmd/oblivion-collector produces the required site/oblivion-collector.exe package component. It accepts only version or service. Service mode requires Windows Service Control Manager and cannot be launched interactively with arbitrary PHP, command, configuration or state paths.

The fixed service name is OblivionCollector. It reuses the computer service's bounded SCM lifecycle: local startup readiness, stop/shutdown cancellation, interrogate status and bounded stop. Event messages contain fixed lifecycle states, not child output, device observations or credentials. Installer registration of the service is now wired for Site and Both roles. Dedicated Event Log registration, exact installer ownership and failed-install cleanup now exist in source; see WINDOWS-EVENT-LOG.md. Real native registry/Event Viewer and signed installation acceptance remain required.

## Paths and identity

The host must run from:

~~~text
<Program Files>/Oblivion/Agent/releases/r-<revision>-<nonce>/site/oblivion-collector.exe
~~~

Program Files and ProgramData are resolved through Windows APIs. The complete package directory chain is held open and checked for protected ownership/permissions and path redirection. Packaged files are checked for unsafe links and file permissions. Publisher signature/hash verification remains the installer/package pipeline's responsibility; these startup checks do not replace it.

Collector state is separate from computer support:

~~~text
<ProgramData>/Oblivion/Collector/
  collector.identity.json
  collector.crt.pem
  collector.key.pem
  checkpoint.json
  collector-host.lock
  enrolment.lock
  Temp/
  quarantine/
  command-journal/
~~~

The installer must enrol into an initially empty protected state directory, then create the three protected runtime subdirectories before starting the service. Creating them before recoverable enrolment would correctly trigger its foreign-state check. Existing state is never adopted by changing its ownership.

The service holds its own lock plus the enrolment lock. Stop it before an approved repair or replacement. Runtime identity paths must remain inside the expected private state directory. Private state files/directories are checked before cycles. Local administrators and SYSTEM remain trusted host authorities.

## Fixed runtime and process containment

The host runs only packaged runtime/php.exe and bin/oblivion-collector. PHP starts with -n and explicitly configured curl, OpenSSL, Sodium and sockets extensions, a packaged CA bundle, UTC and fixed diagnostic settings. It receives a constructed environment, fixed runtime working directory and protected temporary directory. It does not inherit API keys, enrolment tokens, PHPRC, PATH overrides or other process environment values.

Required runtime payload files are shared between the release validator and the host through SiteRuntimeFiles. The CA bundle and the four extension DLLs join the previous minimum host/PHP/script/autoload files. Every transitive dependency, collector source file, vendor file and licence still has to appear in the signed release inventory. A list of filenames is not proof of a working distribution.

PHP's -n option disables normal php.ini loading; see the [PHP command-line reference](https://www.php.net/manual/en/features.commandline.options.php). Actual development-runtime checks verified loading the explicitly selected extensions.

Each cycle is created directly through Windows process APIs, without a shell, in a job assigned atomically at process creation. Service cycles inherit only explicit NUL input/output handles. Setup enrolment uses a bounded private stdin pipe for the token, with NUL output/error; neither command lines nor environment variables carry the token. Closing the job ends leftover descendants after success, cancellation or failure. The host's deadline also terminates the job and waits for the primary process to stop. This uses [Windows process attributes](https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/nf-processthreadsapi-updateprocthreadattribute) and [job lifetime controls](https://learn.microsoft.com/en-us/windows/win32/procthread/job-objects). The job-list mechanism requires Windows 10/Server 2016 or newer; supported deployment versions still require release acceptance.

## Scheduling and health

Before readiness, the local runtime-check command verifies PHP/extensions and the saved collector's server, TLS pin, signing identity and certificate/key/subject/time binding. The short-lived setup grant may have expired: it is not the runtime authorisation lease.

After local readiness, cycles run sequentially with a ten-minute deadline. The normal delay is one minute after completion; failures back off to five minutes. Exit code 2 reports deferred upload with retained observations. Unsafe installation state stops the service instead of retrying it. Repeated identical lifecycle messages are suppressed.

Local readiness does not establish central connectivity, accepted heartbeats, probe health or vendor integration. Monitoring state must come from the server's existing signed configuration, scope/authentication and accepted heartbeat path. A completed process is not a claim that every device check is healthy.

## Verification and remaining release work

Real Windows test child processes verified argument quoting, an explicit environment, exit status, cancellation of parent/descendant processes and cleanup of descendants after the parent exits. These tests do not enrol or monitor the developer's computer.

The real PHP development runtime executed local startup with a generated test identity through the production child launcher. It accepted a valid installed identity after setup-grant expiry and rejected a missing required extension. Collector tests also deny changed server/pin/collector/certificate paths.

Windows amd64 and arm64 host builds pass. These are unsigned development binaries; they are not signed customer packages and do not prove that a matching packaged PHP runtime/DLL set works on each architecture.

Still required:
- Accept the implemented Site/Both transaction, guided deployment profiles, protected state, private-pipe enrolment, fixed services and Event Log ownership on approved signed Windows pilots. See COMBINED-INSTALLATION.md and WINDOWS-EVENT-LOG.md; these source components are no longer wholly missing.
- Full package assembly, CA trust requirements for each deployment, runtime/dependency provenance, signing and accepted catalogue publication.
- Signed installed Windows testing: startup/reboot, blocked network, certificate/revocation changes, spool/command recovery, real provider checks and computer+collector coexistence.
- Repair/update/rollback/uninstall, durable revision enforcement, remaining Obli/technician capabilities and every other Security & Devices requirement.

The setup command now accepts Site/Both only with the required verified package and reviewed profiles; the Orbit graphical launcher also exposes these roles. Builds and isolated tests do not establish signed installed acceptance. Normal remote Connect remains gated by its separate installed acceptance.
