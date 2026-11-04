<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sites\SiteContactRules;
use App\Models\Site;
use App\Models\SiteContact;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\Sites\SiteContactService;
use App\Services\Sites\SiteContactStaffDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteContactController extends Controller
{
    public function __construct(
        private readonly SiteContactService $contacts,
        private readonly SiteContactStaffDirectory $staff,
    ) {}

    /**
     * Staff the viewer may pick as a Site contact, with their work details
     * (Site profile › Contacts and the Overview's "Add manager").
     */
    public function staff(Request $request, Site $site): JsonResponse
    {
        $this->authorize('update', $site);

        return response()->json(['staff' => $this->staff->options($request->user())]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $this->validated($request);
        $this->staff->assertPickableUser($data['user_id'] ?? null, $request->user());

        $contact = $this->contacts->create($site, $data);

        AuditLogger::log('sites.contacts.create', $contact, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'site_contact', $contact, null, [
            'title' => 'Site contact added',
            'body' => $contact->name,
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Contact added.');
    }

    public function update(Request $request, Site $site, SiteContact $contact)
    {
        $this->authorize('update', $site);
        $data = $this->validated($request);
        $currentLink = (int) $contact->site_id === (int) $site->id ? $contact->getRawOriginal('user_id') : null;
        $this->staff->assertPickableUser($data['user_id'] ?? null, $request->user(), $currentLink === null ? null : (int) $currentLink);

        $contact = $this->contacts->update($site, (int) $contact->id, $data);

        AuditLogger::log('sites.contacts.update', $contact, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'site_contact', $contact, null, [
            'title' => 'Site contact updated',
            'body' => $contact->name,
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Site $site, SiteContact $contact)
    {
        $this->authorize('update', $site);
        $name = $contact->name;
        $contact = $this->contacts->delete($site, (int) $contact->id);

        AuditLogger::log('sites.contacts.delete', $site, ['site_id' => $site->id, 'name' => $name]);

        app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'site_contact', $contact, null, [
            'title' => 'Site contact removed',
            'body' => $name,
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Contact removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        // A staff-linked contact takes its details from the staff record.
        $request->replace(SiteContactRules::rowWithoutLinkedDetails($request->all()));

        return $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['required', 'string', Rule::in(SiteContact::TYPES)],
            'name' => ['nullable', 'required_without:user_id', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
