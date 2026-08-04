<?php

namespace App\Domain\Monitoring\Services;

final class UdpPeerAddress
{
    public static function parse(string $peer): ?string
    {
        if (str_starts_with($peer, '[')) {
            $end = strpos($peer, ']');
            if ($end === false) {
                return null;
            }
            $address = substr($peer, 1, $end - 1);
        } else {
            $separator = strrpos($peer, ':');
            $address = $separator === false ? $peer : substr($peer, 0, $separator);
        }

        return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : null;
    }
}
