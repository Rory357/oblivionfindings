/**
 * The effective window for giving a scheduled dose, as the server applies it
 * (`MarScheduleService::windowBeforeMinutes()` / `windowAfterMinutes()` — the
 * org's `medications.mar.*` settings, falling back to `config/medications.php`).
 * Always take these values from the server payload; never hard-code a copy.
 */
export type DoseWindow = {
    early_minutes: number;
    late_minutes: number;
};

export type DoseTiming = 'early' | 'on_time' | 'late';

/**
 * Where an administration time falls against a scheduled dose's window.
 * Mirrors `MedicationSafetyService::validateTimeWindow()`: the edges are
 * inside the window. Returns null when the timing can't be worked out, and
 * the server stays the final check.
 */
export function doseTiming(
    scheduledFor: string | null | undefined,
    administeredAt: string | null | undefined,
    window: DoseWindow | null | undefined,
): DoseTiming | null {
    if (!scheduledFor || !administeredAt || !window) return null;

    const scheduled = new Date(scheduledFor).getTime();
    const administered = new Date(administeredAt).getTime();
    if (Number.isNaN(scheduled) || Number.isNaN(administered)) return null;

    const diffMinutes = (administered - scheduled) / 60000;
    if (diffMinutes < -window.early_minutes) return 'early';
    if (diffMinutes > window.late_minutes) return 'late';

    return 'on_time';
}
