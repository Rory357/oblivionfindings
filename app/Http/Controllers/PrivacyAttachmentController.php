<?php

namespace App\Http\Controllers;

use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\LegalHold;
use App\Models\PrivacyAttachment;
use App\Models\PrivacyImpactAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Privacy command-centre — polymorphic document/evidence upload.
 *
 * One endpoint serves every privacy domain. The `attachable_type` the client
 * sends is a short key (request/breach/hold/dpia/retention) resolved through an
 * allow-list to a model class + permissions — never a raw class name — so an
 * attacker can't attach to (or read from) arbitrary models. Sensitive files are
 * need-to-know: download requires the owning domain's WRITE permission.
 */
class PrivacyAttachmentController extends Controller
{
    /** type key => [model class, view permission, write permission]. */
    private const TYPES = [
        'request' => [DataSubjectRequest::class, 'privacy.viewRequests', 'privacy.processRequests'],
        'breach' => [DataBreachLog::class, 'privacy.reportBreaches', 'privacy.reportBreaches'],
        'hold' => [LegalHold::class, 'privacy.manageLegalHolds', 'privacy.manageLegalHolds'],
        'dpia' => [PrivacyImpactAssessment::class, 'privacy.conductDPIA', 'privacy.conductDPIA'],
        'retention' => [DataRetentionPolicy::class, 'privacy.manageRetention', 'privacy.manageRetention'],
    ];

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(self::TYPES))],
            'attachable_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,rtf,jpg,jpeg,png,gif,webp,heic'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Read via $request->boolean() — no strict rule so a multipart
            // "true"/"1"/"on" value isn't rejected.
            'is_sensitive' => ['nullable'],
        ]);

        [$class, , $writePerm] = self::TYPES[$validated['attachable_type']];
        abort_unless($request->user()?->canDo($writePerm), 403);

        $model = $class::findOrFail($validated['attachable_id']);

        $file = $request->file('file');
        $disk = 'public';
        $path = $file->store('privacy_attachments', $disk);

        $model->attachments()->create([
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'notes' => $validated['notes'] ?? null,
            'is_sensitive' => $request->boolean('is_sensitive'),
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function download(Request $request, PrivacyAttachment $attachment)
    {
        $type = $this->typeKeyFor($attachment->attachable_type);
        abort_unless($type !== null, 404);

        [, $viewPerm, $writePerm] = self::TYPES[$type];
        abort_unless($request->user()?->canDo($viewPerm), 403);

        // Sensitive documents are need-to-know — require the domain write perm.
        if ($attachment->is_sensitive) {
            abort_unless($request->user()?->canDo($writePerm), 403);
        }

        $disk = $attachment->disk ?: 'public';
        abort_unless(Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, PrivacyAttachment $attachment): RedirectResponse
    {
        $type = $this->typeKeyFor($attachment->attachable_type);
        abort_unless($type !== null, 404);

        [, , $writePerm] = self::TYPES[$type];
        abort_unless($request->user()?->canDo($writePerm), 403);

        $disk = $attachment->disk ?: 'public';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

        return back()->with('success', 'Document removed.');
    }

    private function typeKeyFor(string $class): ?string
    {
        foreach (self::TYPES as $key => [$cls]) {
            if ($cls === $class) {
                return $key;
            }
        }

        return null;
    }
}
