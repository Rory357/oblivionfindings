<?php

namespace App\Http\Controllers\It;

use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItTicketBookingService;
use App\Domain\It\Services\ItTicketVersionService;
use App\Domain\It\Services\ItTicketWorkService;
use App\Domain\It\Services\ItTicketWorkTime;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Models\ItTicket;
use App\Models\ItTicketBooking;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketCost;
use App\Models\ItTicketTimeEntry;
use App\Models\ItTicketWorkRevision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ItTicketWorkController extends Controller
{
    public function __construct(private readonly ItTicketWorkService $work, private readonly ItWorkAccessService $access) {}

    public function store(Request $request, ItTicket $ticket)
    {
        abort_unless($request->user() && $this->access->canWork($request->user(), $ticket), 404);

        return response()->json($this->work->execute($ticket, $request->user(), $request->all()))->header('Cache-Control', 'no-store, private');
    }

    public function people(Request $request, ItTicket $ticket)
    {
        $this->work->guard($ticket, $request->user(), false);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'kind' => ['required', 'in:technician,user'],
            'starts_at' => ['nullable', 'string', 'max:40'], 'ends_at' => ['required_with:starts_at', 'nullable', 'string', 'max:40'], 'except_booking_id' => ['nullable', 'integer']]);
        $query = trim($input['q'] ?? '');
        $users = $input['kind'] === 'technician' ? ItStaffDirectory::agentsForTicket($ticket)
            : User::query()->staff()->whereNotNull('approved_at')->when($query !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$query.'%')->orWhere('email', 'like', '%'.$query.'%')->orWhere('work_phone', 'like', '%'.$query.'%')))->orderBy('name')->limit(200)->get();
        $period = isset($input['starts_at']) ? app(ItTicketWorkTime::class)->period($input, 'booking', false) : null;
        $except = isset($input['except_booking_id']) ? ItTicketBooking::where('ticket_id', $ticket->id)->findOrFail($input['except_booking_id'])->id : null;
        $options = $users->filter(function ($person) use ($ticket, $query, $input) {
            if ($query !== '' && ! str_contains(mb_strtolower($person->name.' '.$person->email.' '.$person->work_phone), mb_strtolower($query))) {
                return false;
            }
            if ($input['kind'] === 'technician') {
                return true;
            }

            return $ticket->is_organisation_wide || in_array((int) $ticket->site_id, $this->access->approvedSiteIds($person), true);
        })->take(40)->map(fn ($person) => [...$this->contact($person), 'busy' => $period ? app(ItTicketBookingService::class)->busy($person, $period['starts_at'], $period['ends_at'], $except) : null])->values()->all();

        return response()->json(['options' => $options, 'availability_scope' => 'Ticket bookings, approved leave, time off, published shifts and local personal/site calendars. External calendars may contain unsynchronised commitments.'])->header('Cache-Control', 'no-store, private');
    }

    public function recover(Request $request, ItTicket $ticket, string $requestUuid)
    {
        $this->work->guard($ticket, $request->user(), false);
        $input = $request->validate(['operation' => ['required', 'in:context,book,booking,cost,correct_time,correct_note,review']]);
        $receipt = ItTicketCommandReceipt::where('it_ticket_id', $ticket->id)->where('actor_user_id', $request->user()->id)
            ->where('channel', 'browser')->where('operation', 'work.'.$input['operation'])->where('request_uuid', $requestUuid)->firstOrFail();
        abort_unless($receipt->committed_at || ($receipt->result_metadata['state'] ?? null) === 'cancelled', 404);

        return response()->json(['status' => $receipt->committed_at ? 'committed' : 'cancelled', 'request_uuid' => $receipt->request_uuid, 'lock_version' => $receipt->committed_ticket_version])->header('Cache-Control', 'no-store, private');
    }

    public function cancel(Request $request, ItTicket $ticket, string $requestUuid)
    {
        $input = $request->validate(['operation' => ['required', 'in:context,book,booking,cost,correct_time,correct_note,review']]);

        return DB::transaction(function () use ($request, $ticket, $requestUuid, $input) {
            $ticket = ItTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $actor = app(ItTicketVersionService::class)->currentActor($request->user());
            $this->work->guard($ticket, $actor, false);
            $receipt = ItTicketCommandReceipt::where('actor_user_id', $actor->id)->where('channel', 'browser')
                ->where('operation', 'work.'.$input['operation'])->where('request_uuid', $requestUuid)->lockForUpdate()->first();
            if ($receipt) {
                abort_unless((int) $receipt->it_ticket_id === (int) $ticket->id, 404);
            } else {
                $receipt = ItTicketCommandReceipt::create(['actor_user_id' => $actor->id, 'channel' => 'browser', 'operation' => 'work.'.$input['operation'],
                    'request_uuid' => $requestUuid, 'request_hash' => hash('sha256', 'cancelled:'.$requestUuid), 'it_ticket_id' => $ticket->id,
                    'result_metadata' => ['state' => 'cancelled', 'cancelled_at' => now()->toIso8601String()]]);
            }

            return response()->json(['status' => $receipt->committed_at ? 'committed' : 'cancelled', 'request_uuid' => $requestUuid])->header('Cache-Control', 'no-store, private');
        });
    }

    public function split(Request $request, ItTicket $ticket)
    {
        $this->work->guard($ticket, $request->user(), false);

        return response()->json(app(ItTicketWorkTime::class)->split($request->all(), $ticket->sla_policy_snapshot['calendar'] ?? null))->header('Cache-Control', 'no-store, private');
    }

    public function payload(ItTicket $ticket, User $actor): ?array
    {
        if (! $this->access->canWork($actor, $ticket)) {
            return null;
        }
        if (! $this->work->ready()) {
            return ['ready' => false];
        }
        $details = $this->work->details($ticket);
        $entries = ItTicketTimeEntry::where('ticket_id', $ticket->id)->orderByDesc('starts_at')->get();
        $bookings = ItTicketBooking::where('ticket_id', $ticket->id)->orderBy('starts_at')->get();
        $costs = ItTicketCost::where('ticket_id', $ticket->id)->orderByDesc('id')->get();
        $revisions = ItTicketWorkRevision::where('ticket_id', $ticket->id)->orderByDesc('id')->limit(200)->get();
        $ids = collect([$ticket->requester_user_id, $ticket->requested_for_user_id, $details['alternate_user_id'] ?? null, $details['follow_up']['owner_user_id'] ?? null])
            ->merge($entries->pluck('technician_user_id'))->merge($entries->pluck('recorded_by'))->merge($entries->pluck('approver_user_id'))
            ->merge($bookings->pluck('technician_user_id'))->merge($bookings->pluck('recorded_by'))->merge($costs->pluck('recorded_by'))->merge($costs->pluck('approver_user_id'))->merge($revisions->pluck('actor_user_id'))->filter()->unique();
        $people = User::whereKey($ids)->get()->keyBy('id');
        $named = fn ($records) => $records->map(fn ($record) => [...$record->toArray(),
            'technician_name' => $people->get($record->getAttribute('technician_user_id'))?->name,
            'recorded_by_name' => $people->get($record->recorded_by)?->name ?? 'Former staff member',
            'approver_name' => $people->get($record->getAttribute('approver_user_id'))?->name])->all();

        return ['ready' => true, 'timezone' => config('app.worker_timezone', 'Pacific/Auckland'), 'details' => $details,
            'requester' => $this->contact($people->get($ticket->requester_user_id)), 'affected_user' => $this->contact($people->get($ticket->requested_for_user_id)),
            'alternate_contact' => $this->contact($people->get($details['alternate_user_id'] ?? null)),
            'follow_up_owner' => $people->get($details['follow_up']['owner_user_id'] ?? null)?->name,
            'entries' => $named($entries), 'bookings' => $named($bookings), 'costs' => $named($costs),
            'revisions' => $revisions->map(fn ($row) => [...$row->toArray(), 'actor_name' => $people->get($row->actor_user_id)?->name ?? 'Former staff member'])->all(),
            'recipients' => ItStaffDirectory::watchersForTicket($ticket)->reject(fn ($u) => (int) $u->id === (int) $actor->id)->map(fn ($u) => $this->contact($u))->values()->all(),
            'totals' => ['minutes' => $entries->sum('minutes'), 'after_hours_minutes' => $entries->where('after_hours', true)->sum('minutes'),
                'cost_cents' => $costs->sum('total_cents'), 'labour_cents' => $entries->sum(fn ($entry) => $entry->hourly_rate_cents === null ? 0 : intdiv($entry->minutes * $entry->hourly_rate_cents + 30, 60)),
                'unpriced_minutes' => $entries->whereNull('hourly_rate_cents')->sum('minutes')]];
    }

    private function contact(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'work_phone' => $user->work_phone] : null;
    }
}
