<?php

namespace App\Services\Emar;

use Illuminate\Database\Eloquent\Model;

/**
 * Confirms that an already-authorized canonical medication event has a stored
 * backing record without disclosing network, device, history, or arbitrary
 * model attributes. Authorization and Site binding belong to the controller.
 */
class MedicationAuditIntegrityService
{
    /** @return array{backed: bool} */
    public function forModel(Model $model): array
    {
        return [
            'backed' => (bool) $model->exists,
        ];
    }
}
