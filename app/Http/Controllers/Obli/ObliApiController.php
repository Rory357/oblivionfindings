<?php

namespace App\Http\Controllers\Obli;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Obli\ObliComputerContexts;
use App\Domain\Obli\ObliSignIn;
use App\Domain\Obli\ObliTickets;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ObliApiController
{
    public function token(Request $request, ObliSignIn $signIn)
    {
        return response()->json($signIn->exchange($request->json()->all()));
    }

    public function me(Request $request, ObliSignIn $signIn, ItWorkAccessService $access)
    {
        return $signIn->withSession($request->bearerToken(), fn (User $user) => response()->json([
            'name' => $user->name, 'email' => $user->email,
            'computer_context_enabled' => app(ObliComputerContexts::class)->ready(),
            'sites' => Site::query()->whereKey($access->approvedSiteIds($user, true))
                ->orderBy('name')->get(['id', 'name'])->toArray(),
        ]));
    }

    public function logout(Request $request, ObliSignIn $signIn)
    {
        return $signIn->withSession($request->bearerToken(), function (User $user, string $hash) {
            DB::table('obli_staff_sessions')->where('token_hash', $hash)->update(['revoked_at' => now()]);
            AuditLogger::logOrFail('obli.staff.signed_out', $user, ['actor_id' => (int) $user->id]);

            return response()->noContent();
        });
    }

    public function tickets(Request $request, ObliSignIn $signIn, ItWorkAccessService $access)
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return $signIn->withSession($request->bearerToken(), function (User $user) use ($data, $access) {
            // A technician using their personal helper still receives only their own requests.
            $rows = $access->applyViewScope(ItTicket::query(), $user)->where('requester_user_id', $user->id)
                ->whereIn('site_id', $access->approvedSiteIds($user, true))
                ->whereNull('merged_into_ticket_id')
                ->when($data['before'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))
                ->orderByDesc('id')->limit(31)->lockForUpdate()->get();

            return response()->json([
                'tickets' => $rows->take(30)->map(fn (ItTicket $ticket) => $this->summary($ticket))->values(),
                'next_before' => $rows->count() > 30 ? $rows[29]->id : null,
            ], options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }

    public function showTicket(Request $request, int $ticket, ObliSignIn $signIn, ObliTickets $tickets)
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return $signIn->withSession($request->bearerToken(), fn (User $user) => response()->json(
            $tickets->detail($user, $ticket, isset($data['before']) ? (int) $data['before'] : null),
            options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    public function replyTicket(Request $request, int $ticket, ObliSignIn $signIn, ObliTickets $tickets)
    {
        try {
            $input = $request->json()->all();
            if (array_diff(array_keys($input), ['request_uuid', 'expected_version', 'body']) !== []) {
                return $this->replyError('invalid_request', 422);
            }
            $data = validator($input, [
                'request_uuid' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'],
                'body' => ['required', 'string', 'max:5000'],
            ])->validate();

            return $signIn->withSession($request->bearerToken(), function (User $user) use ($tickets, $ticket, $data) {
                $receipt = $tickets->reply($user, $ticket, $data);
                $status = $receipt['replayed'] ? 200 : 201;
                unset($receipt['replayed']);

                return response()->json($receipt, $status, options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            });
        } catch (ItTicketVersionConflict) {
            return $this->replyError('version_conflict', 409);
        } catch (ItTicketCommandConflict) {
            return $this->replyError('idempotency_conflict', 409);
        } catch (ValidationException) {
            return $this->replyError('invalid_request', 422);
        } catch (DomainException) {
            return $this->replyError('reply_rejected', 422);
        }
    }

    public function recoverReply(Request $request, int $ticket, string $requestUuid, ObliSignIn $signIn, ObliTickets $tickets)
    {
        return $signIn->withSession($request->bearerToken(), function (User $user) use ($tickets, $ticket, $requestUuid) {
            $receipt = $tickets->recover($user, $ticket, $requestUuid);
            unset($receipt['replayed']);

            return response()->json($receipt, options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
    }

    private function replyError(string $code, int $status)
    {
        return response()->json(['code' => $code, 'message' => match ($code) {
            'version_conflict' => 'This ticket changed. Reload it before reviewing your reply.',
            'idempotency_conflict' => 'Keep the original reply and request identity while checking its outcome.',
            default => 'Review this ticket and reply before submitting.',
        }], $status);
    }

    private function summary(ItTicket $ticket): array
    {
        // No internal notes, private attachments, watchers or staff-only context.
        return [
            'id' => $ticket->id, 'reference' => mb_substr((string) $ticket->reference, 0, 200), 'title' => mb_substr((string) $ticket->title, 0, 200),
            'status' => $ticket->status, 'updated_at' => $ticket->updated_at?->toIso8601String(),
            'path' => '/it/tickets/'.$ticket->id,
        ];
    }
}
