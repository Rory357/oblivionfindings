<?php

namespace App\Domain\It\Exceptions;

use App\Models\ItTicket;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Thrown only after the locked command has rechecked the actor's access. */
final class ItTicketVersionConflict extends RuntimeException implements ShouldntReport
{
    /** @var array{id: int, reference: string, lock_version: int, url: string} */
    public readonly array $current;

    public function __construct(ItTicket $ticket)
    {
        parent::__construct('This ticket changed after you opened it. Your changes were not saved. Review the current ticket before applying them again.');
        $this->current = [
            'id' => (int) $ticket->id,
            'reference' => $ticket->reference,
            'lock_version' => (int) $ticket->lock_version,
            'url' => route('it.tickets.show', $ticket, false),
        ];
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'code' => 'stale_ticket',
                'message' => $this->getMessage(),
                'errors' => ['expected_version' => [$this->getMessage()]],
                'current' => $this->current,
            ], 409);
        }

        // Inertia uses field errors to preserve the form and invoke onError.
        // An arbitrary JSON 409 would open its invalid-response modal instead.
        return redirect()->back()
            ->withErrors(['expected_version' => $this->getMessage()])
            ->with('it_ticket_conflict', $this->current);
    }
}
