<?php

namespace App\Services\Queclink\Listener;

/**
 * Per-TCP-connection runtime state.
 *
 * Each Queclink device opens a single long-lived TCP connection (in
 * Report Mode 3 — TCP long-connection) and sends frames over it. We don't
 * know the IMEI until the first frame arrives, so the connection is initially
 * "unidentified" — once we see an IMEI we bind it.
 */
class ConnectionState
{
    public string $buffer = '';
    public ?string $imei = null;
    public ?int $queclinkDeviceId = null;
    public string $sessionId;
    public string $remoteAddress;
    public float $connectedAt;
    public float $lastActivityAt;
    public int $framesIn = 0;
    public int $framesOut = 0;

    public function __construct(string $remoteAddress)
    {
        $this->remoteAddress = $remoteAddress;
        $this->sessionId = bin2hex(random_bytes(8));
        $this->connectedAt = microtime(true);
        $this->lastActivityAt = $this->connectedAt;
    }

    public function bind(string $imei, int $queclinkDeviceId): void
    {
        $this->imei = $imei;
        $this->queclinkDeviceId = $queclinkDeviceId;
    }

    public function touch(): void
    {
        $this->lastActivityAt = microtime(true);
    }
}
