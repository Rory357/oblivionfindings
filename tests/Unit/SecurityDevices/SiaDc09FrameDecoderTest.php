<?php

use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09FrameDecoder;

/*
 * Phase A, item A3 (docs/security-devices/GOAL.md): the alarm receiver is checked against frames that
 * did NOT come from our own encoder.
 *
 * Reference frames: tests/test_sia_package_cases.py and tests/test_utils.py in pysiaalarm, the open SIA
 * receiver used by Home Assistant, at commit c445c15d618cb67dad9ca210ab0825c44f3466ae.
 * pysiaalarm is MIT licensed, Copyright (c) 2021 E.A. van Valkenburg. Only the sample frames and the
 * published test key are used here; no code is copied.
 *
 * Twelve of its samples carry a CRC; the seven below match CRC-16/ARC over the body. The other five
 * were edited by hand in that project and match no CRC rule, so they aren't used.
 */

dataset('pysiaalarm frames with a valid CRC', [
    'ADM-CID closing, receiver and line set' => [
        '57150047"ADM-CID"0247R4F39L4F39#4F39[#4F39|3401 01 057]',
        ['token' => 'ADM-CID', 'encrypted' => false, 'sequence' => '0247', 'receiver' => 'R4F39', 'line_prefix' => 'L4F39', 'account' => '4F39', 'data' => '#4F39|3401 01 057'],
    ],
    'SIA-DCS user code tamper restored' => [
        '97F90078"SIA-DCS"6002L0#AAA[|Nri2/JC12]_12:58:23,09-15-2021',
        ['token' => 'SIA-DCS', 'encrypted' => false, 'sequence' => '6002', 'receiver' => null, 'line_prefix' => 'L0', 'account' => 'AAA', 'data' => '|Nri2/JC12'],
    ],
    'SIA-DCS closing with an M extended-data block' => [
        '87570078"SIA-DCS"6002L0#AAA[|Nri1/CL501][M0026B9E4268B]_14:12:04,09-25-2019',
        ['token' => 'SIA-DCS', 'encrypted' => false, 'sequence' => '6002', 'account' => 'AAA', 'data' => '|Nri1/CL501', 'extended_data' => ['M0026B9E4268B']],
    ],
    'SIA-DCS closing' => [
        'E5D50078"SIA-DCS"6002L0#AAA[|Nri1/CL501]_14:12:04,09-25-2019',
        ['token' => 'SIA-DCS', 'encrypted' => false, 'sequence' => '6002', 'account' => 'AAA', 'data' => '|Nri1/CL501'],
    ],
]);

dataset('pysiaalarm encrypted frames with a valid CRC', [
    'encrypted SIA-DCS' => ['60AB0078"*SIA-DCS"5994L0#AAA[5AB718E008C616BF16F6468033A11326B0F7546CAB230910BCA10E4DEBA42283C436E4F8EFF50931070DDE36D5BB5F0C', 'SIA-DCS', '5994', 'AAA'],
    'encrypted ADM-CID' => ['D0850078"*ADM-CID"6879R0L0#123F[64F7ADD9BA9377DBF2C014BC709329BEEE345B5F20C60B7EB9D2C6B8C222666B13D0B4A114B448B0AC555FCC658DB6EE', 'ADM-CID', '6879', '123F'],
    'encrypted NULL' => ['76D80055"*NULL"0000R0L0#AAAB[B4BC8B40D0E6D959D6BEA78E88CC0B2155741A3C44FBB96D476A3E557CAD64D9', 'NULL', '0000', 'AAAB'],
]);

/** pysiaalarm's published test key (tests/test_utils.py); it decrypts the encrypted NULL sample. */
const PYSIAALARM_TEST_KEY = 'AAAAAAAAAAAAAAAA';

const ENCRYPTED_NULL_FRAME = '76D80055"*NULL"0000R0L0#AAAB[B4BC8B40D0E6D959D6BEA78E88CC0B2155741A3C44FBB96D476A3E557CAD64D9';

it('computes CRC-16/ARC with the catalogue check value', function (): void {
    expect(SiaDc09FrameDecoder::crc16Arc('123456789'))->toBe('BB3D');
});

it('reads reference frames, with the CRC over the body only', function (string $frame, array $expected): void {
    $body = substr($frame, 8);

    // The standard's rule: the CRC covers the body only. The old rule (length field + body) fails here.
    expect(SiaDc09FrameDecoder::crc16Arc($body))->toBe(substr($frame, 0, 4))
        ->and(SiaDc09FrameDecoder::crc16Arc(substr($frame, 4, 4).$body))->not->toBe(substr($frame, 0, 4));

    $decoded = (new SiaDc09FrameDecoder)->decode("\n".$frame."\r");

    expect($decoded['ok'])->toBeTrue()
        ->and($decoded['reason_code'])->toBe('decoded');
    foreach ($expected as $field => $value) {
        expect($decoded[$field])->toBe($value);
    }
})->with('pysiaalarm frames with a valid CRC');

it('reads the timestamp after any extended data, in UTC', function (): void {
    $decoded = (new SiaDc09FrameDecoder)->decode('87570078"SIA-DCS"6002L0#AAA[|Nri1/CL501][M0026B9E4268B]_14:12:04,09-25-2019');

    expect($decoded['timestamp']?->format(DATE_ATOM))->toBe('2019-09-25T14:12:04+00:00');
});

it('asks for the key before reading an encrypted reference frame', function (string $frame, string $token, string $sequence, string $account): void {
    $decoded = (new SiaDc09FrameDecoder)->decode($frame);

    expect($decoded['ok'])->toBeFalse()
        ->and($decoded['reason_code'])->toBe('encryption_key_required')
        ->and($decoded['token'])->toBe($token)
        ->and($decoded['encrypted'])->toBeTrue()
        ->and($decoded['sequence'])->toBe($sequence)
        ->and($decoded['account'])->toBe($account);
})->with('pysiaalarm encrypted frames with a valid CRC');

it('decrypts the encrypted reference NULL frame: hex straight after [ with no closing ] on the wire', function (): void {
    $decoded = (new SiaDc09FrameDecoder)->decode(ENCRYPTED_NULL_FRAME, PYSIAALARM_TEST_KEY);

    expect($decoded['ok'])->toBeTrue()
        ->and($decoded['token'])->toBe('NULL')
        ->and($decoded['encrypted'])->toBeTrue()
        ->and($decoded['account'])->toBe('AAAB')
        ->and($decoded['data'])->toBe('')
        ->and($decoded['timestamp']?->format(DATE_ATOM))->toBe('2020-07-05T18:24:52+00:00');
});

it('refuses the encrypted reference frame with a wrong key', function (): void {
    expect((new SiaDc09FrameDecoder)->decode(ENCRYPTED_NULL_FRAME, 'BBBBBBBBBBBBBBBB')['reason_code'])->toBe('decryption_failed');
});

it('refuses a frame changed in transit', function (): void {
    $tampered = str_replace('CL501', 'OP501', 'E5D50078"SIA-DCS"6002L0#AAA[|Nri1/CL501]_14:12:04,09-25-2019');

    expect((new SiaDc09FrameDecoder)->decode($tampered)['reason_code'])->toBe('crc_mismatch');
});

it('refuses the old encrypted layout that put ] after the hex', function (): void {
    $body = '"*NULL"0000R0L0#AAAB[B4BC8B40D0E6D959D6BEA78E88CC0B2155741A3C44FBB96D476A3E557CAD64D9]';
    $frame = SiaDc09FrameDecoder::crc16Arc($body).sprintf('%04X', strlen($body)).$body;

    expect((new SiaDc09FrameDecoder)->decode($frame, PYSIAALARM_TEST_KEY)['ok'])->toBeFalse();
});

it('reports an unknown message type so the receiver can answer DUH', function (): void {
    $frame = SiaDc09FrameDecoder::wrapBody('"XYZ-NEW"0042L0#AAA[]');
    $decoded = (new SiaDc09FrameDecoder)->decode($frame);

    expect($decoded['reason_code'])->toBe('unsupported_token')
        ->and($decoded['sequence'])->toBe('0042')
        ->and($decoded['account'])->toBe('AAA');
});

it('builds plain ACK, DUH and NAK frames with the CRC over the body', function (): void {
    $ack = SiaDc09FrameDecoder::buildAck('6002', null, 'L0', 'AAA');
    $duh = SiaDc09FrameDecoder::buildDuh('0247', 'R4F39', 'L4F39', '4F39');
    $nak = SiaDc09FrameDecoder::buildNak(new DateTimeImmutable('2019-09-25 14:12:04', new DateTimeZone('UTC')));

    foreach ([
        [$ack, '"ACK"6002L0#AAA[]'],
        [$duh, '"DUH"0247R4F39L4F39#4F39[]'],
        [$nak, '"NAK"0000R0L0A0[]_14:12:04,09-25-2019'],
    ] as [$frame, $body]) {
        expect($frame)->toBe("\n".SiaDc09FrameDecoder::crc16Arc($body).sprintf('%04X', strlen($body)).$body."\r");
    }
});

it('answers an encrypted frame with an encrypted ACK that the panel can read', function (): void {
    $now = new DateTimeImmutable('2020-07-05 18:24:55', new DateTimeZone('UTC'));
    $frame = SiaDc09FrameDecoder::buildEncryptedAck('0000', 'R0', 'L0', 'AAAB', PYSIAALARM_TEST_KEY, $now);

    $inner = trim($frame);
    $body = substr($inner, 8);
    expect(substr($inner, 0, 4))->toBe(SiaDc09FrameDecoder::crc16Arc($body))
        ->and(substr($inner, 4, 4))->toBe(sprintf('%04X', strlen($body)))
        ->and($body)->toStartWith('"*ACK"0000R0L0#AAAB[');

    $cipherHex = substr($body, strlen('"*ACK"0000R0L0#AAAB['));
    $plain = openssl_decrypt((string) hex2bin($cipherHex), 'aes-128-cbc', PYSIAALARM_TEST_KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\x00", 16));

    // Left-padded with 0 to whole 16-byte blocks, then `]` and our UTC time.
    expect($plain)->toBe('00000000000]_18:24:55,07-05-2020');
});

it('accepts timestamps from 40 seconds behind to 20 seconds ahead, and needs one when encrypted', function (): void {
    $at = new DateTimeImmutable('2019-09-25 14:12:04', new DateTimeZone('UTC'));
    $plain = ['encrypted' => false, 'timestamp' => $at];
    $encrypted = ['encrypted' => true, 'timestamp' => $at];

    expect(SiaDc09FrameDecoder::timestampAcceptable($plain, $at->modify('+40 seconds')))->toBeTrue()
        ->and(SiaDc09FrameDecoder::timestampAcceptable($plain, $at->modify('+41 seconds')))->toBeFalse()
        ->and(SiaDc09FrameDecoder::timestampAcceptable($plain, $at->modify('-20 seconds')))->toBeTrue()
        ->and(SiaDc09FrameDecoder::timestampAcceptable($plain, $at->modify('-21 seconds')))->toBeFalse()
        ->and(SiaDc09FrameDecoder::timestampAcceptable($encrypted, $at))->toBeTrue()
        ->and(SiaDc09FrameDecoder::timestampAcceptable(['encrypted' => true, 'timestamp' => null], $at))->toBeFalse()
        ->and(SiaDc09FrameDecoder::timestampAcceptable(['encrypted' => false, 'timestamp' => null], $at))->toBeTrue();
});
