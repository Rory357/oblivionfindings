# Guided Windows computer installation profiles

This is one operating organisation across approved Sites. A profile identifies the current deployment's configured HTTPS origin and one authorised canonical computer. It does not select or switch tenants. The native computer agent is Windows only; Windows/Linux site-collector identities remain separate.

## Device-page flow

When native management is enabled, an administrator with computer-update and command-administration permissions can open **Set up Obli** from the computer's existing device page. Other device families do not receive this entry. The underlying options/create/revoke requests independently recheck current account, permission, assignment and Site authority; a visible button is never authorization.

The dialog loads a read-only preview of the computer, identifier, Site, organisation and server. It does not issue a credential while opening. Incomplete deployment configuration is explicit. The administrator confirms by choosing **Create installation profile**, then saves the JSON attachment for the signed Windows installer. The file is short lived, contains a one-time enrolment token and public signing key, and contains no AI provider key.

The browser does not claim installation or enrolment occurred merely because it created or saved a profile. Signed software comes from Agent downloads, independently of this deployment-specific profile. The current dialog issues computer-support profiles; the unified Computer support / Site monitoring / Both installation workflow is still required.

## Reviewed context and replacement

GET /security-devices/devices/{device}/endpoint/bootstrap-options returns scoped metadata, configuration readiness, enrolment state and an opaque context hash. Its context binds the actor, computer labels, canonical assignment fingerprint, Site identity, existing enrolment state, deployment URL/name, signing public key and configured expiry duration. The assignment fingerprint and signing key are not projected by the preview.

The browser passes expected_context when POSTing to the existing endpoint/bootstrap route. The service holds the device lock through current-context comparison, identity issuance and profile construction. Changed review context produces 409 before issuing a replacement token. Existing authorised programmatic callers may omit this optimistic-review field; they still pass the same current authority checks and cannot replace an unrevoked enrolment.

Pending, expired and enrolled identities are shown explicitly. Their token is not recoverable from the server. Losing a reply/file therefore leads to **Check installation status**, not an automatic issue retry.

Revocation is a separate confirmation with a recorded reason. An enrolled identity warns that revoking it stops the existing agent from authenticating. The browser supplies expected_agent_uuid to the existing revoke route; under the device/agent locks, a mismatch returns 409 and leaves a newer replacement intact. Existing programmatic calls without the optional expected UUID retain the authorised “revoke current computer enrolment” meaning. A successful revocation is followed by a fresh preview before another profile can be created.

## Browser and transport boundary

- HTTPS is required by the endpoint transport; requests use same-origin credentials, CSRF, no-store and redirect rejection.
- Client responses are capped at 16 KiB with an independent 15-second deadline. A failed or uncertain credential-issuing request is not automatically repeated.
- Preview responses must match the selected computer and expected actor. Profile responses must match the reviewed server, organisation and computer and the exact supported profile schema, with bounded token/key/expiry fields.
- Raw tokens are never rendered into dialog text, a URL query, localStorage or sessionStorage. The file is held as a temporary in-memory Blob URL.
- The offered Blob URL is revoked on close/unmount, tab hiding, expiry or explicit status refresh. Late/aborted requests cannot recreate it after close; development StrictMode's aborted initial load is ignored.
- Closing the dialog does not revoke a file already received by an authorised administrator. Saved installation files must be kept private and removed after use; explicit revocation invalidates unused tokens and current machine credentials.
- The actual agent/installer still performs its own strict profile, destination, authority, expiry, package and Windows-signature checks. Client-side validation is not the server authorization boundary.

## Evidence and remaining work

Backend lifecycle coverage includes secret-free preview, changed-server/name/assignment denial, current verified internal accounts, unchanged existing lifecycle behaviour and rejection of stale revocation targets. Frontend coverage includes explicit issue, exact destination/actor validation, secret-free displayed text, close/hide/expiry cleanup, late-response suppression, uncertain outcome recovery, specific revocation and request limits. CHECKPOINT.md records final run IDs and rendered evidence.

This flow does not complete the signed release pipeline, guided native installer, Windows/Linux collector packaging, combined-role activation, repair/update/rollback/uninstall or real installed-machine acceptance. All remain in the full Security & Devices programme and its single final-release gate.
