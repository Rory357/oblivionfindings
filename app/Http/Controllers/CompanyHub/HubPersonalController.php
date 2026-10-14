<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HubPersonalController extends Controller
{
    public function notifications(Request $request, HubAccess $access)
    {
        $access->assertEnter($request->user());
        $data = $request->validate(['news' => 'required|boolean', 'reviews' => 'required|boolean']);
        DB::table('hub_preferences')->updateOrInsert(['user_id' => $request->user()->id], [
            'notifications' => json_encode($data), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Your hub notification preferences were saved.');
    }

    public function shortcuts(Request $request, HubAccess $access)
    {
        $access->assertEnter($request->user());
        $data = $request->validate([
            'shortcuts' => 'present|array|max:20', 'shortcuts.*' => 'array:id,title,url,description',
            'shortcuts.*.id' => 'required|string|max:60|distinct',
            'shortcuts.*.title' => 'required|string|max:100', 'shortcuts.*.description' => 'nullable|string|max:200',
            'shortcuts.*.url' => ['required', 'string', 'max:2000', function ($attribute, $value, $fail): void {
                if (! HubContent::safeUrl($value)) {
                    $fail('Use an https link or internal application path.');
                }
            }],
        ]);
        DB::table('hub_preferences')->updateOrInsert(['user_id' => $request->user()->id], [
            'shortcuts' => json_encode($data['shortcuts']), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Your shortcuts were saved.');
    }

    public function bookmark(Request $request, HubAccess $access, HrCalendarAccessService $calendar)
    {
        $access->assertEnter($request->user());
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['page', 'event'])],
            'subject_id' => 'required|integer', 'saved' => 'required|boolean',
        ]);
        if ($data['subject_type'] === 'page') {
            $access->assertRead($request->user(), HubPage::findOrFail($data['subject_id']));
        } else {
            $event = HrCalendarEvent::active()->findOrFail($data['subject_id']);
            $calendar->assertCanViewEvent($request->user(), $event);
        }
        $key = ['user_id' => $request->user()->id, 'subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id']];
        if ($data['saved']) {
            DB::table('hub_bookmarks')->insertOrIgnore([...$key, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('hub_bookmarks')->where($key)->delete();
        }

        return back()->with('success', $data['saved'] ? 'Saved for later.' : 'Removed from saved items.');
    }
}
