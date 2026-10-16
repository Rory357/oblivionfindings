# Canonical recognition social expansion — 16 October 2026

All social records remain attached to canonical HR kudos and replies. One operating organisation uses current staff status, permissions, approved sites, the post audience and record ownership. Public recognition now permits comments from any eligible audience reader with `hr.recognition.view` and `hr.recognition.give`; private recognition remains limited to its giver and actual recipients. Team recipient snapshots remain historical and unchanged by participation.

## Commands and reader contract

- Include `routes/company-hub-recognition-social.php` outside the existing Company Hub route group; the new file declares its own authenticated, verified, Hub-permission group.
- `GET /company-hub/recognition/{kudos}/conversation` returns the current `HubRecognition::describe` projection.
- `POST /company-hub/recognition/{kudos}/replies` accepts `actor_id`, `body`, optional `parent_id`, `mention_ids[]` and `images[]`. Parents must be a current visible comment on the same canonical kudos; nesting is bounded.
- `PATCH /company-hub/recognition/{kudos}/replies/{reply}` accepts `actor_id`, `version`, `body` and optional `mention_ids[]`. Only the current author edits. An edit preserves existing pictures and parent identity. Stale versions or changed accounts return 409.
- `DELETE /company-hub/recognition/{kudos}/replies/{reply}` accepts `actor_id` and `version`. Author withdrawal removes the text, mention records and image metadata, schedules private-byte cleanup after commit, and clears source notifications. A retained child gets a content-free removed-parent placeholder.
- `PATCH /company-hub/recognition/{kudos}/replies/{reply}/moderation` accepts `actor_id`, `version`, `hidden` and a nonblank `reason` up to 500 characters. Current authorised HR managers or Hub moderators can hide and restore a comment. Ordinary readers receive a content-free placeholder; moderators can inspect the retained text and reason. Hidden image routes deny ordinary readers. Restoration does not issue fresh mention notifications.
- Both `GET /company-hub/recognition/mention-options` and `GET /company-hub/recognition/{kudos}/mention-options` return `{actor_id, available, people:[{id,name}]}`. The latter intersects the actual post audience. Every submitted mention is revalidated against the actual canonical target inside the transaction. Mentions do not expand the audience.
- `GET /company-hub/recognition-images/{image}` requires current Hub and recognition read authority, the actual canonical post, reply visibility and an owned private path. No storage path is exposed in projections. JPEG, PNG and WebP uploads are limited to four pictures, 5 MB each, with 12,000-pixel dimension bounds.

Conversation replies add `user_id`, `parent_id`, `removed`, `hidden`, `edited_at`, `can_edit`, `can_moderate`, `moderation_reason`, `mentions`, `images`, `author_url` and `avatar_url`. Recognition adds `author_id`, `author_url`, `avatar_url`, `recipient_avatar_url`, `mentions`, `images` and `social_available`. Existing reply IDs, versions, author-withdrawal routes and card appreciation remain canonical.

## Creation, retry and delivery safety

`POST /hr/feed/kudos` retains its existing Hub receipt negotiation (`hub_receipt=1`, JSON `Accept`, current `actor_id`). Optional `mention_ids[]`, `images[]` and `client_request_id` extend that same command. A UUID request ID is bound to the actor and a hash of the validated payload and actual image bytes. Repeating that send returns the exact existing canonical receipt. Reusing the key for changed content returns 409. The actor row serialises simultaneous retries. Legacy HR redirects remain supported.

`recognitionFormData(payload, actorId)` encodes this multipart command. The shared wizard only loads Hub mention options when its caller supplies the optional `submission.mentionOptions` URL. Recipient changes clear selected mentions. Browser previews use local object URLs and revoke them when replaced or closed. An uncertain receipt can be confirmed again using the frozen payload and original request UUID.

New mention notifications contain no message preview and recheck current actor, recipient, source, staff, sites, audience, permission and notification preference at actual delivery. Removed mentions and hidden/withdrawn replies remove existing source notices. Queued reply previews use the current canonical text and source visibility, including a directly replied-to comment author.

Disk writes are guarded by the outer receipt transaction so validation or late receipt failure removes newly written pictures. Canonical deletion and author withdrawal schedule owned-image cleanup after commit. Moderation preserves bytes for restoration.

## Deployment and verification

Additive migration: `2026_10_16_140000_extend_canonical_recognition_social.php`. After all ten new social scenarios passed, root applied this exact migration to the local application database (actual exit 0) and verified ten recognition routes. The existing author-withdrawal/version prerequisite `2026_10_14_240000` was already applied (batch 66). No live social data was sent. Optional social reads fail closed before activation, and a rollback refuses to discard populated social records without review.

Focused backend coverage is in `HubRecognitionSocialTest.php`, with shared HR participation expectations updated in `RecognitionWizardBackendTest.php` and `HrTeamRecognitionTest.php`. Focused composer and multipart tests are in `recognition-wizard.test.tsx` and `recognition-social-fields.test.tsx`.

- Focused composer, attachment, mention and receipt checks: 34 tests passed across three files (latest wizard-only run: 21 passed); focused ESLint passed.
- Source syntax checks passed. Scoped PHP formatting was applied to the recognition-owned files.
- Coordinated combined database run: actual exit 1, **156 passed / 2 failed / 1,898 assertions**, 1,354.19 seconds. All ten new social scenarios passed. Failures were stale assertions: public team audience reply eligibility still expected false, and a document companion-block assertion compared JSON key order. Both assertions are corrected; no product changes resulted from the run.
- Narrow failed-pair rerun: actual **exit 0, 2 passed / 34 assertions**, 804.59 seconds. Both corrected methods now complete their remaining workflows. Together with the combined run's 156 passing cases, all 158 selected cases have passed. Evidence: `storage/logs/company-hub-social-documents-combined-20261016.log` (compact captured result/failure summary) and `storage/logs/company-hub-social-documents-failed-pair-20261016.log` (captured final stdout/status).
- Final scoped Pint, frontend ESLint and whitespace checks passed. No backend test worker remains active. Application activation was coordinated and performed by root, separately from the per-process isolated tests.
