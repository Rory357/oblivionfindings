<?php

use App\Models\ControlRoom\Playbook;

/*
 * Stephan, 7 Nov 2026: "Attach on critical too". An intruder alarm or a water leak that a device
 * reports as critical stays critical in Control Room (safety fix round), so their procedures now
 * start on their own at critical as well as high. Only those two playbooks change.
 */

function intrusionLeakMigration(): object
{
    return require database_path('migrations/2026_11_07_120000_intrusion_and_leak_playbooks_attach_on_critical.php');
}

function intrusionLeakPlaybookRow(string $code, string $type, ?array $severities, string $category = Playbook::CATEGORY_SAFETY): Playbook
{
    return Playbook::query()->create([
        'code' => $code, 'name' => $type, 'category' => $category, 'version' => 1, 'is_active' => true,
        'trigger_alert_types' => [$type], 'trigger_severities' => $severities, 'auto_attach' => true,
    ]);
}

it('adds critical to the Intrusion Alarm and Water Leak procedures and nothing else', function (): void {
    $intrusion = intrusionLeakPlaybookRow('intrusion_alarm', 'Intrusion Alarm', ['high']);
    $leak = intrusionLeakPlaybookRow('water_leak', 'Water Leak', ['high'], Playbook::CATEGORY_MAINTENANCE);
    $bedExit = intrusionLeakPlaybookRow('bed_exit_night', 'Bed Exit (Night)', ['high']);
    $panic = intrusionLeakPlaybookRow('panic_sos_person', 'Panic/SOS (Person)', ['critical'], Playbook::CATEGORY_EMERGENCY);
    expect(Playbook::findForAlert('Intrusion Alarm', 'critical'))->toBeNull()
        ->and(Playbook::findForAlert('Water Leak', 'critical'))->toBeNull();

    intrusionLeakMigration()->up();

    expect($intrusion->fresh()->trigger_severities)->toBe(['high', 'critical'])
        ->and($intrusion->fresh()->version)->toBe(2)
        ->and($leak->fresh()->trigger_severities)->toBe(['high', 'critical'])
        ->and($leak->fresh()->version)->toBe(2)
        // Every other procedure is untouched.
        ->and($bedExit->fresh()->trigger_severities)->toBe(['high'])
        ->and($bedExit->fresh()->version)->toBe(1)
        ->and($panic->fresh()->trigger_severities)->toBe(['critical'])
        ->and($panic->fresh()->version)->toBe(1);

    // A critical intruder alarm or leak now starts its procedure; high still does.
    expect(Playbook::findForAlert('Intrusion Alarm', 'critical')?->id)->toBe($intrusion->id)
        ->and(Playbook::findForAlert('Intrusion Alarm', 'high')?->id)->toBe($intrusion->id)
        ->and(Playbook::findForAlert('Water Leak', 'critical')?->id)->toBe($leak->id)
        ->and(Playbook::findForAlert('Bed Exit (Night)', 'critical'))->toBeNull();

    // Safe to re-run: no second "critical", no second version bump.
    intrusionLeakMigration()->up();
    expect($intrusion->fresh()->trigger_severities)->toBe(['high', 'critical'])
        ->and($intrusion->fresh()->version)->toBe(2);
});

it('leaves a procedure someone set to start by hand, or to any severity, as it is', function (): void {
    $manual = intrusionLeakPlaybookRow('intrusion_alarm', 'Intrusion Alarm', []);
    $any = intrusionLeakPlaybookRow('water_leak', 'Water Leak', null, Playbook::CATEGORY_MAINTENANCE);

    intrusionLeakMigration()->up();

    expect($manual->fresh()->trigger_severities)->toBe([])
        ->and($manual->fresh()->version)->toBe(1)
        ->and($any->fresh()->trigger_severities)->toBeNull()
        ->and($any->fresh()->version)->toBe(1);
});
