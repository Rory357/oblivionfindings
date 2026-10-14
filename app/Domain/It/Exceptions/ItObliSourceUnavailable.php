<?php

namespace App\Domain\It\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

final class ItObliSourceUnavailable extends RuntimeException
{
    public function __construct(public readonly string $codeName = 'source_unavailable')
    {
        parent::__construct(match ($codeName) {
            'source_changed' => 'The computer snapshot changed. Review the latest snapshot before sending.', 'context_busy' => 'Computer evidence is busy. Keep the same request and try again.', default => 'This computer evidence is no longer available. Keep an uncertain request while checking its outcome.'
        });
    }

    public function render(Request $request)
    {
        return response()->json(['code' => $this->codeName, 'message' => $this->getMessage()], match ($this->codeName) {
            'source_changed' => 409, 'context_busy' => 503, default => 404
        })->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }
}
