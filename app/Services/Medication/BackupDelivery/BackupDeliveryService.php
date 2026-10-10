<?php

namespace App\Services\Medication\BackupDelivery;

use App\Mail\MailNotSubmitted;
use App\Models\MedicationBackupAttempt;
use App\Models\MedicationBackupDelivery;
use App\Models\MedicationBackupRecipient;
use App\Models\MedicationBackupSchedule;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Connected\ConnectedCareAlerts;
use App\Services\Medication\Connected\ConnectedCareSettings;
use App\Services\Medication\Downtime\DowntimePackPdf;
use App\Services\Medication\Downtime\DowntimePackService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BackupDeliveryService
{
    public function __construct(
        private readonly BackupDeliveryAccess $access,
        private readonly BackupPdfEncryption $encryption,
        private readonly DowntimePackService $packs,
        private readonly DowntimePackPdf $pdf,
        private readonly BackupMailTransport $transport,
        private readonly BackupScheduleClock $clock,
    ) {}

    public function schedule(User $actor, int $siteId, int $version, array $data): MedicationBackupSchedule
    {
        return DB::transaction(function () use ($actor, $siteId, $version, $data) {
            $current = $this->access->manager($actor, $siteId);
            $schedule = MedicationBackupSchedule::query()->where('site_id', $siteId)->lockForUpdate()->first();
            abort_unless(($schedule?->version ?? 0) === $version, 409, 'The backup schedule changed. Reload before editing.');
            $this->clock->resolve(now(BackupScheduleClock::TIMEZONE)->toDateString(), $data['local_time']);
            abort_unless(is_bool($data['enabled']) && is_int($data['retention_days']) && $data['retention_days'] >= 1 && $data['retention_days'] <= 30, 422);
            // EA-143: a daily schedule is never switched on while backups can't
            // be produced — never without the reviewed encryption.
            if ($data['enabled'] && ! app(ConnectedCareSettings::class)->enabled(ConnectedCareSettings::BACKUPS)) {
                throw ValidationException::withMessages(['enabled' => 'Protected backups need strong PDF encryption, which isn’t set up yet. Save the schedule switched off until it is.']);
            }
            $schedule ??= new MedicationBackupSchedule(['site_id' => $siteId, 'version' => 0]);
            $schedule->forceFill(['timezone' => BackupScheduleClock::TIMEZONE, 'local_time' => $data['local_time'], 'enabled' => $data['enabled'], 'retention_days' => $data['retention_days'], 'approved_by' => $current->id, 'version' => $version + 1])->saveOrFail();

            return $schedule;
        }, 5);
    }

    public function approveRecipient(User $actor, int $scheduleId, int $version, int $userId, bool $approved): MedicationBackupSchedule
    {
        return DB::transaction(function () use ($actor, $scheduleId, $version, $userId, $approved) {
            $schedule = MedicationBackupSchedule::query()->lockForUpdate()->findOrFail($scheduleId);
            $current = $this->access->manager($actor, $schedule->site_id);
            abort_unless($schedule->version === $version, 409);
            $row = $schedule->recipients()->where('user_id', $userId)->lockForUpdate()->first();
            if ($approved) {
                abort_if($schedule->recipients()->whereNull('revoked_at')->count() >= 20 && ($row === null || $row->revoked_at !== null), 422, 'Approve at most 20 recipients.');
                $recipient = $this->access->recipient(User::query()->findOrFail($userId), $schedule->site_id);
                $row ??= new MedicationBackupRecipient(['schedule_id' => $schedule->id, 'user_id' => $recipient->id]);
                $row->forceFill(['email_sha256' => $this->access->emailHash($recipient), 'approved_by' => $current->id, 'approved_at' => now(), 'revoked_at' => null])->saveOrFail();
            } else {
                abort_unless($row !== null, 404);
                $row->forceFill(['revoked_at' => now()])->saveOrFail();
            }
            $schedule->increment('version');

            return $schedule;
        }, 5);
    }

    public function prepare(User $actor, int $siteId, string $day, int $version): MedicationBackupDelivery
    {
        return $this->prepareArtifact($actor, $siteId, $day, $version);
    }

    private function prepareArtifact(User $actor, int $siteId, string $day, int $version, bool $scheduled = false): MedicationBackupDelivery
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Prepare chart backups only at an outer durable transaction boundary.');
        }
        abort_unless($this->encryption->ready(), 503, 'Strong PDF encryption is not configured.');
        abort_unless($day === now(BackupScheduleClock::TIMEZONE)->toDateString(), 422, 'Prepare the current NZ day; previous days cannot be reconstructed as current charts.');
        $claim = (string) Str::uuid();
        $previousArtifactToken = null;
        $delivery = DB::transaction(function () use ($actor, $siteId, $day, $version, $claim, $scheduled, &$previousArtifactToken) {
            $current = $this->access->manager($actor, $siteId);
            $schedule = MedicationBackupSchedule::query()->where('site_id', $siteId)->lockForUpdate()->firstOrFail();
            abort_unless($schedule->version === $version, 409);
            if ($scheduled) {
                $this->assertDueSchedule($schedule, $current, $day, $version);
            }
            $recipients = $this->recipients($schedule);
            $delivery = MedicationBackupDelivery::query()->where('schedule_id', $schedule->id)->where('nz_date', $day)->lockForUpdate()->first();
            // EA-142: the every-minute job doesn't rebuild and resend a failed
            // backup straight away — at most once an hour. A manager can still
            // retry by hand at any time.
            if ($scheduled && $delivery?->state === 'failed' && $delivery->updated_at?->greaterThan(now()->subHour())) {
                return $delivery;
            }
            // An author can refresh their own stale unsent chart. Only the scheduler
            // may replace another author's chart under the current due approver.
            $replaceReady = $delivery?->state === 'ready' && $delivery->claim_token === null
                && ($scheduled || $delivery->prepared_by === $current->id)
                && ($delivery->prepared_by !== $current->id || $delivery->schedule_version !== $schedule->version
                    || $delivery->recipient_ids !== array_keys($recipients)
                    || ! $this->access->recipientDigestMatches($recipients, (string) $delivery->recipient_sha256)
                    || ! $delivery->expires_at?->isFuture() || ! $this->artifactMatches($delivery)
                    || ! $this->readySourceIsCurrent($current, $delivery));
            if ($delivery && ! $replaceReady && in_array($delivery->state, ['ready', 'sent', 'sending', 'uncertain', 'preparing', 'purged'], true)) {
                return $delivery;
            }
            $delivery ??= new MedicationBackupDelivery(['schedule_id' => $schedule->id, 'site_id' => $siteId, 'nz_date' => $day, 'version' => 0]);
            abort_if($delivery->claim_token !== null && $delivery->exists, 409, 'Previous preparation cleanup is still pending.');
            $oldPath = $delivery->artifact_path;
            $previousArtifactToken = $oldPath === null ? null : basename($oldPath, '.pdf');
            abort_if($previousArtifactToken !== null && (! Str::isUuid($previousArtifactToken) || $oldPath !== 'medication-backups/'.$delivery->id.'/'.$previousArtifactToken.'.pdf'), 409, 'The previous protected backup reference could not be verified.');
            // Keep the prior encrypted artifact, password, source and expiry until replacement publication commits.
            $delivery->forceFill(['state' => 'preparing', 'version' => $delivery->version + 1, 'schedule_version' => $schedule->version, 'prepared_by' => $oldPath === null ? $current->id : $delivery->prepared_by, 'claim_token' => $claim, 'claimed_at' => now(), 'failure_code' => null, 'expires_at' => $delivery->expires_at ?? now()->addDays($schedule->retention_days)])->saveOrFail();

            return $delivery;
        }, 5);
        if ($delivery->claim_token !== $claim) {
            $cleanupPending = $delivery->state === 'ready' && $delivery->claim_token !== null;
            $this->cleanupReplacedArtifact($delivery);
            $fresh = $delivery->fresh();
            if ($cleanupPending && $fresh->state === 'ready' && $fresh->claim_token === null) {
                // Cleanup can unblock a stale ready row; recheck current authority
                // and freshness before returning or publishing a replacement.
                return $this->prepareArtifact($actor, $siteId, $day, $version, $scheduled);
            }

            return $fresh;
        }
        $owned = null;
        try {
            $pack = $this->packs->build($actor, $siteId, $day);
            $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $bytes = $this->encryption->encrypt($this->pdf->render($pack), $password);
            $owned = 'medication-backups/'.$delivery->id.'/'.$claim.'.pdf';
            abort_unless(Storage::disk('private')->put($owned, $bytes), 503);
            DB::transaction(function () use ($actor, $delivery, $pack, $password, $bytes, $owned, $claim, $previousArtifactToken, $scheduled, $day, $version): void {
                $row = MedicationBackupDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
                abort_unless($row->state === 'preparing' && $row->claim_token === $claim, 409);
                $schedule = MedicationBackupSchedule::query()->lockForUpdate()->findOrFail($row->schedule_id);
                $current = $this->access->manager($actor, $row->site_id);
                abort_unless($row->schedule_version === $schedule->version, 409, 'The schedule changed while the chart was rendered.');
                if ($scheduled) {
                    $this->assertDueSchedule($schedule, $current, $day, $version);
                }
                $recipients = $this->recipients($schedule);
                // Reuses every canonical source/current-authority release check after rendering.
                $this->packs->release($current, $pack, function () use ($row, $schedule, $current, $pack, $password, $bytes, $owned, $recipients, $previousArtifactToken): void {
                    // In ready state the token durably identifies only the previous artifact pending cleanup.
                    $row->forceFill(['state' => 'ready', 'prepared_by' => $current->id, 'source_snapshot' => $pack, 'source_sha256' => hash('sha256', json_encode($pack['_source'], JSON_THROW_ON_ERROR)), 'artifact_path' => $owned, 'artifact_sha256' => hash('sha256', $bytes), 'password' => $password, 'recipient_ids' => array_keys($recipients), 'recipient_sha256' => $this->recipientDigest($recipients), 'claim_token' => $previousArtifactToken, 'claimed_at' => $previousArtifactToken ? now() : null, 'failure_code' => $previousArtifactToken ? 'waiting_for_previous_backup_cleanup_before_sending' : null, 'expires_at' => now()->addDays($schedule->retention_days), 'version' => $row->version + 1])->saveOrFail();
                    $this->event($row, $current, 'prepared');
                });
            }, 1);
        } catch (Throwable $exception) {
            try {
                MedicationBackupDelivery::query()->whereKey($delivery->id)->where('state', 'preparing')->where('claim_token', $claim)->update(['state' => 'failed', 'failure_code' => 'preparation_failed', 'version' => DB::raw('version + 1')]);
            } catch (Throwable) {
                // An ambiguous commit is never followed by deleting a referenced artifact.
            }
            if ($this->deleteUnused($owned)) {
                try {
                    MedicationBackupDelivery::query()->whereKey($delivery->id)->where('state', 'failed')->where('claim_token', $claim)->update(['claim_token' => null, 'claimed_at' => null]);
                } catch (Throwable) {
                    // Retry cleanup from the durable claim without masking the original error.
                }
            }
            throw $exception;
        }
        $this->cleanupReplacedArtifact($delivery->fresh());

        return $delivery->fresh();
    }

    public function send(User $actor, int $id, int $version): MedicationBackupDelivery
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Send chart backups only at an outer durable transaction boundary.');
        }
        abort_unless(config('emar-catalogue-backups.send_enabled', false), 503, 'Backup email delivery is disabled.');
        $token = (string) Str::uuid();
        // Durable claim precedes all external submission; a crash cannot silently retry mail.
        DB::transaction(function () use ($actor, $id, $version, $token): void {
            $row = MedicationBackupDelivery::query()->lockForUpdate()->findOrFail($id);
            $current = $this->access->manager($actor, $row->site_id);
            abort_unless($row->prepared_by === $current->id, 403);
            abort_unless($row->version === $version && $row->state === 'ready' && $row->expires_at?->isFuture(), 409);
            abort_unless($row->claim_token === null, 409, 'The previous protected backup is still being cleaned up. Prepare again to retry cleanup before sending.');
            $row->forceFill(['state' => 'sending', 'claim_token' => $token, 'claimed_at' => now(), 'attempt_count' => $row->attempt_count + 1, 'version' => $row->version + 1])->saveOrFail();
            MedicationBackupAttempt::query()->create(['delivery_id' => $row->id, 'token' => $token, 'actor_id' => $current->id, 'state' => 'sending', 'started_at' => now()]);
        }, 1);
        $submissionStarted = false;
        try {
            app(BackupEmailSender::class)->prepare();
            // ONE attempt. Nested release cannot retry a submitted transport side effect.
            DB::transaction(function () use ($actor, $id, $token, &$submissionStarted): void {
                $row = MedicationBackupDelivery::query()->lockForUpdate()->findOrFail($id);
                abort_unless($row->state === 'sending' && $row->claim_token === $token && $row->expires_at?->isFuture(), 409);
                $schedule = MedicationBackupSchedule::query()->lockForUpdate()->findOrFail($row->schedule_id);
                $current = $this->access->manager($actor, $row->site_id);
                abort_unless($row->prepared_by === $current->id && $row->schedule_version === $schedule->version, 409);
                $recipients = $this->recipients($schedule);
                abort_unless($row->recipient_ids === array_keys($recipients) && $this->access->recipientDigestMatches($recipients, $row->recipient_sha256), 409);
                $pack = $row->source_snapshot;
                abort_unless(is_array($pack) && $row->artifact_path && Storage::disk('private')->exists($row->artifact_path), 409);
                $bytes = Storage::disk('private')->get($row->artifact_path);
                abort_unless(hash_equals($row->artifact_sha256, hash('sha256', $bytes)), 409);
                $this->packs->release($current, $pack, function () use ($row, $current, $token, $recipients, $bytes, &$submissionStarted): void {
                    $submissionStarted = true;
                    // EA-140: the email and file say which house and which backup.
                    $this->transport->send(array_values($recipients), $bytes, $row->nz_date, $this->houseName($row), self::reference($row));
                    $row->forceFill(['state' => 'sent', 'sent_at' => now(), 'failure_code' => null, 'claim_token' => null, 'claimed_at' => null, 'version' => $row->version + 1])->saveOrFail();
                    MedicationBackupAttempt::query()->where('token', $token)->update(['state' => 'sent', 'result_code' => 'accepted', 'finished_at' => now()]);
                    $this->event($row, $current, 'sent');
                });
            }, 1);
        } catch (Throwable $exception) {
            $knownUnsent = ! $submissionStarted || $exception instanceof MailNotSubmitted;
            $this->finishFailedAttempt($id, $token, $knownUnsent ? 'failed' : 'uncertain', $knownUnsent ? 'not_submitted' : 'submission_unknown');
            // EA-138: a failed or uncertain send is surfaced, not left on this page.
            $failed = MedicationBackupDelivery::query()->find($id);
            if ($failed !== null && in_array($failed->state, ['failed', 'uncertain'], true)) {
                $this->alerts()->backupProblem((int) $failed->schedule_id, (string) $failed->nz_date, $failed->state, self::reference($failed));
            }
            throw $exception;
        }
        $sent = MedicationBackupDelivery::query()->findOrFail($id);
        if ($sent->state === 'sent') {
            $this->alerts()->backupDelivered((int) $sent->site_id, (string) $sent->nz_date);
        }

        return $sent;
    }

    public function retry(User $actor, int $id, int $version): MedicationBackupDelivery
    {
        $row = DB::transaction(function () use ($actor, $id, $version) {
            $row = MedicationBackupDelivery::query()->lockForUpdate()->findOrFail($id);
            $this->access->manager($actor, $row->site_id);
            abort_unless($row->version === $version && $row->state === 'failed', 409, 'Only a known unsent backup can be retried.');

            return $row;
        }, 1);
        $schedule = MedicationBackupSchedule::query()->findOrFail($row->schedule_id);
        $ready = $this->prepare($actor, $row->site_id, $row->nz_date, $schedule->version);

        return $this->send($actor, $ready->id, $ready->version);
    }

    public function readable(User $actor, int $id): MedicationBackupDelivery
    {
        return DB::transaction(function () use ($actor, $id) {
            $row = MedicationBackupDelivery::query()->lockForUpdate()->findOrFail($id);
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['*']);
            $expected = $this->access->complete($current, $row->site_id);
            $manager = $current->canDo('medications.backups.manage');
            $schedule = MedicationBackupSchedule::query()->lockForUpdate()->findOrFail($row->schedule_id);
            if (! $manager) {
                $approval = $schedule->recipients()->where('user_id', $current->id)->whereNull('revoked_at')->lockForUpdate()->first();
                abort_unless($approval !== null, 404);
                $recipient = $this->access->recipient($current, $row->site_id);
                abort_unless($this->access->emailHashMatches($recipient, $approval->email_sha256), 409, 'A recipient mailbox changed. Review the recipient again.');
            }
            // A move removes access to that person's historical chart even for this old house.
            $pack = $row->source_snapshot;
            abort_if(is_array($pack) && ($pack['controlled_pages_included'] ?? false) && ! $current->canDo('medications.controlled.view'), 404);
            abort_unless(is_array($pack) && collect($pack['_source']['client_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all() === $expected && $row->expires_at?->isFuture() && $row->artifact_path && $row->password && in_array($row->state, ['ready', 'sent', 'failed', 'uncertain'], true), 404);
            abort_unless(Storage::disk('private')->exists($row->artifact_path) && hash_equals($row->artifact_sha256, hash('sha256', Storage::disk('private')->get($row->artifact_path))), 404);

            return $row;
        }, 1);
    }

    public function recoverInterrupted(): int
    {
        foreach (MedicationBackupDelivery::query()->where('state', 'ready')->whereNotNull('claim_token')->get() as $ready) {
            $this->cleanupReplacedArtifact($ready);
        }
        $count = 0;
        foreach (MedicationBackupDelivery::query()->where('state', 'failed')->whereNotNull('claim_token')->get() as $failed) {
            if (Str::isUuid($failed->claim_token) && $this->deleteUnused('medication-backups/'.$failed->id.'/'.$failed->claim_token.'.pdf')) {
                MedicationBackupDelivery::query()->whereKey($failed->id)->where('state', 'failed')->where('claim_token', $failed->claim_token)->update(['claim_token' => null, 'claimed_at' => null]);
            }
        }
        foreach (MedicationBackupDelivery::query()->whereIn('state', ['preparing', 'sending'])->where('claimed_at', '<', now()->subMinutes(30))->pluck('id') as $id) {
            $interruptedPath = null;
            $cleanupToken = null;
            $count += DB::transaction(function () use ($id, &$interruptedPath, &$cleanupToken): int {
                $row = MedicationBackupDelivery::query()->lockForUpdate()->find($id);
                if (! $row || ! in_array($row->state, ['preparing', 'sending'], true) || ! $row->claimed_at?->lessThan(now()->subMinutes(30))) {
                    return 0;
                }
                if ($row->state === 'preparing' && is_string($row->claim_token) && Str::isUuid($row->claim_token)) {
                    $cleanupToken = $row->claim_token;
                    $interruptedPath = 'medication-backups/'.$row->id.'/'.$row->claim_token.'.pdf';
                }
                if ($row->state === 'sending') {
                    MedicationBackupAttempt::query()->where('token', $row->claim_token)->where('state', 'sending')->update(['state' => 'uncertain', 'result_code' => 'submission_unknown', 'finished_at' => now()]);
                }
                $row->forceFill(['state' => $row->state === 'sending' ? 'uncertain' : 'failed', 'failure_code' => $row->state === 'sending' ? 'submission_unknown' : 'preparation_interrupted', 'claim_token' => $cleanupToken, 'claimed_at' => $cleanupToken ? $row->claimed_at : null, 'version' => $row->version + 1])->saveOrFail();

                return 1;
            }, 1);
            if ($interruptedPath !== null && $this->deleteUnused($interruptedPath)) {
                MedicationBackupDelivery::query()->whereKey($id)->where('state', 'failed')->where('claim_token', $cleanupToken)->update(['claim_token' => null, 'claimed_at' => null]);
            }
        }

        return $count;
    }

    public function purgeExpired(): int
    {
        $this->recoverInterrupted();
        $count = 0;
        foreach (MedicationBackupDelivery::query()->whereNotIn('state', ['preparing', 'sending', 'purged'])->whereNull('claim_token')->where('expires_at', '<=', now())->pluck('id') as $id) {
            $path = DB::transaction(function () use ($id) {
                $row = MedicationBackupDelivery::query()->lockForUpdate()->findOrFail($id);
                if (! $row->expires_at?->lessThanOrEqualTo(now()) || in_array($row->state, ['preparing', 'sending', 'purged'], true)) {
                    return null;
                }
                $path = $row->artifact_path;
                if ($path !== null && Storage::disk('private')->exists($path) && ! Storage::disk('private')->delete($path)) {
                    throw new \RuntimeException('backup_retention_failed');
                }
                $row->forceFill(['state' => 'purged', 'artifact_path' => null, 'password' => null, 'source_snapshot' => null, 'recipient_ids' => null, 'purged_at' => now(), 'version' => $row->version + 1])->saveOrFail();

                return $path;
            }, 1);
            if ($path !== null) {
                $this->deleteUnused($path);
                $count++;
            }
        }

        return $count;
    }

    public function dispatchDue(): array
    {
        foreach (['medication_backup_schedules', 'medication_backup_recipients', 'medication_backup_deliveries', 'medication_backup_attempts', 'medication_backup_step_up_uses'] as $table) {
            if (! Schema::hasTable($table)) {
                return ['prepared' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 1, 'installation' => 'unavailable'];
            }
        }
        $this->recoverInterrupted();
        $this->encryption->cleanupStale();
        $day = now(BackupScheduleClock::TIMEZONE)->toDateString();
        $results = ['prepared' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0];
        // Settings › Connected services: switched off, the job makes nothing (D4).
        if (! app(ConnectedCareSettings::class)->switchedOn(ConnectedCareSettings::BACKUPS)) {
            $this->purgeExpired();

            return ['prepared' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 1, 'feature' => 'off'];
        }
        foreach (MedicationBackupSchedule::query()->where('enabled', true)->orderBy('id')->get() as $schedule) {
            if ($this->clock->resolve($day, $schedule->local_time)['instant']->isFuture()) {
                continue;
            }
            // EA-142: the schedule runs as whoever last saved it. When they can no
            // longer manage this house's backups, say so and alert — never a
            // silent counter.
            $actor = User::query()->find($schedule->approved_by);
            if ($actor === null || ! $this->stillManages($actor, (int) $schedule->site_id)) {
                $results['failed']++;
                $this->recordRun($schedule, $day, 'authority_lapsed', 'schedule_owner_cannot_manage_backups');

                continue;
            }
            if (! app(ConnectedCareSettings::class)->enabled(ConnectedCareSettings::BACKUPS)) {
                $results['failed']++;
                $this->recordRun($schedule, $day, 'not_configured', 'encryption_not_ready');

                continue;
            }
            try {
                $row = $this->prepareArtifact($actor, $schedule->site_id, $day, $schedule->version, scheduled: true);
                if ($row->state === 'ready') {
                    $results['prepared']++;
                    if (config('emar-catalogue-backups.send_enabled', false)) {
                        $row = $this->send($actor, $row->id, $row->version);
                        $results['sent']++;
                    } else {
                        $results['disabled']++;
                    }
                }
                $this->recordRun($schedule, $day, $row->state === 'ready' ? 'prepared_not_emailed' : $row->state, $row->failure_code, $row);
            } catch (Throwable) {
                $results['failed']++; // Aggregate only; no subject, person, medicine, mailbox or secret.
                $row = MedicationBackupDelivery::query()->where('schedule_id', $schedule->id)->where('nz_date', $day)->first();
                $this->recordRun($schedule, $day, in_array($row?->state, ['uncertain', 'sending'], true) ? 'uncertain' : 'failed', $row?->failure_code ?? 'preparation_failed', $row);
            }
        }
        $this->purgeExpired();

        return $results;
    }

    private function stillManages(User $actor, int $siteId): bool
    {
        try {
            DB::transaction(fn () => $this->access->manager($actor, $siteId), 1);

            return true;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    /**
     * EA-142/EA-143: what the job last did for this house, shown on the page;
     * the first problem of the NZ day alerts the house (later minutes of the
     * same problem tell nobody again).
     */
    private function recordRun(MedicationBackupSchedule $schedule, string $day, string $state, ?string $code, ?MedicationBackupDelivery $row = null): void
    {
        $code = $code !== null ? Str::limit($code, 60, '') : null;
        try {
            MedicationBackupSchedule::query()->whereKey($schedule->id)
                ->where(fn ($q) => $q->whereNull('last_run_nz_date')->orWhere('last_run_nz_date', '!=', $day)
                    ->orWhereNull('last_run_state')->orWhere('last_run_state', '!=', $state)
                    ->orWhereRaw('COALESCE(last_run_code, \'\') != ?', [$code ?? '']))
                ->update(['last_run_nz_date' => $day, 'last_run_state' => $state, 'last_run_code' => $code, 'last_run_at' => now()]);
        } catch (Throwable) {
            // The run itself stands; the status line catches up next minute.
        }
        if (in_array($state, ['failed', 'uncertain', 'authority_lapsed', 'not_configured'], true)) {
            $this->alerts()->backupProblem($schedule, $day, $state, $row ? self::reference($row) : null);
        } elseif ($state === 'sent') {
            $this->alerts()->backupDelivered((int) $schedule->site_id, $day);
        }
    }

    private function alerts(): ConnectedCareAlerts
    {
        return app(ConnectedCareAlerts::class);
    }

    public function dto(MedicationBackupDelivery $row, User $actor): array
    {
        $manager = $actor->canDo('medications.backups.manage');
        $alive = $row->expires_at?->isFuture() && $row->artifact_path !== null && in_array($row->state, ['ready', 'sent', 'failed', 'uncertain'], true);

        return ['id' => (int) $row->id, 'reference' => self::reference($row), 'site_id' => (int) $row->site_id, 'nz_date' => $row->nz_date, 'state' => $row->state, 'version' => $row->version, 'attempt_count' => $row->attempt_count, 'failure_code' => $row->failure_code, 'created_at' => $row->created_at?->toIso8601String(), 'sent_at' => $row->sent_at?->toIso8601String(), 'expires_at' => $row->expires_at?->toIso8601String(), 'can_send' => $manager && $row->prepared_by === $actor->id && $row->state === 'ready' && $row->claim_token === null && $alive && (bool) config('emar-catalogue-backups.send_enabled', false), 'can_retry' => $manager && config('emar-catalogue-backups.send_enabled', false) && $row->state === 'failed' && $row->claim_token === null && $row->nz_date === now(BackupScheduleClock::TIMEZONE)->toDateString(), 'can_download' => (bool) $alive, 'can_reveal' => (bool) $alive];
    }

    private function artifactMatches(MedicationBackupDelivery $row): bool
    {
        return $row->artifact_path !== null && Storage::disk('private')->exists($row->artifact_path)
            && hash_equals((string) $row->artifact_sha256, hash('sha256', Storage::disk('private')->get($row->artifact_path)));
    }

    private function readySourceIsCurrent(User $actor, MedicationBackupDelivery $row): bool
    {
        try {
            // No export event or transport: reuse still needs the canonical source gate.
            $this->packs->release($actor, $row->source_snapshot, static function (): void {});
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception; // Current authority/ownership denials never grant replacement.
            }

            return false;
        }

        return true;
    }

    private function assertDueSchedule(MedicationBackupSchedule $schedule, User $actor, string $day, int $version): void
    {
        abort_unless($schedule->enabled && $schedule->version === $version && (int) $schedule->approved_by === $actor->id
            && $day === now(BackupScheduleClock::TIMEZONE)->toDateString()
            && ! $this->clock->resolve($day, $schedule->local_time)['instant']->isFuture(), 409, 'The backup schedule is no longer approved and due. Reload before preparing.');
    }

    private function recipients(MedicationBackupSchedule $schedule): array
    {
        $rows = $schedule->recipients()->whereNull('revoked_at')->orderBy('user_id')->lockForUpdate()->get();
        if ($rows->isEmpty() || $rows->count() > 20) {
            throw ValidationException::withMessages(['recipients' => 'Approve between 1 and 20 current verified house-chart recipients.']);
        }
        $result = [];
        foreach ($rows as $row) {
            $user = $this->access->recipient(User::query()->findOrFail($row->user_id), $schedule->site_id);
            abort_unless($this->access->emailHashMatches($user, $row->email_sha256), 409, 'A recipient mailbox changed. Review the recipient again.');
            // Fixed rule: the HR work email only, never the sign-in email.
            $result[(int) $user->id] = (string) $this->access->workEmail($user);
        }

        return $result;
    }

    private function recipientDigest(array $recipients): string
    {
        return $this->access->recipientDigest($recipients);
    }

    /** "B123" — the delivery reference shown on the page, in the email and in the file name. */
    public static function reference(MedicationBackupDelivery $row): string
    {
        return 'B'.$row->id;
    }

    public function houseName(MedicationBackupDelivery $row): string
    {
        return (string) (Site::query()->whereKey($row->site_id)->value('name') ?? 'House '.$row->site_id);
    }

    /** "chart-backup-kowhai-house-2026-10-09-B123.pdf": the house and reference, no resident names. */
    public function filename(MedicationBackupDelivery $row): string
    {
        return BackupMailTransport::filename($this->houseName($row), (string) $row->nz_date, self::reference($row));
    }

    /**
     * EA-057: who opened a readable copy of a whole-house chart, and when —
     * a download or a password reveal — on the medication event chain (Reports
     * › Export history). Call inside the transaction that checked access.
     */
    public function recordDisclosure(MedicationBackupDelivery $row, User $actor, string $action): void
    {
        abort_unless(in_array($action, ['downloaded', 'password_revealed'], true), 500);
        $pack = is_array($row->source_snapshot) ? $row->source_snapshot : [];
        app(MedicationEventRecorder::class)->append(new MedicationEventData(
            siteId: (int) $row->site_id,
            kind: 'backup.'.$action,
            subjectType: 'medication_backup',
            subjectId: (string) $row->id,
            actorId: (int) $actor->id,
            occurredAt: CarbonImmutable::now('UTC'),
            summary: $action === 'downloaded' ? 'Protected chart backup downloaded' : 'Protected chart backup password revealed',
            facts: ['delivery' => self::reference($row), 'nz_date' => $row->nz_date, 'controlled_pages_included' => (bool) ($pack['controlled_pages_included'] ?? false)],
            controlled: (bool) ($pack['controlled_pages_included'] ?? false),
        ));
    }

    private function event(MedicationBackupDelivery $row, User $actor, string $action): void
    {
        app(MedicationEventRecorder::class)->append(new MedicationEventData(siteId: (int) $row->site_id, kind: 'backup.'.$action, subjectType: 'medication_backup', subjectId: (string) $row->id, actorId: (int) $actor->id, occurredAt: CarbonImmutable::now('UTC'), summary: 'Protected chart backup '.$action, facts: ['nz_date' => $row->nz_date, 'encryption' => 'AES-256-R6', 'attempt_count' => $row->attempt_count]));
    }

    private function finishFailedAttempt(int $id, string $token, string $state, string $code): void
    {
        try {
            DB::transaction(function () use ($id, $token, $state, $code): void {
                $row = MedicationBackupDelivery::query()->whereKey($id)->where('state', 'sending')->where('claim_token', $token)->lockForUpdate()->first();
                if (! $row) {
                    return; // Includes a committed sent result followed by afterCommit failure.
                }
                $row->forceFill(['state' => $state, 'failure_code' => $code, 'claim_token' => null, 'claimed_at' => null, 'version' => $row->version + 1])->saveOrFail();
                MedicationBackupAttempt::query()->where('token', $token)->update(['state' => $state, 'result_code' => $code, 'finished_at' => now()]);
            }, 1);
        } catch (Throwable) {
            // Keep the durable sending claim; recovery marks it uncertain, never resends.
        }
    }

    private function cleanupReplacedArtifact(MedicationBackupDelivery $row): void
    {
        if ($row->state !== 'ready' || ! Str::isUuid($row->claim_token ?? '')) {
            return;
        }
        $path = 'medication-backups/'.$row->id.'/'.$row->claim_token.'.pdf';
        if (! $this->deleteUnused($path)) {
            return;
        }
        try {
            MedicationBackupDelivery::query()->whereKey($row->id)->where('state', 'ready')->where('claim_token', $row->claim_token)
                ->update(['claim_token' => null, 'claimed_at' => null, 'failure_code' => null]);
        } catch (Throwable) {
            // Keep the durable cleanup marker; deleting an already absent old file is safe to retry.
        }
    }

    private function deleteUnused(?string $path): bool
    {
        if ($path === null) {
            return true;
        }
        try {
            if (MedicationBackupDelivery::query()->where('artifact_path', $path)->exists()) {
                return false;
            }

            return ! Storage::disk('private')->exists($path) || Storage::disk('private')->delete($path);
        } catch (Throwable) {
            return false; // Preserve bytes when a committed reference cannot be ruled out.
        }
    }
}
