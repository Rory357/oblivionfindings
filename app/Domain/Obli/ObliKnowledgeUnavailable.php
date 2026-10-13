<?php

namespace App\Domain\Obli;

use App\Http\Middleware\ProtectObliResponses;
use Illuminate\Http\Request;
use RuntimeException;

final class ObliKnowledgeUnavailable extends RuntimeException
{
    public function render(Request $request)
    {
        return ProtectObliResponses::headers(response()->json([
            'code' => 'knowledge_unavailable',
            'message' => 'Selected IT knowledge is unavailable. Clear this conversation and choose current guidance again. The provider may already have processed the request.',
        ], 404));
    }

    public function report(): bool
    {
        return false;
    }
}
