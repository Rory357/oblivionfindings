<?php

use App\Domain\Monitoring\Protocols\Snmp\SnmpQuery;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTopologyParser;
use Carbon\CarbonImmutable;

it('normalises bounded LLDP CDP ARP forwarding and route evidence without persisting raw identities as edge evidence', function () {
    $modernIndex = '2.2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.85';
    $values = [
        '1.3.6.1.2.1.31.1.1.1.1.1' => 'Gi1/0/1',
        '1.3.6.1.2.1.31.1.1.1.1.2' => 'Gi1/0/2',

        '1.0.8802.1.1.2.1.4.1.1.4.100.1.1' => 4,
        '1.0.8802.1.1.2.1.4.1.1.5.100.1.1' => '00:11:22:33:44:55',
        '1.0.8802.1.1.2.1.4.1.1.6.100.1.1' => 5,
        '1.0.8802.1.1.2.1.4.1.1.7.100.1.1' => 'eth0',
        '1.0.8802.1.1.2.1.4.1.1.8.100.1.1' => 'Ethernet 0',
        '1.0.8802.1.1.2.1.4.1.1.9.100.1.1' => 'Access-Point-01',

        '1.3.6.1.4.1.9.9.23.1.2.1.1.3.1.1' => 1,
        '1.3.6.1.4.1.9.9.23.1.2.1.1.4.1.1' => '10.44.1.1',
        '1.3.6.1.4.1.9.9.23.1.2.1.1.6.1.1' => 'Core-Router-01',
        '1.3.6.1.4.1.9.9.23.1.2.1.1.7.1.1' => 'Gi0/1',
        '1.3.6.1.4.1.9.9.23.1.2.1.1.8.1.1' => 'ISR4431',

        '1.3.6.1.2.1.4.22.1.2.2.10.44.1.55' => '00-aa-bb-cc-dd-ee',
        '1.3.6.1.2.1.4.22.1.3.2.10.44.1.55' => '10.44.1.55',
        '1.3.6.1.2.1.4.22.1.4.2.10.44.1.55' => 3,
        '1.3.6.1.2.1.4.35.1.4.'.$modernIndex => '00:aa:bb:cc:dd:ef',
        '1.3.6.1.2.1.4.35.1.6.'.$modernIndex => 3,

        '1.3.6.1.2.1.17.1.4.1.2.3' => 2,
        '1.3.6.1.2.1.17.4.3.1.1.0.17.34.51.68.102' => '00:11:22:33:44:66',
        '1.3.6.1.2.1.17.4.3.1.2.0.17.34.51.68.102' => 3,
        '1.3.6.1.2.1.17.4.3.1.3.0.17.34.51.68.102' => 3,

        '1.3.6.1.2.1.4.21.1.2.0.0.0.0' => 1,
        '1.3.6.1.2.1.4.21.1.7.0.0.0.0' => '10.44.1.1',
        '1.3.6.1.2.1.4.21.1.8.0.0.0.0' => 4,
        '1.3.6.1.2.1.4.21.1.9.0.0.0.0' => 13,
        '1.3.6.1.2.1.4.21.1.11.0.0.0.0' => '0.0.0.0',
    ];
    $completed = [
        '1.0.8802.1.1.2.1.3.7.1',
        '1.0.8802.1.1.2.1.4.1.1',
        '1.3.6.1.4.1.9.9.23.1.2.1.1',
        '1.3.6.1.2.1.4.35.1',
        '1.3.6.1.2.1.4.22.1',
        '1.3.6.1.2.1.17.1.4.1',
        '1.3.6.1.2.1.17.4.3.1',
        '1.3.6.1.2.1.4.21.1',
    ];

    $result = (new SnmpTopologyParser)->parse(
        $values,
        $completed,
        CarbonImmutable::parse('2026-07-27T00:00:00Z'),
    );

    expect($result->completedSources)->toBe(['lldp', 'cdp', 'arp', 'forwarding_table', 'route'])
        ->and(collect($result->observations)->pluck('source')->unique()->sort()->values()->all())
        ->toBe(['arp', 'cdp', 'forwarding_table', 'lldp', 'route'])
        ->and(collect($result->observations)->firstWhere('source', 'lldp')?->localPort)->toBe('gi1/0/1')
        ->and(collect($result->observations)->firstWhere('source', 'lldp')?->remotePort)->toBe('ethernet 0')
        ->and(collect($result->observations)->firstWhere('source', 'forwarding_table')?->localPort)->toBe('gi1/0/2')
        ->and(collect($result->observations)->where('source', 'arp'))->toHaveCount(2)
        ->and(collect($result->observations)->every(function ($item): bool {
            $edgeEvidence = json_encode($item->evidence, JSON_THROW_ON_ERROR);

            return ! str_contains($edgeEvidence, '10.44.')
                && ! str_contains($edgeEvidence, '00:11:22')
                && ! str_contains($edgeEvidence, '00:aa:bb');
        }))->toBeTrue();
});

it('keeps topology walks optional so unsupported tables do not degrade the required inventory contract', function () {
    $query = SnmpQuery::inventory('v3');

    expect($query->walkRoots)->toContain('1.3.6.1.2.1.2.2.1')
        ->and($query->optionalWalkRoots)->toContain(
            '1.0.8802.1.1.2.1.4.1.1',
            '1.3.6.1.4.1.9.9.23.1.2.1.1',
            '1.3.6.1.2.1.4.35.1',
            '1.3.6.1.2.1.17.4.3.1',
            '1.3.6.1.2.1.4.24.4.1',
        )
        ->and(count($query->scalarOids) + count($query->walkRoots) + count($query->optionalWalkRoots))
        ->toBeLessThanOrEqual(SnmpQuery::MAX_OIDS);
});
