<?php

use App\Domain\SecurityDevices\Automations\SafeActions;

/*
 * S&D Phase F (D12): automations and house modes never disarm or unlock. Their only actions are
 * the safe ones, none of which is a governed command.
 */

it('offers no action that disarms, unlocks, bypasses or loosens privacy', function (): void {
    foreach (array_keys(SafeActions::ACTIONS) as $action) {
        expect($action)->not->toContain('disarm')
            ->and($action)->not->toContain('unlock')
            ->and($action)->not->toContain('bypass')
            ->and($action)->not->toContain('privacy_off');
    }
    foreach (SafeActions::NEVER as $capability) {
        expect(SafeActions::allowed($capability))->toBeFalse();
    }
});

it('refuses anything that isn’t a safe action', function (string $action): void {
    expect(fn () => SafeActions::assertAllowed($action))->toThrow(InvalidArgumentException::class);
})->with(['alarm.disarm', 'access.door.unlock_timed', 'disarm', 'unlock_door', 'camera.privacy.disable', '']);

it('lists only the safe actions for the pickers', function (): void {
    expect(array_column(SafeActions::choices(), 'key'))->toBe(['notify', 'open_alert', 'camera_privacy_on', 'door_locked_check', 'alarm_armed_check']);
});
