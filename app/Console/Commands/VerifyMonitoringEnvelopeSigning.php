<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Contracts\EnvelopeSigner;
use Illuminate\Console\Command;
use Throwable;

final class VerifyMonitoringEnvelopeSigning extends Command
{
    protected $signature = 'monitoring:verify-envelope-signing
        {--json : Emit a value-free readiness report}';

    protected $description = 'Fail closed when the active monitoring envelope signing key is unavailable or invalid';

    public function handle(EnvelopeSigner $signer): int
    {
        $checks = [
            'active_key_id' => 'missing',
            'active_key' => 'invalid',
            'signing_probe' => 'not_verified',
        ];

        try {
            $keyId = $signer->activeKeyId();
            $checks['active_key_id'] = 'configured';

            // This fixed in-memory probe validates the configured key length and
            // active-key lookup without sending, persisting, or emitting data.
            $signature = $signer->sign($keyId, 'oblivion-monitoring-envelope-signing-preflight-v1');
            $checks['active_key'] = 'valid';

            if (! $signer->verify($keyId, 'oblivion-monitoring-envelope-signing-preflight-v1', $signature)) {
                throw new \RuntimeException('Monitoring signing probe verification failed.');
            }

            $checks['signing_probe'] = 'verified';
        } catch (Throwable) {
            return $this->report($checks, false);
        }

        return $this->report($checks, true);
    }

    /** @param array<string, string> $checks */
    private function report(array $checks, bool $ready): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'ready' => $ready,
                'checks' => $checks,
            ], JSON_THROW_ON_ERROR));
        } elseif ($ready) {
            $this->info('Monitoring envelope signing preflight passed.');
        } else {
            $this->error('Monitoring envelope signing preflight refused. No signing key material was emitted.');
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
