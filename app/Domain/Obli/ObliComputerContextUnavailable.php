<?php

namespace App\Domain\Obli;

use App\Http\Middleware\ProtectObliResponses;
use Illuminate\Http\Request;
use RuntimeException;

final class ObliComputerContextUnavailable extends RuntimeException
{
    public function __construct(public readonly bool $expired = false)
    {
        parent::__construct('Computer context unavailable.');
    }

    public function render(Request $request)
    {
        return ProtectObliResponses::headers(response()->json(['code' => $this->expired ? 'context_expired' : 'computer_context_unavailable', 'message' => 'The computer association is unavailable. Recover an uncertain ticket request before starting another.'], 404));
    }

    public function report(): bool
    {
        return false;
    }
}
