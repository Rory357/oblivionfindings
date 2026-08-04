<?php

namespace App\Domain\SecurityDevices\Management\Enums;

enum BreakGlassReviewOutcome: string
{
    case ConfirmedAppropriate = 'confirmed_appropriate';
    case FollowUpRequired = 'follow_up_required';
    case IncidentRequired = 'incident_required';
}
