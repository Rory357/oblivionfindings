<?php

declare(strict_types=1);
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Event;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Tracer\Tracer;

/** Optional CLI test diagnostics. Never boot the application or inspect records. */
$itDiagnosticRoot = realpath(__DIR__.'/../../../..');
$itDiagnosticToken = (string) getenv('TEST_TOKEN');
$itDiagnosticPath = (string) getenv('IT_TEST_DIAGNOSTIC_PATH');
if (PHP_SAPI !== 'cli'
    || ! $itDiagnosticRoot
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
    || getenv('MAIL_MAILER') !== 'array'
    || getenv('QUEUE_CONNECTION') !== 'sync'
    || getenv('BROADCAST_CONNECTION') !== 'null'
    || preg_match('/^it_[a-f0-9]{16}$/D', $itDiagnosticToken) !== 1
    || $itDiagnosticPath !== __DIR__.DIRECTORY_SEPARATOR.$itDiagnosticToken.'.diagnostic.jsonl'
    || is_link(__DIR__)
    || file_exists($itDiagnosticPath)) {
    throw new RuntimeException('Isolated diagnostic guard failed.');
}
$itDiagnosticHandle = fopen($itDiagnosticPath, 'xb');
if (! is_resource($itDiagnosticHandle)) {
    throw new RuntimeException('Unable to open isolated diagnostic output.');
}
$itDiagnosticSequence = 0;
$itDiagnosticWrite = static function (array $metadata) use ($itDiagnosticHandle, &$itDiagnosticSequence): void {
    $line = json_encode([
        'sequence' => ++$itDiagnosticSequence,
        'utc' => gmdate(DATE_ATOM),
        'pid' => getmypid(),
        'memory_bytes' => memory_get_usage(true),
        'peak_memory_bytes' => memory_get_peak_usage(true),
        ...$metadata,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($line)) {
        fwrite($itDiagnosticHandle, $line.PHP_EOL);
        fflush($itDiagnosticHandle);
    }
};
$itDiagnosticSafeFile = static function (string $file) use ($itDiagnosticRoot): ?string {
    $normalized = str_replace('\\', '/', $file);
    $root = str_replace('\\', '/', $itDiagnosticRoot).'/';

    return str_starts_with(strtolower($normalized), strtolower($root))
        ? substr($normalized, strlen($root))
        : null;
};
// Register before Pest/Laravel so later shutdown rendering cannot hide this
// metadata. A small reserved block permits the recorder to run after memory exhaustion.
$itDiagnosticReserve = str_repeat('x', 65536);
register_shutdown_function(static function () use (&$itDiagnosticReserve, $itDiagnosticWrite, $itDiagnosticSafeFile): void {
    $itDiagnosticReserve = null;
    $error = error_get_last();
    $buffer = ob_get_contents();
    $locations = [];
    if (is_string($buffer) && str_contains($buffer, 'An error occurred inside PHPUnit.')) {
        preg_match_all('/(?:Location|Caused by): ([^\r\n]+):(\d+)\r?$/m', $buffer, $matches, PREG_SET_ORDER);
        foreach (array_slice($matches, 0, 8) as $match) {
            $locations[] = ['file' => $itDiagnosticSafeFile($match[1]), 'line' => (int) $match[2]];
        }
    }
    $itDiagnosticWrite([
        'phase' => 'early_shutdown',
        'last_error' => is_array($error) ? [
            'type' => $error['type'], 'file' => $itDiagnosticSafeFile($error['file']), 'line' => $error['line'],
        ] : null,
        'output_buffer_depth' => ob_get_level(),
        'phpunit_internal_error_buffered' => is_string($buffer) && str_contains($buffer, 'An error occurred inside PHPUnit.'),
        'internal_error_locations' => $locations,
    ]);
});
$itDiagnosticWrite(['phase' => 'prepend_started', 'memory_limit' => ini_get('memory_limit')]);
require_once $itDiagnosticRoot.'/vendor/autoload.php';
Facade::instance()->registerTracer(new class($itDiagnosticWrite, $itDiagnosticSafeFile) implements Tracer
{
    public function __construct(private Closure $write, private Closure $safeFile) {}

    public function trace(Event $event): void
    {
        $class = $event::class;
        if (! preg_match('/(?:PreparationStarted|Prepared|Finished|Passed|Failed|Errored|ExecutionStarted|ExecutionFinished)$/D', $class)) {
            return;
        }
        $metadata = ['phase' => 'phpunit_event', 'event' => $class];
        if (method_exists($event, 'test')) {
            $test = $event->test();
            if ($test instanceof TestMethod) {
                $metadata['test_file'] = ($this->safeFile)($test->file());
                $metadata['test_line'] = $test->line();
                $metadata['test_method_hash'] = hash('sha256', $test->className().'::'.$test->methodName());
            }
        }
        if (method_exists($event, 'throwable')) {
            $metadata['exception_class'] = $event->throwable()->className();
            // Locations only: assertion messages and SQL/payload details stay out.
            preg_match_all('/^([^\r\n]+\.php):(\d+)\r?$/m', $event->throwable()->stackTrace(), $matches, PREG_SET_ORDER);
            $metadata['failure_locations'] = [];
            foreach ($matches as $match) {
                $file = ($this->safeFile)($match[1]);
                if ($file !== null) {
                    $metadata['failure_locations'][] = ['file' => $file, 'line' => (int) $match[2]];
                }
            }
            $projectLocations = array_values(array_filter($metadata['failure_locations'],
                fn (array $location): bool => preg_match('~^(app|tests|database)/~', $location['file']) === 1));
            $metadata['failure_locations'] = array_slice($projectLocations ?: $metadata['failure_locations'], 0, 8);
            // Numeric SQL classification is useful without persisting SQL, bindings or text.
            if (preg_match('/^SQLSTATE\[([A-Z0-9]{5})\]:[^\r\n]{0,80}?: (\d+)\b/', $event->throwable()->message(), $sqlFailure)) {
                $metadata['sql_state'] = $sqlFailure[1];
                $metadata['driver_error'] = (int) $sqlFailure[2];
            }
        }
        if (method_exists($event, 'numberOfAssertionsPerformed')) {
            $metadata['assertions'] = $event->numberOfAssertionsPerformed();
        }
        ($this->write)($metadata);
    }
});
$itDiagnosticWrite(['phase' => 'event_tracer_registered']);
