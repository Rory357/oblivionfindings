# Oblivion native Windows computer agent

Development source for the full Security & Devices programme, not a release-ready RMM. The accepted computer-agent and tray platform is **Windows only**. Windows/Linux site collectors remain a separate required capability; the earlier Linux probe source is not a supported Linux computer-agent release.

## Implemented

- HTTPS-only enrolment bound to an approved canonical computer/site, short-lived tokens, revocation and assignment checks.
- Private OS-protected state, an exclusive process lock, durable machine credentials and retry after a lost enrolment reply.
- A bounded installation profile issued by the intended deployment, with its configured HTTPS origin, organisation/device labels, expected agent UUID and signing public key.
- The agent rejects ambiguous, expired, oversized or unsupported profiles. Retrying a pending installation preserves its credential; a profile cannot silently replace or redirect an existing identity.
- Sequenced health/inventory reports with durable retry and bounded reconnection delays. Invalid identities stop reporting for administrator review.
- A Windows Service Control Manager host with readiness, stop/shutdown cancellation, bounded startup/shutdown and sanitized event messages. Service mode uses the Windows known-folder API for its state location, rather than a process environment override.
- Ed25519 verification of exact signed command bytes and target/expiry bounds. Command execution is not yet connected.

Inventory currently covers machine-wide Windows uninstall registrations, health and service observations. This does not claim complete coverage of per-user/portable applications or software licences. A stopped service is not automatically a fault. Probe errors and completeness fields remain explicit.

## Development checks

Requires Go 1.27 and the dependencies pinned in go.mod/go.sum.

~~~text
go test ./...
go vet ./...
go build ./cmd/oblivion-agent
oblivion-agent version
oblivion-agent probe
~~~

Normal tests use temporary state and isolated local HTTPS servers. Service tests drive the handler directly; they do not register/start a service or enrol the host. The local probe integration test runs only with OBLIVION_AGENT_PROBE_TEST=1. The probe command is read-only, prints counts/error categories and uploads nothing.

## Deployment installation profile

Configure ENDPOINT_SERVER_URL to the organisation deployment's canonical public HTTPS origin and ENDPOINT_ORGANISATION_LABEL to its display name. The URL is never inferred from the download request Host header. Native management remains disabled by default.

An authorised administrator can POST to /security-devices/devices/{device}/endpoint/bootstrap. Existing device/site/assignment permissions are rechecked. The JSON attachment is delivered with no-store headers and an expiring token (default 15 minutes, maximum 30). No provider API key or reusable machine credential is included. Events retain no raw token. Reissuing requires explicit revocation of the previous enrolment.

~~~text
oblivion-agent enrol --bootstrap <downloaded-profile.json>
~~~

The development CLI validates and displays the intended organisation, computer and server before enrolment. Download only from the intended authenticated HTTPS portal: a key inside a profile cannot independently prove an untrusted file's origin. The eventual signed installer must show this destination for user confirmation. After successful enrolment, the raw token is erased from protected state; delete the downloaded profile. Failed pending enrolments preserve their original credential. Expired/changed installations require administrator review and approved recovery.

Manual development enrolment remains available with --server and --server-key, reading the token from bounded standard input rather than command-line arguments. Do not combine those flags with --bootstrap.

## Service and release boundary

The service entry is oblivion-agent service, for Windows Service Control Manager only. It rejects interactive invocation and state-directory overrides. The foreground run command remains available for development. Neither command installs a service.

Windows state uses a protected DACL, exclusive file lock, flush and atomic replacement. Local administrators are trusted host authorities. The installer now has protected staging, exact release revalidation, deployment-bound enrolment, service registration/recovery, ordinary-user tray startup registration and failure rollback. Installed service writes preserve protected state access. These are source and isolated-test results; signed Windows pilot installation, repair/uninstall, upgrade and rollback acceptance remain required. Do not register unsigned development binaries as privileged services.

Native Orbit tray source and authenticated Windows status/diagnostic/attended-consent operations now exist; see tray/README.md. Staff browser sign-in, reviewed personal tickets and centrally governed private text chat also exist in source. The installed-service runner now negotiates the encrypted RTC connection using signed expiring relay grants, independently of lease renewal. The ordinary-user Windows worker now requires a separately bound worker token and signed active lease for primary-screen capture/input. The browser technician viewer now exists with generated-data interoperability evidence; see ../docs/native-endpoint-management/REMOTE-BROWSER.md. Actual signed installed acceptance remains unfinished. Still required: operational remote support, broader helpdesk/AI features, governed technician tools, signed installed Computer support / Site monitoring / Both acceptance, signed downloads/updates and actual Windows installation acceptance. No binary is published as a supported release.

Official interfaces: [Windows service API](https://pkg.go.dev/golang.org/x/sys/windows/svc) and [Windows system interfaces](https://pkg.go.dev/golang.org/x/sys/windows). Compiling and handler tests do not prove live Windows service installation acceptance.

## Release verification and protected installation paths

See ../docs/native-endpoint-management/RELEASE-INSTALLATION.md for the signed package contract and evidence. Setup supports version, verify and Computer/Site/Both transactions; see [Combined installation](../docs/native-endpoint-management/COMBINED-INSTALLATION.md). The computer-role example remains valid. The default build has no publisher key; installation requires setup/component Windows signatures. No unsigned or runtime trust-override option exists. The graphical unified setup now exists in installer/README.md. Publisher signing, the tray's remaining backend workflows and installed acceptance remain unfinished. Service startup rejects unsafe installed state and unconfigured Windows logging sources. Setup owns registration, interrupted-source recovery and failure cleanup; see ../docs/native-endpoint-management/WINDOWS-EVENT-LOG.md. Actual registry/Event Viewer acceptance remains pending.

~~~text
oblivion-setup install --package <signed-release.zip> --bootstrap <profile.json> --confirm-server <exact-https-origin> --role computer
~~~

This is the operator interface for an approved signed build, not a distributable release. A complete package must include the real Obli tray executable. The receipt commits after local service readiness and tray startup registration; it does not prove tray interaction, remote control, AI or accepted server heartbeats.

## Remote media development boundary

See ../docs/native-endpoint-management/REMOTE-SUPPORT.md for consent, signalling, protocol limits and remaining runtime work. RTC tests use a loopback fixture relay, generated JPEGs and simulated input; they do not capture or control this computer. The production constructor requires configured TURN/TURNS and relay-only operation. Current pinned dependency notices live in third-party-licenses and must accompany distributed builds.

## Application download centre

Security & Devices now has an Agent downloads page backed by a strict signed publisher catalogue and verified private file responses. Generic software downloads are separate from deployment-specific enrolment profiles; see ../docs/native-endpoint-management/RELEASE-DOWNLOADS.md. No supported artifacts are published. Guided computer profiles and the offline Windows package builder now exist; the signed catalogue-directory assembler also exists, while actual Authenticode signing, accepted deployment activation and operational acceptance remain unfinished. The graphical installer now exists; see installer/README.md. See ../docs/native-endpoint-management/RELEASE-BUILDING.md for the publisher command and its limits.

Computer records now expose Set up Obli when native management and the required administration grants are enabled. It previews the current server/site/computer, binds profile creation to that reviewed context and supports explicit UUID-bound revocation for recovery. See ../docs/native-endpoint-management/INSTALLATION-PROFILES.md. This is the guided web computer-profile flow; the native Orbit installer and Site/Both activation now exist in source, with signed installed acceptance and repair/update/uninstall still required.

Site-collector enrolment now has a durable pending-key/recoverable-response implementation in the separate collector source; see [Collector enrolment](../docs/native-endpoint-management/COLLECTOR-ENROLMENT.md). Windows Site/Both activation source and deployment-specific collector profile APIs now exist; see [Combined installation](../docs/native-endpoint-management/COMBINED-INSTALLATION.md). The guided collector profile UI and graphical Windows setup now exist; signed installation acceptance remains unfinished.

## Windows Site collector host

`cmd/oblivion-collector` now builds the fixed Windows Site monitoring service host. It uses separate protected collector state, the existing PHP collector, explicit runtime dependencies and environment, sequential bounded cycles and process-tree cleanup. See [Windows collector host](../docs/native-endpoint-management/WINDOWS-COLLECTOR.md). Setup now accepts Site/Both with the exact approved profiles, separate identity confirmation and fixed service activation. The Orbit setup UI now exists. Complete signed runtime packages and installed acceptance remain unfinished.

## Installed service repair

The signed setup core now provides repair-services for a completed Computer/Site/Both installation. It requires the exact still-valid signed archive, explicit installed destination/role and service-repair confirmation. It restores matching service/logging/startup registration without enrolment, file replacement or clearing identity/spool. Each attempt retains a protected result record. See [Windows service repair](../docs/native-endpoint-management/SERVICE-REPAIR.md) for commands, checks and limits. The graphical Orbit repair flow now discovers/reviews installed details and requires explicit, expiring confirmation. Authorised IT dispatch, file repair/update/uninstall and actual signed installed acceptance remain incomplete.

## Site CCTV media source

The Site role now has a supervised media child and persistent authenticated PHP lane. The Windows collector host and protected Linux Site launcher run inventory concurrently with media; Computer remains Windows-only. `oblivion-media` handles signed short CCTV leases, protected framed pipes, fixed pinned RTSPS/TCP H264 input and video-only relay WebRTC. It preserves incoming offer bytes, checks all negotiated RTSP authorities, and bounds TURN peer addresses/ports inside the signed allocation pool even for learned ICE peers. TURN UDP and TCP/TLS are implemented; TURNS/UDP (DTLS), other video codecs and audio remain unavailable. Equivalent H264 coding-tool profiles are negotiated under RFC6184; no transcoder is present.

The media release gate stays disabled in development builds. Windows signed Site packages must include `site/oblivion-media.exe`; Linux packages require the Go Site launcher/media child, complete protected PHP/extensions/CA/source/vendor bundle and root-owned private enrolled state. Compiling these paths does not establish installed packaging, service supervision or provider acceptance. Deployed NVR TLS currently requires normal trusted-chain/server-name verification plus the approved leaf fingerprint; fixture-only trust roots do not change production trust.

See [CCTV contract](../docs/native-endpoint-management/CCTV-CONTRACT.md). Real synthetic RTSPS/TURN tests cover the transport, and the opt-in browser fixture feeds an unchanged Chrome offer to the actual worker. Neither substitutes for verified deployed Protect version/key/camera/recorder mapping, actual NVR/certificate/SD-WAN reachability or signed installed Windows/Linux acceptance.
