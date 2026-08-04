<?php

use App\Domain\Monitoring\Protocols\Flow\FlowAggregator;
use App\Domain\Monitoring\Protocols\Flow\FlowTemplateRegistry;
use App\Domain\Monitoring\Protocols\Flow\IpfixDecoder;
use App\Domain\Monitoring\Protocols\Flow\NetFlowV5Decoder;
use App\Domain\Monitoring\Protocols\Flow\NetFlowV9Decoder;
use App\Domain\Monitoring\Protocols\Flow\SflowV5Decoder;
use Carbon\CarbonImmutable;

function taskTenIpv4(string $address): int
{
    return unpack('Naddress', inet_pton($address))['address'];
}

function taskTenNetFlowV5(int $count = 1, int $sequence = 1000, int $uptime = 500_000): string
{
    $header = pack('nnNNNNCCn', 5, $count, $uptime, 1_753_247_695, 0, $sequence, 1, 2, 0);
    if ($count > 1000) {
        return $header;
    }
    $record = pack(
        'N3n2N4n2C4n2C2n',
        taskTenIpv4('10.44.0.10'),
        taskTenIpv4('10.44.0.20'),
        0,
        7,
        8,
        5,
        6400,
        499_000,
        499_500,
        51_514,
        443,
        0,
        0x12,
        6,
        0,
        64_512,
        64_513,
        24,
        24,
        0,
    );

    return $header.str_repeat($record, $count);
}

/** @param list<array{0:int,1:int,2:?int}> $fields */
function taskTenTemplateRecord(int $templateId, array $fields, bool $ipfix): string
{
    $record = pack('nn', $templateId, count($fields));
    foreach ($fields as [$type, $length, $enterprise]) {
        $wireType = $enterprise === null ? $type : ($type | 0x8000);
        $record .= pack('nn', $wireType, $length);
        if ($enterprise !== null) {
            $record .= pack('N', $enterprise);
        }
    }

    return $record;
}

function taskTenFlowSet(int $setId, string $payload): string
{
    $padding = (4 - ((strlen($payload) + 4) % 4)) % 4;

    return pack('nn', $setId, strlen($payload) + 4 + $padding).$payload.str_repeat("\0", $padding);
}

/** @return list<array{0:int,1:int,2:?int}> */
function taskTenCommonFields(bool $withEnterprise = false): array
{
    $fields = [
        [8, 4, null], [12, 4, null], [7, 2, null], [11, 2, null], [4, 1, null],
        [1, 4, null], [2, 4, null], [10, 2, null], [14, 2, null],
    ];
    if ($withEnterprise) {
        $fields[] = [400, 4, 42_424];
    }

    return $fields;
}

function taskTenCommonData(bool $withEnterprise = false): string
{
    $data = inet_pton('10.44.0.10').inet_pton('10.44.0.20')
        .pack('nnC', 51_514, 443, 6)
        .pack('NNnn', 6400, 5, 7, 8);

    return $withEnterprise ? $data.pack('N', 999) : $data;
}

function taskTenNetFlowV9Template(int $sequence = 200): string
{
    $template = taskTenTemplateRecord(256, taskTenCommonFields(), false);
    $sets = taskTenFlowSet(0, $template);

    return pack('nnNNNN', 9, 1, 500_000, 1_753_247_695, $sequence, 9).$sets;
}

function taskTenNetFlowV9Data(int $sequence = 201): string
{
    $sets = taskTenFlowSet(256, taskTenCommonData());

    return pack('nnNNNN', 9, 1, 500_500, 1_753_247_696, $sequence, 9).$sets;
}

function taskTenIpfix(int $sequence = 300): string
{
    $template = taskTenFlowSet(2, taskTenTemplateRecord(300, taskTenCommonFields(true), true));
    $data = taskTenFlowSet(300, taskTenCommonData(true));
    $length = 16 + strlen($template) + strlen($data);

    return pack('nnNNN', 10, $length, 1_753_247_695, $sequence, 11).$template.$data;
}

function taskTenSflow(int $sequence = 400): string
{
    $ethernet = hex2bin('00112233445566778899aabb0800');
    $ipv4 = pack('CCnnnCCnNN', 0x45, 0, 40, 1, 0, 64, 6, 0, taskTenIpv4('10.44.0.10'), taskTenIpv4('10.44.0.20'));
    $tcp = pack('nnNNnnnn', 51_514, 443, 1, 0, 0x5012, 65_535, 0, 0);
    $frame = $ethernet.$ipv4.$tcp;
    $rawHeader = pack('NNNN', 1, strlen($frame), 0, strlen($frame)).$frame;
    $rawHeader .= str_repeat("\0", (4 - (strlen($rawHeader) % 4)) % 4);
    $record = pack('NN', 1, strlen($rawHeader)).$rawHeader;
    $sample = pack('NNNNNNNN', 1, 0, 100, 1000, 0, 7, 8, 1).$record;

    return pack('NN', 5, 1).inet_pton('10.44.0.1').pack('NNNN', 0, $sequence, 500_000, 1)
        .pack('NN', 1, strlen($sample)).$sample;
}

it('decodes every approved flow family into one common bounded record shape', function (string $family) {
    $datagram = match ($family) {
        'netflow-v5' => (new NetFlowV5Decoder)->decode(taskTenNetFlowV5(), '10.44.0.1'),
        'netflow-v9' => (function () {
            $decoder = new NetFlowV9Decoder(new FlowTemplateRegistry);
            $decoder->decode(taskTenNetFlowV9Template(), '10.44.0.1');

            return $decoder->decode(taskTenNetFlowV9Data(), '10.44.0.1');
        })(),
        'ipfix' => (new IpfixDecoder(new FlowTemplateRegistry))->decode(taskTenIpfix(), '10.44.0.1'),
        'sflow-v5' => (new SflowV5Decoder)->decode(taskTenSflow(), '10.44.0.1'),
    };

    expect($datagram->family)->toBe($family)
        ->and($datagram->records)->toHaveCount(1)
        ->and($datagram->records[0]->payload())->toMatchArray([
            'source_ip' => '10.44.0.10',
            'destination_ip' => '10.44.0.20',
            'source_port' => 51_514,
            'destination_port' => 443,
            'protocol' => 6,
            'input_interface' => 7,
            'output_interface' => 8,
        ])->and($datagram->records[0]->bytes)->toBeGreaterThan(0)
        ->and($datagram->records[0]->packets)->toBeGreaterThan(0);
})->with(['netflow-v5', 'netflow-v9', 'ipfix', 'sflow-v5']);

it('requires a NetFlow v9 template before data', function () {
    $decoder = new NetFlowV9Decoder(new FlowTemplateRegistry);

    expect(fn () => $decoder->decode(taskTenNetFlowV9Data(), '10.44.0.1'))
        ->toThrow(RuntimeException::class, 'template is unavailable');
});

it('requires an IPFIX template before data', function () {
    $data = taskTenFlowSet(300, taskTenCommonData(true));
    $packet = pack('nnNNN', 10, 16 + strlen($data), 1_753_247_695, 300, 11).$data;
    $decoder = new IpfixDecoder(new FlowTemplateRegistry);

    expect(fn () => $decoder->decode($packet, '10.44.0.1'))
        ->toThrow(RuntimeException::class, 'template is unavailable');
});

it('skips bounded enterprise fields without projecting unknown values', function () {
    $datagram = (new IpfixDecoder(new FlowTemplateRegistry))->decode(taskTenIpfix(), '10.44.0.1');

    expect($datagram->records[0]->payload())
        ->not->toHaveKey('enterprise')
        ->not->toContain(999);
});

it('rejects truncated unknown and over-record-limit packets', function (Closure $decode, string $message) {
    expect($decode)->toThrow(RuntimeException::class, $message);
})->with([
    'truncated v5' => [fn () => (new NetFlowV5Decoder)->decode(substr(taskTenNetFlowV5(), 0, -1), '10.44.0.1'), 'truncated'],
    'unknown v5 version' => [fn () => (new NetFlowV5Decoder)->decode(pack('n', 6).substr(taskTenNetFlowV5(), 2), '10.44.0.1'), 'version is unsupported'],
    'over record limit' => [fn () => (new NetFlowV5Decoder)->decode(taskTenNetFlowV5(1001), '10.44.0.1'), 'record limit'],
    'sFlow sample count' => [fn () => (new SflowV5Decoder)->decode(substr_replace(taskTenSflow(), pack('N', 1001), 24, 4), '10.44.0.1'), 'sample limit'],
]);

it('groups minute buckets and reports deterministic sequence gaps and exporter resets', function () {
    $aggregator = new FlowAggregator;
    $first = (new NetFlowV5Decoder)->decode(taskTenNetFlowV5(sequence: 1000, uptime: 500_000), '10.44.0.1');
    $gap = (new NetFlowV5Decoder)->decode(taskTenNetFlowV5(sequence: 1003, uptime: 501_000), '10.44.0.1');
    $reset = (new NetFlowV5Decoder)->decode(taskTenNetFlowV5(sequence: 1, uptime: 100), '10.44.0.1');

    $aggregate = $aggregator->aggregate(9, '10.44.0.1', $first, CarbonImmutable::parse('2026-07-23T03:34:56Z'));
    $gapHealth = $aggregator->sequenceHealth($first, $gap);
    $resetHealth = $aggregator->sequenceHealth($gap, $reset);

    expect($aggregate->buckets)->toHaveCount(1)
        ->and($aggregate->buckets[0])->toMatchArray([
            'bucket_start' => '2026-07-23T03:34:00.000000Z',
            'application' => 'https',
            'protocol' => 6,
            'bytes' => 6400,
            'packets' => 5,
            'flow_count' => 1,
        ])->and($gapHealth->status)->toBe('gap')
        ->and($gapHealth->expectedSequence)->toBe(1001)
        ->and($gapHealth->actualSequence)->toBe(1003)
        ->and($resetHealth->status)->toBe('reset');
});
