<?php

use App\Domain\SecurityDevices\Endpoints\Services\StunRelayProbe;

/*
 * Phase A, item A2: "Test relay" sends a real STUN Binding Request (RFC 5389) and trusts only a
 * matching Binding response.
 */

it('builds a 20-byte STUN Binding Request with the magic cookie and transaction ID', function (): void {
    $transactionId = str_repeat("\xAB", 12);
    $request = StunRelayProbe::bindingRequest($transactionId);

    expect(strlen($request))->toBe(20)
        ->and(bin2hex(substr($request, 0, 8)))->toBe('000100002112a442')
        ->and(substr($request, 8))->toBe($transactionId);
});

it('accepts only a Binding response for the same transaction', function (): void {
    $transactionId = random_bytes(12);
    // XOR-MAPPED-ADDRESS (0x0020), 8 bytes: family IPv4, port and address XOR'd with the cookie.
    $attribute = pack('nn', 0x0020, 8).pack('CCn', 0, 0x01, 3478 ^ 0x2112).pack('N', 0x7F000001 ^ 0x2112A442);
    $success = pack('nnN', 0x0101, strlen($attribute), 0x2112A442).$transactionId.$attribute;
    $error = pack('nnN', 0x0111, 0, 0x2112A442).$transactionId;

    expect(StunRelayProbe::readBindingResponse($success, $transactionId))->toBe('success')
        ->and(StunRelayProbe::readBindingResponse($error, $transactionId))->toBe('error')
        ->and(StunRelayProbe::readBindingResponse($success, random_bytes(12)))->toBeNull()
        ->and(StunRelayProbe::readBindingResponse(substr($success, 0, 19), $transactionId))->toBeNull()
        ->and(StunRelayProbe::readBindingResponse(pack('nnN', 0x0101, 0, 0xDEADBEEF).$transactionId, $transactionId))->toBeNull()
        ->and(StunRelayProbe::readBindingResponse('HTTP/1.1 400 Bad Request', $transactionId))->toBeNull();
});

it('reports no answer from a relay address where nothing is listening', function (): void {
    // Find a free local TCP port, then close it so nothing listens there.
    $holder = stream_socket_server('tcp://127.0.0.1:0');
    $address = (string) stream_socket_get_name($holder, false);
    fclose($holder);
    $port = (int) substr($address, (int) strrpos($address, ':') + 1);

    $result = (new StunRelayProbe)->probe("turn:127.0.0.1:{$port}?transport=tcp", 0.5);

    expect($result['answered'])->toBeFalse()
        ->and($result['milliseconds'])->toBeNull()
        ->and($result['problem'])->toBe('no connection');
});

it('refuses an address that is not turn: or turns:', function (): void {
    expect((new StunRelayProbe)->probe('https://relay.example.test')['problem'])->toBe('not a turn: or turns: address');
});
