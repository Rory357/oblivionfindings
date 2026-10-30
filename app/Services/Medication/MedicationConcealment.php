<?php

namespace App\Services\Medication;

use App\Models\User;

/**
 * Controlled medicines for readers without medications.controlled.view
 * (eMAR P02, build note 5):
 *
 * - inside a person's own record the rows stay, redacted, and are counted
 *   ("Controlled medicine · Details need controlled-medicine access");
 * - cross-person lists leave the rows out and count them, because a redacted
 *   row would still show who takes a controlled medicine.
 *
 * Counts, search, exports and the printout follow the same rule. Callers say
 * which rows are controlled; the presenter never guesses.
 */
final class MedicationConcealment
{
    public function __construct(private readonly bool $canViewControlled) {}

    public static function for(?User $actor): self
    {
        return new self((bool) $actor?->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY));
    }

    public function canViewControlled(): bool
    {
        return $this->canViewControlled;
    }

    /** Whether a row with this controlled flag is hidden from this reader. */
    public function hides(bool $controlled): bool
    {
        return $controlled && ! $this->canViewControlled;
    }

    /**
     * Cross-person list: the rows this reader sees, and how many were left out.
     *
     * @template T
     *
     * @param  iterable<int, T>  $rows
     * @param  callable(T): bool  $isControlled
     * @return array{rows: list<T>, hidden: int}
     */
    public function leaveOut(iterable $rows, callable $isControlled): array
    {
        $kept = [];
        $hidden = 0;
        foreach ($rows as $row) {
            if ($this->hides((bool) $isControlled($row))) {
                $hidden++;

                continue;
            }
            $kept[] = $row;
        }

        return ['rows' => $kept, 'hidden' => $hidden];
    }

    /**
     * A person's own record: every row stays, hidden ones replaced by their
     * redacted form, and how many were redacted.
     *
     * @template T
     * @template R
     *
     * @param  iterable<int, T>  $rows
     * @param  callable(T): bool  $isControlled
     * @param  callable(T): R  $redacted
     * @return array{rows: list<T|R>, hidden: int}
     */
    public function redact(iterable $rows, callable $isControlled, callable $redacted): array
    {
        $out = [];
        $hidden = 0;
        foreach ($rows as $row) {
            if ($this->hides((bool) $isControlled($row))) {
                $hidden++;
                $out[] = $redacted($row);

                continue;
            }
            $out[] = $row;
        }

        return ['rows' => $out, 'hidden' => $hidden];
    }
}
