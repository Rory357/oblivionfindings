<?php

namespace App\Http\Controllers\Obli;

use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketCommandUnavailable;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Obli\ObliComputerContexts;
use App\Domain\Obli\ObliSignIn;
use App\Domain\Obli\ObliTicketNotificationDispatch;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ObliComputerContextController
{
    public function mint(Request $request, ObliSignIn $signIn, ObliComputerContexts $contexts)
    {
        return $this->errors(fn () => $signIn->withSession($request->bearerToken(), fn (User $actor, string $hash) => $this->json($contexts->mint($actor, $hash, $this->intent($request)))));
    }

    public function show(Request $request, string $requestUuid, ObliSignIn $signIn, ObliComputerContexts $contexts)
    {
        return $signIn->withSession($request->bearerToken(), fn (User $actor, string $hash) => $this->json($contexts->show($actor, $hash, $requestUuid)));
    }

    public function confirm(Request $request, ObliComputerContexts $contexts)
    {
        return $this->errors(function () use ($request, $contexts) {
            $input = $request->json()->all();
            if (array_keys($input) !== ['challenge']) {
                throw ValidationException::withMessages(['challenge' => 'Invalid request.']);
            }
            $data = validator($input, ['challenge' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']])->validate();

            return $this->json($contexts->confirm($request->bearerToken() ?? '', $data['challenge']));
        });
    }

    public function storeTicket(Request $request, ObliSignIn $signIn, ObliComputerContexts $contexts, ItTicketIntakeService $intake, ItWorkAccessService $access)
    {
        return $this->errors(function () use ($request, $signIn, $contexts, $intake, $access) {
            $data = $this->intent($request, true);
            $selected = ($data['computer_context'] ?? false) === true;
            unset($data['computer_context']);

            return $signIn->withSession($request->bearerToken(), function (User $actor, string $hash) use ($data, $selected, $contexts, $intake, $access) {
                abort_unless(in_array((int) $data['site_id'], $access->approvedSiteIds($actor, true), true), 403);
                $create = fn ($proof = null) => $intake->createCommand($actor, [...$data, 'priority' => 'normal', 'work_type' => 'incident', 'requester_user_id' => $actor->id, 'requested_for_user_id' => $actor->id], channel: ItTicketCommandChannel::Obli, computerContext: $proof);
                $result = $selected ? $contexts->withIntake($actor, $hash, $data, $create) : $create();
                $ticket = $this->own($actor, $result->ticket, $access);
                ObliTicketNotificationDispatch::schedule((int) $ticket->id);

                return $this->json($this->receipt($ticket, $result->requestUuid, $result->replayed), $result->replayed ? 200 : 201);
            });
        });
    }

    public function recoverTicket(Request $request, string $requestUuid, ObliSignIn $signIn, ObliComputerContexts $contexts, ItTicketIntakeService $intake, ItWorkAccessService $access)
    {
        return $this->errors(fn () => $signIn->withSession($request->bearerToken(), function (User $actor, string $hash) use ($requestUuid, $contexts, $intake, $access) {
            $result = $intake->recoverCommand($actor, $requestUuid, ItTicketCommandChannel::Obli);
            // Receipt discovery is read-only: Device/context precede the locked ticket.
            $contexts->recoverAuthority($actor, $hash, $requestUuid, $result->ticket);
            $ticket = $this->own($actor, $result->ticket, $access);

            return $this->json($this->receipt($ticket, $result->requestUuid, true));
        }));
    }

    private function intent(Request $request, bool $selection = false): array
    {
        $input = $request->json()->all();
        $allowed = ['request_uuid', 'site_id', 'title', 'description', 'category'];
        if ($selection) {
            $allowed[] = 'computer_context';
        }
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Invalid request.']);
        }
        $data = validator($input, ['request_uuid' => ['required', 'uuid'], 'site_id' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:12000'], 'category' => ['required', Rule::in(ItTicket::CATEGORIES)], 'computer_context' => ['sometimes', 'accepted']])->validate();
        if (array_key_exists('computer_context', $input) && $input['computer_context'] !== true) {
            throw ValidationException::withMessages(['computer_context' => 'Choose the confirmed computer.']);
        }
        foreach (['title', 'description'] as $field) {
            if (! mb_check_encoding($data[$field], 'UTF-8') || preg_match('/(?![\t\r\n])\p{Cc}/u', $data[$field])) {
                throw ValidationException::withMessages([$field => 'Invalid text.']);
            }
        }
        if ($selection && isset($input['computer_context'])) {
            $data['computer_context'] = true;
        }

        return $data;
    }

    private function own(User $actor, ItTicket $ticket, ItWorkAccessService $access): ItTicket
    {
        $ticket = ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $ticket->requester_user_id === (int) $actor->id && in_array((int) $ticket->site_id, $access->approvedSiteIds($actor, true), true) && ! $ticket->isMerged() && $access->canView($actor, $ticket), 404);

        return $ticket;
    }

    private function receipt(ItTicket $ticket, string $uuid, bool $replayed): array
    {
        return ['ticket' => ['id' => (int) $ticket->id, 'reference' => mb_substr((string) $ticket->reference, 0, 200), 'title' => mb_substr((string) $ticket->title, 0, 200), 'status' => $ticket->status, 'updated_at' => $ticket->updated_at?->toIso8601String(), 'path' => '/it/tickets/'.$ticket->id], 'request_uuid' => $uuid, 'replayed' => $replayed];
    }

    private function errors(\Closure $operation)
    {
        try {
            return $operation();
        } catch (ItTicketCommandConflict) {
            return $this->json(['code' => 'idempotency_conflict', 'message' => 'Keep the original request identity while checking its outcome.'], 409);
        } catch (ValidationException) {
            return $this->json(['code' => 'invalid_request', 'message' => 'Review the request before submitting.'], 422);
        } catch (ItTicketCommandUnavailable) {
            abort(404);
        }
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status, options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
