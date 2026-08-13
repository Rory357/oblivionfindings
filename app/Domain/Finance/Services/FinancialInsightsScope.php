<?php

namespace App\Domain\Finance\Services;

enum FinancialInsightsScope: string
{
    case Global = 'global';
    case AccessibleSite = 'accessible_site';
    case ClientRelationship = 'client_relationship';
    case Denied = 'denied';
}
