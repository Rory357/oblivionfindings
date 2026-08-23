<?php

namespace App\Services\Respite;

use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RespiteShiftSync
{
    public function ensureShiftForBooking(RespiteBooking $booking, ?int $actorId = null, ?int $serviceContextId = null): Shift
    {
        return DB::transaction(function () use ($booking, $actorId, $serviceContextId): Shift {
            [$booking, $siteId] = $this->canonicalBooking($booking);
            $serviceContextId ??= $booking->request?->service_context_id
                ?: $booking->client?->service_context_id
                ?: ServiceContext::defaultIdForSite($siteId);

            $shift = $this->lockedShiftForBooking(
                $booking,
                $siteId,
                true,
                $actorId,
                $serviceContextId,
            );
            $this->syncShiftBinding($shift, $booking, $siteId, $serviceContextId);

            return $shift;
        }, 3);
    }

    public function syncBooking(RespiteBooking $booking, ?int $previousSiteId = null): void
    {
        DB::transaction(function () use ($booking, $previousSiteId): void {
            [$booking, $siteId] = $this->canonicalBooking($booking);
            $serviceContextId = $booking->request?->service_context_id
                ?: $booking->client?->service_context_id
                ?: ServiceContext::defaultIdForSite($siteId);
            $shift = $this->lockedShiftForBooking(
                $booking,
                $siteId,
                true,
                auth()->id(),
                $serviceContextId,
                $previousSiteId,
            );
            if ($shift->status === 'cancelled'
                && ! in_array($booking->status, ['cancelled', 'no_show'], true)) {
                $this->fail('A cancelled respite Shift cannot be changed by an active booking.');
            }
            if ($shift->status === 'completed' && $booking->status !== 'completed') {
                $this->fail('A completed respite Shift cannot be changed by an active booking.');
            }
            $this->syncShiftBinding($shift, $booking, $siteId, $serviceContextId);

            if ($shift->status === 'completed') {
                return;
            }

            if ($booking->status === 'cancelled') {
                $this->cancelShiftForBooking($booking, $shift);

                return;
            }

            if ($booking->status === 'no_show') {
                $shift->update([
                    'status' => 'cancelled',
                    'notes' => $this->appendNote($shift->notes, 'Respite booking recorded as no show.'),
                ]);
            }
        }, 3);
    }

    public function checkInStay(RespiteStay $stay, ?Carbon $checkedInAt = null, ?int $actorId = null): void
    {
        DB::transaction(function () use ($stay, $checkedInAt, $actorId): void {
            [$stay, $booking, $siteId] = $this->canonicalStayGraph($stay);
            $serviceContextId = $booking->request?->service_context_id
                ?: $booking->client?->service_context_id
                ?: ServiceContext::defaultIdForSite($siteId);
            $shift = $this->lockedShiftForBooking(
                $booking,
                $siteId,
                true,
                $actorId,
                $serviceContextId,
            );
            $this->assertShiftIsCurrent($shift, 'checked in');
            $this->syncShiftBinding($shift, $booking, $siteId, $serviceContextId);

            $shift->update([
                'actual_starts_at' => $shift->actual_starts_at ?: ($checkedInAt ?: $stay->actual_start ?: now()),
                'started_by' => $shift->started_by ?: $actorId,
                'status' => 'in_progress',
            ]);
        }, 3);
    }

    public function extendStay(RespiteStay $stay, Carbon $newEnd): void
    {
        DB::transaction(function () use ($stay, $newEnd): void {
            [$stay, $booking, $siteId] = $this->canonicalStayGraph($stay);
            $serviceContextId = $booking->request?->service_context_id
                ?: $booking->client?->service_context_id
                ?: ServiceContext::defaultIdForSite($siteId);
            $shift = $this->lockedShiftForBooking(
                $booking,
                $siteId,
                true,
                auth()->id(),
                $serviceContextId,
            );
            $this->assertShiftIsCurrent($shift, 'extended');

            $booking->update([
                'end_at' => $newEnd,
                'updated_by' => auth()->id(),
            ]);
            $booking->end_at = $newEnd;
            $this->syncShiftBinding($shift, $booking, $siteId, $serviceContextId);
        }, 3);
    }

    public function dischargeStay(RespiteStay $stay, string $summary, ?Carbon $dischargedAt = null, ?int $actorId = null): void
    {
        DB::transaction(function () use ($stay, $summary, $dischargedAt, $actorId): void {
            [$stay, $booking, $siteId] = $this->canonicalStayGraph($stay);
            $serviceContextId = $booking->request?->service_context_id
                ?: $booking->client?->service_context_id
                ?: ServiceContext::defaultIdForSite($siteId);
            $shift = $this->lockedShiftForBooking(
                $booking,
                $siteId,
                true,
                $actorId,
                $serviceContextId,
            );
            $this->assertShiftIsCurrent($shift, 'completed');
            $this->syncShiftBinding($shift, $booking, $siteId, $serviceContextId);

            $completedAt = $dischargedAt ?: $stay->actual_end ?: now();
            $shift->update([
                'actual_starts_at' => $shift->actual_starts_at ?: $stay->actual_start ?: $completedAt,
                'actual_ends_at' => $shift->actual_ends_at ?: $completedAt,
                'started_by' => $shift->started_by ?: $actorId,
                'completed_by' => $shift->completed_by ?: $actorId,
                'status' => 'completed',
                'notes' => $this->appendNote($shift->notes, 'Respite discharge summary: '.$summary),
            ]);
        }, 3);
    }

    /**
     * @return array{0: RespiteBooking, 1: int}
     */
    private function canonicalBooking(RespiteBooking $submitted): array
    {
        $candidate = RespiteBooking::query()->find($submitted->id);
        if (! $candidate || (int) $candidate->client_id !== (int) $submitted->client_id) {
            $this->fail('The respite booking and Client binding is no longer valid.');
        }

        $client = Client::query()->lockForUpdate()->find($candidate->client_id);
        if (! $client || ! $client->site_id) {
            $this->fail('The respite booking and Client binding is no longer valid.');
        }

        $siteIds = collect([$client->site_id, $candidate->location_id])
            ->filter(fn ($siteId) => (int) $siteId > 0)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->sort()
            ->values();
        $sites = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('id', $siteIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        if ($sites->count() !== $siteIds->count()) {
            $this->fail('The respite booking Site is no longer active.');
        }

        $sourceRequest = null;
        if ($candidate->booking_request_id) {
            $sourceRequest = RespiteBookingRequest::withTrashed()
                ->whereKey($candidate->booking_request_id)
                ->where('client_id', $candidate->client_id)
                ->lockForUpdate()
                ->first();
        }
        $booking = RespiteBooking::query()
            ->whereKey($candidate->id)
            ->where('client_id', $candidate->client_id)
            ->lockForUpdate()
            ->first();
        if (! $booking || ($booking->booking_request_id && ! $sourceRequest)) {
            $this->fail('The respite booking and Client binding is no longer valid.');
        }

        $siteId = (int) ($booking->location_id ?: $client->site_id);
        if ($siteId <= 0 || ! $siteIds->contains($siteId)) {
            $this->fail('The respite booking requires one canonical Site.');
        }

        $booking->setRelation('client', $client);
        if ($sourceRequest) {
            $booking->setRelation('request', $sourceRequest);
        }

        return [$booking, $siteId];
    }

    /**
     * @return array{0: RespiteStay, 1: RespiteBooking, 2: int}
     */
    private function canonicalStayGraph(RespiteStay $submitted): array
    {
        $submittedBooking = new RespiteBooking([
            'client_id' => $submitted->client_id,
        ]);
        $submittedBooking->id = $submitted->booking_id;
        [$booking, $siteId] = $this->canonicalBooking($submittedBooking);

        $stay = RespiteStay::query()
            ->whereKey($submitted->id)
            ->where('booking_id', $booking->id)
            ->where('client_id', $booking->client_id)
            ->lockForUpdate()
            ->first();
        if (! $stay) {
            $this->fail('The respite stay, booking, and Client binding is no longer valid.');
        }

        $stay->setRelation('booking', $booking);
        $stay->setRelation('client', $booking->client);

        return [$stay, $booking, $siteId];
    }

    private function lockedShiftForBooking(
        RespiteBooking $booking,
        int $siteId,
        bool $create,
        ?int $actorId = null,
        ?int $serviceContextId = null,
        ?int $previousSiteId = null,
    ): ?Shift {
        if ($serviceContextId !== null) {
            $serviceContext = ServiceContext::query()
                ->whereKey($serviceContextId)
                ->where('is_active', true)
                ->availableToSite($siteId)
                ->lockForUpdate()
                ->first();
            if (! $serviceContext) {
                $this->fail('The respite Shift Service Context does not match the booking Site.');
            }
        }

        $shifts = Shift::query()
            ->where('respite_booking_id', $booking->id)
            ->lockForUpdate()
            ->get();
        if ($shifts->count() > 1) {
            $this->fail('The respite booking has multiple linked Shifts and cannot transition safely.');
        }

        /** @var Shift|null $shift */
        $shift = $shifts->first();
        if (! $shift && $create) {
            return Shift::create([
                'client_id' => $booking->client_id,
                'site_id' => $siteId,
                'respite_booking_id' => $booking->id,
                'service_context_id' => $serviceContextId,
                'user_id' => null,
                'starts_at' => $booking->start_at,
                'ends_at' => $booking->end_at,
                'status' => 'scheduled',
                'created_by' => $actorId,
            ]);
        }

        if (! $shift) {
            return null;
        }

        $siteMatches = $shift->site_id === null
            || (int) $shift->site_id === $siteId
            || ($previousSiteId !== null && (int) $shift->site_id === $previousSiteId);
        $contextMatches = $serviceContextId === null
            || $shift->service_context_id === null
            || (int) $shift->service_context_id === $serviceContextId;
        if ((int) $shift->client_id !== (int) $booking->client_id || ! $siteMatches || ! $contextMatches) {
            $this->fail('The linked respite Shift does not match the booking Client, Site, and Service Context.');
        }

        return $shift;
    }

    private function syncShiftBinding(
        Shift $shift,
        RespiteBooking $booking,
        int $siteId,
        ?int $serviceContextId,
    ): void {
        $shift->update([
            'client_id' => $booking->client_id,
            'site_id' => $siteId,
            'service_context_id' => $serviceContextId ?: $shift->service_context_id,
            'starts_at' => $booking->start_at,
            'ends_at' => $booking->end_at,
        ]);
    }

    private function assertShiftIsCurrent(Shift $shift, string $action): void
    {
        if (in_array($shift->status, ['cancelled', 'completed'], true)) {
            $this->fail("A {$shift->status} respite Shift cannot be {$action}.");
        }
    }

    private function cancelShiftForBooking(RespiteBooking $booking, Shift $shift): void
    {
        $reason = trim((string) $booking->cancellation_reason);

        $shift->update([
            'status' => 'cancelled',
            'notes' => $reason !== ''
                ? $this->appendNote($shift->notes, 'Respite booking cancelled: '.$reason)
                : $shift->notes,
        ]);
    }

    private function appendNote(?string $notes, string $line): string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? $line : $notes."\n\n".$line;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['shift' => $message]);
    }
}
