<?php

use App\Domain\It\Services\ItKnowledgeDiagramSource;

function diagramContractFixture(string $name): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures/it/knowledge-diagrams/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
}

test('the diagram server accepts the shared legacy and V2 fixtures without changing their source', function (string $name) {
    $source = diagramContractFixture($name);
    $original = json_encode($source, JSON_THROW_ON_ERROR);
    expect((new ItKnowledgeDiagramSource)->issues([$source]))->toBe([])
        ->and(json_encode($source, JSON_THROW_ON_ERROR))->toBe($original);
})->with(['legacy-basic', 'v2-minimal', 'v2-rich', 'v2-template-catalogue']);

$diagramCases = diagramContractFixture('validation-cases');
test('the server rejects malformed shared drawing contracts', function (array $edits) {
    $source = diagramContractFixture('v2-rich');
    foreach ($edits as $edit) {
        $cursor = &$source;
        $path = $edit['path'];
        $last = array_pop($path);
        foreach ($path as $part) {
            $cursor = &$cursor[$part];
        }
        if ($edit['delete'] ?? false) {
            unset($cursor[$last]);
        } else {
            $cursor[$last] = $edit['value'];
        }
        unset($cursor);
    }
    expect((new ItKnowledgeDiagramSource)->issues([$source]))->not->toBe([]);
})->with(collect($diagramCases['cases'])->mapWithKeys(fn ($case) => [$case['name'] => [$case['edits']]])->all());

test('drawing validation bounds total shapes across pages and total serialized bytes', function () {
    $source = diagramContractFixture('v2-minimal');
    $node = diagramContractFixture('v2-rich')['pages'][0]['nodes'][0];
    $source['pages'][1] = $source['pages'][0];
    $source['pages'][1]['id'] = '10000000-0000-4000-8000-000000000101';
    foreach ($source['pages'][1]['layers'] as $index => &$layer) {
        $layer['id'] = sprintf('10000000-0000-4000-8000-%012d', $index + 200);
    }
    unset($layer);
    foreach ($source['pages'] as $pageIndex => &$page) {
        for ($index = 0; $index < 41; $index++) {
            $shape = $node;
            $shape['id'] = sprintf('10000000-0000-4000-8000-%012d', 300 + $pageIndex * 100 + $index);
            $shape['parentId'] = null;
            $shape['layerId'] = $page['layers'][0]['id'];
            $page['nodes'][] = $shape;
        }
    }
    unset($page);
    expect(collect((new ItKnowledgeDiagramSource)->issues([$source]))->pluck('message')->implode(' '))->toContain('combined page limit');

    $source = diagramContractFixture('v2-minimal');
    for ($index = 0; $index < 9; $index++) {
        $shape = $node;
        $shape['id'] = sprintf('10000000-0000-4000-8000-%012d', 500 + $index);
        $shape['parentId'] = null;
        $shape['layerId'] = $source['pages'][0]['layers'][0]['id'];
        $shape['dataGraphic'] = null;
        $shape['properties'] = [];
        for ($property = 0; $property < 16; $property++) {
            $shape['properties'][] = ['key' => 'Field '.$property, 'value' => str_repeat('😀', 512)];
        }
        $source['pages'][0]['nodes'][] = $shape;
    }
    expect(collect((new ItKnowledgeDiagramSource)->issues([$source]))->pluck('message')->implode(' '))->toContain('storage limit');
});

test('drawing validation stops safely after its diagnostic bound and rejects non-finite geometry', function () {
    $bad = array_fill(0, 12, ['pages' => ['invalid' => true], ...array_fill_keys(range('a', 'z'), null)]);
    expect((new ItKnowledgeDiagramSource)->issues($bad))->toHaveCount(100);
    $source = diagramContractFixture('v2-rich');
    $source['pages'][0]['nodes'][0]['x'] = INF;
    expect((new ItKnowledgeDiagramSource)->issues([$source]))->not->toBe([]);
});
