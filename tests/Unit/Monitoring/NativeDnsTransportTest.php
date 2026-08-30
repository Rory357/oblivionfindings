<?php

use App\Domain\Monitoring\Data\DnsTransportResult;
use App\Domain\Monitoring\Transports\NativeDnsTransport;

const TASK_SIX_DNS_ID = 0x4A21;

function taskSixDnsName(string $name): string
{
    return taskSixDnsLabels(explode('.', rtrim($name, '.')));
}

/** @param list<string> $labels */
function taskSixDnsLabels(array $labels): string
{
    $wire = '';
    foreach ($labels as $label) {
        $wire .= chr(strlen($label)).$label;
    }

    return $wire."\0";
}

function taskSixDnsQuestion(string $name = 'service.example', int $type = 1, int $class = 1): string
{
    return taskSixDnsName($name).pack('nn', $type, $class);
}

function taskSixDnsRecord(string $owner, int $type, string $rdata, int $class = 1): string
{
    return $owner.pack('nnNn', $type, $class, 60, strlen($rdata)).$rdata;
}

/**
 * @param  list<string>  $answers
 * @param  list<string>  $authority
 * @param  list<string>  $additional
 */
function taskSixDnsMessage(
    string $question,
    array $answers = [],
    int $flags = 0x8180,
    int $id = TASK_SIX_DNS_ID,
    int $questionCount = 1,
    ?int $answerCount = null,
    array $authority = [],
    array $additional = [],
): string {
    return pack(
        'nnnnnn',
        $id,
        $flags,
        $questionCount,
        $answerCount ?? count($answers),
        count($authority),
        count($additional),
    ).$question.implode('', $answers).implode('', $authority).implode('', $additional);
}

function taskSixDecode(
    string $packet,
    string $name = 'service.example',
    int $type = 1,
    int $queryId = TASK_SIX_DNS_ID,
): DnsTransportResult {
    $method = new ReflectionMethod(NativeDnsTransport::class, 'decode');

    /** @var DnsTransportResult $result */
    $result = $method->invoke(new NativeDnsTransport, $packet, $queryId, $name, $type, 17);

    return $result;
}

it('accepts direct matching-owner records for every supported DNS type', function (
    int $type,
    string $rdata,
    string $expected,
) {
    $packet = taskSixDnsMessage(
        taskSixDnsQuestion(type: $type),
        [taskSixDnsRecord(taskSixDnsName('service.example'), $type, $rdata)],
    );

    $result = taskSixDecode($packet, type: $type);

    expect($result->answered)->toBeTrue()
        ->and($result->answers)->toBe([$expected])
        ->and($result->reasonCode)->toBe('answer')
        ->and($result->latencyMs)->toBe(17);
})->with([
    'A' => [1, inet_pton('10.44.5.20'), '10.44.5.20'],
    'AAAA' => [28, inet_pton('2001:db8:44::20'), '2001:db8:44::20'],
    'CNAME' => [5, taskSixDnsName('canonical.example'), 'canonical.example'],
    'MX' => [15, pack('n', 10).taskSixDnsName('mail.example'), '10 mail.example'],
    'TXT' => [16, chr(8).'governed', 'governed'],
]);

it('matches names case-insensitively and treats the trailing root dot canonically', function () {
    $packet = taskSixDnsMessage(
        taskSixDnsQuestion('SeRvIcE.ExAmPlE'),
        [taskSixDnsRecord(taskSixDnsName('SERVICE.EXAMPLE'), 1, inet_pton('10.44.5.20'))],
    );

    expect(taskSixDecode($packet, 'service.example.')->answers)->toBe(['10.44.5.20']);
});

it('accepts an address only through a bounded in-packet CNAME chain', function () {
    $packet = taskSixDnsMessage(taskSixDnsQuestion(), [
        taskSixDnsRecord(taskSixDnsName('service.example'), 5, taskSixDnsName('alias.example')),
        taskSixDnsRecord(taskSixDnsName('alias.example'), 5, taskSixDnsName('canonical.example')),
        taskSixDnsRecord(taskSixDnsName('canonical.example'), 1, inet_pton('10.44.5.20')),
    ]);

    expect(taskSixDecode($packet)->answers)->toBe(['10.44.5.20']);
});

it('preserves ordinary name compression across a CNAME-linked address answer', function () {
    $question = taskSixDnsQuestion();
    $questionOwner = pack('n', 0xC00C);
    $questionExampleLabel = pack('n', 0xC014);
    $compressedAlias = chr(5).'alias'.$questionExampleLabel;
    $packet = taskSixDnsMessage($question, [
        taskSixDnsRecord($questionOwner, 5, $compressedAlias),
        taskSixDnsRecord(taskSixDnsName('alias.example'), 1, inet_pton('10.44.5.20')),
    ]);

    expect(taskSixDecode($packet)->answers)->toBe(['10.44.5.20']);
});

it('preserves a compressed MX exchange name', function () {
    $compressedExchange = chr(4).'mail'.pack('n', 0xC014);
    $packet = taskSixDnsMessage(
        taskSixDnsQuestion(type: 15),
        [taskSixDnsRecord(pack('n', 0xC00C), 15, pack('n', 10).$compressedExchange)],
    );

    expect(taskSixDecode($packet, type: 15)->answers)->toBe(['10 mail.example']);
});

it('accepts a compressed matching owner for the longest legal DNS name', function () {
    $name = implode('.', array_fill(0, 127, 'a'));
    $packet = taskSixDnsMessage(
        taskSixDnsQuestion($name),
        [taskSixDnsRecord(pack('n', 0xC00C), 1, inet_pton('10.44.5.20'))],
    );

    expect(strlen(taskSixDnsName($name)))->toBe(255)
        ->and(taskSixDecode($packet, $name)->answers)->toBe(['10.44.5.20']);
});

it('does not treat unrelated, wrong-type, or non-IN answer records as evidence', function (array $answers) {
    $result = taskSixDecode(taskSixDnsMessage(taskSixDnsQuestion(), $answers));

    expect($result->answered)->toBeFalse()
        ->and($result->answers)->toBe([])
        ->and($result->reasonCode)->toBe('no_answer');
})->with([
    'unrelated direct owner' => [[
        taskSixDnsRecord(taskSixDnsName('other.example'), 1, inet_pton('10.44.5.20')),
    ]],
    'unlinked CNAME owner' => [[
        taskSixDnsRecord(taskSixDnsName('other.example'), 5, taskSixDnsName('alias.example')),
        taskSixDnsRecord(taskSixDnsName('alias.example'), 1, inet_pton('10.44.5.20')),
    ]],
    'wrong answer type' => [[
        taskSixDnsRecord(taskSixDnsName('service.example'), 28, inet_pton('2001:db8:44::20')),
    ]],
    'non-IN direct answer' => [[
        taskSixDnsRecord(taskSixDnsName('service.example'), 1, inet_pton('10.44.5.20'), class: 3),
    ]],
    'non-IN CNAME link' => [[
        taskSixDnsRecord(
            taskSixDnsName('service.example'),
            5,
            taskSixDnsName('alias.example'),
            class: 3,
        ),
        taskSixDnsRecord(taskSixDnsName('alias.example'), 1, inet_pton('10.44.5.20')),
    ]],
]);

it('accepts the exact 64-answer boundary without truncating it', function () {
    $unrelated = taskSixDnsRecord(taskSixDnsName('other.example'), 1, inet_pton('10.44.5.19'));
    $answers = array_fill(0, 63, $unrelated);
    $answers[] = taskSixDnsRecord(taskSixDnsName('service.example'), 1, inet_pton('10.44.5.20'));
    $packet = taskSixDnsMessage(taskSixDnsQuestion(), $answers);

    $result = taskSixDecode($packet);

    expect($result->answered)->toBeTrue()
        ->and($result->answers)->toBe(['10.44.5.20'])
        ->and($result->reasonCode)->toBe('answer');
});

it('keeps distinct numeric-looking DNS labels from collapsing as PHP array keys', function (
    string $requestedName,
    string $answerOwner,
    array $expected,
) {
    $packet = taskSixDnsMessage(
        taskSixDnsQuestion($requestedName),
        [taskSixDnsRecord(taskSixDnsName($answerOwner), 1, inet_pton('10.44.5.20'))],
    );

    expect(taskSixDecode($packet, $requestedName)->answers)->toBe($expected);
})->with([
    'zero matches itself' => ['0', '0', ['10.44.5.20']],
    'zero does not match negative zero' => ['0', '-0', []],
    'negative zero does not match zero' => ['-0', '0', []],
]);

it('does not collapse a literal dot inside one owner label with two DNS labels', function () {
    $packet = taskSixDnsMessage(
        taskSixDnsQuestion('a.b'),
        [taskSixDnsRecord(taskSixDnsLabels(['a.b']), 1, inet_pton('10.44.5.20'))],
    );

    $result = taskSixDecode($packet, 'a.b');

    expect($result->answered)->toBeFalse()
        ->and($result->answers)->toBe([])
        ->and($result->reasonCode)->toBe('no_answer');
});

it('rejects a literal-dot question collision before interpreting NXDOMAIN', function () {
    $question = taskSixDnsLabels(['a.b']).pack('nn', 1, 1);
    $result = taskSixDecode(taskSixDnsMessage($question, flags: 0x8183), 'a.b');

    expect($result->answered)->toBeFalse()
        ->and($result->answers)->toBe([])
        ->and($result->reasonCode)->toBe('malformed_response');
});

it('does not authorize a two-label answer through a one-label literal-dot CNAME target', function () {
    $packet = taskSixDnsMessage(taskSixDnsQuestion('q'), [
        taskSixDnsRecord(taskSixDnsName('q'), 5, taskSixDnsLabels(['x.y'])),
        taskSixDnsRecord(taskSixDnsName('x.y'), 1, inet_pton('10.44.5.20')),
    ]);

    $result = taskSixDecode($packet, 'q');

    expect($result->answered)->toBeFalse()
        ->and($result->answers)->toBe([])
        ->and($result->reasonCode)->toBe('no_answer');
});

it('presents boundary-sensitive CNAME labels unambiguously', function (array $labels, string $expected) {
    $packet = taskSixDnsMessage(
        taskSixDnsQuestion('q', type: 5),
        [taskSixDnsRecord(taskSixDnsName('q'), 5, taskSixDnsLabels($labels))],
    );

    expect(taskSixDecode($packet, 'q', type: 5)->answers)->toBe([$expected]);
})->with([
    'literal dot' => [['x.y'], 'x\.y'],
    'literal backslash' => [['x\\y'], 'x\\\\y'],
    'nonprintable octet' => [["x\x01y"], 'x\\001y'],
]);

it('presents a literal-dot MX exchange label unambiguously', function () {
    $packet = taskSixDnsMessage(
        taskSixDnsQuestion('q', type: 15),
        [taskSixDnsRecord(
            taskSixDnsName('q'),
            15,
            pack('n', 10).taskSixDnsLabels(['mail.example']),
        )],
    );

    expect(taskSixDecode($packet, 'q', type: 15)->answers)->toBe(['10 mail\.example']);
});

it('validates response and question identity before accepting any status or answer', function (
    string $packet,
    int $queryId = TASK_SIX_DNS_ID,
) {
    $result = taskSixDecode($packet, queryId: $queryId);

    expect($result->answered)->toBeFalse()
        ->and($result->answers)->toBe([])
        ->and($result->reasonCode)->toBe('malformed_response');
})->with([
    'wrong transaction id' => [taskSixDnsMessage(taskSixDnsQuestion()), TASK_SIX_DNS_ID + 1],
    'wrong transaction id nxdomain' => [
        taskSixDnsMessage(taskSixDnsQuestion(), flags: 0x8183),
        TASK_SIX_DNS_ID + 1,
    ],
    'not a response' => [taskSixDnsMessage(taskSixDnsQuestion(), flags: 0x0180)],
    'non-response nxdomain' => [taskSixDnsMessage(taskSixDnsQuestion(), flags: 0x0183)],
    'non-query opcode' => [taskSixDnsMessage(taskSixDnsQuestion(), flags: 0x8980)],
    'non-query opcode nxdomain' => [taskSixDnsMessage(taskSixDnsQuestion(), flags: 0x8983)],
    'truncated response' => [taskSixDnsMessage(taskSixDnsQuestion(), flags: 0x8380)],
    'truncated nxdomain' => [taskSixDnsMessage(taskSixDnsQuestion(), flags: 0x8383)],
    'zero questions' => [taskSixDnsMessage('', questionCount: 0)],
    'zero-question nxdomain' => [taskSixDnsMessage('', flags: 0x8183, questionCount: 0)],
    'multiple questions' => [taskSixDnsMessage(taskSixDnsQuestion().taskSixDnsQuestion(), questionCount: 2)],
    'multiple-question nxdomain' => [taskSixDnsMessage(
        taskSixDnsQuestion().taskSixDnsQuestion(),
        flags: 0x8183,
        questionCount: 2,
    )],
    'wrong question name' => [taskSixDnsMessage(taskSixDnsQuestion('other.example'))],
    'wrong question type' => [taskSixDnsMessage(taskSixDnsQuestion(type: 28))],
    'wrong question type nxdomain' => [taskSixDnsMessage(taskSixDnsQuestion(type: 28), flags: 0x8183)],
    'wrong question class' => [taskSixDnsMessage(taskSixDnsQuestion(class: 3))],
    'wrong question class nxdomain' => [taskSixDnsMessage(taskSixDnsQuestion(class: 3), flags: 0x8183)],
    'wrong-question nxdomain' => [taskSixDnsMessage(taskSixDnsQuestion('other.example'), flags: 0x8183)],
    'more than 64 answers' => [taskSixDnsMessage(taskSixDnsQuestion(), answerCount: 65)],
    'nxdomain missing its declared answer' => [taskSixDnsMessage(
        taskSixDnsQuestion(),
        flags: 0x8183,
        answerCount: 1,
    )],
    'servfail with undeclared trailing bytes' => [taskSixDnsMessage(
        taskSixDnsQuestion(),
        flags: 0x8182,
    )."\0"],
]);

it('preserves exact-question NXDOMAIN and server-failure results', function (int $flags, string $reason) {
    $result = taskSixDecode(taskSixDnsMessage(taskSixDnsQuestion(), flags: $flags));

    expect($result->answered)->toBeFalse()
        ->and($result->answers)->toBe([])
        ->and($result->reasonCode)->toBe($reason);
})->with([
    'nxdomain' => [0x8183, 'nxdomain'],
    'servfail' => [0x8182, 'server_failure'],
]);

it('fails closed on cyclic and conflicting CNAME chains', function (array $answers) {
    $result = taskSixDecode(taskSixDnsMessage(taskSixDnsQuestion(), $answers));

    expect($result->answered)->toBeFalse()
        ->and($result->answers)->toBe([])
        ->and($result->reasonCode)->toBe('malformed_response');
})->with([
    'cycle' => [[
        taskSixDnsRecord(taskSixDnsName('service.example'), 5, taskSixDnsName('alias.example')),
        taskSixDnsRecord(taskSixDnsName('alias.example'), 5, taskSixDnsName('service.example')),
    ]],
    'conflicting targets' => [[
        taskSixDnsRecord(taskSixDnsName('service.example'), 5, taskSixDnsName('one.example')),
        taskSixDnsRecord(taskSixDnsName('service.example'), 5, taskSixDnsName('two.example')),
    ]],
]);

it('fails closed on malformed compression pointers and record envelopes', function (string $packet) {
    $result = taskSixDecode($packet);

    expect($result->answered)->toBeFalse()
        ->and($result->answers)->toBe([])
        ->and($result->reasonCode)->toBe('malformed_response');
})->with(function (): array {
    $question = taskSixDnsQuestion();
    $answerOffset = 12 + strlen($question);
    $selfPointer = pack('n', 0xC000 | $answerOffset);
    $shortRdata = taskSixDnsName('service.example').pack('nnNn', 1, 1, 60, 4)."\x0A\x2C\x05";
    $cnameOwner = taskSixDnsName('service.example');
    $cnameRdataOffset = $answerOffset + strlen($cnameOwner) + 10;
    $cyclicCnameRdata = taskSixDnsRecord($cnameOwner, 5, pack('n', 0xC000 | $cnameRdataOffset));
    $overlongCnameRdata = taskSixDnsRecord($cnameOwner, 5, taskSixDnsName('alias.example')."\0");
    $forwardAnswerTarget = $answerOffset + 2 + 10 + 4;
    $forwardAnswerOwner = pack('n', 0xC000 | $forwardAnswerTarget)
        .pack('nnNn', 1, 1, 60, 4)
        .inet_pton('10.44.5.20');
    $forwardAnswerPacket = taskSixDnsMessage(
        $question,
        [$forwardAnswerOwner],
        additional: [taskSixDnsRecord(taskSixDnsName('service.example'), 41, '')],
    );
    $forwardCompressedQuestion = pack('nnnnnn', TASK_SIX_DNS_ID, 0x8183, 1, 0, 0, 1)
        .pack('n', 0xC012)
        .pack('nn', 1, 1)
        .taskSixDnsName('service.example')
        .pack('nnNn', 41, 1, 0, 0);

    return [
        'cyclic question pointer' => [taskSixDnsMessage(pack('n', 0xC00C).pack('nn', 1, 1))],
        'out-of-range question pointer' => [taskSixDnsMessage("\xFF\xFF".pack('nn', 1, 1))],
        'forward-compressed question' => [$forwardCompressedQuestion],
        'out-of-range answer-owner pointer' => [taskSixDnsMessage($question, [
            "\xFF\xFF".pack('nnNn', 1, 1, 60, 4).inet_pton('10.44.5.20'),
        ])],
        'forward-compressed answer owner' => [$forwardAnswerPacket],
        'cyclic answer-owner pointer' => [taskSixDnsMessage($question, [
            $selfPointer.pack('nnNn', 1, 1, 60, 4).inet_pton('10.44.5.20'),
        ])],
        'short answer rdata' => [taskSixDnsMessage($question, [$shortRdata])],
        'cyclic CNAME rdata pointer' => [taskSixDnsMessage($question, [$cyclicCnameRdata])],
        'CNAME rdata with undeclared suffix' => [taskSixDnsMessage($question, [$overlongCnameRdata])],
        'missing declared additional record' => [pack('nnnnnn', TASK_SIX_DNS_ID, 0x8180, 1, 0, 0, 1).$question],
        'undeclared trailing bytes' => [taskSixDnsMessage($question)."\0"],
    ];
});

it('keeps UDP queries connected to each exact authorized numeric resolver and gives each attempt a fresh id', function () {
    $source = file_get_contents(
        str_replace('\\', '/', dirname(__DIR__, 3)).'/app/Domain/Monitoring/Transports/NativeDnsTransport.php',
    );

    $addressLoop = strpos($source, 'foreach ($target->addresses as $address)');
    $queryId = strpos($source, '$queryId = random_int(1, 65535);');
    $packet = strpos($source, '$packet = pack(\'nnnnnn\', $queryId');
    $endpoint = strpos($source, '$endpoint = sprintf(\'udp://%s:%d\'');
    $client = strpos($source, '$socket = @stream_socket_client(');
    $decode = strpos($source, 'return $this->decode($response, $queryId, $name, $typeCode, $latency);');

    expect($source)
        ->toContain(
            '$packet = pack(\'nnnnnn\', $queryId, 0x0100, 1, 0, 0, 0).$question;',
            '$endpoint = sprintf(\'udp://%s:%d\', str_contains($address, \':\')',
            '$socket = @stream_socket_client(',
            '$endpoint,',
            'STREAM_CLIENT_CONNECT',
            'return $this->decode($response, $queryId, $name, $typeCode, $latency);',
        )
        ->not->toContain('stream_socket_server(')
        ->and($addressLoop)->not->toBeFalse()
        ->and($queryId)->not->toBeFalse()
        ->and($packet)->not->toBeFalse()
        ->and($endpoint)->not->toBeFalse()
        ->and($client)->not->toBeFalse()
        ->and($decode)->not->toBeFalse()
        ->and($queryId)->toBeGreaterThan($addressLoop)
        ->and($packet)->toBeGreaterThan($queryId)
        ->and($endpoint)->toBeGreaterThan($packet)
        ->and($client)->toBeGreaterThan($endpoint)
        ->and($decode)->toBeGreaterThan($client);
});
