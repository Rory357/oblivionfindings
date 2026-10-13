<?php

use App\Domain\Obli\ObliChatGateway;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('rejects a present empty or null knowledge binding before authentication or database access', function () {
    $gateway = (new ReflectionClass(ObliChatGateway::class))->newInstanceWithoutConstructor();
    foreach ([[], null, ['article_id' => 1], ['version' => str_repeat('a', 64)]] as $binding) {
        try {
            $gateway->send(null, ['request_uuid' => '11111111-1111-4111-8111-111111111111', 'configuration_version' => 1,
                'messages' => [['role' => 'user', 'content' => 'Printer help', 'knowledge' => $binding]]]);
            $this->fail('Invalid binding was accepted.');
        } catch (ValidationException $exception) {
            expect($exception->status)->toBe(422);
            expect($exception->errors())->toHaveKey('messages.0.knowledge');
        }
    }
});
