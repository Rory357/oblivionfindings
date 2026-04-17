import { cn } from '@/lib/utils';

/* -------------------------------------------------------------------------- */
/*  Handover write form — shown at clock-out                                  */
/* -------------------------------------------------------------------------- */
/*
 * PR 11 — Structured, 30-second handover captured at shift end. Rendered
 * inside the clock-out confirmation dialog so the worker flows naturally
 * from "ending shift" → "what the next shift should know" without a second
 * confirmation step or a separate route.
 *
 * Shape chosen to fit the existing `ShiftHandover` columns:
 *   - meds_completed   → `medications_due` (populated when false)
 *   - shift_rating     → `client_mood`     (calm | mixed | challenging)
 *   - notes            → `handover_notes`
 *   - follow_up_needed → `follow_up_items`
 *
 * Deliberately kept compact: four short questions, large tap targets, no
 * freeform required. All fields except notes default to a sensible value so
 * the worker can submit after a couple of taps.
 */

export type HandoverWriteValue = {
    meds_completed: boolean;
    shift_rating: 'calm' | 'mixed' | 'challenging' | null;
    handover_notes: string;
    follow_up_needed: boolean;
};

export type HandoverWriteFormProps = {
    value: HandoverWriteValue;
    onChange: (next: HandoverWriteValue) => void;
    disabled?: boolean;
    alreadySubmitted?: boolean;
};

export const emptyHandoverWriteValue: HandoverWriteValue = {
    meds_completed: true,
    shift_rating: null,
    handover_notes: '',
    follow_up_needed: false,
};

const RATING_OPTIONS: ReadonlyArray<{
    value: HandoverWriteValue['shift_rating'];
    label: string;
}> = [
    { value: 'calm', label: 'Calm' },
    { value: 'mixed', label: 'Mixed' },
    { value: 'challenging', label: 'Challenging' },
];

function YesNoToggle({
    value,
    onChange,
    idPrefix,
    disabled,
}: {
    value: boolean;
    onChange: (v: boolean) => void;
    idPrefix: string;
    disabled?: boolean;
}) {
    return (
        <div role="radiogroup" className="flex gap-2" aria-disabled={disabled}>
            {[
                { v: true, label: 'Yes' },
                { v: false, label: 'No' },
            ].map((opt) => {
                const active = value === opt.v;
                return (
                    <button
                        key={`${idPrefix}-${String(opt.v)}`}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(opt.v)}
                        disabled={disabled}
                        className={cn(
                            'h-11 flex-1 rounded-full border px-4 text-sm font-medium transition-colors',
                            active
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background hover:bg-muted',
                            disabled && 'opacity-60',
                        )}
                    >
                        {opt.label}
                    </button>
                );
            })}
        </div>
    );
}

export default function HandoverWriteForm({
    value,
    onChange,
    disabled,
    alreadySubmitted,
}: HandoverWriteFormProps) {
    if (alreadySubmitted) {
        return (
            <div className="rounded-lg border border-emerald-200 bg-emerald-50/70 p-3 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100">
                Handover already saved for this shift. You're good to clock out.
            </div>
        );
    }

    const set = <K extends keyof HandoverWriteValue>(
        key: K,
        next: HandoverWriteValue[K],
    ) => onChange({ ...value, [key]: next });

    return (
        <div className="space-y-4">
            <div className="text-xs text-muted-foreground">
                Quick handover for the next shift — under 30 seconds.
            </div>

            {/* Meds completed */}
            <div className="space-y-1.5">
                <div className="text-sm font-medium">
                    Was all scheduled medication completed?
                </div>
                <YesNoToggle
                    value={value.meds_completed}
                    onChange={(v) => set('meds_completed', v)}
                    idPrefix="meds"
                    disabled={disabled}
                />
            </div>

            {/* Shift rating */}
            <div className="space-y-1.5">
                <div className="text-sm font-medium">How was the shift overall?</div>
                <div role="radiogroup" className="flex flex-wrap gap-2">
                    {RATING_OPTIONS.map((opt) => {
                        const active = value.shift_rating === opt.value;
                        return (
                            <button
                                key={opt.value ?? 'unset'}
                                type="button"
                                role="radio"
                                aria-checked={active}
                                onClick={() => set('shift_rating', opt.value)}
                                disabled={disabled}
                                className={cn(
                                    'h-11 min-w-24 flex-1 rounded-full border px-4 text-sm font-medium transition-colors sm:flex-none',
                                    active
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-background hover:bg-muted',
                                    disabled && 'opacity-60',
                                )}
                            >
                                {opt.label}
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* Notes */}
            <div className="space-y-1.5">
                <label
                    htmlFor="handover-notes"
                    className="block text-sm font-medium"
                >
                    Anything the next shift should know?{' '}
                    <span className="font-normal text-muted-foreground">
                        (optional)
                    </span>
                </label>
                <textarea
                    id="handover-notes"
                    rows={3}
                    maxLength={2000}
                    disabled={disabled}
                    value={value.handover_notes}
                    onChange={(e) => set('handover_notes', e.target.value)}
                    placeholder="e.g. Slept well, visitor expected at 10am, fluids low."
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-sm focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-60"
                />
            </div>

            {/* Follow up */}
            <div className="space-y-1.5">
                <div className="text-sm font-medium">
                    Anything urgent to follow up?
                </div>
                <YesNoToggle
                    value={value.follow_up_needed}
                    onChange={(v) => set('follow_up_needed', v)}
                    idPrefix="followup"
                    disabled={disabled}
                />
            </div>
        </div>
    );
}
