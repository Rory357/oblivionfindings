<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientOnboardingOverride;
use App\Services\AuditLogger;
use App\Services\Clients\ClientOnboardingAccess;
use Illuminate\Http\Request;

class ClientOnboardingController extends Controller
{
    /**
     * Toggle (or set) a "doesn't have this" onboarding checkbox.
     *
     * When checked, we store value=true for that key.
     * When unchecked, we remove the override row.
     */
    public function toggle(Request $request, Client $client, string $key)
    {
        $this->authorize('view', $client);

        abort_unless(
            $request->user()
                && app(ClientOnboardingAccess::class)->canManageChecklist($request->user()),
            403,
        );

        $allowed = [
            'profile',
            'next_of_kin',
            'medications',
            'conditions',
            'emergency_contacts',
            'history',
            'documents',
        ];

        abort_unless(in_array($key, $allowed, true), 404);

        $checked = (bool) $request->boolean('checked');

        if ($checked) {
            ClientOnboardingOverride::updateOrCreate(
                ['client_id' => $client->id, 'key' => $key],
                ['value' => true, 'updated_by' => $request->user()?->id]
            );

            AuditLogger::log('clients.onboarding.override.set', $client, ['key' => $key]);
        } else {
            ClientOnboardingOverride::query()
                ->where('client_id', $client->id)
                ->where('key', $key)
                ->delete();

            AuditLogger::log('clients.onboarding.override.cleared', $client, ['key' => $key]);
        }

        return back();
    }
}
