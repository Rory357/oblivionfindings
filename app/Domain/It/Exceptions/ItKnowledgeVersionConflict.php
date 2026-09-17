<?php

namespace App\Domain\It\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Raised after current article and actor authorization, without exposing content. */
final class ItKnowledgeVersionConflict extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('This document has changed. Your changes were not saved. Review the current document before trying again.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['code' => 'stale_knowledge', 'message' => $this->getMessage(),
                'errors' => ['lock_version' => [$this->getMessage()]]], 409);
        }

        return redirect()->back()->withErrors(['lock_version' => $this->getMessage()]);
    }
}
