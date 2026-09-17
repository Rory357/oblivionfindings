<?php

use Tests\Support\OneTestCaseClassPerFileScanner;

/*
 * Pest runs one TestCase class per file: the class it generates for a
 * functional file, or the last concrete TestCase a class-based file declares.
 * Any other TestCase class in the file silently never runs, which hid 13
 * spend-approval tests until d8fa284d8. The scanner only tokenizes files, so
 * this guard needs no application boot or database.
 */

function oneTestCaseClassPerFileScanner(): OneTestCaseClassPerFileScanner
{
    static $scanner = null;

    return $scanner ??= new OneTestCaseClassPerFileScanner(dirname(__DIR__));
}

it('keeps every file under tests/ to one runnable TestCase class', function (): void {
    $messages = array_column(oneTestCaseClassPerFileScanner()->violations(dirname(__DIR__)), 'message');

    expect($messages)->toBe([], "Pest would silently skip these TestCase classes:\n".implode("\n", $messages));
});

it('reports the spend approval file that hid 13 tests before it was split', function (): void {
    // Verbatim tests/Feature/Governance/SpendApprovalAuthorityTest.php from d8fa284d8^.
    $fixture = dirname(__DIR__).'/fixtures/pest/SpendApprovalAuthorityTest.pre-split.php.txt';

    $violations = oneTestCaseClassPerFileScanner()->violations($fixture);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['rule'])->toBe('multiple-test-case-classes')
        ->and($violations[0]['classes'])->toBe([
            'Tests\Feature\Governance\SpendApprovalAuthorityTest',
            'Tests\Feature\Governance\SpendApprovalConcurrencyIsolationTest',
        ])
        ->and($violations[0]['message'])->toStartWith('tests/fixtures/pest/SpendApprovalAuthorityTest.pre-split.php.txt declares 2 concrete TestCase classes');
});

it('reports exactly the TestCase classes Pest would skip', function (string $source, array $expected): void {
    $file = tempnam(sys_get_temp_dir(), 'one-test-case-class-');
    file_put_contents($file, $source);

    try {
        $violations = oneTestCaseClassPerFileScanner()->violations($file);
    } finally {
        unlink($file);
    }

    expect(array_map(
        static fn (array $violation): array => [$violation['rule'] => $violation['classes']],
        $violations,
    ))->toBe($expected);
})->with([
    'two TestCase classes in a class-based file' => [<<<'PHP'
        <?php

        namespace Fixture;

        use PHPUnit\Framework\TestCase as PhpUnitTestCase;

        abstract class FixtureTestCase extends PhpUnitTestCase {}

        final class FirstTest extends FixtureTestCase
        {
            public function test_first(): void
            {
                $helper = new class extends PhpUnitTestCase {};

                self::assertSame(PhpUnitTestCase::class, get_parent_class($helper));
            }
        }

        final class SecondTest extends \PHPUnit\Framework\TestCase
        {
            public function test_second(): void {}
        }
        PHP,
        [['multiple-test-case-classes' => ['Fixture\FirstTest', 'Fixture\SecondTest']]],
    ],
    'a #[Test] class beside it()' => [<<<'PHP'
        <?php

        use PHPUnit\Framework\Attributes\Test;
        use PHPUnit\Framework\TestCase;

        it('runs as a Pest test', function (): void {
            expect(true)->toBeTrue();
        });

        final class AttributeMarkedTest extends TestCase
        {
            #[Test]
            public function never_runs(): void {}
        }
        PHP,
        [['test-case-class-in-pest-file' => ['AttributeMarkedTest']]],
    ],
    'an @test class beside describe(), extending Tests\TestCase' => [<<<'PHP'
        <?php

        namespace Tests\Feature;

        use Tests\{TestCase};

        describe('a Pest group', function (): void {
            test('runs as a Pest test', fn () => expect(true)->toBeTrue());
        });

        class AnnotationMarkedTest extends TestCase
        {
            /**
             * @test
             */
            public function never_runs(): void {}
        }
        PHP,
        [['test-case-class-in-pest-file' => ['Tests\Feature\AnnotationMarkedTest']]],
    ],
    'test methods inherited through an abstract parent and trait' => [<<<'PHP'
        <?php

        use PHPUnit\Framework\TestCase;

        trait SharedContractTests
        {
            public function test_shared_contract(): void {}
        }

        abstract class SharedContractTest extends TestCase
        {
            use SharedContractTests;
        }

        final class MysqlContractTest extends SharedContractTest {}

        test('runs as a Pest test', fn () => expect(true)->toBeTrue());
        PHP,
        [['test-case-class-in-pest-file' => ['MysqlContractTest']]],
    ],
    'helper classes beside Pest tests' => [<<<'PHP'
        <?php

        use PHPUnit\Framework\TestCase;
        use Tests\TestCase as LaravelTestCase;

        final class BootstrapHarness extends LaravelTestCase
        {
            public function prepare(): void {}

            protected function testDatabaseName(): string
            {
                return 'harness';
            }
        }

        abstract class AbstractSharedTest extends TestCase
        {
            public function test_shared(): void {}
        }

        final class CountingFake implements Countable
        {
            public function count(): int
            {
                return 0;
            }
        }

        it('uses the helpers', function (): void {
            $anonymous = new class('name') extends TestCase {
                public function test_anonymous(): void {}
            };

            expect(BootstrapHarness::class)->toBeString()
                ->and($anonymous)->toBeInstanceOf(TestCase::class);
        });
        PHP,
        [],
    ],
    'a TestCase lookalike resolved through its namespace' => [<<<'PHP'
        <?php

        namespace Fixture;

        final class LookalikeTest extends TestCase
        {
            public function test_lookalike(): void {}
        }

        final class RealTest extends \PHPUnit\Framework\TestCase
        {
            public function test_real(): void {}
        }
        PHP,
        [],
    ],
]);
