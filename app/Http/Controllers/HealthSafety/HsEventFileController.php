<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HsEvent;
use App\Models\HsEventFile;
use App\Services\AuditLogger;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\VideoDuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * G3 board M5d (approved by Stephan 1 Nov 2026; decision 22): photos, videos and documents on a safety review's step.
 * Adding and hiding need hazards.manage (route) and the review's normal read; opening or playing one needs the review's
 * normal read only (people who can see the whole record — never the team-lead story view). Every add, hide and open is
 * in the audit log (and the review's History). Files are never deleted.
 */
class HsEventFileController extends Controller
{
    private const DISK = 'private';

    public function store(Request $request, int $event): JsonResponse|RedirectResponse
    {
        $readable = $this->event($request, $event);
        abort_unless($readable->isOpen(), 422, 'This safety review is closed, so files can’t be added.');

        $extension = strtolower((string) $request->file('file')?->getClientOriginalExtension());
        $kind = HsEventFile::kindFor($extension);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', array_merge(...array_values(HsEventFile::MIMES))),
                'max:'.($kind === HsEventFile::KIND_VIDEO ? HsEventFile::VIDEO_MAX_KB : HsEventFile::FILE_MAX_KB)],
            'step' => ['required', 'string', 'in:'.implode(',', HsEventFile::STEPS)],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:'.HsEventFile::VIDEO_MAX_SECONDS],
        ], [
            'file.max' => $kind === HsEventFile::KIND_VIDEO ? 'Videos can be up to 500 MB.' : 'Files can be up to 20 MB.',
            'file.mimes' => 'Add photos, videos, PDFs or Word files.',
            'duration_seconds.max' => 'Videos can be up to 2 minutes.',
        ]);
        if ($kind === null) {
            throw ValidationException::withMessages(['file' => 'Add photos, videos, PDFs or Word files.']);
        }

        $upload = $data['file'];
        $path = $upload->store("health-safety/events/{$readable->id}", self::DISK);
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => 'The file couldn’t be stored. Try again.']);
        }
        $duration = null;
        if ($kind === HsEventFile::KIND_VIDEO) {
            // The length read from the file wins; the browser's figure is the fallback (still capped).
            $duration = VideoDuration::seconds(Storage::disk(self::DISK)->path($path)) ?? ($data['duration_seconds'] ?? null);
            if ($duration === null || $duration > HsEventFile::VIDEO_MAX_SECONDS) {
                Storage::disk(self::DISK)->delete($path);
                throw ValidationException::withMessages(['file' => $duration === null
                    ? 'This video’s length couldn’t be read. Try an MP4 or MOV up to 2 minutes.'
                    : 'Too long: videos can be up to 2 minutes.']);
            }
        }

        $file = DB::transaction(function () use ($request, $readable, $data, $upload, $kind, $path, $duration): HsEventFile {
            $file = HsEventFile::query()->create([
                'hs_event_id' => $readable->id, 'step' => $data['step'], 'kind' => $kind,
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255), 'disk' => self::DISK, 'path' => $path,
                'mime_type' => (string) ($upload->getMimeType() ?: $upload->getClientMimeType()), 'size_bytes' => (int) $upload->getSize(),
                'duration_seconds' => $duration, 'description' => isset($data['description']) ? trim($data['description']) ?: null : null,
                'uploaded_by' => $request->user()->id, 'uploaded_by_name' => (string) $request->user()->name,
            ]);
            AuditLogger::logOrFail('healthSafety.event.fileAdded', $readable, [
                'actor_id' => $request->user()->id, 'file_id' => $file->id, 'step' => $file->step, 'kind' => $file->kind,
            ]);

            return $file;
        });

        return $request->expectsJson()
            ? response()->json(['id' => $file->id], 201)
            : back()->with('success', 'File added.');
    }

    public function show(Request $request, int $event, HsEventFile $file): Response
    {
        $readable = $this->event($request, $event);
        abort_unless((int) $file->hs_event_id === (int) $readable->id && $file->hidden_at === null, 404);
        // "Each open is logged" (board M5d): opening or playing a review file is part of its record.
        AuditLogger::logOrFail('healthSafety.event.fileOpened', $readable, [
            'actor_id' => $request->user()->id, 'file_id' => $file->id, 'step' => $file->step, 'kind' => $file->kind,
        ]);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);
        $headers = [
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
            'Content-Type' => $file->mime_type,
        ];
        $disposition = in_array($file->kind, [HsEventFile::KIND_PHOTO, HsEventFile::KIND_VIDEO], true) ? 'inline' : 'attachment';

        // A local disk answers range requests, so a video can be seeked while it plays.
        try {
            return response()->file(Storage::disk($file->disk)->path($file->path), $headers + [
                'Content-Disposition' => $disposition.'; filename="'.addslashes($file->original_name).'"',
            ]);
        } catch (\Throwable) {
            return Storage::disk($file->disk)->response($file->path, $file->original_name, $headers, $disposition);
        }
    }

    public function hide(Request $request, int $event, HsEventFile $file): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        DB::transaction(function () use ($request, $event, $file, $data): void {
            $readable = $this->event($request, $event);
            $locked = HsEventFile::query()->whereKey($file->id)->where('hs_event_id', $readable->id)->lockForUpdate()->firstOrFail();
            if (! $readable->isOpen()) {
                throw ValidationException::withMessages(['reason' => 'This safety review is closed, so its files stay as they are.']);
            }
            if ($locked->hidden_at !== null) {
                throw ValidationException::withMessages(['reason' => 'This file is already hidden.']);
            }
            $locked->update(['hidden_at' => now(), 'hidden_by' => $request->user()->id, 'hidden_reason' => trim($data['reason'])]);
            AuditLogger::logOrFail('healthSafety.event.fileHidden', $readable, [
                'actor_id' => $request->user()->id, 'file_id' => $locked->id, 'reason' => trim($data['reason']),
            ]);
        });

        return back()->with('success', 'File hidden. It is kept with the review’s history.');
    }

    private function event(Request $request, int $event): HsEvent
    {
        return app(HsEventAccessService::class)->readableEventForCommand($request->user(), $event,
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
    }
}
