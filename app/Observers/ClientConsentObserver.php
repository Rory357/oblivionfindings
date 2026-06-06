<?php

namespace App\Observers;

use App\Models\ClientConsent;
use App\Models\FamilyPortalSetting;

class ClientConsentObserver
{
    public function saved(ClientConsent $consent): void
    {
        if (! $this->wasWithdrawn($consent)) {
            return;
        }

        FamilyPortalSetting::query()
            ->where('client_id', $consent->client_id)
            ->update([
                'show_respite' => false,
                'show_care_notes' => false,
                'show_incidents' => false,
            ]);
    }

    private function wasWithdrawn(ClientConsent $consent): bool
    {
        if ($consent->status !== 'withdrawn' && blank($consent->withdrawn_at)) {
            return false;
        }

        return ! $consent->exists
            || $consent->wasRecentlyCreated
            || $consent->wasChanged(['status', 'withdrawn_at']);
    }
}
