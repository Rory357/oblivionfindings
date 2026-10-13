# Windows desktop worker

8 October 2026. Development source for one operating organisation across approved Sites. Computer support is Windows only. Windows/Linux site collectors and the wider final-release requirements are unchanged.

## Implemented path

The ordinary-user Obli process now contains a primary-screen capture/input worker. It starts only after the person clicked Allow in this helper instance and the service confirms that request as allowed. It does not restore consent after helper restart. The installed SYSTEM service handles transport and authority, while capture and input remain in the interactive user's process.

Desktop data uses a separate local pipe, Oblivion.Obli.Desktop.v1. Its two connection slots and bounded messages cannot consume the existing consent/Stop pipe's eight slots. The helper checks the pipe server PID against the registered service before sending; the service obtains the client's actual Windows user, logon SID and session. Requests cannot provide their own OS identity. Only typed desktop_open, desktop_poll and desktop_frame operations are supported.

A ready encrypted connection does not itself activate capture. The service first binds a random worker token to the approved desktop, requires worker presence within two seconds and requests a signed active lease. The helper validates the origin, session, desktop, access mode, token and expiry before each input/capture cycle. Local monotonic deadlines and a watchdog also apply during a blocked pipe call. The service still independently enforces current Windows identity, helper presence and the signed lease.

The input queue is bounded at 128 events, returns at most 32 per poll and ends the session on overflow instead of losing a key/button release silently. Exact event fields, sequence numbers and frame sequences prevent malformed or replayed messages. View-only sessions cannot queue or apply control events. Stop clears the service's token/queue before reporting upstream and cancels the local worker before private pages reopen.

## User-visible behaviour and privacy

The active Orbit notice names the technician, distinguishes view-only/control, identifies the primary screen and keeps Stop visible. The helper stays above ordinary windows. Closing/minimising, lock/logoff, loss of service/origin, expiry or worker failure stops sharing; the OS worker also rejects a hidden, minimised, cloaked or off-screen helper.

While the worker exists, Obli's private chat and ticket pages are collapsed and cannot be reopened through navigation or sign-in. They remain hidden while a capture operation finishes on Stop. The screenshot code additionally masks visible windows belonging to Obli, including borders, before JPEG encoding. It stops if private window bounds cannot be verified. This is separate from any selected AI attachment: remote screen frames are not sent to the AI provider or automatically added to tickets.

Remote mouse targets inside Obli windows and keyboard input while Obli owns focus are ignored, with held input released. A technician cannot use this input path to click the helper's own consent or private-page controls. They can click another application to direct keyboard input there.

## Current capture and input limits

The current worker captures the primary monitor only. It freezes that monitor's geometry for the session and ends on display changes. Captures are bounded to 8192 pixels per dimension and 32 Mi pixels; output preserves aspect ratio within 1600 by 900, targets five frames per second and cannot exceed 512 KiB per JPEG. Buffer pressure can drop frames. These are code limits, not measured quality/latency acceptance.

The worker requires an ordinary, non-elevated helper, an active Windows session and the Default input desktop. It does not switch desktops, bypass UAC/UIPI, capture the secure desktop, read the clipboard or run in session zero. See [Microsoft OpenInputDesktop](https://learn.microsoft.com/en-us/windows/win32/api/winuser/nf-winuser-openinputdesktop), [Graphics.CopyFromScreen](https://learn.microsoft.com/en-us/dotnet/api/system.drawing.graphics.copyfromscreen) and [SendInput](https://learn.microsoft.com/en-us/windows/win32/api/winuser/nf-winuser-sendinput).

Normal shutdown/Stop releases only keys and buttons recorded as pressed by this worker. Release remains best effort when Windows blocks input during secure-desktop or logoff transitions. Abrupt process-death recovery for held input is not solved by an in-process finally block and remains a release requirement. Multi-monitor selection, cursor presentation, keyboard-layout/IME fidelity, secure-desktop workflows and unattended desktop selection also need further implementation/acceptance.

## Verification and missing end-to-end evidence

Go checks exercise worker/OS/token binding, separate activation, view-only denial, input zero values, bounded queues, expiry, frame replay and Stop during an in-flight frame. A real temporary Windows named pipe validates actual identification-token handling and anonymous denial; it uses an injected desktop response and never captures a screen.

Native checks use a fake capture/input backend and local response fixtures. They prove no capture before active permission, input replay/view-only denial, monotonic expiry during blocked I/O, cleanup and private-page navigation. Generated bitmap masks are encoded and decoded to verify JPEG masking. The actual WPF active view is rendered with fictional device/session data.

No real desktop capture or input injection was run on this host. No installed SYSTEM-to-helper-to-browser session, live relay, NAT/performance test, real DPI/layout test or signed customer installation has been verified. The technician browser viewer is not implemented yet. RemoteAvailable/Connect and the deployment remote-support switch remain disabled until the operational acceptance requirements are satisfied.

## Browser integration evidence update

REMOTE-BROWSER.md now records an implemented technician viewer and verified Chromium-to-Go RTC interoperability using generated pixels/in-memory input. This removes the missing-browser-source dependency but does not prove the Windows capture/input worker running under the installed service and authenticated backend. Keep all installed-host and hardware acceptance items above open.
