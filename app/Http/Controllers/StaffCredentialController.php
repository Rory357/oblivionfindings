<?php

namespace App\Http\Controllers;

use App\Models\StaffCredential;
use App\Services\NotificationService;
use App\Models\User;
use Illuminate\Http\Request;

class StaffCredentialController extends Controller
{
    protected function canManage(Request $request, User $user): bool
    {
        $auth = $request->user();
        if (!$auth) {
            return false;
        }

        // Managers/HR/admin can manage any
        if ($auth->canDo('staff.credentials.updateAny') || $auth->canDo('staff.update')) {
            return true;
        }

        // Staff can manage own if allowed
        return $auth->id === $user->id && $auth->canDo('staff.credentials.updateSelf');
    }

    public function index(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        // Staff can view their own credentials; others require viewAny
        if ($auth->id !== $user->id) {
            abort_unless($auth->canDo('staff.credentials.viewAny') || $auth->canDo('staff.viewAny'), 403);
        }

        $creds = StaffCredential::query()
            ->where('user_id', $user->id)
            ->orderByRaw('expires_at is null, expires_at asc')
            ->orderBy('type')
            ->get();

        return inertia('staff/credentials', [
            'user' => $user->only(['id', 'name', 'email']),
            'credentials' => $creds,
            'canManage' => $this->canManage($request, $user),
        ]);
    }

    public function store(Request $request, User $user)
    {
        abort_unless($this->canManage($request, $user), 403);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        StaffCredential::create(array_merge($data, ['user_id' => $user->id]));

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'staff credential', $credential, null, [
            'title' => 'Credential added',
            'url' => url("/staff/{$user->id}/credentials"),
            'target_user_ids' => [$user->id],
        ]);

        return back()->with('success', 'Credential added.');
    }

    public function update(Request $request, User $user, StaffCredential $credential)
    {
        abort_unless($this->canManage($request, $user), 403);
        abort_unless($credential->user_id === $user->id, 404);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $credential->update($data);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'staff credential', $credential, null, [
            'title' => 'Credential updated',
            'url' => url("/staff/{$user->id}/credentials"),
            'target_user_ids' => [$user->id],
        ]);

        return back()->with('success', 'Credential updated.');
    }

    public function destroy(Request $request, User $user, StaffCredential $credential)
    {
        abort_unless($this->canManage($request, $user), 403);
        abort_unless($credential->user_id === $user->id, 404);

        $credential->delete();

        app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'staff credential', $credential, null, [
            'title' => 'Credential removed',
            'url' => url("/staff/{$user->id}/credentials"),
            'target_user_ids' => [$user->id],
        ]);

        return back()->with('success', 'Credential removed.');
    }
}
