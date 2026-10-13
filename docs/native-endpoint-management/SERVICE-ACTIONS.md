# Governed Windows service actions

Development source, 8 October 2026. The complete Security & Devices programme remains active and unshipped. Computer agents are Windows-only; Windows/Linux Site collectors remain a separate capability inside the combined installation experience. Each customer runs an independent single-organisation deployment across approved Sites.

## Technician and staff experience

IT & Support → Remote support links the selected computer to its existing Device Management workspace. Service action policy uses the shared settings review wizard. An administrator can prepare a disabled-by-default, versioned per-computer policy, choose attended consent or separately authorised unattended operations, and review the centrally approved service catalogue.

The canonical management request screen owns service start, stop and restart requests, reasons, impact acknowledgement, current identity confirmation, independent approval, dispatch, reconciliation and audit history. Native actions are identified as running through the Windows computer agent. An unconfirmed request retains its original request key and immutable details for retry. Obli still provides read/suggest assistance; its response does not execute a repair.

For attended actions, the Windows helper shows the technician, complete bounded reason, exact service and operation. Staff explicitly allow or decline. Screen-control consent is separate. The helper does not repeat an approval after a lost reply: Check result only reads the service's state. Expired prompts cannot approve; window/session/context clearing removes private prompt content and declines a pending prompt where possible. Once approval is sent, hiding or closing the helper is not presented as an undo.

## Authority and delivery

The existing canonical command request and attempt remain the sole execution owners. A unique EndpointDelivery attaches the encrypted signed dispatch and immutable result to that attempt. Device/Site/assignment ownership, current requester and independent reviewer permissions, MFA, identity confirmation, approved service/operation, policy revision and catalogue digest are rechecked at final claim. Optional linked IT Changes retain their existing eligibility rules.

The installed agent verifies exact signed bytes, its own incarnation, command and attempt, assignment, approved operation, policy and time window. A separate short signed claim binds the same payload hash and attended desktop consent where required. Replaying a claim cannot extend its authority. Both the deployment action-release gate and the installed SYSTEM/SCM build gate default to disabled; ordinary developer console execution is not an execution path.

The SCM executor receives only an approved service name and start/stop/restart operation. It cannot accept shell text, executable paths, startup changes, service accounts, create/delete instructions or a cascade to dependent services. It rejects the agent itself, protected operating-system/security services, drivers and unsupported service states. The configured service catalogue is bounded; current supported service names use the documented ASCII name grammar rather than every possible Windows service name.

A protected durable journal records intent before mutation. A crash during an action leaves an uncertain result and cannot automatically repeat a restart. Readback is a separate SCM query in a subsequent authenticated heartbeat, taken after the execution receipt was acknowledged; it is not copied from the execution result. A running observation alone cannot prove that an uncertain restart completed. The server stores accepted readback against its existing delivery so later unrelated heartbeat entries cannot erase it. A bounded explicit observation acknowledgement identifies the exact known-state, post-receipt measurement accepted by the server; a transport acknowledgement alone is insufficient. Journal retirement must retain outstanding receipts and only remove acknowledged expired evidence after that acceptance and the retention horizon, while preserving replay protection.

## Policy and privacy

Service action policy has separate manage-policy, service-management and unattended permissions. Changing consent policy does not grant screen access or technician AI access. API credentials stay in the installed service; the tray exchanges bounded consent messages through the verified local service connection. No staff account token, provider key, screenshot, private chat or raw shell output is part of the service command.

The staff prompt reason is limited to 500 characters so the entire reviewed reason fits its contract. Diagnostic and AI-sharing controls remain independent. Command events use existing Device audit history and the existing Security & Devices reconstruction ownership; this increment does not create a second H&S incident store.

## Verification and release boundary

The integrated PHP, Go, native UI and browser development checks have passed. CHECKPOINT.md records the original failures, corrected terminal evidence and operational limitations.

Final parent verification passed 52 native groups (including the actual PHP-to-Go local-view export) and both Windows development builds. Web verification passed 24 tests across policy, actor-bound canonical requests and the actual PHP policy contract. Full TypeScript and scoped lint checks passed. Actual-browser policy checks covered the IT entry, disabled Connect gate, attended default, review, lost-save recovery, light/dark styles and a 390px viewport; the unchanged PHP policy DTO also passed through the rendered component. The corrected backend follow-up passed 36 tests and 279 assertions; separate current-actor and optional IT Change guards passed two tests and 14 assertions. The actual signed PHP command/claim and unchanged heartbeat payload/acknowledgement passed through the Go agent with an injected SCM executor, durable receipt and exact observation acceptance. Focused Go tests, vet and both Windows architecture builds passed. The earlier combined run remains recorded as 74 passed and nine failed; it is not presented as an all-green run.

Required before release: signed installed service/tray/server acceptance on a development computer, actual approved SCM actions, real permission/consent/revocation races, transport-loss and restart recovery, independent heartbeat reconciliation, installation/update/repair/uninstall acceptance, and the rest of the full Security & Devices programme. Source checks and injected SCM tests do not establish those installed outcomes.
