<?php

namespace App\Services\HealthSafety;

use App\Domain\Governance\Models\GovernanceSetting;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Validation\ValidationException;

/** Small audited settings patches preserve unseen and unchanged assignments. */
final class WorkerParticipationTrainingReviewerSettings
{
    private const KEYS = ['governance.settings.manage', 'hazards.view', 'healthSafety.viewAllSites'];

    public function __construct(private readonly WorkerParticipationTrainingReviewerResolver $resolver) {}

    public function payload(User $actor): array
    {
        $keys = [...self::KEYS, 'governance.settings.view'];

        return HealthSafetyReadContext::within($actor, $keys, function ($context) use ($keys): array {
            return $context->consumeSourceViewer($keys, function (User $current, $reads) use ($context, $keys): array {
                abort_unless($current->canDo('governance.settings.view'), 403);
                $configuration = $this->resolver->configuration();
                $internal = $current->approved_at !== null && ! $current->isExternalClinicianAccount()
                    && $reads->query(User::query()->staff())->whereKey($current->id)->exists();
                $canManage = $internal
                    && $current->canDo('governance.settings.manage');
                $siteIds = $internal && $current->canDo('hazards.view')
                    ? app(UserSiteAccessService::class)->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads) : [];
                $authority = $context->originalSourceAuthorityQuery($keys, $siteIds);
                $sites = $reads->query(Site::query()->active()->notArchived()->whereNull('archived_at')->whereIn('id', $siteIds)->whereExists((clone $authority)->selectRaw('1')->toBase()))
                    ->orderBy('name')->get(['id', 'name']);
                $people = $canManage && $siteIds !== []
                    ? $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->get(['users.id']) : collect();
                $candidates = []; $all = []; $identities = [];
                foreach ($people as $person) {
                    $identity = $this->resolver->identityForSites($person, $siteIds);
                    if ($identity !== null) { $identities[] = $identity; }
                }
                foreach ($sites as $site) {
                    $choices = [];
                    foreach ($identities as $identity) {
                        if (in_array((int) $site->id, $identity['site_ids'], true)) {
                            $choices[] = ['id' => $identity['id'], 'name' => $identity['name']];
                            $all[$identity['id']] = $identity['name'];
                        }
                    }
                    usort($choices, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
                    $candidates[(int) $site->id] = $choices;
                }
                $defaultId = $configuration['default_user_id'] ?? null;
                $eligibilityChecked = $canManage && $siteIds !== [];
                $default = is_int($defaultId) && $defaultId > 0
                    ? ['id' => $defaultId, 'name' => $all[$defaultId] ?? null, 'eligible' => $eligibilityChecked ? isset($all[$defaultId]) : null] : null;
                $rows = [];
                foreach ($sites as $site) {
                    $id = (int) $site->id;
                    $overrides = is_array($configuration['site_overrides'] ?? null) ? $configuration['site_overrides'] : [];
                    $present = array_key_exists((string) $id, $overrides);
                    $reviewerId = $present ? $overrides[(string) $id] : null;
                    $choiceNames = array_column($candidates[$id], 'name', 'id');
                    $rows[] = ['id' => $id, 'name' => $site->name,
                        'override' => ! $present ? 'inherit' : ($reviewerId === null ? 'unassigned' : 'person'),
                        'reviewer' => is_int($reviewerId) && $reviewerId > 0
                            ? ['id' => $reviewerId, 'name' => $choiceNames[$reviewerId] ?? null, 'eligible' => $eligibilityChecked ? isset($choiceNames[$reviewerId]) : null] : null,
                        'eligibleReviewers' => $candidates[$id]];
                }
                $defaults = [];
                foreach ($all as $id => $name) { $defaults[] = ['id' => $id, 'name' => $name]; }
                usort($defaults, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

                return ['default' => $default, 'sites' => $rows, 'defaultCandidates' => $defaults, 'can_manage' => $canManage];
            });
        });
    }

    /** Called inside the controller's existing transaction, alongside its audit. */
    public function applyPatch(User $actor, mixed $patch): bool
    {
        if (! is_array($patch) || array_diff(array_keys($patch), ['default_user_id', 'expected_default_user_id', 'sites'])) {
            $this->invalid('training_reviewers_patch', 'Choose reviewer changes from the settings form.');
        }
        if (! array_key_exists('default_user_id', $patch) && ! array_key_exists('expected_default_user_id', $patch)
            && (! array_key_exists('sites', $patch) || $patch['sites'] === [])) {
            return false;
        }
        return HealthSafetyReadContext::within($actor, self::KEYS, function ($context) use ($patch): bool {
            return $context->consumeSourceViewer(self::KEYS, function (User $current, $reads) use ($patch): bool {
                abort_unless($current->approved_at !== null && ! $current->isExternalClinicianAccount()
                    && $current->canDo('governance.settings.manage')
                    && $reads->query(User::query()->staff())->whereKey($current->id)->exists(), 403);
                // The user lock serialises same-actor changes; the unique-key insert
                // and row lock serialise settings patches across distinct managers.
                $inserted = GovernanceSetting::query()->insertOrIgnore(['key' => WorkerParticipationTrainingReviewerResolver::KEY,
                    'value' => '{"default_user_id":null,"site_overrides":{}}', 'category' => GovernanceSetting::CATEGORY_GENERAL,
                    'created_at' => now(), 'updated_at' => now()]);
                $setting = GovernanceSetting::query()->where('key', WorkerParticipationTrainingReviewerResolver::KEY)->lockForUpdate()->firstOrFail();
                $configuration = json_decode((string) $setting->value, true);
                if (! is_array($configuration) || ! is_array($configuration['site_overrides'] ?? null)) {
                    $this->invalid('training_reviewers_patch', 'Stored reviewer settings need an explicit repair before changing assignments.');
                }
                $next = $configuration;
                $siteIds = $current->canDo('hazards.view')
                    ? app(UserSiteAccessService::class)->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads) : [];
                if (array_key_exists('default_user_id', $patch)) {
                    $this->id($patch['default_user_id'], 'training_reviewers_patch.default_user_id');
                    if (! array_key_exists('expected_default_user_id', $patch)
                        || ($configuration['default_user_id'] ?? null) !== $patch['expected_default_user_id']) {
                        $this->invalid('training_reviewers_patch.default_user_id', 'The default reviewer changed. Refresh settings before saving.');
                    }
                    if ($patch['default_user_id'] !== ($configuration['default_user_id'] ?? null) && $patch['default_user_id'] !== null) {
                        $this->assertEligible($patch['default_user_id'], $siteIds, 'training_reviewers_patch.default_user_id');
                    }
                    $next['default_user_id'] = $patch['default_user_id'];
                } elseif (array_key_exists('expected_default_user_id', $patch)) {
                    $this->invalid('training_reviewers_patch', 'A default reviewer change is missing.');
                }
                $changes = $patch['sites'] ?? [];
                if (! is_array($changes) || ! array_is_list($changes) || count($changes) > count($siteIds)) {
                    $this->invalid('training_reviewers_patch.sites', 'Choose changes only for available Sites.');
                }
                $seen = [];
                foreach ($changes as $index => $change) {
                    $field = 'training_reviewers_patch.sites.'.$index;
                    if (! is_array($change) || array_diff(array_keys($change), ['site_id', 'mode', 'reviewer_id', 'expected_mode', 'expected_reviewer_id'])) {
                        $this->invalid($field, 'Choose a valid Site reviewer change.');
                    }
                    $siteId = $change['site_id'] ?? null;
                    if (! is_int($siteId) || ! in_array($siteId, $siteIds, true) || isset($seen[$siteId])) {
                        $this->invalid($field.'.site_id', 'Choose an available Site once.');
                    }
                    $seen[$siteId] = true;
                    $present = array_key_exists((string) $siteId, $configuration['site_overrides']);
                    $stored = $present ? $configuration['site_overrides'][(string) $siteId] : null;
                    $mode = ! $present ? 'inherit' : ($stored === null ? 'unassigned' : 'person');
                    if (($change['expected_mode'] ?? null) !== $mode || ! array_key_exists('expected_reviewer_id', $change)
                        || $change['expected_reviewer_id'] !== $stored) {
                        $this->invalid($field, 'This Site reviewer changed. Refresh settings before saving.');
                    }
                    $target = $change['mode'] ?? null;
                    if (! in_array($target, ['inherit', 'unassigned', 'person'], true)) { $this->invalid($field.'.mode', 'Choose a reviewer option.'); }
                    if ($target === 'person') {
                        $id = $change['reviewer_id'] ?? null;
                        $this->id($id, $field.'.reviewer_id');
                        if ($id === null) { $this->invalid($field.'.reviewer_id', 'Choose a reviewer.'); }
                        if ($mode !== 'person' || $stored !== $id) { $this->assertEligible($id, [$siteId], $field.'.reviewer_id'); }
                        $next['site_overrides'][(string) $siteId] = $id;
                    } else {
                        if (array_key_exists('reviewer_id', $change)) { $this->invalid($field.'.reviewer_id', 'A person can only be supplied for a named reviewer.'); }
                        if ($target === 'unassigned') { $next['site_overrides'][(string) $siteId] = null; }
                        else { unset($next['site_overrides'][(string) $siteId]); }
                    }
                }
                if ($next === $configuration) { return $inserted === 1; }
                GovernanceSetting::set(WorkerParticipationTrainingReviewerResolver::KEY, $next, GovernanceSetting::CATEGORY_GENERAL,
                    'Named H&S training-position reviewers; assignment does not grant access.');

                return true;
            });
        });
    }

    private function id(mixed $id, string $field): void
    {
        if ($id !== null && (! is_int($id) || $id <= 0)) { $this->invalid($field, 'Choose a person from the list.'); }
    }

    private function assertEligible(int $id, array $siteIds, string $field): void
    {
        $person = User::query()->whereKey($id)->first();
        if ($person && $this->resolver->identityForSites($person, $siteIds) !== null) { return; }
        $this->invalid($field, 'Choose current approved staff who can review training at this Site.');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
