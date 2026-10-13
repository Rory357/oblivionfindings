<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HubWorkProfiles
{
    /** The URL identifies the owner even when an old tab outlives a sign-in change. */
    public function save(User $actor, int $profileId, array $data): array
    {
        app(HubAccess::class)->assertEnter($actor);

        return DB::transaction(function () use ($actor, $profileId, $data): array {
            HrEmployeeProfile::whereKey($profileId)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            app(HubDirectory::class)->person($actor, $profileId);
            $stored = DB::table('hub_work_profiles')->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_unless((int) ($stored->version ?? 0) === (int) $data['expected_version'], 409, 'Your shared profile changed in another window. Reload the current profile before saving again.');

            $skills = collect($data['help_with'])->map(fn ($skill) => trim($skill))->filter()
                ->unique(fn ($skill) => mb_strtolower($skill))->values()->all();
            $values = ['about' => trim($data['about'] ?? ''), 'help_with' => json_encode($skills), 'version' => ((int) ($stored->version ?? 0)) + 1, 'updated_at' => now()];
            if ($stored) {
                DB::table('hub_work_profiles')->where('user_id', $actor->id)->update($values);
            } else {
                DB::table('hub_work_profiles')->insert(['user_id' => $actor->id, 'created_at' => now(), ...$values]);
            }
            AuditLog::create(['user_id' => $actor->id, 'action' => 'company_hub.work_profile_updated', 'auditable_type' => 'hr_employee_profile', 'auditable_id' => $profileId,
                'meta' => ['version' => $values['version'], 'has_about' => $values['about'] !== '', 'skills_count' => count($skills)]]);

            return app(HubDirectory::class)->person($actor, $profileId);
        });
    }
}
