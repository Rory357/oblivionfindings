<?php

namespace App\Console\Commands;

use App\Models\MedicationBackupPassphrase;
use App\Models\MedicationBackupRecipient;
use App\Models\MedicationExternalClinician;
use App\Models\MedicationExternalGrant;
use App\Models\MedicineCatalogueSource;
use App\Models\User;
use App\Notifications\BackupPassphraseReminderNotification;
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
        // EA-104: the yearly re-check of a structured registration check.
        if ($settings->switchedOn(ConnectedCareSettings::PORTAL) && Schema::hasColumn('medication_external_clinicians', 'register_checked_at')) {
            $rechecks = MedicationExternalClinician::query()->with('user:id,name')->whereNull('revoked_at')
                ->where('registration_check', 'structured')->whereNotNull('register_checked_at')
                ->whereDate('register_checked_at', '<=', now()->subYear()->addDays(self::DAYS_AHEAD)->toDateString())->orderBy('id')->get();
            foreach ($rechecks as $identity) {
                $due = $identity->register_checked_at->copy()->addYear();
                $sent += $this->remind(array_values(array_unique(array_filter([(int) $identity->register_checked_by, (int) $identity->verified_by]))),
                    'register-recheck:'.$identity->id.':'.$due->toDateString(),
                    'Prescriber registration re-check due',
                    sprintf('Check %s on the %s public register again by %s, and record the new check.', $identity->user?->name ?? 'this prescriber',
                        ConnectedCareSettings::COUNCILS[$identity->registration_authority] ?? $identity->registration_authority, $this->day($due)),
                    '/emar/connected-care#access');
            }
        }
        // EA-141: approved backup recipients without their own passphrase, weekly.
        if ($settings->switchedOn(ConnectedCareSettings::BACKUPS) && Schema::hasTable('medication_backup_passphrases')) {
            $week = now((string) config('app.worker_timezone', 'Pacific/Auckland'))->format('o-\WW');
            $waiting = MedicationBackupRecipient::query()->whereNull('revoked_at')
                ->whereNotIn('user_id', MedicationBackupPassphrase::query()->select('user_id'))->distinct()->pluck('user_id');
            foreach (User::query()->whereIn('id', $waiting)->whereNotNull('approved_at')->get() as $recipient) {
                $key = 'backup-passphrase:'.$recipient->id.':'.$week;
                $already = DatabaseNotification::query()->where('notifiable_type', $recipient->getMorphClass())->where('notifiable_id', $recipient->id)
                    ->where('type', BackupPassphraseReminderNotification::class)->where('data->reminder_key', $key)->exists();
                if (! $already) {
                    $recipient->notify(new BackupPassphraseReminderNotification($key));
                    $sent++;
                }
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
