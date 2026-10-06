# Owner-licensed medicine catalogue and protected chart backups

This module has no installed or maintained supplier catalogue. The owner must supply a licensed dataset and the licence evidence, source/version and attribution. There is no scraping, remote image retrieval, pharmaceutical advice or inferred product strength.

## Catalogue contract

`GET /emar/catalogue` renders `emar/catalogue/index`. `source_page` selects 10 sources; `source_meta` has current_page, last_page and total. Each source contains provenance/status/version and at most 1,000 imported products. General lookup requires an approved medication reader; writes require current `medications.catalogue.manage` authority. Reviewed data is immutable: replacement needs a new source version.

- `POST /sources`: supplier, source_name, source_version, attribution, licence_reference, licence_attested=true. Returns id/version.
- `POST /sources/{source}/import`: multipart dataset (JSON array, 2 MiB maximum) and version. One import per source version, 1..1,000 unique exact identities. Every row contains only code_system, code, name, strength, form.
- `POST /sources/{source}/products/{product}/photo`: version, request_uuid and private JPEG/PNG/WebP photo (2 MiB, maximum 4,096 pixels per side). Filename/path from the client is never used. The actual MIME, dimensions and SHA-256 are checked. Replaying identical bytes returns the same file; conflicting replay is denied.
- `POST /sources/{source}/review`: version and expires_at ISO time with explicit offset, future expiry within one year. Owner review records actor and time.
- `POST /sources/{source}/revoke`: version. Expired/revoked/unreviewed sources cannot resolve or serve ordinary-reader photos.
- `GET /match`: all five identity fields. Returns matched with product/provenance, or unavailable, no_exact_match, expired or review_required. Space/case normalization only; no fuzzy strength/form/code fallback. Multiple current exact sources require review.
- `GET /products/{product}/photo`: current private photo, exact stored digest, no-store/nosniff; never a public storage URL.

The medicine detail uses `GET|POST /medicines/{medication}/binding`. Read/write both use canonical current person, Site and medicine scope; concealed controlled medicine remains 404. Writes additionally require catalogue.manage plus stock.update, product_id, expected_medication_version, product_label_confirmed=true and reference. Current medicine name/form and an existing NZULM code must match; product strength is explicitly checked against packaging because prescribed dose is not product strength. Immutable binding history records medicine version/identity hash, product, verifier/time/reference. Stale medicine identity, expired/revoked/ambiguous source or unverifiable photo returns review_required without a product image.

`GET /medicines/{medication}/products?q=&page=` is the scoped binding picker. It returns 25 current reviewed photo products with exact name/form and any existing NZULM code, plus pagination. Search narrows code/strength/source only; it cannot widen the exact medicine match. All writes return JSON.

## Protected backup contract

`GET /emar/backups` renders `emar/backups/index`. It returns approved Sites, schedules, redacted delivery history, readiness, and can_manage. `deliveries_page`/`deliveries_meta` paginate 25 deliveries. Recipient search is `GET /sites/{site}/recipients?search=&page=`; it filters all eligible current verified staff before 25-row pagination, so later accounts remain reachable.

- `PUT /sites/{site}/schedule`: version (0 new), local_time HH:mm, enabled, retention_days 1..30. A Site has one schedule; timezone is Pacific/Auckland to match the canonical paper-chart day.
- `POST /schedules/{schedule}/recipients`: version, user_id, approved. At most 20 recipients. An approved email hash binds approval to the verified canonical mailbox; changes require reapproval.
- `POST /sites/{site}/prepare`: version and current nz_date. Exactly one durable delivery identity per schedule/NZ date. Previous days are not falsely reconstructed as current charts.
- `POST /deliveries/{delivery}/send|retry`: delivery version. Transport is disabled by default and no live send is accepted during build/test. Only the preparing current actor may submit. Known unsent failures may rebuild and retry; uncertain results never resend automatically.
- `GET /deliveries/{delivery}/download`: current recipient/manager authority, complete current house scope and original person ownership, private no-store PDF.
- `POST /deliveries/{delivery}/password`: own current password and a new code from an enrolled, confirmed personal authenticator. Codes have durable unique-use protection; passwords are never included in an email, index DTO or log. Response is private/no-store/no-cache.

Every recipient independently needs canonical current employment/Site access, verified mailbox, reports.view/export, complete per-person chart access, canonical finance-only exclusion, and controlled view whenever the chart includes controlled evidence. A manager's authority never substitutes for recipient authority. A person's house move conceals that old person's backup from the former house.

`medications:chart-backups` evaluates enabled schedules against the NZ day. Autumn repeated minutes use the earliest UTC occurrence; spring missing minutes use the first valid following minute. The unique per-day identity prevents duplicate deliveries across repeated clock minutes and concurrent dispatches. Root wires the command to the scheduler; this module changes no production schedule or secret.

Preparation reuses DowntimePackService source snapshots and its canonical post-render release check (including actual leave/hospital evidence). The complete pack snapshot and user password are encrypted at rest through application encryption. PDF bytes are separately qpdf AES-256 revision 6, with strong nonempty independent user/owner passwords supplied via stdin rather than process arguments. Plaintext temporary PDF files are private and request-owned. Weak RC4/empty-password protection is rejected. Missing qpdf is visibly unavailable and fail-closed.

Delivery states are preparing, ready, sending, sent, failed, uncertain and purged. A durable attempt claim commits before submission. The external transport runs once inside a one-attempt outer transaction while canonical source/current authority and recipient locks remain held. Nested source validation cannot retry an external side effect. A transport/commit error after possible acceptance becomes uncertain; a committed sent result followed by afterCommit failure stays sent. Interrupted sending is recovered as uncertain. Failed staging keeps its exact owned token until cleanup succeeds. Fixed neutral subject/body and aggregate command output contain no clinical details or passwords.

Retention removes expired encrypted bytes and source/password ciphertext, retaining nonclinical date/state/attempt history. A failed physical delete leaves the row/reference for retry instead of claiming successful retention. No real recipient addresses are assumed or seeded.

## Installation and acceptance

Configure `EMAR_BACKUP_QPDF_PATH` to an absolute reviewed official executable. `EMAR_BACKUP_SEND_ENABLED` defaults false and requires separate owner transport approval. Retain previous application encryption keys until encrypted backup evidence expires. qpdf permissions flags are not DRM; secrecy comes from the nonempty AES-256 password and recipient authorization.

The isolated development proof uses official qpdf 12.4.2 from https://github.com/qpdf/qpdf/releases/tag/v12.4.2, archive SHA-256 773d2fa0c7d161e2271430734338e560a07b25d0edf11ceee8e53de435012766 verified against the official .sha256 release asset. Portable files live only under ignored storage/logs/tooling. Pure tests prove actual revision 6/AESv3, wrong/no password rejection, correct-password decryption with fictional content and one intact page; NZ summer/winter/gap/fold and import/image denials also run without a database. Feature tests use fake encryption/transport while exercising the real canonical source release, recipient scope, durable outcomes, postcommit behavior, MFA and retention. No production migration, transport configuration or live send is performed by these tests.

Temporary encryption work is private and holds an exclusive workspace lease for its lifetime. The schedule command reclaims only known marker-owned UUID workspaces older than one hour when its nonblocking lease lock succeeds; active, recent, symlinked, unmarked and unknown-content workspaces are preserved. Cleanup deletes only the two known PDF files, the lease and empty directory. Mail accepted for any earlier recipient makes a later recipient failure uncertain and nonretryable. Transport failures expose only neutral codes, without chained mailbox or provider error details.
