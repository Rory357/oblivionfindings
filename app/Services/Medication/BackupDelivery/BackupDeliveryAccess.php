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
            abort_if($this->financeOnly($actor), 403);
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

    /** Finance without a clinical or managing role: no backup action, and no backups page (EA-144). */
    public function financeOnly(User $actor): bool
    {
        return $actor->hasRole('finance') && ! $actor->hasRole('admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead', 'auditor');
    }

    /**
     * A backup recipient: a current staff member with a work email on their
     * HR profile (never the sign-in email, which can be personal — fixed, not
     * a setting) and authenticator two-step sign-in, without which they could
     * never open a backup's password (EA-141).
     */
    public function recipient(User $user, int $siteId): User
    {
        $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($user, ['*']);
        abort_unless($current->email_verified_at !== null, 422, 'Approve a verified staff account.');
        // Current staff with whole-house chart authority first (403/404),
        // then what they need to receive and open a backup (422).
        $this->complete($current, $siteId);
        abort_unless($this->workEmail($current) !== null, 422, 'Approve someone with a work email on their HR profile. Backups never go to a sign-in email.');
        abort_unless($this->canOpen($current), 422, 'They need authenticator two-step sign-in before they can open a backup.');

        return $current;
    }

    /** The recipient's HR work email, or null (backups never use the sign-in email). */
    public function workEmail(User $user): ?string
    {
        $email = $user->medicationAlertWorkEmail();

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($email)) : null;
    }

    /** Whether they can reveal a backup's password (needs authenticator 2FA). */
    public function canOpen(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    public function emailHash(User $user): string
    {
        return hash_hmac('sha256', (string) $this->workEmail($user), (string) config('app.key'));
    }

    public function emailHashMatches(User $user, string $approvedHash): bool
    {
        $email = $this->workEmail($user);

        return $email !== null && $this->matchesConfiguredKeyHash($approvedHash, $email);
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
