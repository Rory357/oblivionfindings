<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use RuntimeException;

final class FlowTemplateRegistry
{
    private const int MAX_TEMPLATES = 2048;

    /** @var array<string, FlowTemplate> */
    private array $templates = [];

    public function remember(string $family, string $exporter, int $sourceId, FlowTemplate $template): void
    {
        $key = $this->key($family, $exporter, $sourceId, $template->id);
        if (! array_key_exists($key, $this->templates) && count($this->templates) >= self::MAX_TEMPLATES) {
            throw new RuntimeException('Flow template registry limit is exceeded.');
        }
        $this->templates[$key] = $template;
    }

    public function resolve(string $family, string $exporter, int $sourceId, int $templateId): FlowTemplate
    {
        $template = $this->templates[$this->key($family, $exporter, $sourceId, $templateId)] ?? null;
        if (! $template instanceof FlowTemplate) {
            throw new RuntimeException('Flow data template is unavailable.');
        }

        return $template;
    }

    private function key(string $family, string $exporter, int $sourceId, int $templateId): string
    {
        if (filter_var($exporter, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Flow exporter address is invalid.');
        }

        return $family.'|'.$exporter.'|'.$sourceId.'|'.$templateId;
    }
}
