<?php

use App\Support\BrandThemeTokens;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

it('retains supported colour values and normalizes legacy channels', function (string $input, string $expected) {
    expect(BrandThemeTokens::filter(['--primary' => $input]))->toBe(['--primary' => $expected]);
})->with([
    [' #4F46E5 ', '#4f46e5'], ['#abc', '#abc'], ['#abcf', '#abcf'], ['#12345678', '#12345678'],
    ['oklch(0.5 0.12 190)', 'oklch(0.5 0.12 190)'], ['oklch(72% .12 190 / 80%)', 'oklch(72% .12 190 / 80%)'],
    ['rgb(12, 40, 70)', 'rgb(12, 40, 70)'], ['rgba(12, 40, 70, .8)', 'rgba(12, 40, 70, .8)'],
    ['rgb(20% 30% 40% / 80%)', 'rgb(20% 30% 40% / 80%)'], ['rgb(20%, 30%, 40%)', 'rgb(20%, 30%, 40%)'],
    ['hsl(220, 90%, 56%)', 'hsl(220, 90%, 56%)'], ['hsla(220, 90%, 56%, .8)', 'hsla(220, 90%, 56%, .8)'],
    ['hsl(.5turn 90% 56% / .8)', 'hsl(.5turn 90% 56% / .8)'], ['220 90% 56%', 'hsl(220 90% 56%)'],
    ['hwb(190 20% 30%)', 'hwb(190 20% 30%)'], ['lab(50% 10 20)', 'lab(50% 10 20)'],
    ['lch(50% 10 190deg)', 'lch(50% 10 190deg)'], ['oklab(.5 .1 .2 / .8)', 'oklab(.5 .1 .2 / .8)'],
    ['color(display-p3 0.1 0.2 0.3)', 'color(display-p3 0.1 0.2 0.3)'],
    ['RebeccaPurple', 'rebeccapurple'], ['transparent', 'transparent'], ['currentColor', 'currentcolor'],
    ['color-mix(in oklch, #059669 15%, transparent)', 'color-mix(in oklch, #059669 15%, transparent)'],
    ['color-mix(in oklch, #059669 70%, white 30%)', 'color-mix(in oklch, #059669 70%, white 30%)'],
    ['color-mix(in srgb, rgb(10, 20, 30), color-mix(in oklch, red, blue))', 'color-mix(in srgb, rgb(10, 20, 30), color-mix(in oklch, red, blue))'],
]);

it('only emits supported nonnegative radius lengths', function (string $input) {
    expect(BrandThemeTokens::filter(['--radius' => $input]))->toBe(['--radius' => trim($input)]);
})->with(['0', '0.750rem', '.5em', '12px', ' 10% ', '2vw']);

it('omits malformed values for every allowed key at the real Blade emission boundary', function (mixed $value) {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/app.blade.php');
    $start = strpos($source, '{{-- Organisation theme overrides');
    $fragment = substr($source, $start, strpos($source, '<title inertia>', $start) - $start);
    $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
    $compiled = $compiler->compileString($fragment);
    $tokens = array_fill_keys(BrandThemeTokens::ALLOWED, $value);
    $page = ['props' => ['theme' => ['light' => $tokens, 'dark' => $tokens]]];
    ob_start();
    try {
        eval('?>'.$compiled);
        $rendered = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    expect(BrandThemeTokens::filter($tokens))->toBe([])
        ->and(trim($rendered))->toBe('');
})->with([
    'declaration' => ['oklch(0.5 0.12 190); --status-warning: transparent; --status-warning-foreground: transparent'],
    'block' => ['red} html{--status-warning:transparent'],
    'style tag' => ['</style><style>html{--status-warning:transparent}</style>'],
    'comment' => ['red/*comment*/'], 'escaped delimiter' => ['red\3b --status-warning:transparent'],
    'important' => ['red !important'], 'url' => ['url(https://example.test/a)'],
    'reference' => ['var(--status-warning)'], 'unknown function' => ['paint(foo)'],
    'unbalanced mix' => ['color-mix(in oklch, red, rgb(1,2,3)'],
    'invalid mix weight' => ['color-mix(in oklch, red 110%, blue)'],
    'zero weights' => ['color-mix(in oklch, red 0%, blue 0%)'],
    'unrecognised' => ['definitely-not-a-colour'], 'bad hex' => ['#12zzzz'],
    'incomplete rgb' => ['rgb(12 30)'], 'bad hsl' => ['hsl(190 20 30)'],
    'mixed legacy rgb' => ['rgb(20, 30%, 40)'], 'extra channel' => ['oklch(.5 .12 190 20)'],
    'bad radius' => ['-2rem'], 'compound radius' => ['1rem 2rem'], 'number' => [123],
    'array' => [['red']], 'null' => [null], 'empty' => [''], 'control' => ["red\0"],
]);

it('cannot expand the property allowlist or bypass it with a personal accent', function () {
    expect(BrandThemeTokens::css([
        '--primary' => '#059669', '--radius' => '.75rem',
        '--status-warning' => 'transparent', '--status-warning-foreground' => 'transparent',
        '--chart-1' => 'red', '--primary;--status-warning' => 'red',
    ]))->toBe('--primary: #059669;--radius: .75rem;')
        ->and(BrandThemeTokens::personalAccent('#C026D3'))->toBe('#C026D3')
        ->and(BrandThemeTokens::personalAccent('#fff; --status-warning:transparent'))->toBeNull();
});
