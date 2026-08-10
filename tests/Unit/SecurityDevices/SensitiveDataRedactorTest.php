<?php

use App\Logging\ConfigureSensitiveDataRedaction;
use App\Support\Security\SensitiveDataRedactor;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

it('recursively redacts credential keys headers URLs JSON and exception messages', function () {
    $redactor = new SensitiveDataRedactor;
    $sentinel = 'm05-reusable-secret-sentinel';
    $context = $redactor->context([
        'site_id' => 9,
        'password' => $sentinel,
        'nested' => [
            'Authorization' => 'Bearer '.$sentinel,
            'safe_message' => 'token='.$sentinel,
        ],
        'exception' => new RuntimeException('password='.$sentinel),
    ]);
    $serialised = json_encode($context, JSON_THROW_ON_ERROR);

    expect($context['site_id'])->toBe(9)
        ->and($serialised)->not->toContain($sentinel)
        ->and($serialised)->toContain(SensitiveDataRedactor::REDACTED)
        ->and($context['exception'])->toHaveKeys([
            'exception_class', 'exception_message', 'exception_code', 'trace_hash',
        ]);

    $message = $redactor->message(
        'Bearer '.$sentinel.' https://admin:'.$sentinel.'@device.test?token='.$sentinel
        .' {"private_key":"'.$sentinel.'"}',
    );
    expect($message)->not->toContain($sentinel)
        ->and($message)->toContain(SensitiveDataRedactor::REDACTED);
});

it('redacts the final formatted Monolog record before any configured handler writes it', function () {
    $stream = fopen('php://memory', 'w+');
    $logger = new Logger('credential-redaction-test');
    $logger->pushHandler(new StreamHandler($stream, Level::Debug));
    (new ConfigureSensitiveDataRedaction)($logger);
    $sentinel = 'm05-handler-secret-sentinel';

    $logger->error('API failed with token='.$sentinel, [
        'password' => $sentinel,
        'authorization' => 'Bearer '.$sentinel,
        'exception' => new RuntimeException('lease_id='.$sentinel),
        'site_id' => 9,
    ]);
    rewind($stream);
    $written = stream_get_contents($stream);
    fclose($stream);

    expect($written)->not->toContain($sentinel)
        ->and($written)->toContain(SensitiveDataRedactor::REDACTED, 'site_id');
});

it('attaches the mandatory redaction tap to every operational log channel', function () {
    $source = file_get_contents(__DIR__.'/../../../config/logging.php');

    expect(substr_count($source, "'tap' => \$sensitiveDataTap"))->toBe(9)
        ->and($source)->toContain('ConfigureSensitiveDataRedaction::class');
});
