<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Models\SafeguardingAttachment;
use App\Models\SafeguardingConcern;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Safeguarding redesign — Step 7a (W8). Evidence upload/download/delete.
 * Sensitive evidence is need-to-know — download is gated by viewSensitive.
 */
class SafeguardingAttachmentController extends Controller
{
    use ServesPrivateAttachments;

    public function store(Request $request, SafeguardingConcern $concern): RedirectResponse
    {
        $this->authorize('update', $concern);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'notes' => ['nullable', 'string'],
            // Read via $request->boolean() below — no strict `boolean` rule so a
            // multipart "true"/"1"/"on" value isn't rejected.
            'is_sensitive' => ['nullable'],
        ]);

        $file = $request->file('file');
        $disk = 'private';
        $path = $file->store('safeguarding_attachments', $disk);

        $concern->attachments()->create([
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'notes' => $validated['notes'] ?? null,
            'is_sensitive' => $request->boolean('is_sensitive'),
        ]);

        return back()->with('success', 'Evidence uploaded.');
    }

    public function download(Request $request, SafeguardingConcern $concern, SafeguardingAttachment $attachment): StreamedResponse
    {
        $actualConcern = $attachment->concern()->firstOrFail();
        abort_unless($actualConcern->is($concern), 404);
        $this->authorize('view', $actualConcern);

        // Sensitive evidence is need-to-know.
        if ($attachment->is_sensitive) {
            abort_unless((bool) $request->user()?->can('viewSensitive', SafeguardingConcern::class), 403);
        }

        // Private disk + nosniff + CSP sandbox — see ServesPrivateAttachments.
        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    public function destroy(Request $request, SafeguardingConcern $concern, SafeguardingAttachment $attachment): RedirectResponse
    {
        $actualConcern = $attachment->concern()->firstOrFail();
        abort_unless($actualConcern->is($concern), 404);
        $this->authorize('update', $actualConcern);

        $disk = $attachment->disk ?: 'private';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

        return back()->with('success', 'Evidence removed.');
    }
}
