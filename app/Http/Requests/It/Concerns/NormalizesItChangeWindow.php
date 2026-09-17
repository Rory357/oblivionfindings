<?php

namespace App\Http\Requests\It\Concerns;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\Validator;

trait NormalizesItChangeWindow
{
    private array $maintenanceWindowErrors = [];

    protected function prepareForValidation(): void
    {
        // The new browser editor explicitly labels its local timezone. Existing
        // adapters without this marker retain their timestamp contract.
        if ($this->input('maintenance_timezone') !== config('app.worker_timezone', 'Pacific/Auckland')) {
            return;
        }
        $zone = new DateTimeZone(config('app.worker_timezone', 'Pacific/Auckland'));
        foreach (['maintenance_starts_at', 'maintenance_ends_at'] as $field) {
            $value = $this->input($field);
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) {
                $this->maintenanceWindowErrors[$field] = 'Choose a valid local date and time.';

                continue;
            }
            $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
            if (! $wall || $wall->format('Y-m-d\TH:i') !== $value) {
                $this->maintenanceWindowErrors[$field] = 'Choose a valid local date and time.';

                continue;
            }
            // Enumerate the nearby offsets to reject skipped or repeated wall
            // times instead of silently shifting a maintenance window at DST.
            $transitions = $zone->getTransitions($wall->getTimestamp() - 86400, $wall->getTimestamp() + 86400);
            $offsets = $transitions === false ? [$zone->getOffset($wall)] : array_unique(array_column($transitions, 'offset'));
            $matches = [];
            foreach ($offsets as $offset) {
                $instant = $wall->setTimestamp($wall->getTimestamp() - $offset);
                if ($instant->setTimezone($zone)->format('Y-m-d\TH:i') === $value) {
                    $matches[] = $instant;
                }
            }
            if (count($matches) !== 1) {
                $this->maintenanceWindowErrors[$field] = 'This local time is skipped or repeated by a daylight-saving change. Choose a time outside that hour.';

                continue;
            }
            $this->merge([$field => $matches[0]->format('Y-m-d\TH:i:s\Z')]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->maintenanceWindowErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }
}
