<?php

namespace App\Domain\SecurityDevices\Management\Contracts;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;

interface CommandHttpTransport
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     */
    public function request(
        AuthorizedProbeTarget $target,
        string $method,
        array $headers = [],
        ?array $json = null,
    ): CommandHttpResponse;
}
