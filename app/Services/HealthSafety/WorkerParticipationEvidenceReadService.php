<?php

namespace App\Services\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private, single-use original-operand continuation; no expired read handle crosses the kernel boundary. */
final class WorkerParticipationEvidenceReadService
{
    use ServesPrivateAttachments;

    private array $tickets = [];

    public function __construct(private readonly WorkerParticipationAccessService $access) {}

    public function download(Request $request, string $kind, int $id, ?string $type = null, ?int $siteId = null): StreamedResponse
    {
        abort_unless($id > 0 && ($siteId === null || $siteId > 0) && ($kind === 'meetings' && $type === null || $kind === 'consultations' && in_array($type, ['document', 'outcome'], true)), 404);
        $prepared = $this->access->materializeSource($request->user(), $kind, $id, function ($current, $reads, $context) use ($kind, $id, $type, $siteId): array {
            $site = $this->access->sourceSiteId($kind, $context, $id);
            abort_unless($siteId === null || $siteId === $site, 404);
            $stamp = $this->access->sourceStamp($context, $site);
            $descriptor = $this->descriptor($context, $kind, $id, $type, $site);
            $headers = $this->attachmentHeaders($descriptor);
            return ['stamp' => $stamp, 'descriptor' => $descriptor, 'headers' => $headers, 'site' => $site,
                'site_ownership' => $site === null ? 'unconfirmed' : 'recorded'];
        }, null, $siteId);
        return $this->response($request, ['operation' => 'attachment', 'kind' => $kind, 'id' => $id, 'type' => $type, 'requested_site' => $siteId, ...$prepared], $prepared['headers']);
    }

    /** Board evidence always includes all three sections; register tab/focus is deliberately not an export operand. */
    public function export(Request $request, ?int $siteId = null): StreamedResponse
    {
        abort_unless($siteId === null || $siteId > 0, 404);
        $prepared = $this->access->materialize($request->user(), fn ($current, $reads, $context): array => ['stamp' => $this->access->sourceStamp($context, $siteId)], null, $siteId);
        $response = response()->streamDownload(static function (): void {}, 'worker_participation_'.now('Pacific/Auckland')->format('Ymd_His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store, no-cache, must-revalidate', 'Referrer-Policy' => 'no-referrer']);
        return $this->response($request, ['operation' => 'csv', 'site' => $siteId, ...$prepared], $response->headers->all());
    }

    private function response(Request $request, array $facts, array $headers): StreamedResponse
    {
        $token = bin2hex(random_bytes(24));
        $this->tickets[$token] = ['request' => $request, 'actor' => (int) $request->user()->id, 'head' => $request->isMethod('HEAD'), ...$facts];
        return new StreamedResponse(function () use ($token): void {
            $ticket = $this->tickets[$token] ?? null;
            unset($this->tickets[$token]);
            if ($ticket === null) { throw new LogicException('Participation evidence continuation was already consumed.'); }
            $live = request();
            abort_unless($live === $ticket['request'] && (int) $live->user()?->id === $ticket['actor'], 403);
            $consume = function ($current, $reads, $context) use ($ticket): array {
                // A new current grant cannot replace any original authority/Site/source operand.
                abort_unless(hash_equals($ticket['stamp'], $this->access->sourceStamp($context, $ticket['site'])), 404);
                if ($ticket['operation'] === 'csv') {
                    return ['bytes' => $ticket['head'] ? '' : $this->csv($context, $ticket['site'])];
                }
                $descriptor = $this->descriptor($context, $ticket['kind'], $ticket['id'], $ticket['type'], $ticket['site']);
                abort_unless($descriptor === $ticket['descriptor'] && $this->attachmentHeaders($descriptor) === $ticket['headers'], 404);
                if ($ticket['head']) { return ['bytes' => '']; }
                $stream = Storage::disk('private')->readStream($descriptor['path']);
                abort_unless(is_resource($stream), 404);
                try { $bytes = stream_get_contents($stream); } finally { fclose($stream); }
                abort_unless(is_string($bytes) && strlen($bytes) === (int) ($ticket['headers']['content-length'][0] ?? -1), 404);
                abort_unless($this->descriptor($context, $ticket['kind'], $ticket['id'], $ticket['type'], $ticket['site']) === $descriptor
                    && $this->attachmentHeaders($descriptor) === $ticket['headers'], 404);
                return ['bytes' => $bytes];
            };
            $output = $ticket['operation'] === 'attachment'
                ? $this->access->materializeSource($live->user(), $ticket['kind'], $ticket['id'], $consume, null, $ticket['requested_site'])
                : $this->access->materialize($live->user(), $consume, null, $ticket['site']);
            // The complete source proof and DB callback have finished. No further file or source read occurs after this point.
            echo $output['bytes'];
        }, 200, $headers);
    }

    private function descriptor(HealthSafetyReadContext $context, string $kind, int $id, ?string $type, ?int $site): array
    {
        $pathColumn = $kind === 'meetings' ? 'minutes_document_path' : ($type === 'outcome' ? 'outcome_document_path' : 'document_path');
        $nameColumn = $kind === 'meetings' ? 'minutes_document_name' : ($type === 'outcome' ? 'outcome_document_name' : 'document_name');
        $row = $this->access->query($kind, $context, $site)->whereKey($id)->first(['id', $pathColumn, $nameColumn]);
        abort_unless($row, 404);
        $path = $row->getRawOriginal($pathColumn);
        abort_unless(is_string($path) && $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '\\') && ! str_contains($path, ':')
            && ! preg_match('/[\x00-\x1f\x7f]/', $path) && ! in_array('..', explode('/', $path), true), 404);
        // Both established writers store one file directly under the owning record's directory.
        // A safe relative path alone is not proof that this record owns another module's file.
        $segments = explode('/', $path);
        abort_unless(count($segments) === 4 && array_slice($segments, 0, 3) === ['health-safety', $kind, (string) $id]
            && ! in_array('', $segments, true) && ! in_array('.', $segments, true) && ! in_array('..', $segments, true), 404);
        return ['id' => $id, 'kind' => $kind, 'type' => $type, 'path' => $path,
            'stored_name' => $row->getRawOriginal($nameColumn), 'name' => $row->getRawOriginal($nameColumn) ?? basename($path)];
    }

    private function attachmentHeaders(array $descriptor): array
    {
        $response = $this->streamPrivateAttachment('private', $descriptor['path'], $descriptor['name']);
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        $headers = $response->headers->all();
        // Native transport supplies Date; elapsed wall time is not a file/source mutation.
        unset($headers['date']);
        return $headers;
    }

    private function csv(HealthSafetyReadContext $context, ?int $site): string
    {
        $out = fopen('php://temp', 'w+');
        if ($out === false) { throw new LogicException('Participation CSV could not be buffered.'); }
        try {
            $this->put($out, ['H&S Representatives']);
            $this->put($out, ['Name', 'Site', 'Work group', 'Election method', 'Elected', 'Term expires', 'Training days', 'Status']);
            foreach ($this->access->query('representatives', $context, $site)->orderBy('status')->orderBy('id')->get() as $row) {
                $name = $this->access->relatedQuery('representatives', 'user', $context, $site, $row->id)->value('name');
                $siteName = $this->access->relatedQuery('representatives', 'site', $context, $site, $row->id)->value('name');
                $this->put($out, [$name, $row->site_id === null ? 'Site ownership not confirmed' : $siteName, $row->work_group, $row->election_method, $row->elected_at?->toDateString(), $row->term_expires_at?->toDateString(), $row->training_days_completed, $row->status]);
            }
            $this->put($out, []); $this->put($out, ['Committee Meetings']);
            $this->put($out, ['Committee', 'Site', 'Scheduled (NZ time)', 'Status', 'Minutes filed', 'Actions outstanding']);
            foreach ($this->access->query('meetings', $context, $site)->orderByDesc('scheduled_at')->orderByDesc('id')->get() as $row) {
                $committee = $this->access->relatedQuery('meetings', 'committee', $context, $site, $row->id)->first(['name', 'site_id']);
                $siteName = $this->access->relatedQuery('meetings', 'site', $context, $site, $row->id)->value('name');
                $this->put($out, [$committee?->name, $committee && $committee->site_id === null ? 'Site ownership not confirmed' : $siteName,
                    $row->scheduled_at?->setTimezone('Pacific/Auckland')->format('Y-m-d H:i'), $row->status, $row->minutes_document_path ? 'Yes' : 'No', $row->actions_due_count]);
            }
            $this->put($out, []); $this->put($out, ['Worker Consultations']);
            $this->put($out, ['Title', 'Type', 'Site', 'Date', 'Status', 'Supporting doc', 'Outcome doc']);
            foreach ($this->access->query('consultations', $context, $site)->orderByDesc('consultation_date')->orderByDesc('id')->get() as $row) {
                $name = $this->access->relatedQuery('consultations', 'site', $context, $site, $row->id)->value('name');
                $this->put($out, [$row->title, $row->consultation_type, $row->site_id === null ? 'Site ownership not confirmed' : $name, $row->consultation_date?->toDateString(), $row->status, $row->document_path ? 'Yes' : 'No', $row->outcome_document_path ? 'Yes' : 'No']);
            }
            rewind($out);
            $bytes = stream_get_contents($out);
            if (! is_string($bytes)) { throw new LogicException('Participation CSV could not be read.'); }
            return $bytes;
        } finally { fclose($out); }
    }

    private function put($out, array $values): void
    {
        $cells = array_map(static function ($value): string {
            $text = (string) ($value ?? '');
            return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $text) ? "'".$text : $text;
        }, $values);
        fputcsv($out, $cells, ',', '"', '');
    }
}
