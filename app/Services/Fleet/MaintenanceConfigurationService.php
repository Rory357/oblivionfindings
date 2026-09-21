<?php

namespace App\Services\Fleet;

use App\Models\FleetChecklistTemplate;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit, site-scoped configuration writes. No operating rule is seeded. */
class MaintenanceConfigurationService
{
    public function __construct(
        private readonly MaintenanceAccessService $access,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(User $actor, array $input): array
    {
        $data = Validator::make($input, [
            'kind' => ['required', 'in:route,policy,reviewer'],
            'site_id' => ['required', 'integer', 'min:1'],
            'approval_reference' => ['required', 'string', 'min:8', 'max:150'],
            'asset_category' => ['required_unless:kind,route', 'string', 'max:64'],
            'rule_kind' => ['required_if:kind,policy', 'string', 'in:check,retest,hold,repair,release'],
            'rules' => ['required_if:kind,policy', 'array'],
            'coordinator_user_id' => ['required_if:kind,route', 'integer', 'min:1'],
            'backup_user_id' => ['required_if:kind,route', 'integer', 'min:1'],
            'user_id' => ['required_if:kind,reviewer', 'integer', 'min:1'],
            'decision' => ['required_if:kind,reviewer', 'in:grant,revoke'],
        ])->validate();
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->canDo('fleet.maintenance.configure'), 403);
        abort_unless(in_array((int) $data['site_id'], $this->access->approvedSiteIds($actor), true), 404);
        $this->siteAccess->assertCanUseCurrentStaffAtSite(
            $actor, (int) $actor->id, (int) $data['site_id'], ['sites.viewAll'],
        );
        abort_unless(Site::query()->active()->notArchived()->whereNull('archived_at')
            ->whereKey($data['site_id'])->exists(), 404);

        if ($data['kind'] === 'route') {
            if ((int) $data['coordinator_user_id'] === (int) $data['backup_user_id']) {
                throw ValidationException::withMessages(['backup_user_id' => 'Choose a separate backup.']);
            }
            foreach (['coordinator_user_id', 'backup_user_id'] as $key) {
                $this->siteAccess->assertCanUseCurrentStaffAtSite(
                    $actor, (int) $data[$key], (int) $data['site_id'], ['sites.viewAll'],
                );
            }
        } else {
            abort_unless(DB::table('assets')->where('site_id', $data['site_id'])
                ->where('category', $data['asset_category'])->exists(), 422,
                'The exact asset category must exist at the approved site.');
            if ($data['kind'] === 'policy') {
                $this->validatePolicy((string) $data['rule_kind'], $data['rules']);
            } elseif ($data['decision'] === 'grant') {
                $target = User::query()->findOrFail((int) $data['user_id']);
                abort_unless($target->canDo('fleet.maintenance.release'), 422,
                    'The reviewer needs release capability before a site/category grant.');
                $this->siteAccess->assertCanUseCurrentStaffAtSite(
                    $actor, (int) $target->id, (int) $data['site_id'], ['sites.viewAll'],
                );
            } else {
                abort_unless(User::query()->whereKey($data['user_id'])->exists(), 404);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $input @return array{kind:string,version:int,id:int} */
    public function apply(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input): array {
            $data = $this->validate($actor, $input);
            Site::query()->whereKey($data['site_id'])->lockForUpdate()->firstOrFail();
            $now = now();
            $siteId = (int) $data['site_id'];
            if ($data['kind'] === 'route') {
                $current = DB::table('fleet_maintenance_site_routes')->where('site_id', $siteId)
                    ->lockForUpdate()->first();
                if ($current) {
                    DB::table('fleet_maintenance_site_route_history')->insertOrIgnore([
                        'site_id' => $siteId, 'version' => $current->version,
                        'coordinator_user_id' => $current->coordinator_user_id,
                        'backup_user_id' => $current->backup_user_id,
                        'approved_by_user_id' => $current->approved_by_user_id,
                        'approval_reference' => 'previous-route-record',
                        'approved_at' => $current->approved_at, 'created_at' => $now,
                    ]);
                }
                $version = $current ? (int) $current->version + 1 : 1;
                DB::table('fleet_maintenance_site_routes')->updateOrInsert(['site_id' => $siteId], [
                    'coordinator_user_id' => $data['coordinator_user_id'],
                    'backup_user_id' => $data['backup_user_id'],
                    'approved_by_user_id' => $actor->id, 'approved_at' => $now,
                    'version' => $version, 'created_at' => $current->created_at ?? $now, 'updated_at' => $now,
                ]);
                $id = (int) DB::table('fleet_maintenance_site_route_history')->insertGetId([
                    'site_id' => $siteId, 'version' => $version,
                    'coordinator_user_id' => $data['coordinator_user_id'],
                    'backup_user_id' => $data['backup_user_id'],
                    'approved_by_user_id' => $actor->id,
                    'approval_reference' => $data['approval_reference'],
                    'approved_at' => $now, 'created_at' => $now,
                ]);
            } elseif ($data['kind'] === 'policy') {
                $scope = DB::table('fleet_maintenance_policy_versions')
                    ->where('site_id', $siteId)->where('asset_category', $data['asset_category'])
                    ->where('rule_kind', $data['rule_kind']);
                $version = (int) $scope->max('version') + 1;
                $id = (int) DB::table('fleet_maintenance_policy_versions')->insertGetId([
                    'site_id' => $siteId, 'asset_category' => $data['asset_category'],
                    'rule_kind' => $data['rule_kind'], 'version' => $version,
                    'rules_json' => json_encode($data['rules'], JSON_THROW_ON_ERROR),
                    'content_sha256' => MaintenanceFingerprint::of($data['rules']),
                    'approved_by_user_id' => $actor->id, 'approved_at' => $now, 'created_at' => $now,
                ]);
                DB::table('fleet_maintenance_policy_assignments')->updateOrInsert([
                    'site_id' => $siteId, 'asset_category' => $data['asset_category'],
                    'rule_kind' => $data['rule_kind'],
                ], [
                    'policy_version_id' => $id, 'assigned_by_user_id' => $actor->id,
                    'assigned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $scope = DB::table('fleet_maintenance_reviewer_grants')
                    ->where('site_id', $siteId)->where('asset_category', $data['asset_category'])
                    ->where('review_kind', 'maintenance_release')->where('user_id', $data['user_id']);
                $version = (int) $scope->max('version') + 1;
                $id = (int) DB::table('fleet_maintenance_reviewer_grants')->insertGetId([
                    'site_id' => $siteId, 'asset_category' => $data['asset_category'],
                    'review_kind' => 'maintenance_release', 'user_id' => $data['user_id'],
                    'version' => $version, 'decision' => $data['decision'],
                    'recorded_by_user_id' => $actor->id, 'recorded_at' => $now, 'created_at' => $now,
                ]);
            }
            DB::table('fleet_maintenance_configuration_events')->insert([
                'site_id' => $siteId, 'actor_user_id' => $actor->id,
                'configuration_kind' => $data['kind'],
                'asset_category' => $data['asset_category'] ?? null,
                'rule_kind' => $data['rule_kind'] ?? null,
                'version' => $version, 'approval_reference' => $data['approval_reference'],
                'input_sha256' => MaintenanceFingerprint::of($data),
                'result_json' => json_encode(['id' => $id, 'kind' => $data['kind'], 'version' => $version], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            return ['id' => $id, 'kind' => $data['kind'], 'version' => $version];
        }, 3);
    }

    /** @param array<string, mixed> $rules */
    private function validatePolicy(string $kind, array $rules): void
    {
        if ($kind === 'hold') {
            $kinds = $rules['allowed_kinds'] ?? null;
            if (! is_array($kinds) || ! array_is_list($kinds) || $kinds === []
                || collect($kinds)->contains(fn ($value) => ! is_string($value) || $value === '' || mb_strlen($value) > 32)
                || count($kinds) !== count(array_unique($kinds))) {
                throw ValidationException::withMessages(['rules.allowed_kinds' => 'List each approved hold kind once.']);
            }
            return;
        }
        if ($kind === 'repair') {
            if (($rules['requires_service_evidence'] ?? null) !== true) {
                throw ValidationException::withMessages(['rules.requires_service_evidence' => 'Explicit service evidence is required.']);
            }
            return;
        }
        if ($kind === 'release') {
            if (! is_bool($rules['requires_custody'] ?? null)) {
                throw ValidationException::withMessages(['rules.requires_custody' => 'Choose whether custody applies.']);
            }
            return;
        }

        $template = FleetChecklistTemplate::query()->whereKey((int) ($rules['template_id'] ?? 0))
            ->where('is_active', true)->first();
        if (! $template || ! is_string($rules['template_sha256'] ?? null)
            || ! hash_equals(MaintenanceFingerprint::of($template->items ?? []), $rules['template_sha256'])) {
            throw ValidationException::withMessages(['rules.template_id' => 'Use the current active template and its exact fingerprint.']);
        }
        $items = $template->items ?? [];
        $questions = $rules['questions'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || $items === []
            || ! is_array($questions) || ! array_is_list($questions) || count($questions) !== count($items)) {
            throw ValidationException::withMessages(['rules.questions' => 'Map every current template question in order.']);
        }
        $seen = [];
        $seenOptions = [];
        foreach ($questions as $index => $question) {
            $item = $items[$index];
            if (! in_array($item['type'] ?? null, ['select', 'checkbox'], true)) {
                throw ValidationException::withMessages(['rules.questions' =>
                    'Approved checks use choice or yes/no questions. Text and number items are observations only; create a choice template for an approved result.']);
            }
            $id = $item['id'] ?? null;
            $options = $item['options'] ?? null;
            if (! is_string($id) || $id === '' || isset($seen[$id]) || ! is_array($question) || ($question['id'] ?? null) !== $id
                || ! is_array($options) || ! array_is_list($options) || $options === []
                || collect($options)->contains(fn ($value) => ! is_string($value) || $value === '')
                || ! is_array($question['pass_values'] ?? null)
                || ! array_is_list($question['pass_values']) || $question['pass_values'] === []
                || collect($question['pass_values'])->contains(fn ($value) => ! is_string($value))
                || array_diff($question['pass_values'], $options) !== []
                || ! is_bool($question['allow_na'] ?? null)
                || (isset($question['evidence_required']) && ! is_bool($question['evidence_required']))) {
                throw ValidationException::withMessages(['rules.questions' => 'Match each approved option and applicability rule to its template question.']);
            }
            if (isset($question['when'])) {
                $when = $question['when'];
                if (! is_array($when) || ! is_string($when['question_id'] ?? null)
                    || ! isset($seen[$when['question_id']])
                    || ! is_string($when['equals'] ?? null)
                    || ! in_array($when['equals'], $seenOptions[$when['question_id']], true)) {
                    throw ValidationException::withMessages(['rules.questions' => 'Conditional questions must refer to an earlier answer.']);
                }
            }
            $seen[$id] = true;
            $seenOptions[$id] = $options;
        }
        if (isset($rules['availability_impact'])
            && ! in_array($rules['availability_impact'], ['blocking', 'advisory'], true)) {
            throw ValidationException::withMessages(['rules.availability_impact' => 'Choose blocking or advisory impact.']);
        }
    }
}
