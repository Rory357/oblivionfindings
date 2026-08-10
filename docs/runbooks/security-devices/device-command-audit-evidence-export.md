# Device command audit evidence export

## When to export

Use the governed command evidence export for incident review, change closure, provider escalation, break-glass review, or an authorised internal audit. Export only the exact command needed for the stated purpose; do not bulk-collect unrelated Device history.

## Access and privacy boundary

The viewer must retain current application approval, Device view access, exact Site/ownership visibility, the source-domain permission, sensitive-domain permission where applicable, and at least the Observe command level. A direct URL outside those boundaries returns a concealed not-found response. Losing access removes both the UI link and retained direct-object access.

The export deliberately omits request and approval narratives, break-glass text, signatures and signing-key identifiers, credential references and leases, reusable secret material, raw provider requests/responses, provider request references, raw collector identity, and raw observation references. It does not export tracking history, clinical readings, CCTV/media, access-history events, or unrelated Device data.

## Procedure

1. Open the exact Device and select **Management**.
2. Locate the command in **Command history** and verify Device, Site, capability, status, route, expected state, and final reconciliation.
3. Select **Export audit evidence**. The application records an immutable `evidence_exported` event before producing the JSON file.
4. Verify the file contains `schema_version`, export actor/time, command UUID, safe Device/Site context, approvals without comments, attempts without provider request references, reconciliations with hashed observation references, and the linked audit chain.
5. Confirm `audit_chain.linked` is `true`. If it is false, stop and escalate possible evidence corruption; do not edit or regenerate stored audit records.
6. Store the file only in the authorised incident, change, or audit record. Apply that destination's access and retention policy and avoid email or chat attachments.

## Bulk evidence

For a bulk action, first download the batch **result ledger** to preserve per-target inclusion and outcome. Export detailed command evidence only for the affected children. If current access to any retained target is lost, the batch export is concealed to prevent identity or count disclosure.

## Closure

Record the export time, actor, purpose, command or batch UUID, destination record, and retention owner. Do not rename the contents, remove redaction declarations, or supplement the pack with credential logs or raw provider payloads.
