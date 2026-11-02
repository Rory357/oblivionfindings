<?php

namespace App\Services\HealthSafety;

use App\Models\HsEvent;
use App\Models\HsEventFile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fleet\VehicleTripReportExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * G3 board M5c (approved by Stephan 1 Nov 2026; decisions 21, 24, 25): the safety review as a PDF in the Fleet reports'
 * style. Built only from the page payload this person already sees (HsEventController::buildEventDetail), so the PDF
 * never shows more than the app does. A closed review gives its final record ("version 1, frozen when it closed": a
 * closed review can't change; a correction after close would add a dated addendum and a new version — decision 24);
 * an open one gives a summary marked "In progress, not final". Photos are included only when ticked at download
 * (decision 25); videos are listed with their length (a PDF can't play them). Every download is logged.
 */
final class HsReviewPdfService
{
    private const NZ = 'Pacific/Auckland';

    public function __construct(
        private readonly HsEventActivityService $activity,
        private readonly VehicleTripReportExporter $fleet,
    ) {}

    /** @param  array<string, mixed>  $detail */
    public function download(HsEvent $event, array $detail, User $viewer, bool $withPhotos): Response
    {
        $html = $this->html($event, $detail, $viewer, $withPhotos);
        AuditLogger::logOrFail('healthSafety.event.pdfDownloaded', $event, [
            'actor_id' => $viewer->id, 'final' => $event->status === HsEvent::STATUS_CLOSED, 'photos' => $withPhotos,
        ]);
        $pdf = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
            ->loadHTML($html)
            ->setPaper('a4', 'portrait');
        $name = ($event->status === HsEvent::STATUS_CLOSED ? 'final-record-' : 'summary-').$event->reference_number.'.pdf';
        $response = $pdf->download($name);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @param  array<string, mixed>  $detail */
    public function html(HsEvent $event, array $detail, User $viewer, bool $withPhotos): string
    {
        $final = $event->status === HsEvent::STATUS_CLOSED;
        $entries = $this->activity->forEvent($event, $viewer);
        $files = HsEventFile::query()->where('hs_event_id', $event->id)->whereNull('hidden_at')->orderBy('created_at')->get();
        $role = $viewer->roles()->first()?->name;
        $now = Carbon::now(self::NZ);
        $actions = collect($detail['corrective_actions'] ?? []);
        $failed = $actions->filter(fn (array $a): bool => in_array($a['status'], ['verified', 'closed'], true) && $a['effectiveness_confirmed'] === false)->count();
        $corrections = collect($entries)->where('kind', 'edit')->filter(fn (array $e): bool => isset($e['detail']['before']))->values();
        $opened = Carbon::parse($detail['occurred_at'] ?? $detail['reported_at'] ?? $event->created_at)->timezone(self::NZ);
        $ended = $final && $event->closed_at ? $event->closed_at->copy()->timezone(self::NZ) : $now;

        return view('pdf.hs-review', [
            'title' => ($detail['title'] ?? $event->reference_number).' · '.$event->reference_number,
            'brand' => $this->fleet->branding(),
            'final' => $final,
            'd' => $detail,
            'event' => $event,
            'closedLine' => $final && $event->closed_at
                ? 'Closed '.$event->closed_at->copy()->timezone(self::NZ)->format('D j M Y, g:i a')
                    .(($detail['handover']['owner']['name'] ?? null) ? ', by '.$detail['handover']['owner']['name'].' (owner)' : '')
                : 'In progress, not final',
            'tiles' => [
                ['Steps', $final ? '5 of 5' : '—', $final ? 'all done' : 'still open'],
                ['Actions', (string) $actions->count(), $failed ? $failed.' didn’t work, revised' : 'checked as they were done'],
                // Carbon 3's diffInDays is signed and fractional: whole days from opening to closing (or now).
                ['Days open', (string) (int) max(0, floor($opened->diffInDays($ended))),
                    $opened->format('j M').' – '.($final ? $ended->format('j M') : 'now')],
                ['Corrections', (string) $corrections->count(), $corrections->count() ? 'shown below' : 'none'],
            ],
            'steps' => $this->steps($entries, $final),
            'actions' => $actions->all(),
            'corrections' => $corrections->all(),
            'entries' => $entries,
            'photos' => $withPhotos ? $this->photos($files) : [],
            'videos' => $files->where('kind', HsEventFile::KIND_VIDEO)->values(),
            'filesLine' => $this->filesLine($files),
            'footer' => 'Confidential — contains health information. Downloaded by '.$viewer->name
                .($role ? ', '.str_replace('_', ' ', $role) : '').' · '.$now->format('D j M Y, g:i a').' · NZ time · '
                .($final ? 'version 1, frozen when it closed' : 'in progress, not final'),
        ])->render();
    }

    /**
     * "Steps taken": each step with when (its latest entry), who and what was done, newest entry per step.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{label: string, when: ?string, who: string, what: string}>
     */
    private function steps(array $entries, bool $final = false): array
    {
        $labels = ['assigned' => 'Assigned', 'investigated' => 'Investigated', 'implemented' => 'Implemented', 'check' => 'Check it worked', 'closed' => 'Closed'];
        $out = [];
        foreach ($labels as $key => $label) {
            $mine = collect($entries)->where('step', $key)->sortBy('at')->values();
            $out[] = [
                'label' => $label,
                'when' => $mine->isNotEmpty() ? Carbon::parse($mine->last()['at'])->timezone(self::NZ)->format('D j M, g:i a') : null,
                'who' => $mine->pluck('who')->filter()->unique()->implode(', '),
                'what' => $mine->isNotEmpty() ? ucfirst((string) $mine->last()['what']).($mine->count() > 1 ? ' (and '.($mine->count() - 1).' more)' : '') : ($final ? 'Done (not in the activity log)' : 'Not yet'),
            ];
        }

        return $out;
    }

    /** @return list<array{src: string, caption: ?string, step: string, who: string, when: string}> */
    private function photos($files): array
    {
        $out = [];
        foreach ($files->where('kind', HsEventFile::KIND_PHOTO)->take(24) as $file) {
            try {
                $bytes = Storage::disk($file->disk)->get($file->path);
            } catch (\Throwable) {
                continue;
            }
            if (! is_string($bytes) || $bytes === '') {
                continue;
            }
            $data = $this->shrink($bytes) ?? ['mime' => $file->mime_type, 'bytes' => $bytes];
            if (! in_array($data['mime'], ['image/jpeg', 'image/png'], true)) {
                continue;
            }
            $out[] = [
                'src' => 'data:'.$data['mime'].';base64,'.base64_encode($data['bytes']),
                'caption' => $file->description,
                'step' => $file->step,
                'who' => $file->uploaded_by_name,
                'when' => $file->created_at?->copy()->timezone(self::NZ)->format('D j M, g:i a') ?? '',
            ];
        }

        return $out;
    }

    /** Photos are shrunk to keep the file small (board M5c): at most 1000 px, JPEG. */
    private function shrink(string $bytes): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, 1000 / max($w, $h, 1));
        $resized = imagescale($image, max(1, (int) ($w * $scale)), max(1, (int) ($h * $scale)));
        ob_start();
        imagejpeg($resized ?: $image, null, 72);
        $out = (string) ob_get_clean();

        return $out !== '' ? ['mime' => 'image/jpeg', 'bytes' => $out] : null;
    }

    private function filesLine($files): ?string
    {
        if ($files->isEmpty()) {
            return null;
        }
        $photos = $files->where('kind', HsEventFile::KIND_PHOTO)->count();
        $videos = $files->where('kind', HsEventFile::KIND_VIDEO);
        $docs = $files->where('kind', HsEventFile::KIND_DOCUMENT)->count();

        return collect([
            $photos ? $photos.' '.($photos === 1 ? 'photo' : 'photos') : null,
            $videos->count() ? $videos->count().' '.($videos->count() === 1 ? 'video' : 'videos') : null,
            $docs ? $docs.' '.($docs === 1 ? 'document' : 'documents') : null,
        ])->filter()->implode(', ');
    }
}
