# W14 Fleet technical intake — desktop browser verification

12 September 2026. Mappings: W14 / F06 / B04 / E13. The journeys below passed locally; full package and release acceptance remain open.

## Runtime and isolation

Owned run `b8e1480e92fa437b`, fingerprint `cf1faea4507a0c9d9b2c6cdaa8956892f63958548a90d450fef319693fe9b719`. Bootstrap49348 completed with terminal0. Identity endpoint confirmed this checkout, owned schema, local environment, normal CSRF, sync queue, array mail and disabled SSR. Current asset was `app-B9FgKzu6.js`; manifest SHA256 `b650d03c08bbe907a998d378d95313d0fb8992439d38d0625835df97fab8101c`; no public/hot. See the matching browser identity, preview, run and bootstrap files.

Used only owned in-app browser tab7 at its existing desktop size. No resizing. Unowned tab2 and external browser tabs were untouched. Normal fixture authentication; canonical telemetry, detector, source and IT jobs produced the records. No application, fixture, migration or asset edits during verification. Screenshots were visually inspected in tool output; no filesystem screenshot artifact is claimed.

## Passed journeys

- Cover role: direct ticket15 remained Open with direct-to-IT wording, no invented alert, integrity-verified v3 evidence, actual Asset1 and Device8 links. From the selected Linked records tab, two Tab presses focused the Asset link; Return opened `/fleet-assets/assets/1`. Its canonical Device link opened `/security-devices/devices/8`.
- Cover role: recovered ticket16 remained Open and explicitly required technician resolution. It retained the sealed offline observation and current Asset2/Device9 destinations.
- Cover role without Control Room access: urgent ticket17 retained permitted Asset3/Device10 links. Operational snapshot details and the alert destination were withheld; the source row explained the required access.
- Audit role without Asset/Device access: ticket16 remained usable with its recovery notice, concealed Fleet snapshot metadata and a generic restricted Asset row without name, tag or destination. Direct Asset2 and Device9 URLs each returned403. Browser Back restored the usable restricted ticket state after navigation completed.
- Technician role with all source grants: ticket17 showed Open status, integrity-verified Fleet evidence, Asset3/Device10 and alert CR-2026-0004. The alert link opened the existing Control Room workspace with Open/operational-response-active state and High severity. Its Evidence tab showed the same sealed checksum prefix `9c9aafb6b9a6`. Linked records returned through the canonical IT-000017 destination to the still-open ticket with the real synthetic assignee and generic System report.

## Cleanup and limits

Owned tab7 was closed. Exact-fingerprint StopAndRemove reported the owned schema and directory removed, with no other database or Herd environment changed. Independent read-only postflight confirmed `schema_absent` and `owned_directory_absent`, with no database mutations. See `w14-fleet-intake-browser-cleanup.txt` and `w14-fleet-intake-browser-postflight.json`.

All28 source/test/fixture hashes still matched after verification. All10 protected design hashes matched; application/resources/tests/database whitespace check passed. The working database was not migrated; migrations17–29 remain unapplied there.

These journeys do not prove independent-worker Fleet IT contention, interruption recovery, operator create/link-existing handoff, delivery-failure/retry UI, every source capability or the final release gate. Wrong-Site Fleet denial has backend evidence; no additional wrong-Site browser journey is claimed here.
