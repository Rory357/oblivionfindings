<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LimitsStaffRecordsToViewerSites;
use App\Models\Client;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffAssignmentController extends Controller
{
    use LimitsStaffRecordsToViewerSites;

    public function edit(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('staff.assignments.update'), 403);
        $this->abortUnlessStaffInViewerSites($auth, $user);

        $clients = $this->clientsInViewerSites($auth)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'status']);

        return inertia('staff/assignments', [
            'user' => $user->only(['id', 'name', 'email']),
            'clients' => $clients,
            // Only assignments the viewer can see; others stay private and untouched.
            'assignedIds' => $user->assignedClients()
                ->whereIn('clients.id', $clients->pluck('id'))
                ->pluck('clients.id')
                ->values(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('staff.assignments.update'), 403);
        $this->abortUnlessStaffInViewerSites($auth, $user);

        $data = $request->validate([
            'client_ids' => ['array'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
        ]);

        $requested = collect($data['client_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $allowed = $this->clientsInViewerSites($auth)
            ->whereIn('clients.id', $requested)
            ->pluck('clients.id')
            ->map(fn ($id) => (int) $id);
        if ($requested->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['client_ids' => 'Choose clients at your own Sites.']);
        }

        $visibleIds = $this->clientsInViewerSites($auth)->select('clients.id');
        // Assignments to clients at Sites the viewer can't see are kept exactly as they are.
        $hiddenAssignedIds = $user->assignedClients()
            ->whereNotIn('clients.id', $visibleIds)
            ->pluck('clients.id')
            ->map(fn ($id) => (int) $id);
        $oldAssignedIds = $user->assignedClients()
            ->whereIn('clients.id', $this->clientsInViewerSites($auth)->select('clients.id'))
            ->pluck('clients.id')
            ->map(fn ($id) => (int) $id);

        $user->assignedClients()->sync($requested->merge($hiddenAssignedIds)->unique()->values()->all());

        $added = $requested->diff($oldAssignedIds)->values()->all();
        $removed = $oldAssignedIds->diff($requested)->values()->all();

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'staff assignments', $user, null, [
            'title' => "Staff assignments updated: {$user->name}",
            'body' => 'Added client IDs: ' . (count($added) ? implode(', ', $added) : 'none') . ' | Removed client IDs: ' . (count($removed) ? implode(', ', $removed) : 'none'),
            'url' => url("/staff/{$user->id}/assignments"),
            'target_user_ids' => [$user->id],
        ]);

        return redirect()->back()->with('success', 'Assignments updated.');
    }

    /**
     * Clients at the viewer's own Sites (Stephan, 25 Oct 2026), the same Site
     * rule as the staff records: sites.viewAll sees every client; a client with
     * no Site is only for sites.viewAll.
     *
     * @return Builder<Client>
     */
    private function clientsInViewerSites(User $viewer): Builder
    {
        $siteAccess = app(UserSiteAccessService::class);
        $query = Client::query();
        if ($siteAccess->canBypass($viewer, self::STAFF_RECORD_SITE_BYPASS_PERMISSIONS)) {
            return $query;
        }

        return $query->whereIn('clients.site_id', $siteAccess->accessibleSiteIds($viewer, self::STAFF_RECORD_SITE_BYPASS_PERMISSIONS));
    }
}
