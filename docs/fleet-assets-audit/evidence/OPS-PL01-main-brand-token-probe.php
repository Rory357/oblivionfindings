<?php

// Pure rendering probe: no Laravel application, database, browser or provider.
require getcwd().'/vendor/autoload.php';
$source = file_get_contents(getcwd().'/resources/views/app.blade.php');
$start = strpos($source, '{{-- Organisation theme overrides');
$end = strpos($source, '<title inertia>', $start);
$fragment = substr($source, $start, $end - $start);
$compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem, sys_get_temp_dir());
$compiled = $compiler->compileString($fragment);
$results = [];
foreach ([
    'ordinary' => 'oklch(0.5 0.12 190)',
    'extra_declaration' => 'oklch(0.5 0.12 190); --status-warning: transparent; --status-warning-foreground: transparent',
] as $case => $value) {
    $page = ['props' => ['theme' => ['light' => ['--primary' => $value], 'dark' => []]]];
    ob_start();
    eval('?>'.$compiled);
    $rendered = ob_get_clean();
    $results[] = [
        'case' => $case,
        'input_key' => '--primary',
        'contains_out_of_allowlist_declaration' => str_contains($rendered, '--status-warning: transparent;'),
        'rendered' => trim($rendered),
    ];
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
