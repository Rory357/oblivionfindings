<?php

namespace App\Domain\It\Enums;

enum ItWorkType: string
{
    case Incident = 'incident';
    case ServiceRequest = 'service_request';
    case Provisioning = 'provisioning';
    case Problem = 'problem';
    case Change = 'change';
    case Task = 'task';
    case SecurityRequest = 'security_request';
    case MajorIncident = 'major_incident';
}
