# PKG-04 — Main publication verification

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-27.

**Approved Fleet source is published and independently verified. Its serial publication slot is released.**

Main independently read local HEAD and GitHub `refs/heads/main`: both `79ea01a561f7d2fa5affa592dc096f516d663635`, exactly the [reviewed candidate](PKG-04-main-technical-review.md). Local committed tree is `496b55b3eb17934b07d408fc209209a37bde52f9`. The existing Designer performed the authorised ordinary fast-forward froma6b9fae6a and non-force push; Main performed no application merge/staging/push.

All **36** application/test paths in the candidate's reviewed integration manifest independently match their raw SHA256 on actual Main. Application/test/build-config paths are clean. All **70** paths captured before integration independently match their current hashes: **0 missing,0 changed**, including unrelated `public/.user.ini`. Main made no programme edits during this captured publication interval. Later programme updates and copied evidence are Main's own documented work.

The actual Main build manifest independently matches `A0EF7FAB0846BC532076444C74117C95BC1DE534BEA551DEC5AF494E3F8B339E`; `public/hot` is absent. The owner reports copying and individually checking1,642 generated local build files, manifest last. Main verified the manifest, not a second full1,642-file comparison or a fresh browser run. This is local build publication, not remote deployment.

The owner reran **35 frontend tests/five files from actual Main**, all passing in1.17s at17:53:13. Main inspected [that exact log](PKG-04-owner-integrated-vitest.log) and retained the [publication receipt](PKG-04-owner-publication-receipt.json) plus [70-path pre-integration fingerprint](PKG-04-owner-preservation-before.json). These are separate from Main's earlier31-test candidate run/eight actual-callback cases and the owner's broader102 frontend/59 backend1,430-assertion/build/browser evidence; overlapping totals are not added.

The reported new hosted runs are [tests](https://github.com/Rory357/oblivionfindings/actions/runs/36295642460), [lint](https://github.com/Rory357/oblivionfindings/actions/runs/36295642490), [visual](https://github.com/Rory357/oblivionfindings/actions/runs/36295642534) and [database bootstrap](https://github.com/Rory357/oblivionfindings/actions/runs/36295642502), pending/in progress at handoff. No terminal success is claimed. The preceding Transport runtime CI remains failed with separately recorded unclassified failures. Existing owner retains hosted-result evidence; publication does not imply green full CI or user acceptance.

No operational database migration, monitoring activation, remote deployment or PKG-03 local-only source is included. Sol stays archived.06A's three fixes are verified and its same owner is reconciling this published base before final exact approval;06B/07 remain in preparation/review and03 remains local-only review. None receives a main-write slot by this release. Read-only Rory guides/frozen artifacts and final user acceptance remain protected.
