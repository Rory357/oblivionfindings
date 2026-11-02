<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;

/*
 * S&D data move "Old local door cards and schedules → UniFi people" (security-devices:moves:door-cards).
 * Cards are matched to the UniFi person at the same Site's console by the holder's employee number.
 * --apply records only "suggested" matches for a manager to confirm (pop-up 90); nothing is sent to
 * UniFi and the old records are not changed. A resident's card is listed without a name.
 */

beforeEach(function (): void {
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->actor = User::factory()->create(['name' => 'Synthetic Mover', 'approved_at' => now()]);
    $this->console = AccessConsole::query()->create(['site_id' => $this->site->id, 'address' => '10.78.0.40', 'port' => 12445, 'state' => 'ok']);
    $this->schedule = AccessControlSchedule::query()->create([
        'site_id' => $this->site->id, 'name' => 'Weekdays', 'timezone' => 'Pacific/Auckland',
        'days' => ['monday', 'tuesday'], 'starts_at' => '07:00', 'ends_at' => '19:00', 'is_active' => true,
    ]);

    $this->staff = function (string $name, string $employeeNumber): User {
        $user = User::factory()->create(['name' => $name, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'employee_number' => $employeeNumber,
            'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true,
        ]);

        return $user;
    };
    $this->card = fn (string $holderType, int $holderId, string $label, array $extra = []): AccessControlCredential => AccessControlCredential::query()->create([
        'site_id' => $this->site->id, 'access_schedule_id' => $this->schedule->id, 'label' => $label,
        'holder_type' => $holderType, 'holder_id' => $holderId, 'reference_key' => 'local:card/'.uniqid('', true), ...$extra,
    ]);
    $this->person = fn (string $first, string $employeeNumber, array $extra = []): AccessPerson => AccessPerson::query()->create([
        'console_id' => $this->console->id, 'external_id' => 'person-'.$employeeNumber, 'first_name' => $first, 'last_name' => 'Synthetic',
        'employee_number' => $employeeNumber, 'status' => 'active', 'kind' => 'unknown', 'match_state' => 'unmatched', 'seen_at' => now(), ...$extra,
    ]);

    $this->ana = ($this->staff)('Synthetic Ana', 'EMP-7001');
    $this->ben = ($this->staff)('Synthetic Ben', 'EMP-7002');
    $this->cal = ($this->staff)('Synthetic Cal', 'EMP-7003');
    ($this->card)(AccessControlCredential::HOLDER_STAFF, $this->ana->id, 'Ana front door card');
    ($this->card)(AccessControlCredential::HOLDER_STAFF, $this->ben->id, 'Ben card');
    ($this->card)(AccessControlCredential::HOLDER_STAFF, $this->cal->id, 'Cal card');
    ($this->card)(AccessControlCredential::HOLDER_STAFF, $this->cal->id, 'Cal old card', ['valid_until' => now()->subMonth()]);
    ($this->card)(AccessControlCredential::HOLDER_CLIENT, 424242, 'Fob for Synthetic Resident');

    $this->anaPerson = ($this->person)('Ana', 'EMP-7001');
    $this->benPerson = ($this->person)('Ben', 'EMP-7002', ['user_id' => $this->ben->id, 'kind' => 'staff', 'match_state' => 'matched']);

    $this->localRecords = fn (): array => AccessControlCredential::query()->orderBy('id')->get(['id', 'status', 'holder_id', 'label'])->toArray();
});

it('matches by employee number in a dry run, lists the rest for a manager, and changes nothing', function (): void {
    $records = ($this->localRecords)();

    $this->artisan('security-devices:moves:door-cards')
        ->expectsOutputToContain('Will be suggested')
        ->expectsOutputToContain('Already confirmed')
        ->expectsOutputToContain('Not in UniFi Access at this Site')
        ->expectsOutputToContain('A resident')
        ->doesntExpectOutputToContain('Synthetic Resident')
        ->expectsOutputToContain('Dry run: nothing changed')
        ->assertSuccessful();

    expect($this->anaPerson->fresh()->match_state)->toBe('unmatched')
        ->and($this->anaPerson->fresh()->user_id)->toBeNull()
        ->and(($this->localRecords)())->toBe($records)
        ->and(AuditLog::query()->where('action', 'like', 'security_devices.data_move.door_cards.%')->exists())->toBeFalse();
});

it('records only suggestions, sends nothing to UniFi, keeps the old records, and is idempotent', function (): void {
    $records = ($this->localRecords)();

    $this->artisan('security-devices:moves:door-cards', ['--apply' => true, '--actor' => $this->actor->id])
        ->expectsOutputToContain('Done: recorded 1 suggested matches')
        ->assertSuccessful();

    $ana = $this->anaPerson->fresh();
    expect($ana->match_state)->toBe('suggested')
        ->and($ana->user_id)->toBe($this->ana->id)
        ->and($ana->kind)->toBe('staff')
        ->and($this->benPerson->fresh()->match_state)->toBe('matched')
        ->and(AccessPerson::query()->count())->toBe(2)
        ->and(AccessJob::query()->count())->toBe(0)
        ->and(($this->localRecords)())->toBe($records)
        ->and(AuditLog::query()->where('action', 'security_devices.data_move.door_cards.match_suggested')->count())->toBe(1);

    $this->artisan('security-devices:moves:door-cards', ['--apply' => true, '--actor' => $this->actor->id])
        ->expectsOutputToContain('Done: recorded 0 suggested matches')
        ->assertSuccessful();
    expect($this->anaPerson->fresh()->match_state)->toBe('suggested')
        ->and(AuditLog::query()->where('action', 'security_devices.data_move.door_cards.match_suggested')->count())->toBe(1);
});

it('refuses --apply in production without --force', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('security-devices:moves:door-cards', ['--apply' => true, '--actor' => $this->actor->id])->assertFailed();

    expect($this->anaPerson->fresh()->match_state)->toBe('unmatched');
});
