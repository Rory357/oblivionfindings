<?php

namespace Tests\Unit;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FrontlineFixtureResetDiagnostic;

final class FrontlineFixtureResetDiagnosticTest extends TestCase
{
    public function test_query_failure_exposes_only_sqlstate_driver_and_constraint_metadata(): void
    {
        $previous = new PDOException('Cannot delete private-person-value: CONSTRAINT "fixture_parent_fk" failed.');
        $previous->errorInfo = ['23000', 1451, 'private-person-value'];
        $exception = new QueryException('mysql', 'delete from private_table where personal_note = ?', ['private-bound-secret'], $previous);

        self::assertSame([
            'ok' => false,
            'exception_class' => QueryException::class,
            'sqlstate' => '23000',
            'driver_code' => 1451,
            'constraint' => 'fixture_parent_fk',
        ], FrontlineFixtureResetDiagnostic::describe($exception));
        $json = json_encode(FrontlineFixtureResetDiagnostic::describe($exception), JSON_THROW_ON_ERROR);
        foreach (['private-person-value', 'private-bound-secret', 'private_table', 'personal_note', 'delete from'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function test_validation_failure_exposes_field_names_without_private_messages(): void
    {
        $validator = $this->createMock(Validator::class);
        $validator->method('errors')->willReturn(new MessageBag(['attendance' => ['Private attendance details.']]));
        $exception = new ValidationException($validator);

        self::assertSame([
            'ok' => false,
            'exception_class' => ValidationException::class,
            'validation_fields' => ['attendance'],
        ], FrontlineFixtureResetDiagnostic::describe($exception));
    }

    public function test_other_exceptions_do_not_emit_their_message_or_stack(): void
    {
        self::assertSame([
            'ok' => false,
            'exception_class' => RuntimeException::class,
        ], FrontlineFixtureResetDiagnostic::describe(new RuntimeException('A private person and a secret credential.')));
        self::assertSame('retained_medication_evidence', FrontlineFixtureResetDiagnostic::describe(
            new LogicException('Medication browser fixture reset cannot erase paper reconciliation evidence.'),
        )['reason']);
    }

    public function test_clock_identity_failure_has_a_stable_safe_reason_without_private_context(): void
    {
        $diagnostic = FrontlineFixtureResetDiagnostic::describe(new LogicException(
            'Attendance-backed time-entry clock-in provenance conflicts. private-person clock details',
        ));
        self::assertSame([
            'ok' => false,
            'exception_class' => LogicException::class,
            'reason' => 'attendance_clock_identity_conflict',
        ], $diagnostic);
        self::assertStringNotContainsString('private-person', json_encode($diagnostic, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('databaseContexts')]
    public function test_database_guard_accepts_only_dedicated_disposable_contexts(
        string $environment, string $connection, string $database, string $url, bool $allowed,
    ): void {
        if (! $allowed) {
            $this->expectException(LogicException::class);
        }
        FrontlineFixtureResetDiagnostic::assertDisposableContext($environment, $connection, $database, $url);
        if ($allowed) {
            self::assertTrue(true);
        }
    }

    public static function databaseContexts(): iterable
    {
        yield 'CI visual' => ['local', 'mysql', 'oblivion_findings_visual', '', true];
        yield 'owned numeric process schema' => ['testing', 'mysql', 'oblivion_findings_codex_test_frontline_12345', '', true];
        yield 'production' => ['production', 'mysql', 'oblivion_findings_visual', '', false];
        yield 'shared app database' => ['testing', 'mysql', 'oblivion_findings', '', false];
        yield 'shared test base' => ['testing', 'mysql', 'oblivion_findings_codex_test', '', false];
        yield 'unnumbered lane base' => ['testing', 'mysql', 'oblivion_findings_codex_test_frontline', '', false];
        yield 'different connection' => ['testing', 'sqlite', 'oblivion_findings_visual', '', false];
        yield 'URL redirect' => ['testing', 'mysql', 'oblivion_findings_visual', 'mysql://example.invalid/another_database', false];
    }
}
