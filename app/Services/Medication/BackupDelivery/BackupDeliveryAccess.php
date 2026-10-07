<?php

namespace App\Services\Medication\BackupDelivery;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Medication\Downtime\DowntimeAccess;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\UserSiteAccessService;

class BackupDeliveryAccess
{
    public function manager(User $actor, int $siteId): User
    {
        $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['*']);
        abort_unless($current->canDo('medications.backups.manage'), 403);
        $this->complete($current, $siteId);

        return $current;
    }

    public function complete(User $actor, int $siteId): array
    {
        return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $siteId): array {
            abort_unless($reads->query(app(HrCurrentStaffService::class)->currentUsersQuery()->whereKey($actor->id))->exists()
                && $actor->canDo('medications.reports.view') && $actor->canDo('medications.reports.export'), 403);
            abort_if($actor->hasRole('finance') && ! $actor->hasRole('admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead', 'auditor'), 403);
            $site = $reads->query(Site::query()->whereKey($siteId))->first();
            abort_unless($site && $site->is_active && ! $site->archived && $site->archived_at === null, 404);
            $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless(in_array($siteId, $ids, true), 404);
            $people = $reads->query(Client::query()->where('site_id', $siteId)->orderBy('id'))->get();
            abort_if($people->count() > 100, 422, 'This house exceeds the safe backup limit.');
            foreach ($people as $person) {
                app(MedicationRecordAccess::class)->assertReportable($actor, $person);
            }
            // Complete house backup cannot quietly omit controlled charts.
            $controlled = $reads->query(ClientMedication::query()->whereIn('client_id', $people->modelKeys())->where('controlled_drug', true))->exists();
            abort_if($controlled && ! $actor->canDo('medications.controlled.view'), 403);
            $expected = array_map('intval', $people->modelKeys());
            $actual = app(DowntimeAccess::class)->clients($actor, $siteId, pack: true);
            sort($actual, SORT_NUMERIC);
            abort_unless($actual === $expected, 403);

            return $expected;
        });
    }

    public function recipient(User $user, int $siteId): User
    {
        $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($user, ['*']);
        abort_unless($current->email_verified_at && filter_var($current->email, FILTER_VALIDATE_EMAIL), 422, 'Approve a verified staff mailbox.');
        $this->complete($current, $siteId);

        return $current;
    }

    public function emailHash(User $user): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($user->email)), (string) config('app.key'));
    }

    public function emailHashMatches(User $user, string $approvedHash): bool
    {
        return $this->matchesConfiguredKeyHash($approvedHash, mb_strtolower(trim($user->email)));
    }

    public function recipientDigest(array $recipients): string
    {
        return hash_hmac('sha256', json_encode($recipients, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function recipientDigestMatches(array $recipients, string $preparedHash): bool
    {
        return $this->matchesConfiguredKeyHash($preparedHash, json_encode($recipients, JSON_THROW_ON_ERROR));
    }

    private function matchesConfiguredKeyHash(string $approvedHash, string $value): bool
    {
        // Persisted HMACs use the literal configured key, including base64:. Only encryption decodes it.
        foreach ([(string) config('app.key'), ...config('app.previous_keys', [])] as $key) {
            if (is_string($key) && $key !== '' && hash_equals($approvedHash, hash_hmac('sha256', $value, $key))) {
                return true;
            }
        }

        return false;
    }
}
