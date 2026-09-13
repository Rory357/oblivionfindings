<?php

namespace App\Domain\It\Services;

/** Validate inert drawing data without interpreting markup or resolving resources. */
final class ItKnowledgeDiagramSource
{
    private array $issues = [];

    public function issues(mixed $diagrams): array
    {
        $this->issues = [];
        $limits = ItKnowledgeDiagramContract::LIMITS;
        if (! is_array($diagrams) || ! array_is_list($diagrams) || count($diagrams) > $limits['diagrams']) {
            $this->issue('diagrams', 'Choose an ordered collection of at most '.$limits['diagrams'].' diagrams.');

            return $this->issues;
        }
        $ids = [];
        foreach ($diagrams as $index => $diagram) {
            if (count($this->issues) >= 100) {
                break;
            }
            $path = 'diagrams.'.$index;
            $v2 = is_array($diagram) && array_key_exists('schema_version', $diagram);
            if ($v2 && $diagram['schema_version'] !== 2) {
                $this->issue($path.'.schema_version', 'This diagram version is not supported. The saved original has been retained.');

                continue;
            }
            $before = count($this->issues);
            $this->check($diagram, ['kind' => 'object', 'name' => $v2 ? 'diagramV2' : 'legacy'], $path);
            if (count($this->issues) !== $before) {
                continue;
            }
            $this->bytes($diagram, $limits['diagramBytes'], $path);
            $key = strtolower($diagram['id']);
            if (isset($ids[$key])) {
                $this->issue($path.'.id', 'Each diagram must have its own identity.');
            }
            $ids[$key] = true;
            if ($v2) {
                $this->graph($diagram, $path);
            } else {
                $nodes = array_column($diagram['nodes'], 'id');
                $edges = array_column($diagram['edges'], 'id');
                if (count($nodes) !== count(array_unique($nodes)) || count($edges) !== count(array_unique($edges))) {
                    $this->issue($path, 'Each shape and connector must have its own identity.');
                }
                foreach ($diagram['edges'] as $edge) {
                    if ($edge['from'] === $edge['to'] || ! in_array($edge['from'], $nodes, true) || ! in_array($edge['to'], $nodes, true)) {
                        $this->issue($path.'.edges', 'Each connector must join two existing shapes.');
                    }
                }
            }
        }
        $this->bytes($diagrams, $limits['collectionBytes'], 'diagrams');

        return $this->issues;
    }

    private function issue(string $path, string $message): void
    {
        if (count($this->issues) < 100) {
            $this->issues[] = ['path' => $path, 'message' => $message];
        }
    }

    private function bytes(mixed $value, int $max, string $path): void
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
            if (strlen($json) > $max) {
                $this->issue($path, 'The diagram source exceeds its storage limit. Split it into smaller diagrams.');
            }
        } catch (\JsonException) {
            $this->issue($path, 'The diagram source is not valid JSON.');
        }
    }

    private function check(mixed $value, array $rule, string $path): void
    {
        if (count($this->issues) >= 100 || ($value === null && ($rule['nullable'] ?? false))) {
            return;
        }
        switch ($rule['kind']) {
            case 'string':
                if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                    $this->issue($path, 'Use valid plain text.');

                    return;
                }
                $length = mb_strlen($value, 'UTF-8');
                if ($length < $rule['min'] || $length > $rule['max'] || (($rule['nonblank'] ?? false) && preg_match('/^\s*$/u', $value))) {
                    $this->issue($path, 'Text length must be '.$rule['min'].' to '.$rule['max'].' characters.');
                }
                if (isset($rule['pattern']) && ! preg_match('~'.$rule['pattern'].'~uD', $value)) {
                    $this->issue($path, 'This value is not in the accepted format.');
                }
                if (preg_match('~'.ItKnowledgeDiagramContract::FORBIDDEN_TEXT_PATTERN.'~u', $value)) {
                    $this->issue($path, 'Text contains unsupported control characters.');
                }

                return;
            case 'number':
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < $rule['min'] || $value > $rule['max']
                    || (($rule['integer'] ?? false) && floor((float) $value) !== (float) $value)) {
                    $this->issue($path, 'Use a finite number within the accepted range.');
                }

                return;
            case 'boolean':
                if (! is_bool($value)) {
                    $this->issue($path, 'Use true or false.');
                }

                return;
            case 'enum':
                if (! in_array($value, $rule['values'], true)) {
                    $this->issue($path, 'Choose a supported value.');
                }

                return;
            case 'array':
                if (! is_array($value) || ! array_is_list($value) || count($value) < $rule['min'] || count($value) > $rule['max']) {
                    $this->issue($path, 'Use an ordered list with '.$rule['min'].' to '.$rule['max'].' entries.');

                    return;
                }
                foreach ($value as $index => $child) {
                    $this->check($child, $rule['items'], $path.'.'.$index);
                }

                return;
            case 'object':
                if (! is_array($value) || array_is_list($value)) {
                    $this->issue($path, 'Use a diagram object.');

                    return;
                }
                $fields = ItKnowledgeDiagramContract::OBJECTS[$rule['name']];
                foreach (array_keys($value) as $key) {
                    if (! array_key_exists($key, $fields)) {
                        $this->issue($path.'.'.$key, 'This field is not accepted.');
                    }
                }
                foreach ($fields as $key => $child) {
                    if (! array_key_exists($key, $value)) {
                        $this->issue($path.'.'.$key, 'This field must be present.');
                    } else {
                        $this->check($value[$key], $child, $path.'.'.$key);
                    }
                }
        }
    }

    private function graph(array $diagram, string $path): void
    {
        $ids = [];
        $identity = function (string $id) use (&$ids, $path): void {
            $key = strtolower($id);
            if (isset($ids[$key])) {
                $this->issue($path, 'Each diagram element must have its own identity.');
            }
            $ids[$key] = true;
        };
        $identity($diagram['id']);
        $totals = ['nodesPerDiagram' => 0, 'edgesPerDiagram' => 0, 'groupsPerDiagram' => 0, 'pointsPerDiagram' => 0];
        foreach ($diagram['pages'] as $pageIndex => $page) {
            $pagePath = $path.'.pages.'.$pageIndex;
            $identity($page['id']);
            foreach (['layers', 'groups', 'nodes', 'edges'] as $collection) {
                foreach ($page[$collection] as $element) {
                    $identity($element['id']);
                }
            }
            $layers = array_fill_keys(array_map('strtolower', array_column($page['layers'], 'id')), true);
            $nodes = array_fill_keys(array_map('strtolower', array_column($page['nodes'], 'id')), true);
            $containers = array_fill_keys(array_map('strtolower', array_column($page['groups'], 'id')), true);
            $parents = [];
            foreach ([...$page['groups'], ...$page['nodes']] as $element) {
                $parents[strtolower($element['id'])] = $element['parentId'] === null ? null : strtolower($element['parentId']);
                if (isset($element['type']) && in_array($element['type'], ItKnowledgeDiagramContract::ENUMS['containerShape'], true)) {
                    $containers[strtolower($element['id'])] = true;
                }
            }
            foreach ($parents as $id => $parent) {
                if ($parent !== null && ! isset($containers[$parent])) {
                    $this->issue($pagePath, 'Each parent must be a group or supported container on this page.');
                }
                $visited = [$id => true];
                for ($cursor = $parent; $cursor !== null && array_key_exists($cursor, $parents); $cursor = $parents[$cursor]) {
                    if (isset($visited[$cursor])) {
                        $this->issue($pagePath, 'Groups and containers cannot contain themselves or form cycles.');

                        break;
                    }
                    $visited[$cursor] = true;
                }
            }
            foreach ($page['nodes'] as $index => $node) {
                $nodePath = $pagePath.'.nodes.'.$index;
                if (! isset($layers[strtolower($node['layerId'])])) {
                    $this->issue($nodePath.'.layerId', 'Choose a layer on this page.');
                }
                if ($node['type'] === 'ink' ? count($node['points']) < 2 : $node['points'] !== []) {
                    $this->issue($nodePath.'.points', 'Ink needs at least two points; other shapes must have no points.');
                }
                if ($node['type'] === 'image' ? $node['imageFileId'] === null : $node['imageFileId'] !== null) {
                    $this->issue($nodePath.'.imageFileId', 'Only images require a file reference.');
                }
                $properties = [];
                foreach ($node['properties'] as $property) {
                    $key = strtolower($property['key']);
                    if (isset($properties[$key]) || in_array($key, ['constructor', 'prototype'], true)) {
                        $this->issue($nodePath.'.properties', 'Use unique, supported field names.');
                    }
                    $properties[$key] = true;
                }
                if ($node['dataGraphic'] !== null) {
                    $property = array_values(array_filter($node['properties'], fn ($item) => $item['key'] === $node['dataGraphic']))[0] ?? null;
                    if ($property === null || $property['value'] === null || ! preg_match('~'.ItKnowledgeDiagramContract::PERCENT_PATTERN.'~D', $property['value']) || (float) $property['value'] > 100) {
                        $this->issue($nodePath.'.dataGraphic', 'Choose a property containing a decimal percentage from 0 to 100.');
                    }
                }
                $totals['pointsPerDiagram'] += count($node['points']);
            }
            foreach ($page['edges'] as $edge) {
                if (! isset($layers[strtolower($edge['layerId'])])) {
                    $this->issue($pagePath.'.edges', 'Choose a layer on this page.');
                }
                if (strtolower($edge['from']) === strtolower($edge['to']) || ! isset($nodes[strtolower($edge['from'])], $nodes[strtolower($edge['to'])])) {
                    $this->issue($pagePath.'.edges', 'Each connector must join two different shapes on this page.');
                }
            }
            $totals['nodesPerDiagram'] += count($page['nodes']);
            $totals['edgesPerDiagram'] += count($page['edges']);
            $totals['groupsPerDiagram'] += count($page['groups']);
        }
        foreach ($totals as $limit => $total) {
            if ($total > ItKnowledgeDiagramContract::LIMITS[$limit]) {
                $this->issue($path.'.pages', 'The diagram exceeds its combined page limit for shapes, connectors, groups or ink points.');
            }
        }
    }
}
