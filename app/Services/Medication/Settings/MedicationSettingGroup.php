<?php

namespace App\Services\Medication\Settings;

/**
 * Settings saved together on one Settings view (eMAR P11), with when a saved
 * change takes effect and the audit event each save records.
 */
final class MedicationSettingGroup
{
    /** @param  list<MedicationSettingDefinition>  $definitions */
    public function __construct(
        public readonly string $key,
        public readonly string $view,
        public readonly string $effect,
        public readonly string $auditEvent,
        public readonly array $definitions,
    ) {}

    public function definition(string $key): ?MedicationSettingDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toClient(): array
    {
        return [
            'key' => $this->key,
            'view' => $this->view,
            'effect' => $this->effect,
            'audit_event' => $this->auditEvent,
            'keys' => array_map(fn (MedicationSettingDefinition $d): string => $d->key, $this->definitions),
        ];
    }
}
