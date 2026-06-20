<?php

namespace App\Http\Requests\HealthSafety;

/**
 * Validation for editing a draft risk assessment — identical field set to create.
 * The draft-only transition is enforced in the controller (friendly redirect on a
 * non-draft) rather than a hard 403.
 */
class UpdateHsRiskAssessmentRequest extends StoreHsRiskAssessmentRequest
{
}
