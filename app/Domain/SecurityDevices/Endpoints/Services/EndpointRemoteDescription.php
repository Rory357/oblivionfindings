<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

/** Bounded opaque WebRTC setup, parsed again by the actual RTC implementation. */
final class EndpointRemoteDescription
{
    public static function validate(mixed $value): string
    {
        abort_unless(is_string($value) && strlen($value) >= 100 && strlen($value) <= 32768, 422);
        abort_unless(str_starts_with($value, "v=0\r\n") && str_ends_with($value, "\r\n"), 422);
        abort_if(preg_match('/[^\x20-\x7E\r\n]/', $value) === 1, 422);
        $lines = explode("\r\n", substr($value, 0, -2));
        abort_if(count($lines) > 256, 422);
        $media = 0;
        $fingerprints = 0;
        foreach ($lines as $line) {
            abort_if($line === '' || strlen($line) > 1024 || preg_match('/[\r\n]/', $line) === 1, 422);
            if (str_starts_with($line, 'm=')) {
                $media++;
                abort_unless(preg_match('/\Am=application [0-9]{1,5} UDP\/DTLS\/SCTP webrtc-datachannel\z/', $line) === 1, 422);
            }
            if (str_starts_with($line, 'a=fingerprint:')) {
                $fingerprints++;
                abort_unless(preg_match('/\Aa=fingerprint:sha-256 (?:[A-Fa-f0-9]{2}:){31}[A-Fa-f0-9]{2}\z/', $line) === 1, 422);
            }
        }
        abort_unless($media === 1 && $fingerprints === 1, 422);

        return $value;
    }
}
