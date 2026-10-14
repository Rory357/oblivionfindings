<?php

namespace App\Domain\Obli;

use App\Http\Middleware\ProtectObliResponses;
use Illuminate\Http\Request;
use RuntimeException;

final class ObliChatRejected extends RuntimeException
{
    public function __construct(public readonly string $codeName, public readonly int $statusCode)
    {
        parent::__construct('Obli investigation could not confirm this request.');
    }

    public function render(Request $request)
    {
        return ProtectObliResponses::headers(response()->json(['code' => $this->codeName, 'message' => match ($this->codeName) {
            'invalid_request' => 'Review the question and selected diagnostic snapshot.','chat_unavailable' => 'Technician Obli is not available with the current configuration.','budget_exceeded' => 'The configured Obli budget has been reached.','outcome_expired' => 'This private investigation response has expired.','idempotency_conflict' => 'Keep the original question and request identity while checking its outcome.','source_changed' => 'The diagnostic snapshot changed. Review it before sending.','context_busy' => 'The computer context is busy. Retry the same request later.',default => 'The investigation or its source is unavailable. A previous request may already have been processed.'
        }], $this->statusCode));
    }

    public function report(): bool
    {
        return false;
    }
}
