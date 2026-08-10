<?php

namespace App\Domain\SecurityDevices\Console;

use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
use Illuminate\Console\Command;
use Throwable;

final class VerifyCredentialContainmentCommand extends Command
{
    protected $signature = 'security-devices:verify-credential-containment
        {site_id : Exact Site identifier}
        {reference_key : Governed credential reference key, never secret material}
        {--require-active : Require the rotated replacement to be tested and active}';

    protected $description = 'Verify prior credential leases are contained after rotation or compromise response.';

    public function handle(
        CredentialReferenceRules $rules,
        CredentialLeaseLifecycleService $leases,
    ): int {
        try {
            $siteId = filter_var($this->argument('site_id'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $referenceKey = $rules->referenceKey((string) $this->argument('reference_key'));
        } catch (Throwable) {
            $this->error('Credential containment scope is invalid.');

            return self::INVALID;
        }
        if ($siteId === false) {
            $this->error('Credential containment scope is invalid.');

            return self::INVALID;
        }

        $reference = CredentialReference::query()
            ->where('site_id', $siteId)
            ->where('reference_key', $referenceKey)
            ->first();
        if (! $reference) {
            $this->error('Credential reference is unavailable in the exact Site scope.');

            return self::FAILURE;
        }

        $leases->reconcile();
        $outstandingPrior = CredentialLeaseGrant::query()
            ->where('credential_reference_id', $reference->id)
            ->where('reference_version', '<', $reference->version)
            ->where(function ($query): void {
                $query
                    ->whereNotIn('status', [
                        CredentialLeaseGrant::STATUS_RELEASED,
                        CredentialLeaseGrant::STATUS_CONTAINED,
                        CredentialLeaseGrant::STATUS_EXPIRED,
                    ])
                    ->orWhereNotNull('lease_id')
                    ->orWhereNull('ended_at');
            })
            ->count();
        if ($outstandingPrior > 0) {
            $this->error("Containment is incomplete: {$outstandingPrior} prior lease lifecycle record(s) are not terminal and erased.");

            return self::FAILURE;
        }

        if ($this->option('require-active') && (
            $reference->status !== CredentialReferenceStatus::Active
            || $reference->rotation_status !== CredentialRotationStatus::Current
            || $reference->test_status !== CredentialTestStatus::Passed
            || $reference->last_tested_at === null
        )) {
            $this->error('The rotated replacement has not passed activation testing.');

            return self::FAILURE;
        }

        $this->info('Credential containment verified.');
        $this->line('Site: '.$siteId);
        $this->line('Reference UUID: '.$reference->reference_uuid);
        $this->line('Current version: '.$reference->version);
        $this->line('Outstanding prior leases: 0');

        return self::SUCCESS;
    }
}
