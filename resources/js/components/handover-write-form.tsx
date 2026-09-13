import DictateButton from '@/components/dictate-button';
import type { HandoverWorkerNotes } from '@/components/handover-person-notes';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import type { HandoverPerson } from '@/hooks/use-handover-editor';
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
    worker_notes?: HandoverWorkerNotes;
    expected_version?: number | null;
};

export type HandoverWriteFormProps = {
    value: HandoverWriteValue;
    onChange: (next: HandoverWriteValue) => void;
    disabled?: boolean;
    alreadySubmitted?: boolean;
    people?: HandoverPerson[];
    showSharedNotes?: boolean;
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
                    <Button
                        key={`${idPrefix}-${String(opt.v)}`}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        variant={active ? 'default' : 'outline'}
                        onClick={() => onChange(opt.v)}
                        disabled={disabled}
                        className={cn(
                            'h-11 flex-1 rounded-full px-4 text-sm font-medium transition-colors',
                            active
                                ? 'bg-primary text-primary-foreground'
                                : 'border-border bg-background hover:bg-muted',
                            disabled && 'opacity-60',
                        )}
                    >
                        {opt.label}
                    </Button>
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
    people = [],
    showSharedNotes = true,
}: HandoverWriteFormProps) {
    if (alreadySubmitted) {
        return (
            <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-3 text-sm text-status-success dark:border-status-success/50 dark:bg-status-success-bg dark:text-status-success">
                Handover saved for this shift. You're good to clock out.
            </div>
        );
    }

    const set = <K extends keyof HandoverWriteValue>(
        key: K,
        next: HandoverWriteValue[K],
    ) => onChange({ ...value, [key]: next });

    if (value.worker_notes) {
        const notes = value.worker_notes;
        const updatePerson = (
            id: number,
            change: Partial<HandoverWorkerNotes['people'][number]>,
        ) => {
            set('worker_notes', {
                ...notes,
                people: notes.people.map((person) =>
                    person.client_id === id ? { ...person, ...change } : person,
                ),
            });
        };
        return (
            <div className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    Write about each person in their own section, or select “Did
                    not support this person” when appropriate.
                </p>
                {people.map((person) => {
                    const note = notes.people.find(
                        (entry) => entry.client_id === person.id,
                    );
                    if (!note) return null;
                    return (
                        <Card key={person.id} className="gap-3 p-5">
                            <h3 className="text-base font-semibold">
                                {person.name}
                            </h3>
                            <label
                                htmlFor={`handover-person-${person.id}`}
                                className="text-sm font-medium"
                            >
                                What should the next worker know about{' '}
                                {person.name}?
                            </label>
                            <textarea
                                id={`handover-person-${person.id}`}
                                rows={3}
                                maxLength={2000}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-base focus-visible:outline-2 focus-visible:outline-ring"
                                placeholder="What happened, what helped, and what needs to happen next."
                                value={note.notes}
                                disabled={disabled || note.not_supported}
                                onChange={(event) =>
                                    updatePerson(person.id, {
                                        notes: event.target.value,
                                        no_updates: false,
                                    })
                                }
                            />
                            <div className="flex flex-wrap gap-x-6 gap-y-2">
                                <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={note.no_updates}
                                        disabled={
                                            disabled ||
                                            !!note.notes.trim() ||
                                            note.not_supported
                                        }
                                        onCheckedChange={(checked) =>
                                            updatePerson(person.id, {
                                                no_updates: checked === true,
                                            })
                                        }
                                    />
                                    Supported — no updates to pass on
                                </label>
                                <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={note.follow_up_needed}
                                        disabled={
                                            disabled || note.not_supported
                                        }
                                        onCheckedChange={(checked) =>
                                            updatePerson(person.id, {
                                                follow_up_needed:
                                                    checked === true,
                                            })
                                        }
                                    />
                                    Needs follow-up
                                </label>
                                <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={note.not_supported ?? false}
                                        disabled={
                                            disabled ||
                                            !!note.notes.trim() ||
                                            note.no_updates ||
                                            note.follow_up_needed
                                        }
                                        onCheckedChange={(checked) =>
                                            updatePerson(person.id, {
                                                not_supported: checked === true,
                                            })
                                        }
                                    />
                                    Did not support this person
                                </label>
                            </div>
                        </Card>
                    );
                })}
                {showSharedNotes && (
                    <Card className="gap-2 p-5">
                        <label
                            htmlFor="handover-shared-notes"
                            className="text-base font-semibold"
                        >
                            Whole site{' '}
                            <span className="text-sm font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </label>
                        <p className="text-sm text-muted-foreground">
                            Shared practical matters, such as supplies or
                            transport. Keep personal care details in the named
                            sections above.
                        </p>
                        <textarea
                            id="handover-shared-notes"
                            rows={2}
                            maxLength={2000}
                            value={notes.shared_notes}
                            disabled={disabled}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-base focus-visible:outline-2 focus-visible:outline-ring"
                            placeholder="e.g. Fresh towels are in the hallway cupboard."
                            onChange={(event) =>
                                set('worker_notes', {
                                    ...notes,
                                    shared_notes: event.target.value,
                                })
                            }
                        />
                    </Card>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="text-xs text-muted-foreground">
                Quick handover for the next shift — under 30 seconds.
            </div>

            {/* Meds completed */}
            <div className="space-y-1.5">
                <div className="text-sm font-medium">
                    Were all scheduled meds given?
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
                <div className="text-sm font-medium">
                    How was the shift overall?
                </div>
                <div role="radiogroup" className="flex flex-wrap gap-2">
                    {RATING_OPTIONS.map((opt) => {
                        const active = value.shift_rating === opt.value;
                        return (
                            <Button
                                key={opt.value ?? 'unset'}
                                type="button"
                                role="radio"
                                aria-checked={active}
                                variant={active ? 'default' : 'outline'}
                                onClick={() => set('shift_rating', opt.value)}
                                disabled={disabled}
                                className={cn(
                                    'h-11 min-w-24 flex-1 rounded-full px-4 text-sm font-medium transition-colors sm:flex-none',
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'border-border bg-background hover:bg-muted',
                                    disabled && 'opacity-60',
                                )}
                            >
                                {opt.label}
                            </Button>
                        );
                    })}
                </div>
            </div>

            {/* Notes */}
            <div className="space-y-1.5">
                <div className="flex items-center justify-between gap-2">
                    <label
                        htmlFor="handover-notes"
                        className="block text-sm font-medium"
                    >
                        What should the next shift know?{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </label>
                    <DictateButton
                        value={value.handover_notes}
                        onChange={(next) => set('handover_notes', next)}
                        fieldLabel="Handover notes"
                        disabled={disabled}
                    />
                </div>
                <textarea
                    id="handover-notes"
                    rows={3}
                    maxLength={2000}
                    disabled={disabled}
                    value={value.handover_notes}
                    onChange={(e) => set('handover_notes', e.target.value)}
                    placeholder="e.g. Slept well, visitor expected at 10am, fluids low."
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-sm focus:ring-2 focus:ring-ring focus:outline-none disabled:opacity-60"
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
