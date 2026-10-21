<?php

namespace App\Services\Chat;

use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/** Chat owns staging/authority; the shared scanner receives only server-resolved paths. */
final class ChatFileSafety
{
    public function __construct(private MalwareScanner $scanner, private Repository $config) {}

    public function inspect(string $serverPath): ChatFileScan
    {
        if (! is_file($serverPath) || ! is_readable($serverPath)) {
            return new ChatFileScan('unavailable', errorCode: 'file_unavailable');
        }
        $before = @hash_file('sha256', $serverPath);
        if (! is_string($before)) {
            return new ChatFileScan('unavailable', errorCode: 'file_unavailable');
        }
        try {
            $result = $this->scanner->scanPath($serverPath, (array) $this->config->get('chat.files.scanner', []));
        } catch (Throwable) {
            return new ChatFileScan('unavailable', errorCode: 'scanner_failed');
        }
        if ($result->disposition === MalwareScanDisposition::Infected) {
            return new ChatFileScan('blocked', scanner: $result->scanner, errorCode: 'file_blocked');
        }
        if ($result->disposition !== MalwareScanDisposition::Clean) {
            return new ChatFileScan('unavailable', scanner: $result->scanner, errorCode: $result->errorCode ?? 'scanner_unavailable');
        }
        $verdict = new ChatFileScan('clean', $before, $result->scanner);

        return $verdict->verifies($serverPath) ? $verdict : new ChatFileScan('changed', scanner: $result->scanner, errorCode: 'file_changed');
    }
}
