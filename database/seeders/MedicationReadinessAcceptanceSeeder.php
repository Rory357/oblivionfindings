<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\User;
use App\Services\Medication\DoseTimingSettings;
use App\Services\Medication\MedicationOrderWorkflow;
use App\Services\Medication\WitnessPinService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/** Synthetic browser acceptance evidence; never an operational staff grant. */
class MedicationReadinessAcceptanceSeeder extends Seeder
{
    public const WITNESS_PIN = '593027';

    private const MEDICATION_NAMES = ['PW Meds Morning Tablets', 'PW Meds Vitamin D', 'PW Meds Eye Drops', 'PW Meds PRN Paracetamol', 'PW Meds Controlled PRN'];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Medication acceptance fixtures require a local or testing environment.');
        }

        $client = Client::query()->where('first_name', 'Playwright')->where('last_name', 'Meds')->sole();
        $worker = User::query()->where('email', 'sw-meds@demo.test')->sole();
        $witness = User::query()->where('email', 'sw-meds-witness@demo.test')->sole();
        $times = $this->scheduledTimes();
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $firstCheckedAt = Carbon::parse(now($timezone)->toDateString().' '.$times['PW Meds Morning Tablets'], $timezone)
            ->subMinutes(15)->utc();
        $officeWorker = function (string $email, string $name, string $capability) use ($client, $firstCheckedAt): User {
            $setup = fn () => $this->officeWorker($email, $name, $client, $capability);

            // New fictional source actors existed and were approved before
            // that first check. Existing identities retain their chronology.
            return User::query()->where('email', $email)->exists()
                ? $setup()
                : Carbon::withTestNow($firstCheckedAt->copy()->subMinute(), $setup);
        };
        $enterer = $officeWorker('sw-meds-order-entry@demo.test', 'Medication Fixture Enterer', 'medications.orders.manage');
        $checker = $officeWorker('sw-meds-order-checker@demo.test', 'Medication Fixture Checker', 'medications.orders.verify');

        $this->grant($worker, ['medications.view', 'medications.administer.record', 'medications.controlled.view', 'medications.controlled.record']);
        $this->grant($witness, ['medications.view', 'medications.controlled.view', 'medications.controlled.witness']);
        foreach ([$worker, $witness] as $subject) {
            $this->assessment($subject, $enterer);
        }
        $this->presence($witness, $client);
        $pins = app(WitnessPinService::class);
        if ($pins->status($witness) !== WitnessPinService::STATUS_SET) {
            // The named fixture owner proves their known synthetic login before
            // first setup or self-reset; the service retains hashing and audit.
            if (! Hash::check('password', $witness->password)) {
                throw new LogicException('The synthetic witness login has changed.');
            }
            if (! in_array($pins->status($witness), [WitnessPinService::STATUS_NOT_SET, WitnessPinService::STATUS_RESET], true)) {
                $pins->set($witness, '708142', WitnessPinService::CONFIRMED_WITH_LOGIN_PASSWORD);
            }
            $pins->set($witness, self::WITNESS_PIN, WitnessPinService::CONFIRMED_WITH_LOGIN_PASSWORD);
        }

        $orders = app(MedicationOrderWorkflow::class);
        $fixtures = ClientMedication::query()->where('client_id', $client->id)->whereIn('name', self::MEDICATION_NAMES)->orderBy('id')->get();
        if ($fixtures->count() !== count(self::MEDICATION_NAMES) || $fixtures->pluck('name')->unique()->count() !== count(self::MEDICATION_NAMES)) {
            throw new LogicException('Medication acceptance requires the exact five unique synthetic orders.');
        }
        foreach ($fixtures as $order) {
            if ($order->controlled_drug) {
                $order->forceFill(['nz_controlled_class' => 'B', 'controlled_class_source' => 'Synthetic reviewed browser acceptance configuration'])->save();
            }
            $changes = [
                'indication' => 'Synthetic medication readiness prescription',
                'prescriber' => 'Dr Playwright Fixture',
                'dose_amount' => $order->name === 'PW Meds PRN Paracetamol' ? 500 : 1,
                'dose_unit' => $order->name === 'PW Meds PRN Paracetamol' ? 'mg' : ($order->form === 'capsule' ? 'capsules' : ($order->form === 'drops' ? 'drops' : 'tablets')),
                'dose_times' => isset($times[$order->name]) ? [$times[$order->name]] : $order->dose_times,
            ];
            if ($order->approval_status === 'verified' && $order->verified_by !== null
                && collect($changes)->every(fn ($value, string $key): bool => $key === 'dose_amount'
                    ? (float) $order->getAttribute($key) === (float) $value
                    : $order->getAttribute($key) === $value)) {
                continue;
            }
            $payload = array_replace($orders->payload($order), $changes, [
                'start_date' => $order->start_date?->toDateString(),
                'end_date' => $order->end_date?->toDateString(),
            ]);
            $publish = function () use ($orders, $enterer, $checker, $client, $order, $payload): void {
                $revision = $orders->enter($enterer, $client->id, $order->id, [
                    'expected_version' => $order->version,
                    'request_key' => 'pw-meds-check:'.Str::uuid(),
                    'source' => ['type' => 'written', 'prescriber' => 'Dr Playwright Fixture', 'received_at' => now()->subMinute()->toIso8601String(), 'description' => 'Synthetic browser acceptance source'],
                    'prescription' => $payload,
                    'change_reason' => 'Check the synthetic medication readiness source.',
                ], UploadedFile::fake()->createWithContent('pw-meds-prescription.pdf', "%PDF-1.4\nSynthetic browser acceptance prescription\n%%EOF"));
                $orders->check($checker, $revision->id, [
                    'source_matches' => true, 'dose_route_times_checked' => true, 'allergies_interactions_checked' => true,
                ]);
            };
            // Only the first typed source for this explicitly historical
            // synthetic order is checked before its first due dose. Existing
            // checked history and later changes retain their actual chronology.
            if (! $order->versions()->whereNotNull('entry_request_key')->exists()
                && $order->created_at?->lte($firstCheckedAt)
                && $enterer->approved_at?->lte($firstCheckedAt) && $checker->approved_at?->lte($firstCheckedAt)) {
                Carbon::withTestNow($firstCheckedAt, $publish);
            } else {
                $publish();
            }
        }
        $this->retireObsoleteProjectionSlots($client, $fixtures->pluck('id')->all());
    }

    /** @return array<string, string> */
    private function scheduledTimes(): array
    {
        $anchor = now(config('app.worker_timezone', 'Pacific/Auckland'));
        if ($anchor->copy()->addMinutes(15)->toDateString() !== $anchor->toDateString()
            || app(DoseTimingSettings::class)->earlyMinutes() < 15) {
            throw new LogicException('Medication acceptance needs three future doses today inside the configured early recording window.');
        }

        return [
            // Every genuine later publication precedes its new due instant.
            // These are still inside the normal early recording window.
            'PW Meds Morning Tablets' => $anchor->copy()->addMinutes(5)->format('H:i'),
            'PW Meds Vitamin D' => $anchor->copy()->addMinutes(10)->format('H:i'),
            'PW Meds Eye Drops' => $anchor->copy()->addMinutes(15)->format('H:i'),
        ];
    }

    /** Only disposable, unrecorded TODAY projections of these synthetic orders. */
    private function retireObsoleteProjectionSlots(Client $client, array $medicationIds): void
    {
        DB::transaction(function () use ($client, $medicationIds): void {
            $client = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $orders = ClientMedication::query()->where('client_id', $client->id)->whereIn('id', $medicationIds)
                ->whereIn('name', self::MEDICATION_NAMES)->orderBy('id')->lockForUpdate()->get();
            if ($orders->count() !== count(self::MEDICATION_NAMES)) {
                throw new LogicException('The synthetic medication reset scope changed.');
            }
            foreach ($orders as $order) {
                if ($order->approval_status !== 'verified' || $order->verified_by === null) {
                    throw new LogicException('A disposable fixture projection needs its genuinely checked current order.');
                }
                $obsolete = DB::table('medication_dose_slots')->where('client_id', $client->id)->where('client_medication_id', $order->id)
                    ->where('nz_date', now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString())
                    ->whereNotIn('ordered_time', $order->dose_times ?? [])->whereNull('outcome')->whereNull('outcome_administration_id')
                    ->whereNull('outcome_at')->where('reconstructed', false)
                    ->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->get();
                if ($obsolete->isEmpty()) {
                    continue;
                }
                $disposable = $obsolete->reject(fn ($slot) => DB::table('medication_downtime_doses')->where('dose_slot_id', $slot->id)->exists()
                    || DB::table('medication_paper_entries')->where('dose_identity', 'slot:'.$slot->id)->exists()
                    || DB::table('client_medication_administrations')->where('client_medication_id', $order->id)
                        ->where('scheduled_for', $slot->due_at)->exists());
                // Match the production projection's explicit invalidation,
                // without deleting slots or changing any checked history.
                DB::table('medication_dose_slots')->whereIn('id', $disposable->pluck('id'))
                    ->update(['superseded_at' => now()->utc(), 'updated_at' => now()->utc()]);
            }
        });
    }

    private function officeWorker(string $email, string $name, Client $client, string $capability): User
    {
        $existing = User::query()->where('email', $email)->first();
        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make('password'), 'role' => 'support_worker',
            'approved_at' => $existing?->approved_at ?? now(), 'email_verified_at' => $existing?->email_verified_at ?? now(),
        ]);
        // A separate source enterer and checker have only the fixture keys.
        $user->roles()->sync([]);
        $this->grant($user, ['clients.viewAssigned', 'medications.view', 'medications.administer.record', 'medications.controlled.view', 'medications.controlled.record', $capability]);
        HrEmployeeProfile::query()->updateOrCreate(['user_id' => $user->id], [
            'employee_number' => $capability === 'medications.orders.manage' ? 'PWMEDSENTRY' : 'PWMEDSCHECK',
            'work_email' => $user->email, 'position_title' => $name, 'position_role' => 'support_worker',
            'employment_type' => 'full_time', 'contract_type' => 'permanent',
            'primary_site_id' => $client->site_id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear()->toDateString(), 'end_date' => null, 'is_active' => true,
        ]);
        $client->supportWorkers()->syncWithoutDetaching([$user->id]);
        $this->presence($user, $client);

        return $user;
    }

    private function assessment(User $subject, User $assessor): void
    {
        MedicationCompetencyAssessment::query()->updateOrCreate([
            'user_id' => $subject->id, 'assessor_id' => $assessor->id, 'assessment_type' => 'Playwright medication readiness',
        ], [
            'status' => 'passed', 'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(), 'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'medication_knowledge' => true, 'five_rights' => true, 'safety_checks' => true, 'documentation' => true,
            'controlled_drugs' => true, 'prn_assessment' => true, 'error_reporting' => true, 'allergy_awareness' => true,
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'restricted' => false, 'not_seen_areas' => [],
        ]);
    }

    private function presence(User $user, Client $client): void
    {
        $shift = Shift::query()->updateOrCreate(['user_id' => $user->id, 'notes' => 'PW:meds-acceptance:'.$user->email], [
            'client_id' => $client->id, 'site_id' => $client->site_id, 'service_context_id' => $client->service_context_id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5), 'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null, 'status' => 'in_progress', 'started_by' => $user->id,
        ]);
        HrAttendanceSession::query()->updateOrCreate(['user_id' => $user->id, 'shift_id' => $shift->id], [
            'site_id' => $client->site_id, 'clock_in_at' => now()->subHour(), 'clock_out_at' => null,
            'status' => 'open', 'source' => 'playwright', 'created_by' => $user->id, 'closed_by' => null, 'break_minutes' => 0,
        ]);
    }

    /** @param list<string> $keys */
    private function grant(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all());
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
