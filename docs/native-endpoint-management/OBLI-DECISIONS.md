# Confirmed Obli and endpoint requirements — 7 October 2026

These user decisions supersede earlier pending questions and any Windows/Linux computer-agent wording in the goal or older plans. They extend the full Security & Devices programme.

## Product and deployment boundary

- One operating organisation across approved sites per Oblivion installation. No tenant selector, shared cross-customer device registry, or tenant-scoped transport.
- The user clarified that Oblivion Care will have multiple customers despite the single-tenant product. Working interpretation explained to the user: each customer organisation has its own independent Oblivion deployment. Multiple deployments may reuse the same application and signed agent binaries; each has separate configuration, data, credentials and enrolment authority.
- Download/install flow must preconfigure the appropriate deployment server address and an expiring enrolment code from that organisation's Oblivion. The agent enrols into an explicitly approved device/site and receives a revocable machine credential. Do not hard-code a universal server, discover arbitrary servers, or let ordinary staff switch organisations after enrolment.
- The canonical public agent URL is deployment configuration, not a URL inferred from an untrusted browser Host header. Installation should display the intended organisation/server for confirmation. Endpoint state must protect that binding. Reassignment requires explicit revocation/re-enrolment.
- AI provider secrets remain server-side. No OpenAI/Claude key in installers, endpoint config, local logs or staff-visible settings.

## Platforms and one application

- Windows ONLY for native computer support/RMM agents and the staff tray UI.
- Windows and Linux remain supported targets for site collectors. Prior Linux endpoint prototype files are not evidence of an agreed Linux computer-support release.
- One Windows installer/application: Computer support, Site monitoring, or Both. Ordinary staff PCs normally run Computer support; an always-on site machine may run Both.
- Internally the Windows background service and interactive tray UI remain separate privilege boundaries. Collection must continue when the chat window closes or no staff member is logged in.
- Obli identity comes from stephanv48v/oblivion-care-website, read at commit 4e9ea7cddfd32512bf1eefaa6301e20cb169eb69. The website's marketing AI and any historical live tests are not authority to send application data or bill AI calls.

## Staff Obli

- Organisation administrators centrally choose OpenAI/Claude, model, API key and spending limits.
- Read-only approved technical diagnostics: health, versions, update failures, network status and selected redacted logs. Clearly show what is shared.
- Screenshots/files require explicit selection and preview before sending.
- Explain suggested repairs and offer technician handoff. Model output never becomes a privileged command by itself.
- Basic anonymous computer help; sign-in required for tickets and organisational knowledge. Shared PCs must keep each person's private chat and permissions separate.
- Approved knowledge articles and organisation/Oblivion procedures are filtered by the signed-in person's access.
- Private chats; only chosen messages, summaries and attachments enter tickets. More access requires explicit sharing.
- User reviews and submits a ticket draft into the existing IT module, including chosen issue description, steps tried and diagnostics.
- Separate configurable chat, diagnostic and attachment retention; submitted evidence follows IT ticket policy.
- Ticket replies/status, remote-session requests, outages, approved software catalogue, health warnings, restart reminders and offline ticket drafts are all required, configurable features.
- Voice/dictation supported where the configured provider permits it; disabled until the user starts it, explicit microphone consent, visible stop. Current design previews do not access a microphone.

## Technician access, consent and AI

- IT & Support > Remote support is the technician entry, with a shortcut from a ticket's linked computer. Keep canonical Device and IT ticket ownership/access checks; selecting a device does not establish a session.
- Required screen features include live viewing and actual mouse/keyboard control, not only diagnostic visibility.
- Default attended behaviour: the person at the computer must click **Allow connection**, with **Decline** available. Display technician identity and requested access. An active-session notice and **Stop session** control remain visible while connected.
- Configure policy centrally in Oblivion: attended consent requirements, view-only/control rights, separately authorised unattended equipment and tightly controlled emergency access. Permission to list computers is never permission to connect, run commands or disclose diagnostics.
- Technician tools: screen/control, terminal/PowerShell, approved scripts, processes/services, software/updates, file transfer, logs, network diagnostics and restart scheduling, each with separate permissions.
- Technician Obli defaults to read/suggest and explicit technician approval of execution. Automation is configurable only through governed, approved repair workflows.
- Session video recording configurable, OFF by default, visible notice when enabled. Commands, approvals, connections and transfers require action audit regardless of video.
- Privacy-sensitive staff chats are not automatically exposed through remote support or AI diagnostics.

## Design choice and browser evidence

- User selected **02 — Obli orbit**. It is now the initial selection in the preview and the direction for implementation.
- Keep the simplicity. The user rejected generic/generated-looking status-card UI: tightened one-line icon/name, compact Windows chrome, factual device identity/details, fewer rounded containers.
- Animated orbital ring with quiet idle movement and faster thinking/voice state; visible motion toggle plus reduced-motion support.
- The mockup includes Allow/Decline/Disconnect and persistent active-session notice. It is a local design demonstration, not a live agent or AI connection.
- Canvas: C:/Users/steph/.cursor/projects/c-Users-steph-Herd-oblivionfindings/canvases/obli-windows-tray.canvas.tsx
- Standalone preview: C:/Users/steph/.codex/visualizations/2026/10/07/01a1148a-1cba-7652-a537-7e36600d54ae/obli-windows-tray.html
- Browser checks exercised motion on/off, reduced motion, thinking state, Allow/Decline, active notice surviving navigation, disconnect, hidden-icons reopening, tray menu, all three styles and 780px fit. No machine actions, tickets or AI calls occurred.

## Current implementation limit

The source branch contains the scoped IT Remote support workspace, current-actor per-computer policy editor and signed session broker. Normal Connect remains unavailable until the Windows consent bridge passes installed acceptance and real screen/control acceptance is complete. Technician Obli now has a separate, disabled-by-default read/suggest investigation workspace using server-resolved approved observations, central provider/budget settings and separate diagnostics/assistance permissions; see TECHNICIAN-OBLI.md. It cannot activate remote control or execute a repair. The native Orbit helper has authenticated service status/approved diagnostic preview, staff browser sign-in, reviewed personal tickets and central private AI text chat. Approved IT guide selection and reviewed computer-to-ticket association now exist in development source. Voice, selected attachments, offline drafts and remaining helpdesk features still need work. See STAFF-HELPER.md, REMOTE-SUPPORT.md and CHECKPOINT.md for verified scope and limitations. The protected per-deployment bootstrap and Windows service/installer foundations exist; combined installer lifecycle, signing, real installed acceptance and all remaining programme requirements are still part of the one final-release gate.

## Pre-client implementation and deferred field acceptance — 11 October 2026

The user confirmed there are no clients and no live cameras available yet. Continue as much complete-programme implementation, NZ primary-source market research and meaningful synthetic/interoperability verification as possible now. Field-test real equipment and fix compatibility when clients become available. Do not require the user to provide equipment before progressing independent source work, repeatedly request unavailable hardware, or label synthetic equipment as operationally accepted. Keep documented candidates, implemented/synthetic capabilities and future installed/model/firmware witnesses distinct.

The user is going to bed and cannot answer approval requests. Continue autonomously within the existing authorised scope and approval policy, save concrete checkpoints, and hold any genuinely approval-dependent action. This is not permission to bypass a sandbox rejection or to perform publication, purchase, production migration or real privileged machine actions that require separate authority. The complete programme stays active; external acceptance timing follows this latest user instruction.