<?php

namespace Tests\Support;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use InvalidArgumentException;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Finds test files declaring TestCase classes that Pest will never run.
 *
 * Pest's TestSuiteLoader includes a test file and keeps one non-abstract
 * TestCase subclass from it: the class Pest generates for a functional file
 * (top-level it()/test()/describe()), otherwise the last one declared. Every
 * other concrete TestCase class in that file is skipped without a warning.
 *
 * Files are read with the tokenizer and never included. Classes declared
 * under tests/ are resolved from the parsed files, never autoloaded: loading
 * a test file before Pest does would hide its tests from Pest in the same
 * process.
 */
final class OneTestCaseClassPerFileScanner
{
    private const PEST_TEST_FUNCTIONS = ['it', 'test', 'describe', 'todo', 'arch'];

    private const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    private const NO_MEMBER = ['doc' => null, 'attributes' => [], 'modifiers' => []];

    private readonly string $testsDirectory;

    /** @var array<string, array{file: string, pest: bool, declarations: list<array<string, mixed>>}>|null */
    private ?array $index = null;

    /** @var array<string, list<array<string, mixed>>> */
    private array $indexByName = [];

    /** @var array<string, bool> */
    private array $externalTestCases = [];

    public function __construct(string $testsDirectory)
    {
        $directory = realpath($testsDirectory);

        if ($directory === false || ! is_dir($directory)) {
            throw new InvalidArgumentException("Tests directory [{$testsDirectory}] does not exist.");
        }

        $this->testsDirectory = $directory;
    }

    /**
     * Scan one file (any extension) or every *.php file below a directory.
     *
     * @return list<array{file: string, rule: string, classes: list<string>, message: string}>
     */
    public function violations(string $path): array
    {
        $this->buildIndex();

        $analyses = array_map(
            fn (string $file): array => $this->index[$file] ?? $this->analyse($file),
            $this->phpFiles($path),
        );
        $declarations = $this->declarationsByName($analyses);
        $violations = [];

        foreach ($analyses as $analysis) {
            array_push($violations, ...$this->fileViolations($analysis, $declarations));
        }

        return $violations;
    }

    /**
     * @param  array{file: string, pest: bool, declarations: list<array<string, mixed>>}  $analysis
     * @param  array<string, list<array<string, mixed>>>  $declarations
     * @return list<array{file: string, rule: string, classes: list<string>, message: string}>
     */
    private function fileViolations(array $analysis, array $declarations): array
    {
        $file = $this->displayPath($analysis['file']);
        $testCases = array_values(array_filter(
            $analysis['declarations'],
            fn (array $declaration): bool => $declaration['kind'] === T_CLASS
                && ! $declaration['abstract']
                && $this->extendsTestCase($declaration, $declarations),
        ));
        $violations = [];

        if (count($testCases) > 1) {
            $violations[] = [
                'file' => $file,
                'rule' => 'multiple-test-case-classes',
                'classes' => array_column($testCases, 'name'),
                'message' => sprintf(
                    '%s declares %d concrete TestCase classes (%s). Pest runs one TestCase class per file and silently skips the rest: give each class its own file.',
                    $file,
                    count($testCases),
                    implode(', ', array_map(
                        static fn (array $declaration): string => "{$declaration['name']} on line {$declaration['line']}",
                        $testCases,
                    )),
                ),
            ];
        }

        if ($analysis['pest']) {
            $hidden = [];

            foreach ($testCases as $declaration) {
                $methods = $this->testMethods($declaration, $declarations);

                if ($methods !== []) {
                    $hidden[$declaration['name']] = "{$declaration['name']} on line {$declaration['line']} (".implode(', ', $methods).')';
                }
            }

            if ($hidden !== []) {
                $violations[] = [
                    'file' => $file,
                    'rule' => 'test-case-class-in-pest-file',
                    'classes' => array_keys($hidden),
                    'message' => sprintf(
                        '%s calls it()/test()/describe() and also declares TestCase classes with test methods: %s. Pest runs only the class it generates for the file, so those methods never run: move each class into its own file.',
                        $file,
                        implode('; ', $hidden),
                    ),
                ];
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @param  array<string, list<array<string, mixed>>>  $declarations
     * @param  array<string, true>  $seen
     */
    private function extendsTestCase(array $declaration, array $declarations, array $seen = []): bool
    {
        $parent = $declaration['parent'];

        if ($parent === null) {
            return false;
        }

        if (strcasecmp($parent, TestCase::class) === 0) {
            return true;
        }

        $key = strtolower($parent);

        if (isset($seen[$key])) {
            return false;
        }

        $parentDeclaration = $this->findDeclaration($parent, $declaration['file'], $declarations);

        if ($parentDeclaration !== null) {
            return $this->extendsTestCase($parentDeclaration, $declarations, [...$seen, $key => true]);
        }

        return $this->externalTestCases[$key] ??= $this->autoloadStaysOutsideTests($parent)
            && $this->isSubclassOfTestCase($parent);
    }

    /**
     * Test methods a class declares itself or picks up from parents and traits
     * declared in the scanned files.
     *
     * @param  array<string, mixed>  $declaration
     * @param  array<string, list<array<string, mixed>>>  $declarations
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private function testMethods(array $declaration, array $declarations, array $seen = []): array
    {
        $seen[strtolower($declaration['name'])] = true;
        $methods = $declaration['testMethods'];
        $related = $declaration['parent'] === null
            ? $declaration['traits']
            : [...$declaration['traits'], $declaration['parent']];

        foreach ($related as $name) {
            $relatedDeclaration = isset($seen[strtolower($name)])
                ? null
                : $this->findDeclaration($name, $declaration['file'], $declarations);

            if ($relatedDeclaration !== null) {
                $methods = [...$methods, ...$this->testMethods($relatedDeclaration, $declarations, $seen)];
            }
        }

        return array_values(array_unique($methods));
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $declarations
     * @return array<string, mixed>|null
     */
    private function findDeclaration(string $class, string $preferredFile, array $declarations): ?array
    {
        $candidates = $declarations[strtolower($class)] ?? [];

        foreach ($candidates as $candidate) {
            if ($candidate['file'] === $preferredFile) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Composer maps Tests\ to tests/, so autoloading an unindexed name there
     * would include a test file early. Refuse instead of guessing.
     */
    private function autoloadStaysOutsideTests(string $class): bool
    {
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return true;
        }

        $testsPrefix = $this->normalisePath($this->testsDirectory).'/';

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($class);

            if ($file !== false) {
                $resolved = realpath($file);

                return $resolved !== false && ! str_starts_with($this->normalisePath($resolved), $testsPrefix);
            }
        }

        return true;
    }

    private function isSubclassOfTestCase(string $class): bool
    {
        try {
            return is_subclass_of($class, TestCase::class);
        } catch (Throwable) {
            return false;
        }
    }

    private function buildIndex(): void
    {
        if ($this->index !== null) {
            return;
        }

        $this->index = [];

        foreach ($this->phpFiles($this->testsDirectory) as $file) {
            $this->index[$file] = $this->analyse($file);
        }

        $this->indexByName = $this->groupByName(array_values($this->index));
    }

    /**
     * Declarations from the scanned files take precedence over the tests/ index.
     *
     * @param  list<array{file: string, pest: bool, declarations: list<array<string, mixed>>}>  $analyses
     * @return array<string, list<array<string, mixed>>>
     */
    private function declarationsByName(array $analyses): array
    {
        $unindexed = array_values(array_filter(
            $analyses,
            fn (array $analysis): bool => ! isset($this->index[$analysis['file']]),
        ));

        if ($unindexed === []) {
            return $this->indexByName;
        }

        $declarations = $this->groupByName($unindexed);

        foreach ($this->indexByName as $key => $candidates) {
            $declarations[$key] = [...$declarations[$key] ?? [], ...$candidates];
        }

        return $declarations;
    }

    /**
     * @param  list<array{file: string, pest: bool, declarations: list<array<string, mixed>>}>  $analyses
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByName(array $analyses): array
    {
        $grouped = [];

        foreach ($analyses as $analysis) {
            foreach ($analysis['declarations'] as $declaration) {
                $grouped[strtolower($declaration['name'])][] = $declaration;
            }
        }

        return $grouped;
    }

    /**
     * @return array{file: string, pest: bool, declarations: list<array<string, mixed>>}
     */
    private function analyse(string $file): array
    {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new InvalidArgumentException("Unable to read [{$file}].");
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_INLINE_HTML]),
        ));
        $count = count($tokens);

        $namespace = '';
        $imports = [];
        $declarations = [];
        $callsPest = false;
        $parens = 0;
        $braces = [];
        $pendingBodies = [];
        $classBodies = 0;
        $member = self::NO_MEMBER;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $top = $braces === [] ? null : $braces[array_key_last($braces)];
            $inClassBody = $top !== null && $top['class'] && $top['parens'] === $parens;

            if ($token->id === T_ATTRIBUTE) {
                [$attributes, $i] = $this->readAttributeGroup($tokens, $i);

                if ($inClassBody) {
                    $member['attributes'] = [...$member['attributes'], ...$attributes];
                }

                continue;
            }

            if ($inClassBody) {
                if ($token->id === T_DOC_COMMENT) {
                    $member['doc'] = $token->text;

                    continue;
                }

                if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_VAR])) {
                    $member['modifiers'][] = $token->id;

                    continue;
                }

                if ($token->id === T_FUNCTION) {
                    $name = $tokens[$i + 1]->text ?? '';

                    if ($name === '&') {
                        $name = $tokens[$i + 2]->text ?? '';
                    }

                    if ($top['declaration'] !== null && $this->isTestMethod($name, $member)) {
                        $declarations[$top['declaration']]['testMethods'][] = $name;
                    }

                    $member = self::NO_MEMBER;

                    continue;
                }

                if ($token->id === T_USE) {
                    for ($i++; $i < $count && ! in_array($tokens[$i]->id, [ord(';'), ord('{')], true); $i++) {
                        if ($top['declaration'] !== null && $tokens[$i]->is(self::NAME_TOKENS)) {
                            $declarations[$top['declaration']]['traits'][] = $this->resolveName($tokens[$i]->text, $namespace, $imports);
                        }
                    }

                    $i--;

                    continue;
                }
            }

            if ($token->id === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;

                if ($next !== null && $next->is([T_STRING, T_NAME_QUALIFIED])) {
                    $namespace = $next->text;
                    $imports = [];
                    $i++;
                } elseif ($next?->id === ord('{')) {
                    $namespace = '';
                    $imports = [];
                }

                continue;
            }

            if ($token->id === T_USE && ($tokens[$i + 1] ?? null)?->id !== ord('(')) {
                $i = $this->readImports($tokens, $i, $imports);

                continue;
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ($tokens[$i - 1] ?? null)?->id !== T_DOUBLE_COLON) {
                $next = $tokens[$i + 1] ?? null;

                if ($next?->id !== T_STRING) {
                    // Only `new class` has a body here; `class:` named arguments do not.
                    if ($token->id === T_CLASS && $next !== null
                        && ($next->id === ord('(') || $next->id === ord('{') || $next->is([T_EXTENDS, T_IMPLEMENTS]))) {
                        $pendingBodies[$parens] = null;
                    }

                    continue;
                }

                $abstract = false;

                for ($j = $i - 1; $j >= 0 && $tokens[$j]->is([T_ABSTRACT, T_FINAL, T_READONLY]); $j--) {
                    $abstract = $abstract || $tokens[$j]->id === T_ABSTRACT;
                }

                $parent = null;

                for ($j = $i + 2; $j < $count && $tokens[$j]->id !== ord('{'); $j++) {
                    if ($token->id === T_CLASS && $tokens[$j]->id === T_EXTENDS && ($tokens[$j + 1] ?? null)?->is(self::NAME_TOKENS)) {
                        $parent = $this->resolveName($tokens[$j + 1]->text, $namespace, $imports);
                    }
                }

                $declarations[] = [
                    'kind' => $token->id,
                    'name' => $namespace === '' ? $next->text : $namespace.'\\'.$next->text,
                    'file' => $file,
                    'line' => $token->line,
                    'abstract' => $abstract,
                    'parent' => $parent,
                    'traits' => [],
                    'testMethods' => [],
                ];
                $pendingBodies[$parens] = array_key_last($declarations);
                $i = $j - 1;

                continue;
            }

            if ($classBodies === 0 && ! $callsPest && $this->isPestTestCall($tokens, $i)) {
                $callsPest = true;

                continue;
            }

            if ($token->id === ord('(')) {
                $parens++;
            } elseif ($token->id === ord(')')) {
                $parens--;
            } elseif ($token->id === ord('{') || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $opensClass = $token->id === ord('{') && array_key_exists($parens, $pendingBodies);
                $braces[] = [
                    'class' => $opensClass,
                    'declaration' => $opensClass ? $pendingBodies[$parens] : null,
                    'parens' => $parens,
                ];

                if ($opensClass) {
                    unset($pendingBodies[$parens]);
                    $classBodies++;
                }

                $member = self::NO_MEMBER;
            } elseif ($token->id === ord('}')) {
                $closed = array_pop($braces);
                $classBodies -= ($closed['class'] ?? false) ? 1 : 0;
                $member = self::NO_MEMBER;
            } elseif ($token->id === ord(';') || $token->id === T_CLOSE_TAG) {
                $member = self::NO_MEMBER;
            }
        }

        return ['file' => $file, 'pest' => $callsPest, 'declarations' => $declarations];
    }

    /**
     * A call to Pest's it()/test()/describe()/todo()/arch() outside any class
     * body, as opposed to a method call or a declaration of the same name.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function isPestTestCall(array $tokens, int $i): bool
    {
        $token = $tokens[$i];

        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
            || ! in_array(strtolower(ltrim($token->text, '\\')), self::PEST_TEST_FUNCTIONS, true)
            || ($tokens[$i + 1] ?? null)?->id !== ord('(')) {
            return false;
        }

        $previous = $tokens[$i - 1] ?? null;

        if ($previous?->text === '&') {
            $previous = $tokens[$i - 2] ?? null;
        }

        return $previous === null
            || ! $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]);
    }

    /**
     * PHPUnit runs public methods named test* or marked #[Test]; @test is
     * matched too so an old annotation cannot slip past.
     *
     * @param  array{doc: string|null, attributes: list<string>, modifiers: list<int>}  $member
     */
    private function isTestMethod(string $name, array $member): bool
    {
        if (in_array(T_PRIVATE, $member['modifiers'], true) || in_array(T_PROTECTED, $member['modifiers'], true)) {
            return false;
        }

        if (str_starts_with($name, 'test')) {
            return true;
        }

        foreach ($member['attributes'] as $attribute) {
            $segments = explode('\\', $attribute);

            if (strcasecmp(end($segments), 'Test') === 0) {
                return true;
            }
        }

        return $member['doc'] !== null && preg_match('/@test(?![\w-])/', $member['doc']) === 1;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array{0: list<string>, 1: int} attribute names and the index of the closing bracket
     */
    private function readAttributeGroup(array $tokens, int $i): array
    {
        $names = [];
        $depth = 0;
        $expectName = true;
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;

            if ($depth === 0 && $text === ']') {
                break;
            }

            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;
            } elseif ($depth === 0 && $text === ',') {
                $expectName = true;
            } elseif ($depth === 0 && $expectName && $tokens[$j]->is(self::NAME_TOKENS)) {
                $names[] = $text;
                $expectName = false;
            }
        }

        return [$names, $j];
    }

    /**
     * Read a namespace-level `use` statement into the class import table,
     * expanding group imports and skipping function and constant imports.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $imports
     * @return int index of the terminating semicolon
     */
    private function readImports(array $tokens, int $i, array &$imports): int
    {
        $count = count($tokens);
        $statementKind = T_CLASS;
        $j = $i + 1;

        if (($tokens[$j] ?? null)?->is([T_FUNCTION, T_CONST])) {
            $statementKind = $tokens[$j]->id;
            $j++;
        }

        $kind = $statementKind;
        $prefix = '';
        $name = '';
        $alias = null;
        $readingAlias = false;

        for (; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token->is([T_FUNCTION, T_CONST])) {
                $kind = $token->id;
            } elseif ($token->id === T_AS) {
                $readingAlias = true;
            } elseif ($token->is(self::NAME_TOKENS) || $token->id === T_NS_SEPARATOR) {
                if ($readingAlias) {
                    $alias = $token->text;
                    $readingAlias = false;
                } else {
                    $name .= $token->text;
                }
            } elseif ($token->id === ord('{')) {
                $prefix = trim($name, '\\').'\\';
                $name = '';
            } elseif (in_array($token->id, [ord(','), ord('}'), ord(';')], true)) {
                if ($name !== '' && $kind === T_CLASS) {
                    $class = ltrim($prefix.ltrim($name, '\\'), '\\');
                    $segments = explode('\\', $class);
                    $imports[strtolower($alias ?? end($segments))] = $class;
                }

                if ($token->id === ord(';')) {
                    return $j;
                }

                if ($token->id === ord('}')) {
                    $prefix = '';
                }

                $kind = $statementKind;
                $name = '';
                $alias = null;
            }
        }

        return $j;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function resolveName(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        if (strncasecmp($name, 'namespace\\', 10) === 0) {
            return ltrim($namespace.'\\'.substr($name, 10), '\\');
        }

        $segments = explode('\\', $name, 2);
        $imported = $imports[strtolower($segments[0])] ?? null;

        if ($imported !== null) {
            return isset($segments[1]) ? $imported.'\\'.$segments[1] : $imported;
        }

        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $path): array
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            throw new InvalidArgumentException("Path [{$path}] does not exist.");
        }

        if (is_file($resolved)) {
            return [$resolved];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function displayPath(string $file): string
    {
        $root = dirname($this->testsDirectory).DIRECTORY_SEPARATOR;

        return str_replace('\\', '/', str_starts_with($file, $root) ? substr($file, strlen($root)) : $file);
    }

    private function normalisePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }
}
