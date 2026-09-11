<?php

namespace App\Domain\It\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;

final class ItTicketDraftException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self('draft_unavailable', 404, 'This draft is no longer available.');
    }

    public function render(Request $request): mixed
    {
        if ($request->expectsJson()) {
            return response()->json([
                'code' => $this->errorCode, 'message' => $this->getMessage(), ...$this->details,
            ], $this->status, ['Cache-Control' => 'no-store, private']);
        }

        return back()->withErrors(['draft_uuid' => $this->getMessage()]);
    }
}
