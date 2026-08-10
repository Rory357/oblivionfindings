<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use InvalidArgumentException;

final readonly class FlowTemplate
{
    /** @param non-empty-list<FlowTemplateField> $fields */
    public function __construct(public int $id, public array $fields)
    {
        if ($id < 256 || $id > 65_535 || $fields === [] || count($fields) > 128) {
            throw new InvalidArgumentException('Flow template is invalid.');
        }
        foreach ($fields as $field) {
            if (! $field instanceof FlowTemplateField) {
                throw new InvalidArgumentException('Flow template field is invalid.');
            }
        }
    }

    public function fixedRecordLength(): ?int
    {
        $length = 0;
        foreach ($this->fields as $field) {
            if ($field->length === 65_535) {
                return null;
            }
            $length += $field->length;
        }

        return $length;
    }
}
