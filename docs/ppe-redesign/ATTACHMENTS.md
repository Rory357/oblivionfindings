# PPE & Equipment — Premium Document Upload pattern (ATTACHMENTS.md)

Canonical spec for adding **feature-complete document upload** to the PPE redesign modals, matching the rest of H&S (Fleet Incidents, Emergency Drills, Safeguarding). This defines the storage convention, the table-vs-polymorphic decision, the migration + model + controller endpoints PPE needs, and exactly how the existing reusable frontend uploader plugs into the wizard.

> TL;DR — **There is already a reusable, premium, drag-and-drop uploader: `AttachmentUploader` in `resources/js/components/ui/file-dropzone.tsx`. Do NOT build a new `<DocumentUpload/>`.** Create **dedicated** `ppe_attachments` / `ppe_allocation_attachments` / `ppe_inspection_attachments` tables (do **not** reuse `HsAttachment`), and copy the Fleet/Drill controller methods verbatim. The wizard "create" case stages files into `useForm` via the existing `FileDropzone` + `StagedFileCard` exactly as `add-site-dialog.tsx StepDocuments` does.

---

## 0. The two upload UX patterns in this codebase (PPE needs both)

The redesign has two distinct moments where documents attach, and the codebase already has a proven pattern for each:

| Pattern | When | Mechanism | Reference impl |
|---|---|---|---|
| **A. Post-hoc upload to an existing record** | Detail-modal "Documents/Evidence" section; the record already has an ID. | `AttachmentUploader` posts each staged file **sequentially** to a **single-file** `POST …/{id}/attachments` endpoint via `router.post(endpoint, FormData)`. Each file drops out of the queue as it lands. | `fleet-incident-dialog.tsx` line ~564; `drill-detail-dialog.tsx` line ~499 |
| **B. Inline-with-create wizard** | A create wizard with a "Documents" step where the parent record doesn't exist yet. | `FileDropzone` + `StagedFileCard` write `File[]` drafts into `useForm` data; submitted in **one** multipart request with `forceFormData: true`; the **store()** controller loops `$request->file('documents.*.file')`. | `add-site-dialog.tsx` `StepDocuments` line ~1306 + `form.post('/sites', { forceFormData: true })` line ~501 |

For PPE, the design (HANDOFF.md §5 detail-modal + §6 wizards) means:
- **Detail-modal sections** (Overview/Allocation/Inspections/History) → **Pattern A** (`AttachmentUploader`) for adding certificates after the fact. This is the primary surface and covers ~90% of the need.
- **Wizards** (Add inventory, Record inspection, Condemn/Dispose) → can **optionally** use **Pattern B** to capture a doc at creation. **Recommendation: ship Pattern A first** (detail modal), and only add Pattern B to the wizards where the design explicitly wants capture-at-source (inspection photos on Record inspection; disposal evidence on Dispose). Pattern A alone makes every modal feature-complete because the detail modal is reachable from every row.

Both patterns share the same dropzone chrome (`FileDropzone`) and the same per-domain table + controller. The only difference is whether files post one-at-a-time after creation (A) or batched with the parent (B).

---

## 1. Storage disk, path convention, validation, download/authorization — the established convention

Pulled verbatim from the three newest, best implementations:
- `app/Http/Controllers/FleetAssets/IncidentController.php` → `uploadAttachment`/`downloadAttachment`/`destroyAttachment` (lines 279–336) — **the gold standard** (has `kind` + `alt_text` + `notes`, 20 MB cap, `inertiaOrJson`).
- `app/Http/Controllers/HealthSafety/EmergencyDrillController.php` → same trio (lines 440–489) — section literally commented `/* Evidence (premium document upload) */`.
- `app/Http/Controllers/SafeguardingAttachmentController.php` → adds the **need-to-know** gate (`is_sensitive` + `viewSensitive`) and a 10 MB cap.

### Storage disk
- **`public` disk**, hardcoded as `$disk = 'public'` at upload time and persisted to a `disk` column (so a later disk migration is non-breaking). Fleet and Drill both use `public`. Safeguarding also uses `public` despite the polymorphic `hs_attachments` default of `private`.
- **PPE recommendation: `public`** to match Fleet/Drill. PPE certificates (declarations of conformity, fit-test records) are not need-to-know-sensitive the way safeguarding evidence is, so the simpler `public` disk + URL-based inline image preview is correct. (If a future requirement makes fit-test records sensitive, the `disk` column + a gated download route already support switching to `private` per-row without a schema change.)

### Path convention
```php
$path = $file->store('ppe_attachments', 'public');
// → storage/app/public/ppe_attachments/<hash>.<ext>
```
One folder per table, named after the table:
- inventory item docs → `ppe_attachments`
- allocation docs (fit-test) → `ppe_allocation_attachments`
- inspection docs (photos/reports) → `ppe_inspection_attachments`

`$file->store(...)` returns a hashed filename (Laravel default) — never trust/keep the client filename as the path; the original name is stored separately in `original_name` for the download `Content-Disposition`.

### Validation rules
The established baseline (Fleet/Drill `uploadAttachment`):
```php
$request->validate([
    'file'     => ['required', 'file', 'max:20480'], // 20 MB (KB units)
    'kind'     => ['nullable', 'string', 'max:30'],
    'notes'    => ['nullable', 'string', 'max:1000'],
    'alt_text' => ['nullable', 'string', 'max:255'],
]);
```
Notes on the convention:
- **Size**: Fleet/Drill use **20 MB** (`max:20480`, KB) for photos/dashcam/PDFs; Safeguarding uses 10 MB. **PPE: 20 MB** (certificate PDFs + inspection photos). The `max` value is **kilobytes**.
- **No `mimes:` rule on the server.** All three gold-standard controllers deliberately validate only `file` (presence + max size), NOT mime type. **Mime restriction is done on the client** via the `accept` attribute on the dropzone (`accept="image/*,.pdf,.doc,.docx"`), which is a UX hint, not a security control. Keep this convention for parity (a hard server `mimes:` rule tends to reject legitimate files because browsers report inconsistent mime strings for `.doc`/`.docx`). If you want a belt-and-braces server guard, add `'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx'` — but the rest of H&S does not, so omit it for strict parity.
- `kind` is a free short tag the frontend supplies (e.g. `certificate`, `declaration_of_conformity`, `fit_test`, `inspection_photo`, `inspection_report`, `disposal_evidence`). Stored as `string(30)`, nullable.
- `alt_text` powers a11y for image previews (`<img alt={a.alt_text ?? a.original_name}>`).
- Booleans (if ever needed, e.g. a sensitive flag): read with `$request->boolean('field')` and validate with `['nullable']` only (NOT `['boolean']`) — a multipart `"true"/"1"/"on"` value fails a strict `boolean` rule. See SafeguardingAttachmentController line 26.

### Download + authorization approach
```php
public function downloadAttachment(Request $request, PpeInventory $inventory, PpeAttachment $attachment)
{
    abort_unless((int) $attachment->ppe_inventory_id === (int) $inventory->id, 404); // ownership
    $disk = $attachment->disk ?: 'public';
    abort_unless(Storage::disk($disk)->exists($attachment->path), 404);             // missing file
    return Storage::disk($disk)->download($attachment->path, $attachment->original_name);
}
```
- **Route-level permission** is the primary gate: the download route sits behind `permission:hazards.view` (read), while upload/delete sit behind `permission:hazards.manage` (write) — exactly the Drill route structure (`routes/health-safety.php` lines 236–244).
- **Ownership check**: `abort_unless($attachment->{parent}_id === $parent->id, 404)` defends against IDOR (an attachment id from a different parent). Every gold-standard controller does this on download AND delete.
- **Existence check** before download (`Storage::disk(...)->exists(...)` → 404) so a deleted-on-disk file doesn't 500.
- **Inline image preview** in the detail modal uses `Storage::disk($disk)->url($a->path)` (Fleet, payload line 709) OR a route URL (`"/health-safety/.../attachments/{id}/download"`, Drill, line 583). For `public` disk, the `->url()` form gives a direct `/storage/...` link that renders `<img src>` without hitting the controller — preferred for images. Use the download **route** for non-images (forces attachment download with the friendly filename).
- **Delete**: physically delete the blob (`Storage::disk($disk)->delete($path)` guarded by `exists`) then soft-delete the row (`$attachment->delete()`), then `AuditLogger::log(...)`.

### Audit
Fleet logs `fleet.incident.attachment.add` / `.remove` via `AuditLogger::log(...)`. PPE should log `ppe.attachment.add` / `.remove` for parity (the PPE controller doesn't currently use AuditLogger, but adding it for evidence mutations matches the H&S bar; non-blocking if you skip it for v1).

---

## 2. Decision: dedicated `ppe_attachments` table vs reuse polymorphic `HsAttachment` — **RECOMMENDATION: dedicated tables**

### What `HsAttachment` actually is
- Model: `app/Models/HsAttachment.php` — **polymorphic** (`morphTo('attachable')`), with `uploader()`. Fillable: `attachable_type, attachable_id, uploaded_by, original_name, path, disk, mime_type, size_bytes, description`.
- Table `hs_attachments` **does exist** — created in `database/migrations/2026_03_29_000001_enhance_worker_participation.php` (line 27, `$table->morphs('attachable')`) and present in `database/schema/mysql-schema.sql` (line 10927). Columns: `attachable_type, attachable_id, uploaded_by, original_name, path, disk DEFAULT 'private', mime_type, size_bytes, description, timestamps, softDeletes`.
- Used today via `morphMany(HsAttachment::class, 'attachable')` on worker-participation models (`HsCommitteeMeeting`, `HsConsultation`, etc.).

### Why NOT to reuse it for PPE
1. **The three newest gold-standard modules deliberately chose dedicated tables over it.** Fleet (2026-06-18), Safeguarding (2026-06-18) and Emergency Drills (2026-06-20) each created their own `*_attachments` table with a typed FK, **even though `HsAttachment` already existed**. The migration headers explicitly say "Mirrors `safeguarding_attachments` / `fleet_incident_attachments`." This is the current, intentional house style — following it keeps PPE consistent with its closest siblings.
2. **Schema mismatch — `HsAttachment` lacks the fields the premium pattern needs.** No `kind`, no `alt_text`, no per-file domain flag. Its columns are `mime_type`/`size_bytes`/`description`; the gold-standard tables use `mime`/`size`/`notes` + `kind` + `alt_text`. The `AttachmentUploader` posts `notes` (+ optional sensitive), and the detail dialogs render `kind`/`alt_text`/`is_image`. Reusing `HsAttachment` means either (a) altering the shared polymorphic table (risky — touches worker-participation) or (b) building a divergent UI for PPE. Both are worse than a 1-file migration.
3. **Disk default `private`** on `hs_attachments` is the opposite of the PPE/Fleet/Drill convention (`public`, with inline image previews). You'd be overriding it on every insert anyway.
4. **No controller is wired to `HsAttachment`.** There is NO upload/download/delete controller for the polymorphic table — worker-participation writes its attachment rows ad hoc. So "reuse" buys you the model class but zero of the endpoint/authorization/IDOR logic you'd copy from Fleet regardless. The marginal saving is ~40 lines of a near-empty model.
5. **Polymorphic ownership checks are weaker.** A typed FK (`ppe_inventory_id`) + `abort_unless($a->ppe_inventory_id === $inventory->id, 404)` is a clean IDOR guard. A polymorphic `(attachable_type, attachable_id)` pair invites mistakes (forgetting to check `attachable_type`).

### Recommendation
**Create dedicated tables**, one per parent that needs evidence, mirroring `fleet_incident_attachments` / `emergency_drill_attachments` exactly:

| Table | Parent | Evidence it holds (HANDOFF intent) |
|---|---|---|
| `ppe_attachments` | `ppe_inventory` (`PpeInventory`) | Item-level: certificates, **declarations of conformity**, purchase invoices, **disposal evidence** (condemn/dispose write to the item). |
| `ppe_allocation_attachments` | `ppe_allocations` (`PpeAllocation`) | **Fit-test records** (AS/NZS 1715 for RPE), signed acknowledgement forms, training certificates. |
| `ppe_inspection_attachments` | `ppe_inspections` (`PpeInspection`) | **Inspection photos + reports**. |

> Pragmatic minimum: if you want to ship one table, **`ppe_attachments` on `PpeInventory` alone covers the bulk** (certificates + disposal evidence + — by routing through the item — most inspection/fit-test docs). But because the design wants fit-test docs on the *allocation* and inspection photos on the *inspection*, the clean answer is three thin tables (they're identical boilerplate). Start with `ppe_attachments`; add the other two in the same migration file or a follow-up if the wizards demand capture-at-source.

---

## 3. Migration + model + controller endpoints PPE needs (copy-paste-ready)

### 3a. Migration — `database/migrations/2026_06_20_020001_create_ppe_attachments_table.php`
(Mirror of `2026_06_20_010001_create_emergency_drill_attachments_table.php`. Add the allocation/inspection variants as extra `Schema::create` blocks in the same file, or separate migrations.)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPE redesign — premium document upload. Evidence attached to PPE records:
 * certificates / declarations of conformity / disposal evidence on items,
 * fit-test records on allocations, inspection photos/reports on inspections.
 * Mirrors fleet_incident_attachments / emergency_drill_attachments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ppe_attachments') && Schema::hasTable('ppe_inventory')) {
            Schema::create('ppe_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ppe_inventory_id')->constrained('ppe_inventory')->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk')->default('public');
                $table->string('original_name');
                $table->string('path');
                $table->string('mime')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('kind', 30)->nullable(); // certificate|declaration_of_conformity|disposal_evidence|document
                $table->text('notes')->nullable();
                $table->string('alt_text')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('ppe_inventory_id', 'ppe_attach_inv_idx');
            });
        }

        if (! Schema::hasTable('ppe_allocation_attachments') && Schema::hasTable('ppe_allocations')) {
            Schema::create('ppe_allocation_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ppe_allocation_id')->constrained('ppe_allocations')->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk')->default('public');
                $table->string('original_name');
                $table->string('path');
                $table->string('mime')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('kind', 30)->nullable(); // fit_test|acknowledgement|training|document
                $table->text('notes')->nullable();
                $table->string('alt_text')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('ppe_allocation_id', 'ppe_attach_alloc_idx');
            });
        }

        if (! Schema::hasTable('ppe_inspection_attachments') && Schema::hasTable('ppe_inspections')) {
            Schema::create('ppe_inspection_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ppe_inspection_id')->constrained('ppe_inspections')->cascadeOnDelete();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk')->default('public');
                $table->string('original_name');
                $table->string('path');
                $table->string('mime')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('kind', 30)->nullable(); // inspection_photo|inspection_report|document
                $table->text('notes')->nullable();
                $table->string('alt_text')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('ppe_inspection_id', 'ppe_attach_insp_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ppe_inspection_attachments');
        Schema::dropIfExists('ppe_allocation_attachments');
        Schema::dropIfExists('ppe_attachments');
    }
};
```
> ⚠️ The inventory table name is **`ppe_inventory`** (singular, set via `protected $table` on the model) — `constrained('ppe_inventory')`, not `ppe_inventories`. Verify `ppe_allocations` / `ppe_inspections` table names against `2026_03_28_200005_create_ppe_tables.php` before finalising (the FK `constrained()` infers from the column name, so pass the table explicitly as shown).

### 3b. Model — `app/Models/PpeAttachment.php`
(Exact mirror of `app/Models/EmergencyDrillAttachment.php`.)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PPE redesign — evidence attached to a PPE inventory item (certificates,
 * declarations of conformity, disposal evidence).
 */
class PpeAttachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ppe_inventory_id', 'uploaded_by', 'disk',
        'original_name', 'path', 'mime', 'size', 'kind', 'notes', 'alt_text',
    ];

    protected $casts = ['size' => 'integer'];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(PpeInventory::class, 'ppe_inventory_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
```
Plus `PpeAllocationAttachment` (FK `ppe_allocation_id`, relation `allocation()`) and `PpeInspectionAttachment` (FK `ppe_inspection_id`, relation `inspection()`) — identical bodies.

### 3c. Relations on the parent models
Add to `app/Models/PpeInventory.php` (it already has `allocations()` / `inspections()` HasMany at lines 55–63):
```php
public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(PpeAttachment::class);
}
```
Add `attachments()` → `PpeAllocationAttachment` on `PpeAllocation`, and → `PpeInspectionAttachment` on `PpeInspection`.

### 3d. Controller endpoints on `PpeController` (mirror of Fleet/Drill trio)
The existing PPE controller returns `redirect()->back()` everywhere (no `RespondsToInertiaOrJson` trait), so the **Safeguarding/Drill `return back()->with('success', ...)` form** is the right match (simplest, and the modals reload via Inertia partial). Add `use Illuminate\Support\Facades\Storage;` and the new models.

```php
/* ---- Evidence (premium document upload) — inventory item ---- */

public function uploadInventoryAttachment(Request $request, PpeInventory $inventory): RedirectResponse
{
    $data = $request->validate([
        'file'     => ['required', 'file', 'max:20480'], // 20 MB
        'kind'     => ['nullable', 'string', 'max:30'],
        'notes'    => ['nullable', 'string', 'max:1000'],
        'alt_text' => ['nullable', 'string', 'max:255'],
    ]);

    $file = $request->file('file');
    $disk = 'public';
    $path = $file->store('ppe_attachments', $disk);

    $inventory->attachments()->create([
        'uploaded_by'   => $request->user()?->id,
        'disk'          => $disk,
        'original_name' => $file->getClientOriginalName(),
        'path'          => $path,
        'mime'          => $file->getClientMimeType(),
        'size'          => $file->getSize(),
        'kind'          => $data['kind'] ?? null,
        'notes'         => $data['notes'] ?? null,
        'alt_text'      => $data['alt_text'] ?? null,
    ]);

    return back()->with('success', 'Document uploaded.');
}

public function downloadInventoryAttachment(Request $request, PpeInventory $inventory, PpeAttachment $attachment)
{
    abort_unless((int) $attachment->ppe_inventory_id === (int) $inventory->id, 404);
    $disk = $attachment->disk ?: 'public';
    abort_unless(Storage::disk($disk)->exists($attachment->path), 404);
    return Storage::disk($disk)->download($attachment->path, $attachment->original_name);
}

public function destroyInventoryAttachment(Request $request, PpeInventory $inventory, PpeAttachment $attachment): RedirectResponse
{
    abort_unless((int) $attachment->ppe_inventory_id === (int) $inventory->id, 404);
    $disk = $attachment->disk ?: 'public';
    if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
        Storage::disk($disk)->delete($attachment->path);
    }
    $attachment->delete();
    return back()->with('success', 'Document removed.');
}
```
Repeat the trio for allocations (`uploadAllocationAttachment(PpeAllocation $allocation)` → folder `ppe_allocation_attachments`) and inspections (`uploadInspectionAttachment(PpeInspection $inspection)` → folder `ppe_inspection_attachments`).

### 3e. Routes — append inside the `prefix('ppe')->name('ppe.')` group (`routes/health-safety.php` ~line 311)
Put **uploads/deletes** inside the existing `permission:hazards.manage` block (lines 317–331) and **downloads** inside the `permission:hazards.view` block (or its own `hazards.view` group), mirroring the Drill structure (lines 236–244):
```php
// inside permission:hazards.manage group
Route::post('/inventory/{inventory}/attachments', [PpeController::class, 'uploadInventoryAttachment'])->name('inventory.attachments.store');
Route::delete('/inventory/{inventory}/attachments/{attachment}', [PpeController::class, 'destroyInventoryAttachment'])->name('inventory.attachments.destroy');
Route::post('/allocations/{allocation}/attachments', [PpeController::class, 'uploadAllocationAttachment'])->name('allocations.attachments.store');
Route::delete('/allocations/{allocation}/attachments/{attachment}', [PpeController::class, 'destroyAllocationAttachment'])->name('allocations.attachments.destroy');
Route::post('/inspections/{inspection}/attachments', [PpeController::class, 'uploadInspectionAttachment'])->name('inspections.attachments.store');
Route::delete('/inspections/{inspection}/attachments/{attachment}', [PpeController::class, 'destroyInspectionAttachment'])->name('inspections.attachments.destroy');

// inside permission:hazards.view group
Route::get('/inventory/{inventory}/attachments/{attachment}/download', [PpeController::class, 'downloadInventoryAttachment'])->name('inventory.attachments.download');
Route::get('/allocations/{allocation}/attachments/{attachment}/download', [PpeController::class, 'downloadAllocationAttachment'])->name('allocations.attachments.download');
Route::get('/inspections/{inspection}/attachments/{attachment}/download', [PpeController::class, 'downloadInspectionAttachment'])->name('inspections.attachments.download');
```
> Route-model-binding: `{attachment}` binds `PpeAttachment` / `PpeAllocationAttachment` / `PpeInspectionAttachment` by the controller method's type-hint — no `scopeBindings()` needed because the ownership `abort_unless` does the cross-check.

### 3f. Detail payload — add to the PPE `detail` prop (HANDOFF §Backend asks for a `detail` prop)
For each attachment, serialize exactly like Fleet (controller line 706) / Drill (line 568):
```php
'attachments' => $inventory->attachments->with('uploader:id,name')->get()->map(fn (PpeAttachment $a) => [
    'id'             => $a->id,
    'original_name'  => $a->original_name,
    'url'            => Storage::disk($a->disk ?: 'public')->url($a->path), // inline preview for images
    'download_url'   => "/health-safety/ppe/inventory/{$inventory->id}/attachments/{$a->id}/download",
    'mime'           => $a->mime,
    'kind'           => $a->kind,
    'notes'          => $a->notes,
    'alt_text'       => $a->alt_text,
    'size'           => $a->size,
    'is_image'       => $a->isImage(),
    'uploaded_by'    => $a->uploader ? ['id' => $a->uploader->id, 'name' => $a->uploader->name] : null,
    'created_at'     => optional($a->created_at)->toISOString(),
])->values(),
```

---

## 4. Frontend — the reusable uploader already exists; do NOT build a new one

### 4a. The component to compose: `AttachmentUploader`
**File: `resources/js/components/ui/file-dropzone.tsx`** — exports `FileDropzone`, `StagedFileCard`, `formatFileSize`, and the full **`AttachmentUploader`**. This file IS the "premium document upload" pattern the migration comments reference as "the shared AttachmentUploader". It is token-only (carries the sanctioned `eslint-disable no-restricted-syntax` header for its bespoke dropzone chrome). It is already wired in `fleet-incident-dialog.tsx`, `drill-detail-dialog.tsx`, `incident-detail-dialog.tsx`, `safeguarding/concern-dialog.tsx`, and the worker-participation dialogs.

**`AttachmentUploader` props (exact signature):**
```ts
function AttachmentUploader(props: {
    endpoint: string;                                  // single-file POST endpoint
    noteField?: string | null;                         // form field for the per-file note (default null → no note input)
    sensitive?: { field: string; label: string } | null; // optional per-file checkbox (Safeguarding need-to-know)
    accept?: string;                                   // client-side mime hint, e.g. "image/*,.pdf,.doc,.docx"
    hint?: string;                                     // dropzone subtext
}): JSX.Element
```
Behaviour (already implemented — no work needed): drag/drop or browse multiple files → staged as premium cards with optional per-file note + sensitive toggle → on "Upload N files" it posts **sequentially** to `endpoint` via `router.post(endpoint, FormData, { preserveScroll, preserveState })`, removing each file from the queue on success so the remaining files read as a progress queue. Inline error on failure.

### 4b. Pattern A wiring — drop it into the PPE detail-modal sections (primary, do this first)
In the PPE detail-as-modal (`resources/js/pages/health-safety/ppe/index.tsx` detail dialog), in each relevant section, render existing docs + the uploader. Copy the Fleet/Drill block verbatim, just swap the endpoint:

```tsx
import { AttachmentUploader, formatFileSize } from '@/components/ui/file-dropzone';
import { Paperclip } from 'lucide-react';

// — Overview / Documents section (inventory item) —
{detail.can.manage && (
    <AttachmentUploader
        endpoint={`/health-safety/ppe/inventory/${detail.id}/attachments`}
        noteField="notes"
        accept="image/*,.pdf,.doc,.docx"
        hint="Certificate, declaration of conformity, disposal evidence — up to 20 MB each"
    />
)}
{detail.attachments.length ? (
    <div className="grid grid-cols-3 gap-2">
        {detail.attachments.map((a) => (
            <a key={a.id} href={a.download_url} className="overflow-hidden rounded-lg border border-border">
                {a.is_image ? (
                    <img src={a.url} alt={a.alt_text ?? a.original_name} className="h-24 w-full object-cover" />
                ) : (
                    <span className="flex h-24 w-full items-center justify-center bg-muted text-muted-foreground">
                        <Paperclip className="h-5 w-5" />
                    </span>
                )}
                <div className="p-2">
                    <div className="truncate text-[13px] font-medium">{a.original_name}</div>
                    <div className="text-[11px] text-muted-foreground">{formatFileSize(a.size)}</div>
                </div>
            </a>
        ))}
    </div>
) : null}
```
- **Allocation section** → `endpoint={`/health-safety/ppe/allocations/${allocation.id}/attachments`}`, `hint="Fit-test record (AS/NZS 1715), acknowledgement, training certificate"`. For RPE allocations this is where the **fit-test record** lives.
- **Inspections section** → `endpoint={`/health-safety/ppe/inspections/${inspection.id}/attachments`}`, `hint="Inspection photos and report"`.
- The detail modal already reloads on these posts because `AttachmentUploader` uses `preserveState` and the PPE `detail` prop is re-fetched via the partial reload (`router.get(..., { only: ['detail'] })` pattern from HANDOFF §5). Ensure the success flash triggers a `detail` refresh; the existing dialogs rely on the controller `back()` re-rendering the page props.

### 4c. Pattern B wiring — capture-at-source in a wizard (only where the design wants it)
If the design wants a doc captured **inside** a create wizard (e.g. inspection photo on the Record-inspection step, disposal evidence on Dispose), do NOT use `AttachmentUploader` (it posts to its own endpoint). Instead stage files into the wizard's `useForm`, exactly like `add-site-dialog.tsx StepDocuments` (line 1306):

```tsx
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';

// form shape (Inertia useForm):
type InspectionDraft = { file: File; kind: string; note: string };
const form = useForm<{ result: string; /* ... */; documents: InspectionDraft[] }>({ /* ..., */ documents: [] });

// in the step body:
<FileDropzone
    onFiles={(files) => form.setData('documents', [
        ...form.data.documents,
        ...files.map((file) => ({ file, kind: file.type.startsWith('image/') ? 'inspection_photo' : 'inspection_report', note: '' })),
    ])}
    accept="image/*,.pdf,.doc,.docx"
    hint="PDF, Word, images — up to 20 MB each"
/>
{form.data.documents.map((d, i) => (
    <StagedFileCard key={i} file={d.file} onRemove={() => form.setData('documents', form.data.documents.filter((_, idx) => idx !== i))}>
        {/* optional per-file note Input → patch form.data.documents[i].note */}
    </StagedFileCard>
))}

// submit — forceFormData turns the nested File[] into multipart:
form.post(`/health-safety/ppe/inventory/${inventoryId}/inspections`, {
    forceFormData: true,    // REQUIRED for File payloads (HANDOFF §6 already mandates this on every wizard)
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { /* success pane */ },
});
```
Server side, the `storeInspection`-style method then accepts `documents.*.file`:
```php
'documents'             => ['nullable', 'array'],
'documents.*.file'      => ['required', 'file', 'max:20480'],
'documents.*.kind'      => ['nullable', 'string', 'max:30'],
'documents.*.note'      => ['nullable', 'string', 'max:1000'],
// after creating the inspection:
foreach ($request->file('documents', []) as $i => $upload) {
    $file = $upload['file'];
    $inspection->attachments()->create([
        'uploaded_by'   => $request->user()?->id,
        'disk'          => 'public',
        'original_name' => $file->getClientOriginalName(),
        'path'          => $file->store('ppe_inspection_attachments', 'public'),
        'mime'          => $file->getClientMimeType(),
        'size'          => $file->getSize(),
        'kind'          => $request->input("documents.$i.kind"),
        'notes'         => $request->input("documents.$i.note"),
    ]);
}
```
> Because the wizards already submit with `forceFormData: true` (HANDOFF §6), adding a `documents` step is purely additive — no change to the submit plumbing.

### 4d. Do NOT build a `<DocumentUpload/>`
A new component would duplicate `AttachmentUploader`/`FileDropzone`/`StagedFileCard`. The only thing missing from `AttachmentUploader` for PPE is a per-file **`kind`** selector (it supports `noteField` + `sensitive`, not `kind`). Two options:
1. **Simplest (recommended for v1):** don't surface `kind` in the UI — let the server default `kind` per endpoint/folder (item endpoint → `certificate`, inspection endpoint → `inspection_photo` for images). The `kind` column stays useful for filtering without a UI control.
2. **If per-file `kind` is desired:** make a tiny additive change to `AttachmentUploader` — add an optional `kindField?: { field: string; options: {value;label}[] }` prop that renders a `SelectInput` in the staged card and appends `fd.append(kindField.field, it.kind)`. ~10 lines, mirrors how `sensitive` is already handled (lines 194, 224–234). This keeps ONE uploader for the whole app.

Either way: **compose the existing component; extend it minimally if needed; never fork it.**

---

## 5. Summary checklist for the PPE redesign

- [ ] Migration `ppe_attachments` (+ optionally `ppe_allocation_attachments`, `ppe_inspection_attachments`) — mirror `2026_06_20_010001_create_emergency_drill_attachments_table.php`. FK to **`ppe_inventory`** (singular). 20 MB intent via controller, not schema.
- [ ] Models `PpeAttachment` (+ siblings) — mirror `EmergencyDrillAttachment` (`mime`/`size`/`kind`/`notes`/`alt_text`, `isImage()`, `uploader()`).
- [ ] `attachments()` HasMany on `PpeInventory` / `PpeAllocation` / `PpeInspection`.
- [ ] Controller trio per parent on `PpeController` — upload (`store('ppe_attachments','public')`, `back()->with('success')`), download (ownership `abort_unless` + `exists` + `->download(..., original_name)`), destroy (delete blob then soft-delete row). Add `AuditLogger::log('ppe.attachment.add'|'.remove')` for full H&S parity.
- [ ] Routes in the `ppe.` group — uploads/deletes under `hazards.manage`, downloads under `hazards.view`. Names `ppe.inventory.attachments.{store,destroy,download}` etc.
- [ ] `detail` payload serializes `attachments` with `url` (image preview, `Storage::url`) + `download_url` (route) + `is_image`/`kind`/`alt_text`/`size`/`uploaded_by`.
- [ ] Frontend: import `AttachmentUploader` + `formatFileSize` from `@/components/ui/file-dropzone`; drop into detail-modal Overview/Allocation/Inspections sections (Pattern A). Copy the Fleet/Drill block; swap endpoint + hint.
- [ ] (Optional) Capture-at-source in Record-inspection / Dispose wizards via `FileDropzone` + `StagedFileCard` staged into `useForm` + `forceFormData: true` (Pattern B), with `documents.*.file` server handling.
- [ ] Tokens only — `AttachmentUploader`/`FileDropzone` are already token-clean and carry the sanctioned eslint-disable; no raw colours introduced.

## Reference files (absolute)
- Reusable uploader: `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\thirsty-varahamihira-12dfb4\resources\js\components\ui\file-dropzone.tsx`
- Pattern A consumers: `resources\js\components\fleet\fleet-incident-dialog.tsx` (~L29, L564), `resources\js\components\health-safety\drill-detail-dialog.tsx` (~L11, L499)
- Pattern B consumer: `resources\js\components\sites\add-site-dialog.tsx` (`StepDocuments` ~L1306, submit ~L501)
- Backend gold standard: `app\Http\Controllers\FleetAssets\IncidentController.php` (L279–336), `app\Http\Controllers\HealthSafety\EmergencyDrillController.php` (L440–489), `app\Http\Controllers\SafeguardingAttachmentController.php`
- Migrations to mirror: `database\migrations\2026_06_18_010001_create_fleet_incident_attachments_table.php`, `2026_06_20_010001_create_emergency_drill_attachments_table.php`
- Models to mirror: `app\Models\FleetIncidentAttachment.php`, `app\Models\EmergencyDrillAttachment.php`
- Polymorphic (rejected) option: `app\Models\HsAttachment.php`; table in `database\migrations\2026_03_29_000001_enhance_worker_participation.php` (L27) + `database\schema\mysql-schema.sql` (L10927)
- PPE targets: `app\Http\Controllers\HealthSafety\PpeController.php`, `app\Models\PpeInventory.php`, `routes\health-safety.php` (~L311), `resources\js\pages\health-safety\ppe\index.tsx`
