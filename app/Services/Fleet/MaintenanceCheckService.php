<?php

namespace App\Services\Fleet;

use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class MaintenanceCheckService
{
    public function __construct(
        private readonly MaintenanceAccessService $access,
        private readonly MaintenancePolicyService $policy,
    ) {}

    /**
     * @param array{asset_id:int, template_id:int, check_kind:string, answers:array,
     *   request_key:string, rule_version_id?:?int, work_order_id?:?int,
     *   source_report_id?:?int, corrects_run_id?:?int, observed_at?:?string,
     *   notes?:?string, metadata?:array} $data
     */
    public function submit(User $actor, array $data): FleetChecklistRun
    {
        abort_unless($this->access->canManage($actor), 403);
        $files = $data['files'] ?? [];
        $identity = $data;
        $identity['files'] = [];
        foreach ($files as $question => $file) {
            abort_unless(array_key_exists($question, $data['answers']), 422);
            $identity['files'][$question] = $this->fileIdentity($file);
        }
        $fingerprint = MaintenanceFingerprint::of([
            'actor_id' => (int) $actor->id,
            'operation' => 'maintenance.check',
            'data' => $identity,
        ]);
        $writtenPaths = [];

        try {
            return DB::transaction(function () use ($actor, $data, $fingerprint, $files, &$writtenPaths): FleetChecklistRun {
            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($this->access->canManage($currentActor), 403);
            $asset = $this->access->asset($currentActor, (int) $data['asset_id'], true);

            $prior = FleetChecklistRun::query()
                ->where('user_id', $currentActor->id)->where('request_key', $data['request_key'])
                ->lockForUpdate()->first();
            if ($prior) {
                abort_unless((int) $prior->asset_id === (int) $asset->id
                    && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409);

                return $prior;
            }

            $order = null;
            if (! empty($data['work_order_id'])) {
                $order = FleetWorkOrder::query()->whereKey((int) $data['work_order_id'])
                    ->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
            }

            $template = FleetChecklistTemplate::query()->whereKey((int) $data['template_id'])
                ->lockForUpdate()->firstOrFail();
            // Daily checks are recorded observations (VehicleDailyCheckService),
            // never Maintenance checks that decide availability.
            if ($template->type === FleetChecklistTemplate::TYPE_DAILY_CHECK) {
                throw ValidationException::withMessages(['template_id' => 'Record daily checks from the Daily checks page.']);
            }
            $policyKind = $data['check_kind'] === 'retest' ? 'retest' : 'check';
            $policy = $this->policy->current((int) $asset->site_id, (string) $asset->category, $policyKind, true);

            $approved = $policy
                && (int) ($data['rule_version_id'] ?? 0) === $policy['id']
                && (int) ($policy['rules']['template_id'] ?? 0) === (int) $template->id
                && isset($policy['rules']['template_sha256'])
                && hash_equals((string) $policy['rules']['template_sha256'], MaintenanceFingerprint::of($template->items ?? []));

            $answers = $this->normaliseAnswers($data['answers'], $order?->id);
            foreach ($files as $question => $file) {
                $path = Storage::disk('private')->putFileAs('maintenance/checks/'.$asset->id,
                    $file, \Illuminate\Support\Str::uuid()->toString());
                if (! is_string($path) || $path === '') {
                    throw ValidationException::withMessages(['files' => 'The evidence could not be saved. Keep your draft and retry.']);
                }
                $writtenPaths[] = $path;
                $answers[(string) $question]['evidence_file'] = [...$this->fileIdentity($file), 'path' => $path];
                $answers[(string) $question]['evidence_verified'] = true;
            }
            $outcome = $this->policy->checkOutcome($approved ? $policy : null, $answers);
            $coveredRestrictions = null;
            if ($data['check_kind'] === 'retest') {
                $supplied = $data['covered_restriction_ids'] ?? null;
                if (is_array($supplied) && array_is_list($supplied)) {
                    $coveredRestrictions = array_map('intval', $supplied);
                    sort($coveredRestrictions, SORT_NUMERIC);
                    if (count($coveredRestrictions) !== count(array_unique($coveredRestrictions))
                        || in_array(0, $coveredRestrictions, true)) {
                        throw ValidationException::withMessages(['covered_restriction_ids' => 'Choose each current restriction once.']);
                    }
                    if ($coveredRestrictions !== [] && DB::table('fleet_maintenance_restrictions')
                        ->whereIn('id', $coveredRestrictions)->where('asset_id', $asset->id)
                        ->where('state', 'active')->lockForUpdate()->get(['id'])->count() !== count($coveredRestrictions)) {
                        abort(404);
                    }
                }
                $activeRestrictions = DB::table('fleet_maintenance_restrictions')
                    ->where('asset_id', $asset->id)->where('state', 'active')
                    ->orderBy('id')->lockForUpdate()->get(['id'])->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($outcome === 'passed'
                    && ($coveredRestrictions === null || $coveredRestrictions !== $activeRestrictions)) {
                    $outcome = 'needs_assessment';
                }
            }

            $correction = null;
            if (! empty($data['corrects_run_id'])) {
                $correction = FleetChecklistRun::query()->whereKey((int) $data['corrects_run_id'])
                    ->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
                if ($order && $correction->work_order_id && (int) $correction->work_order_id !== (int) $order->id) {
                    throw ValidationException::withMessages(['corrects_run_id' => 'The earlier check belongs to another work order.']);
                }
            }

            if (! empty($data['source_report_id'])) {
                $report = DB::table('fleet_maintenance_reports')->where('id', (int) $data['source_report_id'])
                    ->where('asset_id', $asset->id)->first();
                abort_unless($report && (! $order || (int) $report->work_order_id === (int) $order->id), 404);
            }

            $responses = $answers;
            if (! empty($data['metadata'])) {
                $responses['_metadata'] = $data['metadata'];
            }
            $run = FleetChecklistRun::create([
                'template_id' => $template->id,
                'asset_id' => $asset->id,
                'user_id' => $currentActor->id,
                'responses' => $responses,
                'passed' => $outcome === 'passed',
                'notes' => $data['notes'] ?? null,
                'completed_at' => now(),
                'work_order_id' => $order?->id,
                'source_report_id' => $data['source_report_id'] ?? null,
                'rule_version_id' => $approved ? $policy['id'] : null,
                'rule_snapshot_json' => $approved ? $policy['rules'] : null,
                'presented_template_json' => [
                    'template_id' => $template->id,
                    'name' => $template->name,
                    'type' => $template->type,
                    'items' => $template->items ?? [],
                ],
                'rule_sha256' => $approved ? $policy['sha256'] : null,
                'outcome' => $outcome,
                'check_kind' => $data['check_kind'],
                'corrects_run_id' => $correction?->id,
                'covered_restriction_ids_json' => $coveredRestrictions,
                'observed_at' => $data['observed_at'] ?? null,
                'submitted_at' => now()->format('Y-m-d H:i:s.u'),
                'request_key' => $data['request_key'],
                'request_fingerprint' => $fingerprint,
            ]);

            AuditLogger::log('fleet.maintenance.check.submit', $run, [
                'asset_id' => $asset->id,
                'work_order_id' => $order?->id,
                'outcome' => $outcome,
                'rule_version_id' => $approved ? $policy['id'] : null,
            ]);

            return $run;
            }, $files === [] ? 3 : 1);
        } catch (\Throwable $exception) {
            foreach ($writtenPaths as $path) Storage::disk('private')->delete($path);
            if (! $exception instanceof QueryException || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($this->access->canManage($currentActor), 403);
            $asset = $this->access->asset($currentActor, (int) $data['asset_id']);
            $prior = FleetChecklistRun::query()
                ->where('user_id', $currentActor->id)
                ->where('request_key', $data['request_key'])->first();
            if (! $prior) {
                throw $exception;
            }
            abort_unless((int) $prior->asset_id === (int) $asset->id
                && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409);

            return $prior;
        }
    }

    private function fileIdentity(mixed $file): array
    {
        abort_unless($file instanceof \Illuminate\Http\UploadedFile && $file->isValid(), 422);
        $mime = $file->getMimeType();
        $size = (int) $file->getSize();
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)
            && $size > 0 && $size <= 10 * 1024 * 1024, 422);
        return ['original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
            'mime_type' => $mime, 'byte_size' => $size, 'sha256' => hash_file('sha256', $file->getRealPath())];
    }

    /** @param array<string|int, mixed> $answers
     *  @return array<string, array<string, mixed>>
     */
    private function normaliseAnswers(array $answers, ?int $workOrderId): array
    {
        $normalised = [];
        foreach ($answers as $key => $answer) {
            $value = is_array($answer)
                ? $answer
                : ['result' => $answer];
            unset($value['evidence_verified'], $value['evidence_file']);
            if (isset($value['evidence_attachment_id'])) {
                $attachment = $workOrderId ? DB::table('fleet_maintenance_attachments')
                    ->where('id', (int) $value['evidence_attachment_id'])
                    ->where('work_order_id', $workOrderId)
                    ->where('disk', 'private')->first() : null;
                abort_unless($attachment && Storage::disk('private')->exists($attachment->path), 404);
                $value['evidence_verified'] = true;
            }
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }
}
