<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_ROLE_KEY = 'active_site_role_key';

    private const ACTIVE_ROLE_UNIQUE = 'hr_succession_plans_active_site_role_uq';

    private const SITE_RISK_INDEX = 'hr_succession_plans_site_active_risk_idx';

    private const SITE_DEPARTMENT_INDEX = 'hr_succession_plans_site_department_active_idx';

    private const SITE_HOLDER_INDEX = 'hr_succession_plans_site_holder_active_idx';

    private const CANDIDATE_IDENTITY = 'hr_succession_candidates_plan_profile_uq';

    public function up(): void
    {
        if (! Schema::hasTable('hr_succession_plans')) {
            return;
        }

        $siteAssignments = $this->assertAndResolveSiteAssignments();
        $this->assertCandidateIdentityCanBeEnforced();

        if (! Schema::hasColumn('hr_succession_plans', 'site_id')) {
            Schema::table('hr_succession_plans', function (Blueprint $table): void {
                $table->foreignId('site_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('sites')
                    ->restrictOnDelete();
            });
        }

        foreach ($siteAssignments as $planId => $siteId) {
            DB::table('hr_succession_plans')
                ->where('id', $planId)
                ->update(['site_id' => $siteId]);
        }

        Schema::table('hr_succession_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable(false)->change();
        });

        $this->addActiveRoleIdentity();
        $this->addIndex(
            'hr_succession_plans',
            self::SITE_RISK_INDEX,
            fn (Blueprint $table) => $table->index(
                ['site_id', 'is_active', 'risk_level'],
                self::SITE_RISK_INDEX,
            ),
        );
        $this->addIndex(
            'hr_succession_plans',
            self::SITE_DEPARTMENT_INDEX,
            fn (Blueprint $table) => $table->index(
                ['site_id', 'department', 'is_active'],
                self::SITE_DEPARTMENT_INDEX,
            ),
        );
        $this->addIndex(
            'hr_succession_plans',
            self::SITE_HOLDER_INDEX,
            fn (Blueprint $table) => $table->index(
                ['site_id', 'current_holder_user_id', 'is_active'],
                self::SITE_HOLDER_INDEX,
            ),
        );
        $this->addIndex(
            'hr_succession_candidates',
            self::CANDIDATE_IDENTITY,
            fn (Blueprint $table) => $table->unique(
                ['succession_plan_id', 'employee_profile_id'],
                self::CANDIDATE_IDENTITY,
            ),
        );

        foreach ($this->legacyIndexes() as $name => $columns) {
            $this->dropIndex('hr_succession_plans', $name);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_succession_plans')) {
            return;
        }

        foreach ($this->legacyIndexes() as $name => $columns) {
            $this->addIndex(
                'hr_succession_plans',
                $name,
                fn (Blueprint $table) => $table->index($columns, $name),
            );
        }

        $this->dropIndex('hr_succession_candidates', self::CANDIDATE_IDENTITY, unique: true);
        $this->dropIndex('hr_succession_plans', self::SITE_HOLDER_INDEX);
        $this->dropIndex('hr_succession_plans', self::SITE_DEPARTMENT_INDEX);
        $this->dropIndex('hr_succession_plans', self::SITE_RISK_INDEX);
        $this->dropIndex('hr_succession_plans', self::ACTIVE_ROLE_UNIQUE, unique: true);

        if (Schema::hasColumn('hr_succession_plans', self::ACTIVE_ROLE_KEY)) {
            Schema::table(
                'hr_succession_plans',
                fn (Blueprint $table) => $table->dropColumn(self::ACTIVE_ROLE_KEY),
            );
        }

        if (Schema::hasColumn('hr_succession_plans', 'site_id')) {
            Schema::table('hr_succession_plans', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('site_id');
            });
        }
    }

    /** @return array<int, int> */
    private function assertAndResolveSiteAssignments(): array
    {
        $assignments = [];
        $activeIdentities = [];

        foreach (DB::table('hr_succession_plans')->orderBy('id')->get() as $plan) {
            $profiles = collect();
            if ($plan->current_holder_user_id !== null) {
                $holderProfile = DB::table('hr_employee_profiles')
                    ->where('user_id', $plan->current_holder_user_id)
                    ->orderByDesc('id')
                    ->first(['id', 'primary_site_id', 'secondary_site_ids']);
                if ($holderProfile === null) {
                    throw new RuntimeException("Cannot assign Site provenance to succession plan {$plan->id}: its current holder has no employee profile.");
                }
                $holderProfile->is_holder = true;
                $profiles->push($holderProfile);
            }

            $candidateProfiles = DB::table('hr_succession_candidates')
                ->join(
                    'hr_employee_profiles',
                    'hr_employee_profiles.id',
                    '=',
                    'hr_succession_candidates.employee_profile_id',
                )
                ->where('hr_succession_candidates.succession_plan_id', $plan->id)
                ->orderBy('hr_succession_candidates.id')
                ->get([
                    'hr_employee_profiles.id',
                    'hr_employee_profiles.primary_site_id',
                    'hr_employee_profiles.secondary_site_ids',
                ])
                ->map(function (object $profile): object {
                    $profile->is_holder = false;

                    return $profile;
                });
            $profiles = $profiles->concat($candidateProfiles)->values();

            if ($profiles->isEmpty()) {
                throw new RuntimeException("Cannot assign Site provenance to succession plan {$plan->id}: it has no current holder or candidate evidence.");
            }

            $siteSets = $profiles
                ->map(fn ($profile) => $this->profileSiteIds($profile))
                ->all();
            if (collect($siteSets)->contains(fn (array $siteIds) => $siteIds === [])) {
                throw new RuntimeException("Cannot assign Site provenance to succession plan {$plan->id}: a participant has no Site provenance.");
            }

            $commonSiteIds = array_values(array_intersect(...$siteSets));
            $holderPrimary = $profiles
                ->first(fn ($profile) => $profile->is_holder === true)?->primary_site_id;
            $primarySiteIds = $profiles
                ->pluck('primary_site_id')
                ->filter(fn ($siteId) => (int) $siteId > 0)
                ->map(fn ($siteId) => (int) $siteId)
                ->unique()
                ->values();
            $siteId = null;
            if ($holderPrimary !== null && in_array((int) $holderPrimary, $commonSiteIds, true)) {
                $siteId = (int) $holderPrimary;
            } elseif ($primarySiteIds->count() === 1 && in_array($primarySiteIds->first(), $commonSiteIds, true)) {
                $siteId = $primarySiteIds->first();
            } elseif (count($commonSiteIds) === 1) {
                $siteId = $commonSiteIds[0];
            }

            if ($siteId === null || ! DB::table('sites')->where('id', $siteId)->exists()) {
                throw new RuntimeException("Cannot assign unambiguous Site provenance to succession plan {$plan->id}.");
            }

            $assignments[(int) $plan->id] = $siteId;
            if ((bool) $plan->is_active) {
                $roleIdentity = $plan->position_id !== null
                    ? 'position:'.(int) $plan->position_id
                    : 'role:'.mb_strtolower(trim((string) $plan->role_title));
                if ($roleIdentity === 'role:') {
                    throw new RuntimeException("Cannot enforce active succession identity while plan {$plan->id} has a blank role title.");
                }
                $identity = $siteId.':'.$roleIdentity;
                if (isset($activeIdentities[$identity])) {
                    throw new RuntimeException("Cannot enforce active succession identity while plans {$activeIdentities[$identity]} and {$plan->id} collide.");
                }
                $activeIdentities[$identity] = (int) $plan->id;
            }
        }

        return $assignments;
    }

    private function assertCandidateIdentityCanBeEnforced(): void
    {
        if (! Schema::hasTable('hr_succession_candidates')) {
            return;
        }

        $duplicate = DB::table('hr_succession_candidates')
            ->selectRaw('succession_plan_id, employee_profile_id, COUNT(*) AS duplicate_count')
            ->groupBy('succession_plan_id', 'employee_profile_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException('Cannot enforce succession candidate identity while duplicate plan/profile rows exist.');
        }
    }

    /** @return array<int, int> */
    private function profileSiteIds(object $profile): array
    {
        $secondary = json_decode((string) ($profile->secondary_site_ids ?? '[]'), true);
        if (! is_array($secondary)) {
            $secondary = [];
        }

        return collect([$profile->primary_site_id, ...$secondary])
            ->map(fn ($siteId) => (int) $siteId)
            ->filter(fn (int $siteId) => $siteId > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function addActiveRoleIdentity(): void
    {
        if (! Schema::hasColumn('hr_succession_plans', self::ACTIVE_ROLE_KEY)) {
            $driver = Schema::getConnection()->getDriverName();
            $expression = match (true) {
                in_array($driver, ['mysql', 'mariadb'], true) => "if(`is_active` = 1, concat(`site_id`, ':', if(`position_id` is null, concat('role:', lower(trim(`role_title`))), concat('position:', `position_id`))), null)",
                $driver === 'pgsql' => "case when is_active = true then site_id::text || ':' || case when position_id is null then 'role:' || lower(trim(role_title)) else 'position:' || position_id::text end else null end",
                default => "case when is_active = 1 then cast(site_id as text) || ':' || case when position_id is null then 'role:' || lower(trim(role_title)) else 'position:' || cast(position_id as text) end else null end",
            };
            Schema::table('hr_succession_plans', function (Blueprint $table) use ($expression): void {
                $table->string(self::ACTIVE_ROLE_KEY, 512)->nullable()->virtualAs($expression);
            });
        }

        $this->addIndex(
            'hr_succession_plans',
            self::ACTIVE_ROLE_UNIQUE,
            fn (Blueprint $table) => $table->unique(
                self::ACTIVE_ROLE_KEY,
                self::ACTIVE_ROLE_UNIQUE,
            ),
        );
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $unique): void {
            $unique ? $table->dropUnique($name) : $table->dropIndex($name);
        });
    }

    /** @return array<string, list<string>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_succession_plans_tenant_id_index' => ['tenant_id'],
            'hr_succession_plans_tenant_id_is_active_index' => ['tenant_id', 'is_active'],
        ];
    }
};
