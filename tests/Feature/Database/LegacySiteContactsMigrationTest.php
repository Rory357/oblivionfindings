<?php

use App\Models\Site;
use App\Models\SiteContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function legacySiteContactsMigration(): object
{
    return require database_path('migrations/2026_05_16_000001_drop_legacy_site_contact_scalars.php');
}

function addLegacySiteContactColumnsForTest(): object
{
    $migration = legacySiteContactsMigration();
    $migration->down();

    return $migration;
}

test('migration backfills manager scalars into a manager site contact and drops the legacy columns', function () {
    $site = Site::factory()->create(['tenant_id' => 42]);
    $migration = addLegacySiteContactColumnsForTest();

    DB::table('sites')->where('id', $site->id)->update([
        'manager_name' => 'Jane Manager',
        'manager_phone' => '021 987 6543',
    ]);

    $migration->up();

    expect(Schema::hasColumn('sites', 'manager_name'))->toBeFalse()
        ->and(Schema::hasColumn('sites', 'manager_phone'))->toBeFalse()
        ->and(Schema::hasColumn('sites', 'after_hours_phone'))->toBeFalse();

    $this->assertDatabaseHas('site_contacts', [
        'tenant_id' => 42,
        'site_id' => $site->id,
        'type' => 'manager',
        'name' => 'Jane Manager',
        'phone' => '021 987 6543',
    ]);
});

test('migration backfills after hours phone into an emergency site contact', function () {
    $site = Site::factory()->create(['tenant_id' => 7]);
    $migration = addLegacySiteContactColumnsForTest();

    DB::table('sites')->where('id', $site->id)->update([
        'after_hours_phone' => '0800 111 222',
    ]);

    $migration->up();

    $this->assertDatabaseHas('site_contacts', [
        'tenant_id' => 7,
        'site_id' => $site->id,
        'type' => 'emergency',
        'name' => 'After-hours contact',
        'role' => 'After hours',
        'phone' => '0800 111 222',
    ]);
});

test('migration does not double insert contacts when matching contact types already exist', function () {
    $site = Site::factory()->create(['tenant_id' => 5]);
    SiteContact::create([
        'tenant_id' => 5,
        'site_id' => $site->id,
        'type' => 'manager',
        'name' => 'Existing Manager',
        'phone' => '021 000 0000',
    ]);

    $migration = addLegacySiteContactColumnsForTest();

    DB::table('sites')->where('id', $site->id)->update([
        'manager_name' => 'Jane Manager',
        'manager_phone' => '021 987 6543',
    ]);

    $migration->up();

    expect(SiteContact::where('site_id', $site->id)->where('type', 'manager')->count())->toBe(1);
    $this->assertDatabaseHas('site_contacts', [
        'site_id' => $site->id,
        'type' => 'manager',
        'name' => 'Existing Manager',
    ]);
});
