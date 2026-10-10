<?php

namespace App\Console\Commands;

use App\Models\MedicationExternalClinician;
use App\Models\MedicationExternalGrant;
use App\Models\MedicineCatalogueSource;
use App\Models\User;
use App\Notifications\ConnectedCareExpiryNotification;
use App\Services\Medication\Connected\ConnectedCareSettings;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;

/**
 * B10 (EA-188): reminders 14 days ahead of connected-care expiries, once per
 * item and expiry date, only while the feature is switched on.
 */
class RemindConnectedCareExpiries extends Command
{
    public const DAYS_AHEAD = 14;

    protected $signature = 'medications:connected-expiry-reminders';

    protected $description = 'Remind owners 14 days before prescriber access, prescriber identities and catalogue source reviews expire';

    public function handle(ConnectedCareSettings $settings): int
    {
        $until = now()->addDays(self::DAYS_AHEAD);
        $sent = 0;
        if ($settings->switchedOn(ConnectedCareSettings::PORTAL) && Schema::hasTable('medication_external_grants')) {
            $grants = MedicationExternalGrant::query()->with(['clinician.user:id,name', 'client:id,first_name,last_name'])
                ->whereNull('revoked_at')->where('expires_at', '>', now())->where('expires_at', '<=', $until)->orderBy('id')->get();
            foreach ($grants as $grant) {
                $person = trim((string) $grant->client?->first_name.' '.mb_substr((string) $grant->client?->last_name, 0, 1)).'.';
                $sent += $this->remind([(int) $grant->granted_by], 'grant:'.$grant->id.':'.$grant->expires_at->toDateString(),
                    'Prescriber access ends soon',
                    sprintf('%s’s access to %s’s medication chart ends on %s. Renew it or let it end.', $grant->clinician?->user?->name ?? 'A prescriber', $person, $this->day($grant->expires_at)),
                    '/emar/connected-care?client_id='.$grant->client_id.'#access');
            }
            $identities = MedicationExternalClinician::query()->with('user:id,name')
                ->whereNull('revoked_at')->where('expires_at', '>', now())->where('expires_at', '<=', $until)->orderBy('id')->get();
            foreach ($identities as $identity) {
                $sent += $this->remind([(int) $identity->verified_by], 'identity:'.$identity->id.':'.$identity->expires_at->toDateString(),
                    'Prescriber identity check ends soon',
                    sprintf('The identity check for %s ends on %s. After that they can’t open any chart until it is checked again.', $identity->user?->name ?? 'a prescriber', $this->day($identity->expires_at)),
                    '/emar/connected-care#access');
            }
        }
        if ($settings->switchedOn(ConnectedCareSettings::CATALOGUE) && Schema::hasTable('medicine_catalogue_sources')) {
            $sources = MedicineCatalogueSource::query()->where('status', 'reviewed')
                ->where('expires_at', '>', now())->where('expires_at', '<=', $until)->orderBy('id')->get();
            foreach ($sources as $source) {
                $sent += $this->remind(array_values(array_unique(array_filter([(int) $source->reviewed_by, (int) $source->created_by]))),
                    'catalogue:'.$source->id.':'.$source->expires_at->toDateString(),
                    'Catalogue source review ends soon',
                    sprintf('The review of “%s” ends on %s. After that its pictures show “review required”.', $source->source_name, $this->day($source->expires_at)),
                    '/emar/catalogue');
            }
        }
        $this->line(json_encode(['sent' => $sent], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @param list<int> $userIds */
    private function remind(array $userIds, string $key, string $title, string $message, string $url): int
    {
        $sent = 0;
        foreach (User::query()->whereIn('id', $userIds)->whereNotNull('approved_at')->get() as $user) {
            $already = DatabaseNotification::query()->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)
                ->where('type', ConnectedCareExpiryNotification::class)->where('data->reminder_key', $key)->exists();
            if (! $already) {
                $user->notify(new ConnectedCareExpiryNotification($key, $title, $message, $url));
                $sent++;
            }
        }

        return $sent;
    }

    private function day(\DateTimeInterface $at): string
    {
        return \Carbon\CarbonImmutable::instance($at)->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->format('j M Y');
    }
}
