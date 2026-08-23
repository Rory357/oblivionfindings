<?php

namespace App\Domain\Finance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Keep the legacy route names available while the unsupported multi-entity
 * accounting boundary remains quarantined in this single-tenant application.
 */
final class RejectUnsupportedConsolidation
{
    public function handle(Request $request, Closure $next): never
    {
        abort(404);
    }
}
