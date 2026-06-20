<?php

namespace App\Http\Requests\HealthSafety;

/**
 * Validation for superseding an assessment with a new version. The new version
 * carries a full field set (the wizard is the New form pre-filled), so the rules
 * match create. The old → superseded / new → draft transition is in the service.
 */
class SupersedeHsRiskAssessmentRequest extends StoreHsRiskAssessmentRequest
{
}
