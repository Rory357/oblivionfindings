# Obli Windows tray helper

Native Windows desktop source for the selected Obli Orbit design. The service is Go; the ordinary-user window is WPF on .NET 10 LTS. The combined installer owns both components. This is development software, not a signed, supported release.

## Implemented

- A native notification-area icon, context menu, hidden startup, per-Windows-user/session instance and reopen signal.
- Orbit vector artwork reused from the accepted website-derived mockup, one semantic theme, keyboard focus, quiet animation, user motion switch and Windows reduced-animation preference.
- Computer/service status, last durably acknowledged check-in, native navigation, privacy explanation and offline read-only help guides.
- A bounded local-only named pipe. The helper verifies the pipe's server process against the running SCM service before sending a request, and allows identification only.
- The SYSTEM service authenticates the pipe's Windows user, interactive logon SID and nonzero session from the operating-system token. Ordinary interactive users cannot create another instance of the service pipe. Remote pipe access is rejected. Eight concurrent connections and four-second I/O deadlines bound work.
- Strict versioned status and diagnostic-cache operations exist. Attended consent/Stop operations are described below. No terminal, file, software-management, AI-provider or credential operation is exposed through this channel.

Windows identity is not an Oblivion staff login and does not grant access to organisational knowledge or tickets. Staff sign-in, personal reviewed tickets and centrally configured private AI text chat now exist in source; see the continuation sections below. Broader diagnostics, approved knowledge, attachments/voice, software catalogue and actual remote screen/control remain unfinished. Basic anonymous guides are local static guidance. The real helper does not display simulated technician sessions.

## Build and inspect

Use SDK 10.0.401 (pinned in global.json; approved patch roll-forward allowed) on Windows. No extra third-party UI package is required. A portable SDK may be passed without system-wide installation.

~~~powershell
./build.ps1 -DotNet <absolute-path-to-dotnet.exe>
~~~

The script runs the native check harness, creates x64/ARM64 self-contained development outputs and renders the actual WPF tree to an image. It uses an isolated short NuGet cache path because the initial ARM64 copy hit the Windows 260-character limit. Outputs are ignored under the repository's .endpoint-tools directory by default.

The two activation modes are opening obli.exe normally or starting obli.exe --tray at sign-in. The development --render-preview <png> command only renders the helper's own visual tree; it does not create a tray icon, connect to the service or install anything.

All files from each architecture's publish directory needed by the app must ship in the signed release manifest. obli.exe contains managed runtime assemblies, but native WPF DLLs remain beside it; do not copy the EXE alone. PDB files are development symbols and should be excluded from customer packages. The release verifier now preserves exact Windows runtime filename casing while rejecting ambiguous file/directory casing.

## Evidence and release limits

Six native check groups cover strict status parsing, duplicate/unknown/private fields, frame bounds, stale contact, native navigation and text contrast. The Go suite additionally exercises an actual temporary Windows pipe with real identification-token capture and anonymous-client rejection. This is not a test of an installed SYSTEM service or attended remote control.

Both architectures compile. Actual Windows tray shell interaction, multi-user/logoff/reboot behaviour, DPI/high-contrast modes, code signing, SCM-to-tray communication in the signed pilot, upgrades/uninstall and the remaining staff/technician features still require acceptance.

Platform references: [Microsoft WPF/.NET installation](https://learn.microsoft.com/en-us/dotnet/core/install/windows), [self-contained publishing](https://learn.microsoft.com/en-us/dotnet/core/deploying/single-file/overview), [Windows named-pipe permissions](https://learn.microsoft.com/en-us/windows/win32/ipc/named-pipe-security-and-access-rights), [Microsoft go-winio](https://github.com/microsoft/go-winio). The pinned pipe library is v0.6.2.

## Staff support continuation — 8 October 2026

The previous unavailable-state description is superseded for staff sign-in and reviewed text tickets. The helper now has an external-browser sign-in flow and a personal ticket API/client/form; server configuration defaults disabled. See [the staff helper contract](../../docs/native-endpoint-management/STAFF-HELPER.md) for the exact contract and remaining work. At this checkpoint AI and remote support were unavailable; the later private-chat continuation below supersedes the AI statement. Drafts are currently memory-only. Native public replies and paginated history are now implemented as described in the personal-conversation continuation below; Open in Oblivion still offers the full browser workspace.

New checks use a temporary loopback listener and injected HTTP replies. They never contact a deployed Oblivion server or launch the user's browser. OBLI_TEST_RENDER on the test harness renders the real ticket form using fictional data; the production helper has no test sign-in switch.

## Private chat continuation — 8 October 2026

The helper now includes signed-in read-only text chat with the centrally configured OpenAI/Anthropic provider. This supersedes the earlier AI-unavailable text for this path only. Provider keys remain server-side; the local service pipe has not gained command or credential access. Before a send, staff review the named provider and explicitly consent; configuration changes reset consent. Recovery never automatically repeats a provider call. Selected messages can be copied into the reviewed IT draft, while all other conversation remains private.

See STAFF-HELPER.md for the server settings, budgets, retention, precise scope and remaining acceptance. Anonymous help is still local quick guides. Diagnostic/file/screenshot selection, approved knowledge, voice and actual technician control are not complete. OBLI_CHAT_RENDER on the check harness renders a fictional private-chat view without external requests. Sixteen native check groups now pass. Development outputs remain unsigned and are not a supported customer release.

## Diagnostic sharing continuation — 8 October 2026

The helper now provides explicit preview and selection for centrally approved system, recognised-software and Windows-service checks from the last accepted report. Nothing is automatically attached or sent. See STAFF-HELPER.md for exclusions, freshness and policy rules. Network/update/log coverage remains pending. OBLI_DIAGNOSTICS_RENDER renders the real diagnostic-selection view with fictional data. Nineteen native check groups pass; x64/ARM64 builds remain unsigned development outputs.

## Attended remote prompt continuation — 8 October 2026

The native helper now handles a service-verified attended request with Allow/Decline, persistent waiting/allowed notice and Stop. The local pipe includes narrowly typed remote polling/decision/stop operations; earlier statements that it has no consent operation are superseded. Window close/minimise, lock/logoff and origin changes withdraw local approval. Uncertain Stop also suspends renewals so background polling cannot keep permission alive.

The service validates current Windows session/logon identity, retains machine credentials and server challenges privately, and enforces replay/expiry/late-reply checks. See [the remote-support contract](../../docs/native-endpoint-management/REMOTE-SUPPORT.md). There is still no actual screen/control worker, unattended activation, recording or technician AI; Connect remains unavailable. The helper never claims that approval itself is a screen connection.

Current native evidence: 22 check groups, x64/ARM64 publishes and actual WPF fictional consent rendering. Set OBLI_REMOTE_RENDER to a local PNG output path when running the test harness to inspect that view. This does not install the agent, contact SCM from the injected UI case or capture the desktop. Signed installed SYSTEM/tray/OS-session acceptance remains required.

## Windows screen worker continuation — 8 October 2026

The helper now supplies primary-screen capture and ordinary-user input through a separately authenticated service pipe. It requires the locally allowed request, a service-bound worker token and a confirmed active lease; negotiation alone cannot start capture. A monotonic watchdog, Stop cleanup, persistent notice and hidden/masked private helper content are implemented. See ../../docs/native-endpoint-management/REMOTE-WINDOWS.md for precise limits and remaining real OS/installed acceptance.

Twenty-seven native check groups pass with injected capture/input and generated bitmaps; the actual active WPF view is rendered using OBLI_DESKTOP_RENDER. These checks never capture or control the host. The browser technician viewer, actual installed relay session, multi-monitor support and abrupt-process held-input recovery remain unfinished; no supported customer release exists.


## Personal ticket conversation continuation — 8 October 2026

My tickets now provides paginated own requests and public conversation history, reviewed text replies and read-only delivery recovery. Exact retries retain the same request identity/version/body. A changed ticket requires fresh detail and review, while unknown outcomes keep the pending reply unchanged. Session lock/sign-out/lost authority clears personal conversation/draft state and prevents late responses from restoring it. No internal notes or private chat are automatically exposed or shared.

See ../../docs/native-endpoint-management/obli-ticket-conversation.md for the strict API/receipt, Unicode/byte bounds and remaining acceptance. OBLI_TICKET_RENDER renders fictional native conversation views; OBLI_TICKET_EXPORT reads an optional fixture emitted by the isolated Laravel feature test through the actual native HTTP parser. Neither invokes a live service. The native harness has 35 check groups; exact final PHP/build evidence lives in CHECKPOINT.md. Persistent/offline drafts, attachments, broader knowledge sources, voice, other configured helpdesk features and signed installed acceptance remain unfinished.


## Approved organisation guides — 8 October 2026

The signed-in helper now searches and previews current reviewer-approved IT knowledge, including when AI is disabled. Staff may explicitly attach the displayed excerpt to a question. The server resolves source text from its approved publication and rechecks current access/version on send and recovery. The central settings switch is independent of AI and guides-only setup requires no provider key. See ../../docs/native-endpoint-management/approved-knowledge.md for contracts, privacy and acceptance limits. This development increment does not complete the final-release gate.
