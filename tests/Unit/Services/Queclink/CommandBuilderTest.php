<?php

use App\Services\Queclink\CommandBuilder;
use App\Services\Queclink\SerialNumberAllocator;

test('gl30m family commands use the GL30MEU factory password', function () {
    $serials = new class extends SerialNumberAllocator
    {
        public function next(): string
        {
            return '0001';
        }
    };

    $command = (new CommandBuilder($serials))->requestLocation(CommandBuilder::FAMILY_GL30M);

    expect($command['raw'])->toBe('AT+GTRTO=gl30,1,,,,,,0001$');
});
