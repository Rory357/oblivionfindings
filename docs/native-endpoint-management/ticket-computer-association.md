# Reviewed ticket and computer association

This is development source within the full Security & Devices programme. It links a staff member's reviewed IT request to a currently authorised, enrolled Windows computer. It is evidence from the authenticated endpoint service, not hardware attestation or proof of who is physically using the machine. Signed installed acceptance remains required.

## Staff and technician flow

The Windows helper offers an unchecked **Include this computer** option when the deployment has the capability. It keeps ordinary tickets available without a computer link. Review shows the server-confirmed computer name and Site alongside the exact ticket description. Selecting the option does not start remote access or append private chat, files, screenshots or diagnostics.

The existing canonical affected-device link makes the computer available in the technician's IT ticket context. Its existing remote-support link still requires the technician's current IT work and remote-support permissions. The live Connect release gate, attended consent policy and session controls remain independent; attaching a computer does not grant remote control.

Review can be cancelled and staff can sign out while the check is pending. Windows lock/logoff, a lost/changed trusted service connection and staff-session expiry clear private data. Late replies cannot restore a cleared review. Current drafts remain memory-only; durable/offline recovery across app closure is a separate requirement.

## Authority and protocol

1. The signed-in staff client mints a five-minute challenge against its current staff session, request UUID, approved Site and complete immutable ticket intent. The server stores the challenge encrypted and a digest of the intent.
2. The helper sends only this challenge and a hash of its expected deployment origin through the SCM-verified Windows service pipe. Staff bearer tokens never enter the service.
3. The service compares the expected origin with its configured HTTPS origin before sending the challenge. Its own endpoint credential calls a fixed endpoint. Current Device, agent, Site and staff-session evidence must all agree.
4. The service validates the server-signed acknowledgement, bound to the challenge hash, agent UUID and a maximum 30-second acknowledgement lifetime. The helper then reads the confirmed computer summary through its staff API. It never supplies a device ID.
5. Only an explicitly selected `computer_context:true` ticket command can use that proof. The canonical IT intake and link writers remain the owners of creation, audit, routing and the affected-device relation. POST adapters schedule existing notification delivery after commit/response, including replay; read-only recovery does not dispatch. The link hook requires the exact pending canonical CREATE receipt and its newly bound ticket ID, the reviewed fields and a live proof callback. Normal arbitrary device-picker permissions are unchanged.

Origin comparison uses canonical ASCII HTTPS authority: lower-case DNS/punycode or bracketed IPv6, explicit non-default port, no trailing slash and default port 443 omitted. IPv6 hashes use the same eight lower-case, four-digit groups in Go and C#, including mapped and compatible IPv4 forms; the request destination itself is unchanged. No arbitrary callback URL is accepted.

The new proof operation has separate short deadlines (3 seconds for the service request, 6 seconds for its pipe operation, 8 seconds for the helper wait) and a two-operation service limit. It does not hold the status mutex over HTTP or occupy every local pipe worker. Existing status/remote deadlines remain unchanged.

## Expiry and uncertain delivery

Before the first ticket submission, an expired computer check returns the draft to an explicit Edit/review path. Once submission is attempted, the request UUID, description and selected association remain immutable. A lost reply offers **Check delivery** and **Retry same request**. Read-only receipt recovery does not create another ticket.

A missing receipt or unavailable/expired association may conceal a previously committed request, so the helper does not promise that nothing was created or silently mint a replacement. A committed receipt can be recovered after the original challenge expires only while current staff, session, ticket ownership, approved Site, computer/agent assignment and canonical link checks still pass. Revocation, reassignment, retirement or access loss closes that path.

Expired encrypted challenges are cleared in bounded prune batches. Uncommitted expired contexts are removed after a day. Committed association records remain with canonical ticket receipt evidence; the normal current-authority checks still apply on reads.

## Development verification and release limits

The checks use isolated databases, injected HTTP responses and the WPF window's own visual tree. They do not install a service, capture the desktop, use a real customer account or start a support session. See CHECKPOINT.md for exact final test/build results and PHP-to-Go/C# contract evidence.

Still required: signed installed service/tray/server acceptance, HTTPS/MFA and Windows-user isolation, real outage/reconfiguration races, canonical notification dispatch and the existing actual remote-control release gate. The wider network/topology/alarms/CCTV/access/HR/care/tracking/Control Room/H&S programme remains active.

Final development evidence:69 backend tests/713 assertions,3 notification scheduling mock units/10 assertions,45 native helper check groups with actual PHP fixtures, real PHP signed ACK validation in Go, and Windows x64/ARM64 helper plus service builds. All completed successfully; see the final checkpoint entry for handles and limitations.
