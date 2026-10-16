<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubBookmarks;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HubPersonalController extends Controller
{
    public function notificationPreferences(Request $request, HubNotificationPreferences $preferences)
    {
        return response()->json($preferences->read($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function notifications(Request $request, HubNotificationPreferences $preferences)
    {
        $guarded = $request->hasAny(['actor_id', 'version', 'recognition', 'replies', 'events']);
        $data = $request->validate([
            'actor_id' => [Rule::requiredIf($guarded), 'integer', 'min:1'],
            'version' => [Rule::requiredIf($guarded), 'integer', 'min:1', 'max:2147483647'],
            'recognition' => [Rule::requiredIf($guarded), 'boolean'],
            'replies' => [Rule::requiredIf($guarded), 'boolean'], 'events' => [Rule::requiredIf($guarded), 'boolean'],
            'news' => 'required|boolean', 'reviews' => [Rule::requiredIf(! $guarded), 'boolean'],
            'user_id' => 'prohibited', 'author_id' => 'prohibited', 'id' => 'prohibited',
        ]);
        $result = $preferences->save($request->user(), $data, $guarded);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($result)->header('Cache-Control', 'private, no-store');
        }

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
        $guarded = $request->boolean('hub_receipt') && $request->expectsJson() && ! $request->header('X-Inertia');
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['page', 'event', 'media'])],
            'subject_id' => $guarded ? 'required|integer|min:1|max:2147483647' : 'required|integer', 'saved' => 'required|boolean',
            ...($guarded ? [
                'hub_receipt' => 'required|boolean|accepted', 'actor_id' => 'required|integer|min:1|max:2147483647',
                'expected_saved' => 'required|boolean', 'expected_version' => 'present|nullable|string|size:64',
                'user_id' => 'prohibited', 'id' => 'prohibited',
            ] : []),
        ]);
        $result = app(HubBookmarks::class)->set($request->user(), $data, $guarded);
        if ($guarded) {
            return response()->json($result)->header('Cache-Control', 'private, no-store');
        }

        return back()->with('success', $data['saved'] ? 'Saved for later.' : 'Removed from saved items.');
    }

    public function bookmarkState(Request $request, HubBookmarks $bookmarks)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1|max:2147483647',
            'subject_type' => ['required', Rule::in(['page', 'event', 'media'])], 'subject_id' => 'required|integer|min:1|max:2147483647',
            'user_id' => 'prohibited', 'id' => 'prohibited',
        ]);

        return response()->json($bookmarks->read($request->user(), $data))->header('Cache-Control', 'private, no-store');
    }
}
