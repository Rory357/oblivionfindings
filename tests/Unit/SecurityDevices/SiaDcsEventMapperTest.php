<?php

use App\Domain\SecurityDevices\Alarms\SiaDc09\ContactIdEventMapper;

/*
 * Phase A, item A3: SIA-DCS data blocks as real panels send them (from the pysiaalarm reference frames,
 * MIT, Copyright (c) 2021 E.A. van Valkenburg). The earlier parser read the `ri` (area) marker as the
 * event code, so `Nri1/CL501` (area 1 closed by user 501) became an unknown event, not "armed".
 */

it('reads the event code after the area marker', function (string $data, string $code, string $eventType, string $partition): void {
    $mapped = (new ContactIdEventMapper)->map('SIA-DCS', $data);

    expect($mapped['ok'])->toBeTrue()
        ->and($mapped['sia_code'] ?? null)->toBe($code)
        ->and($mapped['event_type'])->toBe($eventType)
        ->and($mapped['partition'])->toBe($partition);
})->with([
    'closing, area 1, user 501' => ['|Nri1/CL501', 'CL', 'alarm_armed', '01'],
    'opening, area 4, with account' => ['#006969|Nri04/OP001NM', 'OP', 'alarm_disarmed', '04'],
    'closing with no slash and a text tail' => ['#123456|Nri1CL0^VX FRONTE ^', 'CL', 'alarm_armed', '01'],
    'fire alarm with no area' => ['#12345A|NFA129', 'FA', 'alarm_triggered', '01'],
    'water alarm, no N' => ['Nri1/WA000', 'WA', 'alarm_triggered', '01'],
    'user code tamper restored' => ['|Nri2/JC12', 'JC', 'alarm_event', '02'],
]);
