<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\IcmpTransport;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;
use UnexpectedValueException;

final class DevicePingController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly DnsResolver $dnsResolver,
        private readonly CidrMatcher $cidrMatcher,
        private readonly EgressPolicy $egressPolicy,
        private readonly IcmpTransport $icmpTransport,
    ) {}

    public function __invoke(Request $request, Device $device): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $checkedAt = now()->toImmutable()->toISOString();
        $rawTarget = trim((string) ($device->ip_address ?? ''));

        if ($rawTarget === '') {
            return response()->json([
                'reachable' => false,
                'target' => null,
                'resolved_address' => null,
                'latency_ms' => null,
                'latency_label' => null,
                'reason_code' => 'missing_address',
                'message' => 'Add an IP address or hostname to this device first.',
                'checked_at' => $checkedAt,
            ]);
        }

        try {
            $probeTarget = ProbeTarget::icmp($rawTarget);
        } catch (Throwable) {
            return response()->json([
                'reachable' => false,
                'target' => $rawTarget,
                'resolved_address' => null,
                'latency_ms' => null,
                'latency_label' => null,
                'reason_code' => 'invalid_address',
                'message' => 'The saved IP address or hostname is not valid.',
                'checked_at' => $checkedAt,
            ]);
        }

        try {
            $numeric = filter_var($probeTarget->host, FILTER_VALIDATE_IP) !== false;
            $answers = $numeric
                ? [$probeTarget->host]
                : $this->dnsResolver->resolve($probeTarget->host);
        } catch (Throwable) {
            $answers = [];
        }

        if (! is_array($answers) || $answers === []) {
            return response()->json([
                'reachable' => false,
                'target' => $rawTarget,
                'resolved_address' => null,
                'latency_ms' => null,
                'latency_label' => null,
                'reason_code' => 'dns_failed',
                'message' => 'Could not look up that hostname in DNS.',
                'checked_at' => $checkedAt,
            ]);
        }

        $canonicalAddresses = [];
        $hostCidrs = [];
        foreach ($answers as $answer) {
            if (! is_string($answer) || trim($answer) === '') {
                continue;
            }
            try {
                $canonical = $this->cidrMatcher->canonicalAddress($answer);
                $canonicalAddresses[] = $canonical;
                $hostCidrs[] = str_contains($canonical, ':')
                    ? "{$canonical}/128"
                    : "{$canonical}/32";
            } catch (Throwable) {
                continue;
            }
        }

        $canonicalAddresses = array_values(array_unique($canonicalAddresses));
        $hostCidrs = array_values(array_unique($hostCidrs));

        if ($canonicalAddresses === [] || $hostCidrs === []) {
            return response()->json([
                'reachable' => false,
                'target' => $rawTarget,
                'resolved_address' => null,
                'latency_ms' => null,
                'latency_label' => null,
                'reason_code' => 'dns_failed',
                'message' => 'Could not resolve a usable IP address for that hostname.',
                'checked_at' => $checkedAt,
            ]);
        }

        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            $siteId = 1;
        }

        try {
            $authorized = $this->egressPolicy->authoriseDiscovery(
                siteId: max(1, $siteId),
                approvedCidrs: $hostCidrs,
                allowedPorts: [443],
                target: $probeTarget,
            );
        } catch (EgressDenied) {
            return response()->json([
                'reachable' => false,
                'target' => $rawTarget,
                'resolved_address' => $canonicalAddresses[0] ?? null,
                'latency_ms' => null,
                'latency_label' => null,
                'reason_code' => 'address_blocked',
                'message' => 'That IP address is reserved or private to the server and cannot be pinged.',
                'checked_at' => $checkedAt,
            ]);
        }

        $result = $this->icmpTransport->probe($authorized);
        $resolvedAddress = $authorized->addresses[0] ?? ($canonicalAddresses[0] ?? null);

        if ($result->reachable) {
            $latencyMs = max(0, (int) ($result->latencyMs ?? 0));
            $latencyLabel = $latencyMs < 1 ? '< 1 ms' : "{$latencyMs} ms";

            return response()->json([
                'reachable' => true,
                'target' => $rawTarget,
                'resolved_address' => $resolvedAddress,
                'latency_ms' => $latencyMs,
                'latency_label' => $latencyLabel,
                'reason_code' => 'reply',
                'message' => "Replied in {$latencyLabel}.",
                'checked_at' => $checkedAt,
            ]);
        }

        $message = match ($result->reasonCode) {
            'timeout' => 'No reply before the ping timed out. Check that the device is powered on, connected to the network, and reachable from this server.',
            'unreachable' => 'The network reported that this address is unreachable from the server.',
            'probe_unavailable' => 'Ping is blocked by server operating system permissions.',
            default => 'No ping reply was received from this address.',
        };

        return response()->json([
            'reachable' => false,
            'target' => $rawTarget,
            'resolved_address' => $resolvedAddress,
            'latency_ms' => null,
            'latency_label' => null,
            'reason_code' => $result->reasonCode,
            'message' => $message,
            'checked_at' => $checkedAt,
        ]);
    }
}
