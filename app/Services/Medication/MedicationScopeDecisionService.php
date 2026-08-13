<?php

namespace App\Services\Medication;

use App\Models\BreakGlassAccessEvent;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The server-authoritative relationship and work-scope boundary for medication
 * writes. Every callback runs while the resolved aggregate rows remain locked.
 */
class MedicationScopeDecisionService
{
    private const GENERIC_NOT_FOUND = 'The requested medication action is not available.';

    private const GENERIC_NOT_ASSIGNED = 'You do not have a current assignment for this medication action.';

    public function __construct(
        private readonly MarScheduleService $schedule,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function forAdministration(
        User $performer,
        Client $submittedClient,
        ClientMedication $submittedMedication,
        Carbon $actionAt,
        ?Carbon $scheduledFor,
        ?int $submittedShiftId,
        ?MedicationRound $submittedRound,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use (
            $performer,
            $submittedClient,
            $submittedMedication,
            $actionAt,
            $scheduledFor,
            $submittedShiftId,
            $submittedRound,
            $callback,
        ) {
            $this->notFoundUnless($actionAt->lessThanOrEqualTo(now()->addMinute()));

            $medication = ClientMedication::query()
                ->whereKey($submittedMedication->getKey())
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);

            $client = Client::query()
                ->whereKey($medication->client_id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($client !== null && (int) $client->id === (int) $submittedClient->getKey());

            $round = null;
            if ($submittedRound !== null) {
                $round = MedicationRound::query()
                    ->whereKey($submittedRound->getKey())
                    ->lockForUpdate()
                    ->first();
                $this->notFoundUnless($round !== null);
                $this->assertRoundCell($performer, $round, $client, $medication, $scheduledFor);
            } elseif ($medication->is_prn) {
                $this->notFoundUnless($scheduledFor === null);
            } else {
                $this->assertScheduledCell($medication, $scheduledFor);
            }
            $this->assertMedicationIsActiveFor($medication, $scheduledFor ?? $actionAt);

            [$shift, $breakGlass] = $this->resolveClientAuthority(
                $performer,
                $client,
                $actionAt,
                $submittedShiftId,
            );

            $decision = new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
                medication: $medication,
                round: $round,
            );

            return $callback($decision);
        }, 3);
    }

    public function forPrnEffectiveness(
        User $performer,
        ClientMedicationAdministration $submittedAdministration,
        Carbon $actionAt,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($performer, $submittedAdministration, $actionAt, $callback) {
            $administration = ClientMedicationAdministration::query()
                ->whereKey($submittedAdministration->getKey())
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($administration !== null && $administration->status === 'given');

            $medication = ClientMedication::query()
                ->whereKey($administration->client_medication_id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless(
                $medication !== null
                && $medication->is_prn
                && (int) $medication->client_id === (int) $administration->client_id
            );
            $this->assertMedicationIsActiveFor($medication, $actionAt);

            $client = Client::query()->whereKey($administration->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null);
            [$shift, $breakGlass] = $this->resolveClientAuthority($performer, $client, $actionAt, null);

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
                medication: $medication,
                administration: $administration,
            ));
        }, 3);
    }

    public function forClient(
        User $performer,
        int $submittedClientId,
        Carbon $actionAt,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($performer, $submittedClientId, $actionAt, $callback) {
            $client = Client::query()->whereKey($submittedClientId)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null);
            [$shift, $breakGlass] = $this->resolveClientAuthority($performer, $client, $actionAt, null);

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
            ));
        }, 3);
    }

    public function forMedication(
        User $performer,
        ClientMedication $submittedMedication,
        Carbon $actionAt,
        Closure $callback,
        bool $requireAdministrable = false,
    ): mixed {
        return DB::transaction(function () use (
            $performer,
            $submittedMedication,
            $actionAt,
            $callback,
            $requireAdministrable,
        ) {
            $medication = ClientMedication::query()
                ->whereKey($submittedMedication->getKey())
                ->whereNull('deleted_at')
                ->whereNull('superseded_by')
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($medication !== null);
            if ($requireAdministrable) {
                $this->assertMedicationIsActiveFor($medication, $actionAt);
            }

            $client = Client::query()->whereKey($medication->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null);
            [$shift, $breakGlass] = $this->resolveClientAuthority($performer, $client, $actionAt, null);

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
                medication: $medication,
            ));
        }, 3);
    }

    public function forPrescription(
        User $performer,
        MedicationPrescriberOrder $submittedPrescription,
        Carbon $actionAt,
        Closure $callback,
    ): mixed {
        return DB::transaction(function () use ($performer, $submittedPrescription, $actionAt, $callback) {
            $prescription = MedicationPrescriberOrder::query()
                ->whereKey($submittedPrescription->getKey())
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($prescription !== null);

            $client = Client::query()->whereKey($prescription->client_id)->lockForUpdate()->first();
            $this->notFoundUnless($client !== null);

            $medication = null;
            if ($prescription->client_medication_id !== null) {
                $medication = ClientMedication::query()
                    ->whereKey($prescription->client_medication_id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
                $this->notFoundUnless(
                    $medication !== null
                    && (int) $medication->client_id === (int) $client->id
                );
            }

            [$shift, $breakGlass] = $this->resolveClientAuthority($performer, $client, $actionAt, null);

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $this->clientSiteId($client),
                shift: $shift,
                breakGlassAccess: $breakGlass,
                medication: $medication,
                prescription: $prescription,
            ));
        }, 3);
    }

    public function forRound(
        User $performer,
        MedicationRound $submittedRound,
        Carbon $actionAt,
        Closure $callback,
        array $allowedStatuses = [],
    ): mixed {
        return DB::transaction(function () use ($performer, $submittedRound, $actionAt, $callback, $allowedStatuses) {
            $round = MedicationRound::query()->whereKey($submittedRound->getKey())->lockForUpdate()->first();
            $this->notFoundUnless($round !== null);
            $siteId = $this->positiveId($round->site_id);
            $this->notFoundUnless(
                $siteId !== null
                && ($allowedStatuses === [] || in_array($round->status, $allowedStatuses, true))
                && ((int) $round->assigned_to === (int) $performer->id
                    || (int) $round->started_by === (int) $performer->id)
            );

            [$shift, $breakGlass, $client] = $this->resolveSiteAuthority($performer, $siteId, $actionAt);

            return $callback(new MedicationScopeDecision(
                performer: $performer,
                client: $client,
                siteId: $siteId,
                shift: $shift,
                breakGlassAccess: $breakGlass,
                round: $round,
            ));
        }, 3);
    }

    public function recordBreakGlassUse(
        MedicationScopeDecision $decision,
        string $action,
        ?string $detail = null,
    ): void {
        if (! $decision->breakGlassAccess) {
            return;
        }

        BreakGlassAccessEvent::query()->create([
            'break_glass_access_id' => $decision->breakGlassAccess->id,
            'action' => mb_substr($action, 0, 100),
            'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
        ]);
    }

    private function assertRoundCell(
        User $performer,
        MedicationRound $round,
        Client $client,
        ClientMedication $medication,
        ?Carbon $scheduledFor,
    ): void {
        $siteId = $this->clientSiteId($client);
        $this->notFoundUnless(
            $round->status === 'in_progress'
            && $this->positiveId($round->site_id) === $siteId
            && ($round->service_context_id === null
                || (int) $round->service_context_id === (int) $client->service_context_id)
            && ((int) $round->assigned_to === (int) $performer->id
                || (int) $round->started_by === (int) $performer->id)
            && ! $medication->is_prn
            && $scheduledFor !== null
        );

        $roundDate = $this->schedule->dateFromInput($round->round_date?->toDateString());
        $roundAt = $roundDate->copy()->setTimeFromTimeString((string) $round->scheduled_time);
        $window = max(0, (int) $round->window_minutes);
        $scheduledLocal = $scheduledFor->copy()->timezone($this->schedule->workerTimezone());

        $this->notFoundUnless(
            $scheduledLocal->toDateString() === $roundDate->toDateString()
            && $scheduledLocal->betweenIncluded(
                $roundAt->copy()->subMinutes($window),
                $roundAt->copy()->addMinutes($window),
            )
        );
        $this->assertScheduledCell($medication, $scheduledFor);
    }

    private function assertScheduledCell(ClientMedication $medication, ?Carbon $scheduledFor): void
    {
        $this->notFoundUnless($scheduledFor !== null && ! $medication->is_prn);
        $date = $this->schedule->dateFromInput(
            $scheduledFor->copy()->timezone($this->schedule->workerTimezone())->toDateString(),
        );
        $matches = collect($this->schedule->scheduledTimesForDate($medication, $date))
            ->contains(fn (Carbon $slot): bool => abs($slot->copy()->utc()->diffInSeconds($scheduledFor->copy()->utc(), false)) < 60);
        $this->notFoundUnless($matches);
    }

    private function assertMedicationIsActiveFor(ClientMedication $medication, Carbon $at): void
    {
        $date = $at->copy()->timezone($this->schedule->workerTimezone())->toDateString();
        if ($medication->state !== 'active'
            || ! (bool) $medication->active
            || $medication->superseded_by !== null
            || $medication->deleted_at !== null
            || ($medication->start_date && $date < $medication->start_date->toDateString())
            || ($medication->end_date && $date > $medication->end_date->toDateString())) {
            throw ValidationException::withMessages([
                'medication' => self::GENERIC_NOT_FOUND,
            ]);
        }
    }

    /** @return array{0: Shift|null, 1: ClientBreakGlassAccess|null} */
    private function resolveClientAuthority(
        User $performer,
        Client $client,
        Carbon $actionAt,
        ?int $submittedShiftId,
    ): array {
        $siteId = $this->clientSiteId($client);
        $this->notFoundUnless(in_array(
            $siteId,
            $this->siteAccess->accessibleSiteIds($performer, ['clinical.accessAllSites', 'sites.viewAll']),
            true,
        ));
        $shiftQuery = $this->coveringShiftQuery($performer, $siteId, $actionAt)
            ->where(function (Builder $scope) use ($client): void {
                $scope->where('client_id', $client->id);

                if (Schema::hasTable('shift_clients')) {
                    $scope->orWhereExists(function ($query) use ($client): void {
                        $query->selectRaw('1')
                            ->from('shift_clients')
                            ->whereColumn('shift_clients.shift_id', 'shifts.id')
                            ->where('shift_clients.client_id', $client->id);
                    });
                }
            });

        if ($submittedShiftId !== null) {
            $shiftQuery->whereKey($submittedShiftId);
        }

        $shift = $shiftQuery->lockForUpdate()->orderByDesc('actual_starts_at')->first();
        if ($shift) {
            return [$shift, null];
        }

        return [null, $this->activeBreakGlass($performer, $client, $siteId, $actionAt)];
    }

    /** @return array{0: Shift|null, 1: ClientBreakGlassAccess|null, 2: Client|null} */
    private function resolveSiteAuthority(User $performer, int $siteId, Carbon $actionAt): array
    {
        $this->notFoundUnless(in_array(
            $siteId,
            $this->siteAccess->accessibleSiteIds($performer, ['clinical.accessAllSites', 'sites.viewAll']),
            true,
        ));
        $shift = $this->coveringShiftQuery($performer, $siteId, $actionAt)
            ->lockForUpdate()
            ->orderByDesc('actual_starts_at')
            ->first();
        if ($shift) {
            $client = Client::query()
                ->where('site_id', $siteId)
                ->whereKey($shift->client_id)
                ->lockForUpdate()
                ->first();
            $this->notFoundUnless($client !== null);

            return [$shift, null, $client];
        }

        // Emergency access is client-specific and minimum-necessary. It must
        // never widen into authority over a whole round or its residents.
        $this->notAssignedUnless(false);

        return [null, null, null];
    }

    private function coveringShiftQuery(User $performer, int $siteId, Carbon $actionAt): Builder
    {
        $utc = $actionAt->copy()->utc();

        return Shift::query()
            ->where('user_id', $performer->id)
            ->where(function (Builder $site) use ($siteId): void {
                $site->where('site_id', $siteId)
                    ->orWhere(function (Builder $derived) use ($siteId): void {
                        $derived->whereNull('site_id')
                            ->whereHas('client', fn (Builder $clients) => $clients->where('site_id', $siteId));
                    });
            })
            ->whereNotNull('actual_starts_at')
            ->where('actual_starts_at', '<=', $utc)
            ->where(function (Builder $scope) use ($utc): void {
                $scope->where(function (Builder $active): void {
                    $active->where('status', 'in_progress')->whereNull('actual_ends_at');
                })->orWhere(function (Builder $completed) use ($utc): void {
                    $completed->where('status', 'completed')
                        ->whereNotNull('actual_ends_at')
                        ->where('actual_ends_at', '>=', $utc);
                });
            });
    }

    private function activeBreakGlass(
        User $performer,
        Client $client,
        int $siteId,
        Carbon $actionAt,
    ): ClientBreakGlassAccess {
        $this->notAssignedUnless($performer->canDo('medications.breakglass'));
        $this->notAssignedUnless(in_array($siteId, $this->siteAccess->accessibleSiteIds($performer), true));

        $access = ClientBreakGlassAccess::query()
            ->where('client_id', $client->id)
            ->where('user_id', $performer->id)
            ->where('created_at', '<=', $actionAt->copy()->utc())
            ->where('expires_at', '>=', $actionAt->copy()->utc())
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->latest('created_at')
            ->first();
        $this->notAssignedUnless($access !== null && $this->isCanonicalBreakGlass($access, $siteId));

        return $access;
    }

    private function isCanonicalBreakGlass(ClientBreakGlassAccess $access, int $siteId): bool
    {
        if ($access->created_at === null
            || $access->expires_at === null
            || ! in_array($access->authorization_mode, ['self', 'co_sign'], true)
            || ! $access->acknowledged_min_necessary
            || ! $access->acknowledged_incident_report
            || ($access->authorization_mode === 'co_sign'
                && ($access->co_signed_by === null || (int) $access->co_signed_by === (int) $access->user_id))) {
            return false;
        }

        $policy = BreakGlassPolicy::current();
        if ($policy->reason_required && blank($access->reason)) {
            return false;
        }

        if ($access->authorization_mode === 'co_sign') {
            $coSigner = User::query()
                ->whereKey($access->co_signed_by)
                ->whereNotNull('approved_at')
                ->first();
            if (! $coSigner
                || (! $coSigner->canDo('medications.breakglass') && ! $coSigner->canDo('medications.audit.view'))
                || ! in_array($siteId, $this->siteAccess->accessibleSiteIds($coSigner), true)) {
                return false;
            }
        }

        $duration = $access->created_at->diffInMinutes($access->expires_at, false);

        return $duration >= 5 && $duration <= (int) $policy->max_minutes;
    }

    private function clientSiteId(Client $client): int
    {
        $siteId = $this->positiveId($client->site_id);
        $this->notFoundUnless($siteId !== null);

        return $siteId;
    }

    private function positiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function notFoundUnless(bool $condition): void
    {
        abort_unless($condition, 404, self::GENERIC_NOT_FOUND);
    }

    private function notAssignedUnless(bool $condition): void
    {
        abort_unless($condition, 403, self::GENERIC_NOT_ASSIGNED);
    }
}
