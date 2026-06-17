# Safeguarding Redesign — Step Plan: 07a — Evidence (SafeguardingAttachment)

> Split: **7a** evidence (model + upload/download + detail Evidence section); **7b** auto-advance (W5) +
> review/ack reminders (W9) + W10 close check verify.

## 0. Identity
- **Step:** 7a — `SafeguardingAttachment` (W8): model + migration + sensitivity-gated upload/download/delete + detail Evidence rail section
- **New:** `app/Models/SafeguardingAttachment.php`, `app/Http/Controllers/SafeguardingAttachmentController.php`, migration `2026_06_18_000001_create_safeguarding_attachments`, Evidence section in `concern-dialog.tsx`
- **Drop refs:** HANDOFF §7.4 + gap C2/D; Incidents `IncidentController::uploadAttachment/downloadAttachment/removeAttachment` + `PhotosSection` (§7 template)
- **Migration:** run local autonomously, reversible.

## 1. Backend
- Migration `safeguarding_attachments`: id, safeguarding_concern_id (fk cascade), uploaded_by (fk users nullOnDelete), disk (default 'public'), original_name, path, mime (null), size (null int), notes (text null), **is_sensitive** (bool default false), timestamps, softDeletes; index (concern_id).
- Model: fillable + cast is_sensitive bool; `concern()`, `uploader()`; soft deletes.
- Controller `SafeguardingAttachmentController`:
  - `store(concern)` — `authorize('update', concern)`; validate file required|file|max:10240, notes nullable, is_sensitive boolean; `$file->store('safeguarding_attachments','public')`; create record; `back()`.
  - `download(concern, attachment)` — `authorize('view', concern)`; ownership; **if `is_sensitive` → require `viewSensitive` (403 else)**; `Storage::disk->download`.
  - `destroy(concern, attachment)` — `authorize('update', concern)`; ownership; delete file + record; `back()`.
- Routes (`routes/safeguarding.php`): POST `/safeguarding/{concern}/attachments`; GET `…/{attachment}/download`; DELETE `…/{attachment}`.

## 2. Detail Evidence section (`SafeguardingConcernDialog`)
- Add `'evidence'` rail section (between Action plan and Linked records); badge = attachment count.
- `buildConcernDetail` += `attachments` (redaction-aware): each {id, name, mime, is_image, size, notes, is_sensitive, uploaded_by, created_at, download_url}. **Sensitive attachments for a viewer without viewSensitive → `{id, locked:true}`** (name/download redacted) — need-to-know on evidence (gap G3).
- Section UI: upload form (file + notes + "sensitive" checkbox) when `can.update`; image thumbnails (lazy `loading="lazy"`) + doc rows (download + delete). Locked sensitive items show a lock placeholder.

## 3. Wizard evidence (step ②)
- **Deferred — keep the existing honest InfoCard** ("photos & documents can be attached from the concern once it's raised"). Rationale: create-time upload needs `forceFormData` (which turns store()'s boolean fields into strings → breaks the `boolean` validation) or a two-phase staged-upload chain; the detail Evidence section is the robust surface and is one click away via the success pane → "Open concern" → Evidence. Matches the shipped Incidents wizard exactly. Note in PROGRESS.

## 4. Need-to-know (§3b / G3)
- Restricted concern → whole detail already redacted (no attachments). Non-restricted concern, viewer without `viewSensitive` → sensitive attachments listed as locked (no name/download); download endpoint 403s on sensitive without viewSensitive.

## 5. Incidents-consistency (§7)
- Same upload-form/AttachmentRow idiom + `Storage` `public` disk + `back()` + download route shape. Delta: `notes` + `is_sensitive` per file + sensitivity-gated download (incidents had `portal_visible` instead).

## 6. Tests
- upload creates attachment; download streams; sensitive download 403 without viewSensitive, OK with; destroy removes; detail payload includes attachments (sensitive locked for uncleared viewer). `Storage::fake('public')`.

## 7. Verify
- migrate local; pint new PHP; tsc/eslint/build; safeguarding suite green; commit + tick PROGRESS.

## 8. 7b (next)
- W5 auto-advance: `SafeguardingInvestigation` completion → concern action_plan/monitoring (observer/controller).
- W9: scheduled job for due `next_review_date` risk reviews + stalled ext-report acks (console command + schedule).
- W10: subject-informed close check already warned in ClosePane — verify/keep.
