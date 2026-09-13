<?php

namespace App\Http\Controllers\Sites\Concerns;

use App\Services\SiteTypeAccessService;
use Illuminate\Http\Request;

trait ResolvesAllowedSiteTypes
{
    protected function allowedSiteTypes(Request $request): array
    {
        return app(SiteTypeAccessService::class)->allowedTypes($request->user());
    }
}
