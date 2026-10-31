<?php

use App\Domain\Monitoring\Transports\NativeIcmpTransport;

it('rejects Windows destination host unreachable even when ping.exe exits zero', function () {
    $transport = new NativeIcmpTransport;

    $stdout = <<<'WIN'

Pinging 192.168.1.240 with 32 bytes of data:
Reply from 192.168.1.50: Destination host unreachable.

Ping statistics for 192.168.1.240:
    Packets: Sent = 1, Received = 1, Lost = 0 (0% loss),
WIN;

    $result = $transport->parseOutput('192.168.1.240', 0, $stdout);

    expect($result->reachable)->toBeFalse()
        ->and($result->latencyMs)->toBeNull()
        ->and($result->packetLossPercent)->toBe(100.0)
        ->and($result->reasonCode)->toBe('unreachable');
});

it('rejects Windows TTL expired or replies from an address other than the target', function () {
    $transport = new NativeIcmpTransport;

    $ttlExpired = <<<'WIN'
Pinging 10.20.30.40 with 32 bytes of data:
Reply from 192.168.1.1: TTL expired in transit.
WIN;

    $wrongHostReply = <<<'WIN'
Pinging 192.168.1.240 with 32 bytes of data:
Reply from 192.168.1.1: bytes=32 time=2ms TTL=64
WIN;

    expect($transport->parseOutput('10.20.30.40', 0, $ttlExpired)->reachable)->toBeFalse()
        ->and($transport->parseOutput('10.20.30.40', 0, $ttlExpired)->reasonCode)->toBe('unreachable')
        ->and($transport->parseOutput('192.168.1.240', 0, $wrongHostReply)->reachable)->toBeFalse();
});

it('accepts genuine Windows IPv4 and IPv6 echo replies from the target address', function () {
    $transport = new NativeIcmpTransport;

    $subMs = <<<'WIN'
Pinging 192.168.1.1 with 32 bytes of data:
Reply from 192.168.1.1: bytes=32 time<1ms TTL=64
WIN;

    $multiMs = <<<'WIN'
Pinging 192.168.1.25 with 32 bytes of data:
Reply from 192.168.1.25: bytes=32 time=14ms TTL=64
WIN;

    $ipv6 = <<<'WIN'
Pinging ::1 with 32 bytes of data:
Reply from ::1: time<1ms
WIN;

    $subMsResult = $transport->parseOutput('192.168.1.1', 0, $subMs);
    $multiMsResult = $transport->parseOutput('192.168.1.25', 0, $multiMs);
    $ipv6Result = $transport->parseOutput('::1', 0, $ipv6);

    expect($subMsResult->reachable)->toBeTrue()
        ->and($subMsResult->latencyMs)->toBe(0)
        ->and($subMsResult->reasonCode)->toBe('reply')
        ->and($multiMsResult->reachable)->toBeTrue()
        ->and($multiMsResult->latencyMs)->toBe(14)
        ->and($ipv6Result->reachable)->toBeTrue()
        ->and($ipv6Result->latencyMs)->toBe(0);
});

it('parses Linux IPv4 and IPv6 echo replies, timeouts, and raw socket permission errors', function () {
    $transport = new NativeIcmpTransport;

    $linuxReply = <<<'LINUX'
PING 192.168.1.10 (192.168.1.10) 56(84) bytes of data.
64 bytes from 192.168.1.10: icmp_seq=1 ttl=64 time=3.42 ms

--- 192.168.1.10 ping statistics ---
1 packets transmitted, 1 received, 0% packet loss, time 0ms
LINUX;

    $linuxTimeout = <<<'LINUX'
PING 192.168.1.99 (192.168.1.99) 56(84) bytes of data.

--- 192.168.1.99 ping statistics ---
1 packets transmitted, 0 received, 100% packet loss, time 0ms
LINUX;

    $permissionError = 'ping: socket: Operation not permitted';

    $reply = $transport->parseOutput('192.168.1.10', 0, $linuxReply);
    $timeout = $transport->parseOutput('192.168.1.99', 1, $linuxTimeout);
    $denied = $transport->parseOutput('127.0.0.1', 2, '', $permissionError);

    expect($reply->reachable)->toBeTrue()
        ->and($reply->latencyMs)->toBe(3)
        ->and($timeout->reachable)->toBeFalse()
        ->and($timeout->reasonCode)->toBe('timeout')
        ->and($denied->reachable)->toBeFalse()
        ->and($denied->reasonCode)->toBe('probe_unavailable');
});

it('probes loopback on the current operating system when ping permission is available', function () {
    $transport = new NativeIcmpTransport;

    $result = $transport->probeLoopback(1);

    if ($result->reasonCode === 'probe_unavailable') {
        expect($result->reachable)->toBeFalse();

        return;
    }

    expect($result->reachable)->toBeTrue()
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0)
        ->and($result->reasonCode)->toBe('reply');
});
