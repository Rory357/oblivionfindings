<?php

use App\Models\Queclink\QueclinkRawFrame;

it('prints the last frame age without treating the timestamp as a string', function () {
    QueclinkRawFrame::create([
        'imei' => '867963069916998',
        'direction' => 'inbound',
        'frame_type' => 'RESP',
        'command_word' => 'GTFRI',
        'raw_frame' => '+RESP:GTFRI,867963069916998,,,,20260518031500,0000$',
        'parse_ok' => true,
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('queclink:status')
        ->expectsOutputToContain('Last frame:')
        ->assertExitCode(0);
});
