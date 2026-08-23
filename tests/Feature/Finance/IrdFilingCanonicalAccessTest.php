<?php

use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinIrdFiling;
use App\Domain\Finance\Services\IrdFilingService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayrollRunItem;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Support\LegacyStorageContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->allowedSite = Site::factory()->create([
        ...LegacyStorageContext::attributes(),
        'name' => 'Allowed IRD payroll Site',
    ]);
    $this->hiddenSite = Site::factory()->create([
        ...LegacyStorageContext::attributes(),
        'name' => 'Hidden IRD payroll Site',
    ]);

    $this->staff = function (Site $site, string $role = 'support_worker'): User {
        $user = User::factory()->create([
            'role' => $role,
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            ...LegacyStorageContext::attributes(),
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);

        return $user;
    };

    $this->viewer = function (array $permissions): User {
        $user = ($this->staff)($this->allowedSite, 'finance');
        foreach ($permissions as $key) {
            $permission = Permission::query()->where('key', $key)->firstOrFail();
            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    };

    $this->postedRun = function (array $staff): HrPayrollRun {
        $run = HrPayrollRun::factory()->create([
            ...LegacyStorageContext::attributes(),
            'journal_id' => fake()->unique()->numberBetween(1000, 999999),
            'status' => 'finalised',
        ]);
        foreach ($staff as $employee) {
            HrPayrollRunItem::query()->create([
                'payroll_run_id' => $run->id,
                'user_id' => $employee->id,
            ]);
        }

        return $run;
    };
});

test('IRD index does not project payroll runs without HR payroll source access', function () {
    $viewer = ($this->viewer)(['finance.tax.manage']);
    $staff = ($this->staff)($this->allowedSite);
    ($this->postedRun)([$staff]);

    $response = $this->actingAs($viewer)
        ->get(route('finance.ird-filings.index'))
        ->assertOk();

    expect($response->inertiaProps('availablePayrollRuns'))->toBeEmpty();
});

test('IRD index projects only payroll runs wholly owned by staff at approved Sites', function () {
    $viewer = ($this->viewer)(['finance.tax.manage', 'hr.payroll.view']);
    $allowedStaff = ($this->staff)($this->allowedSite);
    $hiddenStaff = ($this->staff)($this->hiddenSite);
    $allowed = ($this->postedRun)([$allowedStaff]);
    $hidden = ($this->postedRun)([$hiddenStaff]);
    $mixed = ($this->postedRun)([$allowedStaff, $hiddenStaff]);

    $response = $this->actingAs($viewer)
        ->get(route('finance.ird-filings.index'))
        ->assertOk();

    expect(collect($response->inertiaProps('availablePayrollRuns'))->pluck('id')->all())
        ->toContain($allowed->id)
        ->not->toContain($hidden->id, $mixed->id);
});

test('IRD payroll filing creation requires HR payroll source access', function () {
    $viewer = ($this->viewer)(['finance.tax.manage']);
    $staff = ($this->staff)($this->allowedSite);
    $run = ($this->postedRun)([$staff]);

    $this->actingAs($viewer)
        ->post(route('finance.ird-filings.from-payroll', $run), [
            'ird_number' => '123456789',
        ])
        ->assertNotFound();

    expect(FinIrdFiling::query()->where('payroll_run_id', $run->id)->exists())->toBeFalse();
});

test('IRD payroll filing creation conceals a run containing hidden Site staff', function () {
    $viewer = ($this->viewer)(['finance.tax.manage', 'hr.payroll.view']);
    $hiddenStaff = ($this->staff)($this->hiddenSite);
    $run = ($this->postedRun)([$hiddenStaff]);

    $this->actingAs($viewer)
        ->post(route('finance.ird-filings.from-payroll', $run), [
            'ird_number' => '123456789',
        ])
        ->assertNotFound();

    expect(FinIrdFiling::query()->where('payroll_run_id', $run->id)->exists())->toBeFalse();
});

test('explicit payroll export and all-Site access permit application-wide IRD payroll filing', function () {
    $viewer = ($this->viewer)([
        'finance.tax.manage',
        'hr.payroll.view',
        'hr.payroll.export',
        'hr.employees.viewAllSites',
    ]);
    $hiddenStaff = ($this->staff)($this->hiddenSite);
    $run = ($this->postedRun)([$hiddenStaff]);

    $response = $this->actingAs($viewer)
        ->get(route('finance.ird-filings.index'))
        ->assertOk();
    expect(collect($response->inertiaProps('availablePayrollRuns'))->pluck('id')->all())
        ->toContain($run->id);

    $this->actingAs($viewer)
        ->post(route('finance.ird-filings.from-payroll', $run), [
            'ird_number' => '123456789',
        ])
        ->assertRedirect();

    // This compatibility value satisfies a legacy non-null storage column. It
    // is asserted directly and must never become an access/query predicate.
    $filing = FinIrdFiling::query()
        ->where('payroll_run_id', $run->id)
        ->firstOrFail();

    expect($filing->getAttribute('organization_id'))->toBe(LegacyStorageContext::id())
        ->and($filing->getHidden())->toContain('organization_id')
        ->and((new FinGstReturn)->getHidden())->toContain('organization_id');

    $index = $this->actingAs($viewer)
        ->get(route('finance.ird-filings.index'))
        ->assertOk();
    $indexFiling = collect($index->inertiaProps('filings.data'))->firstWhere('id', $filing->id);
    expect($indexFiling)->not->toBeNull()
        ->and($indexFiling)->not->toHaveKey('organization_id');

    $show = $this->actingAs($viewer)
        ->get(route('finance.ird-filings.show', $filing))
        ->assertOk();
    expect($show->inertiaProps('filing'))->not->toHaveKey('organization_id');
});

test('IRD payroll filing creation rejects a run that already has a filing', function () {
    $viewer = ($this->viewer)([
        'finance.tax.manage',
        'hr.payroll.view',
        'hr.payroll.export',
    ]);
    $staff = ($this->staff)($this->allowedSite);
    $run = ($this->postedRun)([$staff]);

    $this->actingAs($viewer)
        ->post(route('finance.ird-filings.from-payroll', $run), [
            'ird_number' => '123456789',
        ])
        ->assertRedirect();

    $this->actingAs($viewer)
        ->post(route('finance.ird-filings.from-payroll', $run), [
            'ird_number' => '123456789',
        ])
        ->assertUnprocessable();

    expect(FinIrdFiling::query()->where('payroll_run_id', $run->id)->count())->toBe(1);
});

test('IRD payday filing identity is enforced by a unique nullable database index', function () {
    $index = collect(Schema::getIndexes('fin_ird_filings'))
        ->firstWhere('name', 'fin_ird_filings_payroll_run_id_uq');

    expect($index)->not->toBeNull()
        ->and($index['columns'] ?? null)->toBe(['payroll_run_id'])
        ->and($index['unique'] ?? null)->toBeTrue();
});

test('a raced duplicate payday filing returns the same friendly 422 as the precheck', function () {
    $message = 'A payday filing already exists for this payroll run.';
    $viewer = ($this->viewer)([
        'finance.tax.manage',
        'hr.payroll.view',
        'hr.payroll.export',
    ]);
    $staff = ($this->staff)($this->allowedSite);
    $precheckedRun = ($this->postedRun)([$staff]);
    FinIrdFiling::query()->create(irdPaydayFilingAttributes($precheckedRun, $viewer));
    $racedRun = ($this->postedRun)([$staff]);
    $service = Mockery::mock(IrdFilingService::class);
    $service->shouldReceive('createPaydayFiling')
        ->once()
        ->andReturnUsing(function (HrPayrollRun $run, string $irdNumber) use ($viewer): FinIrdFiling {
            expect($irdNumber)->toBe('123456789');
            FinIrdFiling::query()->create(irdPaydayFilingAttributes($run, $viewer));

            return FinIrdFiling::query()->create(irdPaydayFilingAttributes($run, $viewer));
        });
    app()->instance(IrdFilingService::class, $service);

    $raced = $this->actingAs($viewer)
        ->post(route('finance.ird-filings.from-payroll', $racedRun), [
            'ird_number' => '123456789',
        ])
        ->assertUnprocessable();

    // The first request resolves the controller with the raced service. The
    // ordinary precheck can then be compared through that same controller
    // instance without calling the service again.
    $prechecked = $this->actingAs($viewer)
        ->post(route('finance.ird-filings.from-payroll', $precheckedRun), [
            'ird_number' => '123456789',
        ])
        ->assertUnprocessable();

    expect($prechecked->exception?->getMessage())->toBe($message)
        ->and($raced->exception?->getMessage())->toBe($message)
        ->and(FinIrdFiling::query()->where('payroll_run_id', $racedRun->id)->count())->toBe(1);
});

function irdPaydayFilingAttributes(HrPayrollRun $run, User $creator): array
{
    return [
        'organization_id' => LegacyStorageContext::id(),
        'ird_number' => '123456789',
        'filing_type' => 'payday',
        'period_from' => $run->period_start,
        'period_to' => $run->period_end,
        'payroll_run_id' => $run->id,
        'filing_data' => ['payroll_run_id' => $run->id],
        'total_amount' => 0,
        'status' => 'draft',
        'created_by' => $creator->id,
    ];
}
