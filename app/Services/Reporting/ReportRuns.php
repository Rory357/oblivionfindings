<?php

namespace App\Services\Reporting;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Jobs\GenerateOperationalReport;
use App\Models\OperationalReport;
use App\Models\OperationalReportRun;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ReportRuns
{
    public function __construct(private ReportAccess $access, private ReportDefinition $definitions, private ReportSourceReader $reader, private ReportEngine $engine) {}

    public function queue(User $actor, array $definition, string $reason, ?OperationalReport $report = null, bool $notify = false): OperationalReportRun
    {
        abort_if(mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 500, 422, 'Give a reason between 3 and 500 characters.');
        $definition = $this->definitions->validate($definition, $this->access->sources($actor));
        $this->access->context($actor, $definition);
        $run = new OperationalReportRun(['user_id' => $actor->id, 'report_id' => $report?->id,
            'definition' => $definition, 'reason' => $reason, 'status' => 'queued', 'expires_at' => now()->addDay()]);
        $run->id = (string) Str::uuid();
        $run->save();
        GenerateOperationalReport::dispatch($run->id, $notify)->afterCommit();

        return $run->refresh();
    }

    public function execute(OperationalReportRun $run): void
    {
        if (! OperationalReportRun::whereKey($run->id)->where('status', 'queued')->where('expires_at', '>', now())->update(['status' => 'running'])) {
            return;
        }
        try {
            $actor = User::findOrFail($run->user_id);
            $definition = $this->definitions->validate($run->definition, $this->access->sources($actor));
            $source = $this->reader->read($actor, $definition);
            $result = $this->engine->calculate($definition, $source['rows']);
            $comparison = null;
            if ($definition['comparison']) {
                $days = CarbonImmutable::parse($definition['date_from'])->diffInDays(CarbonImmutable::parse($definition['date_to'])) + 1;
                $previous = $definition;
                $previous['date_to'] = CarbonImmutable::parse($definition['date_from'])->subDay()->toDateString();
                $previous['date_from'] = CarbonImmutable::parse($definition['date_from'])->subDays($days)->toDateString();
                $previousSource = $this->reader->read($actor, $previous);
                $source['comparison_evidence'] = $previousSource['evidence'];
                if (isset($previousSource['watermark']['medication_digest'])) {
                    $source['medication_comparison'] = ['definition' => $previous, 'digest' => $previousSource['watermark']['medication_digest']];
                }
                $source['assignment_fingerprints'] += $previousSource['assignment_fingerprints'];
                $expiries = array_filter([$source['retention_expires_at'], $previousSource['retention_expires_at']]);
                $source['retention_expires_at'] = $expiries ? min($expiries) : null;
                $comparison = ['window' => $previousSource['window'], 'totals' => $this->engine->calculate($previous, $previousSource['rows'])['totals'],
                    'coverage' => $previousSource['coverage']];
            }
            unset($source['rows']);
            $this->access->recheck($actor, $definition, $source['fingerprint']);
            $this->assertEvidence($actor, $source, $definition);
            $payload = ['source' => $source, 'result' => $result, 'comparison' => $comparison, 'generated_at' => now()->toISOString(), 'definition_hash' => hash('sha256', json_encode($definition))];
            AuditLogger::logOrFail('reports.run.generated', $actor, ['run_id' => $run->id, 'actor_id' => $actor->id, 'source' => $definition['source'], 'row_count' => $result['row_count'], 'definition_hash' => $payload['definition_hash']]);
            DB::transaction(function () use ($run, $payload) {
                $current = OperationalReportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'running') {
                    return;
                }
                $current->update(['status' => 'ready', 'payload' => $payload]);
            });
        } catch (\Throwable $exception) {
            $code = $exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [403, 404])
                ? 'access_changed' : ($exception instanceof ValidationException || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 422) ? 'invalid_scope' : 'generation_failed');
            OperationalReportRun::whereKey($run->id)->where('status', 'running')->update(['status' => 'failed', 'failure_code' => $code, 'payload' => null]);
            report($exception);
        }
    }

    public function result(User $actor, OperationalReportRun $run): array
    {
        abort_unless((int) $run->user_id === (int) $actor->id, 404);
        abort_unless($run->expires_at->isFuture(), 410, 'This report has expired. Run it again.');
        abort_unless($run->status === 'ready', 409, 'This report is not ready.');
        $payload = $run->payload;
        $this->definitions->validate($run->definition, $this->access->sources($actor));
        $this->access->recheck($actor, $run->definition, $payload['source']['fingerprint']);
        foreach ($payload['source']['assignment_fingerprints'] as $id => $hash) {
            $assignment = DeviceAssignment::find($id);
            abort_unless($assignment && hash_equals($hash, hash('sha256', json_encode($assignment->getAttributes(), JSON_THROW_ON_ERROR))), 403);
        }
        // Retention advances between generation and download. Never serve an expired point.
        abort_if(isset($payload['source']['retention_expires_at']) && CarbonImmutable::parse($payload['source']['retention_expires_at'])->lte(now()), 410, 'The report has crossed its retention boundary. Run it again.');

        $this->assertEvidence($actor, $payload['source'], $run->definition);

        return $payload;
    }

    private function assertEvidence(User $actor, array $source, array $definition): void
    {
        $actor = $this->access->actor($actor);
        if (config('operational-reports.sources.'.$definition['source'].'.domain') === 'medication') {
            app(\App\Services\Medication\Reporting\MedicationBuilderSource::class)->assertCurrent($actor, $definition, $source['watermark']['medication_digest'] ?? '');
            if ($definition['comparison']) {
                $previous = $source['medication_comparison'] ?? [];
                abort_unless(isset($previous['definition'], $previous['digest']), 403);
                app(\App\Services\Medication\Reporting\MedicationBuilderSource::class)->assertCurrent($actor, $previous['definition'], $previous['digest']);
            }
        }
        ReportSourceEvidence::assertCurrent($actor, $source['evidence'] ?? null);
        if ($definition['comparison']) {
            ReportSourceEvidence::assertCurrent($actor, $source['comparison_evidence'] ?? null);
        }
    }

    public static function publicSource(array $source): array
    {
        unset($source['fingerprint'], $source['assignment_fingerprints'], $source['evidence'], $source['comparison_evidence'], $source['medication_comparison']);
        $watermark = $source['watermark'];
        $source['watermark'] = ['captured_at' => $watermark['captured_at'] ?? null, 'snapshot_id' => hash_hmac('sha256', json_encode($watermark, JSON_THROW_ON_ERROR), (string) config('app.key'))];

        return $source;
    }
}
