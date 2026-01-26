<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteContact;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class SiteContactController extends Controller
{
    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $isPrimary = (bool) ($data['is_primary'] ?? false);
        if ($isPrimary) {
            SiteContact::query()->where('site_id', $site->id)->update(['is_primary' => false]);
        }

        $contact = SiteContact::create(array_merge($data, [
            'site_id' => $site->id,
            'is_primary' => $isPrimary,
        ]));

        AuditLogger::log('sites.contacts.create', $contact, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'site_contact', $contact, $site, [
            'title' => 'Site contact added',
            'body' => $contact->name,
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Contact added.');
    }

    public function update(Request $request, Site $site, SiteContact $contact)
    {
        $this->authorize('update', $site);
        abort_unless($contact->site_id === $site->id, 404);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $isPrimary = (bool) ($data['is_primary'] ?? false);
        if ($isPrimary) {
            SiteContact::query()
                ->where('site_id', $site->id)
                ->whereKeyNot($contact->id)
                ->update(['is_primary' => false]);
        }

        $contact->update(array_merge($data, ['is_primary' => $isPrimary]));

        AuditLogger::log('sites.contacts.update', $contact, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'site_contact', $contact, $site, [
            'title' => 'Site contact updated',
            'body' => $contact->name,
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Site $site, SiteContact $contact)
    {
        $this->authorize('update', $site);
        abort_unless($contact->site_id === $site->id, 404);

        $name = $contact->name;
        $contact->delete();

        AuditLogger::log('sites.contacts.delete', $site, ['site_id' => $site->id, 'name' => $name]);

        app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'site_contact', $contact, $site, [
            'title' => 'Site contact removed',
            'body' => $name,
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Contact removed.');
    }
}
