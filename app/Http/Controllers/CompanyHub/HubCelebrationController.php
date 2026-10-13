<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HubCelebrationController extends Controller
{
    public function update(Request $request, User $person, HubAccess $access, UserSiteAccessService $sites)
    {
        $access->assertEnter($request->user());
        abort_unless($request->user()->canDo('company_hub.celebrations'), 403);
        abort_unless($sites->applyStaffScope(User::query(), $request->user())->whereKey($person->id)->exists(), 404);
        $data = $request->validate(['birthday_enabled' => 'required|boolean']);
        DB::transaction(function () use ($request, $person, $data): void {
            DB::table('hub_celebration_settings')->updateOrInsert(['user_id' => $person->id], [
                'birthday_enabled' => $data['birthday_enabled'], 'updated_by' => $request->user()->id,
                'updated_at' => now(), 'created_at' => now(),
            ]);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'company_hub.birthday_visibility',
                'auditable_type' => User::class, 'auditable_id' => $person->id,
                'meta' => ['enabled' => $data['birthday_enabled']],
            ]);
        });

        return back()->with('success', $data['birthday_enabled'] ? 'Birthday celebration enabled.' : 'Birthday celebration hidden.');
    }
}
