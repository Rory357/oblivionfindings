<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class MedicationScopeDecision
{
    /** @param  Collection<int, Shift>|null  $lockedPresenceShifts */
    public function __construct(
        public User $performer,
        public ?Client $client,
        public int $siteId,
        public ?Shift $shift = null,
        public ?ClientBreakGlassAccess $breakGlassAccess = null,
        public ?ClientMedication $medication = null,
        public ?MedicationRound $round = null,
        public ?ClientMedicationAdministration $administration = null,
        public ?MedicationPrescriberOrder $prescription = null,
        public ?Collection $lockedPresenceShifts = null,
        public ?CarbonInterface $lockedPresenceEffectiveAt = null,
    ) {}

    public function shiftId(): ?int
    {
        return $this->shift?->getKey();
    }

    public function usedBreakGlass(): bool
    {
        return $this->breakGlassAccess !== null;
    }
}
