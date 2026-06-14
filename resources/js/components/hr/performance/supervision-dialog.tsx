/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import { CalendarClock, ClipboardCheck, MessagesSquare } from 'lucide-react';
import { useMemo } from 'react';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { PeoplePicker, type PersonOption } from '../people-picker';
import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '../wizard';

export interface SupervisionStaff {
    id: number;
    name: string;
    email?: string;
}

export interface SessionTypeOption {
    value: string;
    label: string;
}

export interface ExistingSupervisionNote {
    id: number;
    employee_user_id?: number | null;
    staff_user?: { id: number; name: string } | null;
    session_type: string;
    session_date: string | null;
    duration_minutes: number | null;
    topics_discussed: string | null;
    actions_agreed: string[] | null;
    next_session_date: string | null;
    is_visible_to_employee: boolean;
}

const STEPS: readonly WizardStep[] = [
    { key: 'session', label: 'Session', blurb: 'Who & what', icon: MessagesSquare },
    { key: 'actions', label: 'Actions', blurb: 'Follow-up', icon: CalendarClock },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ClipboardCheck },
];

const toLines = (v: string[] | null | undefined) => (v ?? []).join('\n');
const fromLines = (v: string) =>
    v
        .split('\n')
        .map((s) => s.trim())
        .filter((s) => s !== '');

/**
 * Create / edit a supervision note in a WizardShell modal, replacing the
 * page-based create-supervision + edit-supervision forms. Posts to
 * hr.performance.supervision (store) or PUTs .update. topics_discussed is
 * required (the column is NOT NULL). Employee is fixed in edit mode.
 */
export function SupervisionDialog({
    open,
    onClose,
    staff,
    sessionTypes,
    note,
}: {
    open: boolean;
    onClose: () => void;
    staff: SupervisionStaff[];
    sessionTypes: SessionTypeOption[];
    note?: ExistingSupervisionNote | null;
}) {
    const isEdit = !!note;
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        employee_user_id: string;
        session_type: string;
        session_date: string;
        duration_minutes: string;
        topics_discussed: string;
        actions_text: string;
        next_session_date: string;
        is_visible_to_employee: boolean;
    }>({
        employee_user_id: note?.staff_user?.id
            ? String(note.staff_user.id)
            : note?.employee_user_id
              ? String(note.employee_user_id)
              : '',
        session_type: note?.session_type ?? '',
        session_date: note?.session_date ?? '',
        duration_minutes: note?.duration_minutes ? String(note.duration_minutes) : '',
        topics_discussed: note?.topics_discussed ?? '',
        actions_text: toLines(note?.actions_agreed),
        next_session_date: note?.next_session_date ?? '',
        is_visible_to_employee: note?.is_visible_to_employee ?? true,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () =>
            staff.map((s) => ({
                value: String(s.id),
                label: s.name,
                sub: s.email,
            })),
        [staff],
    );

    const employeeName =
        note?.staff_user?.name ??
        staff.find((s) => String(s.id) === form.data.employee_user_id)?.name ??
        '—';
    const typeLabel =
        sessionTypes.find((t) => t.value === form.data.session_type)?.label ?? '—';

    const canSubmit =
        form.data.employee_user_id !== '' &&
        form.data.session_type !== '' &&
        form.data.session_date !== '' &&
        form.data.topics_discussed.trim() !== '';

    const submit = () => {
        form.transform((data) => ({
            ...data,
            duration_minutes: data.duration_minutes || null,
            next_session_date: data.next_session_date || null,
            actions_agreed: fromLines(data.actions_text),
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (
                    form.errors.employee_user_id ||
                    form.errors.session_type ||
                    form.errors.session_date ||
                    form.errors.topics_discussed
                ) {
                    wizard.goTo(0);
                }
            },
        };

        if (isEdit) {
            form.put(`/hr/performance/supervision/${note!.id}`, opts);
        } else {
            form.post('/hr/performance/supervision', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit supervision note' : 'New supervision note'}
            description="Record a supervision, 1:1 or check-in session."
            railIcon={MessagesSquare}
            railTitle={isEdit ? 'Edit note' : 'New note'}
            railSub="Supervision"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            footerStart={
                wizard.isFirst ? null : (
                    <button
                        type="button"
                        onClick={wizard.back}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Back
                    </button>
                )
            }
            footerEnd={
                <>
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Cancel
                    </button>
                    {wizard.isLast ? (
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!canSubmit || form.processing}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                (!canSubmit || form.processing) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Record note'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                        >
                            Continue
                        </button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={MessagesSquare}
                        title="Session"
                        blurb="Who the session was with, the type, date and what was discussed."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Staff member"
                            required
                            span
                            error={form.errors.employee_user_id}
                        >
                            {isEdit ? (
                                <Input value={employeeName} disabled />
                            ) : (
                                <PeoplePicker
                                    value={form.data.employee_user_id}
                                    onChange={(v) =>
                                        form.setData('employee_user_id', v)
                                    }
                                    people={people}
                                    placeholder="Select a staff member…"
                                />
                            )}
                        </Field>
                        <Field
                            label="Session type"
                            required
                            error={form.errors.session_type}
                        >
                            <SelectInput
                                value={form.data.session_type}
                                onChange={(v) => form.setData('session_type', v)}
                                placeholder="Select a type"
                                options={sessionTypes}
                            />
                        </Field>
                        <Field
                            label="Session date"
                            required
                            error={form.errors.session_date}
                        >
                            <Input
                                type="date"
                                value={form.data.session_date}
                                onChange={(e) =>
                                    form.setData('session_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Duration (minutes)"
                            hint="optional"
                            error={form.errors.duration_minutes}
                        >
                            <Input
                                type="number"
                                min="1"
                                value={form.data.duration_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'duration_minutes',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. 30"
                            />
                        </Field>
                        <Field
                            label="Topics discussed"
                            required
                            span
                            error={form.errors.topics_discussed}
                        >
                            <Textarea
                                rows={4}
                                value={form.data.topics_discussed}
                                onChange={(e) =>
                                    form.setData(
                                        'topics_discussed',
                                        e.target.value,
                                    )
                                }
                                placeholder="What was covered in the session…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="Actions & follow-up"
                        blurb="Agreed actions (one per line), the next session date, and visibility."
                    />
                    <div className="space-y-4">
                        <Field label="Actions agreed" hint="one per line">
                            <Textarea
                                rows={4}
                                value={form.data.actions_text}
                                onChange={(e) =>
                                    form.setData('actions_text', e.target.value)
                                }
                                placeholder={'Complete medication competency by month-end\nBook first-aid refresher'}
                            />
                        </Field>
                        <Field
                            label="Next session date"
                            hint="optional"
                            error={form.errors.next_session_date}
                        >
                            <Input
                                type="date"
                                value={form.data.next_session_date}
                                onChange={(e) =>
                                    form.setData(
                                        'next_session_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <label className="flex items-start gap-2.5 rounded-lg border p-3 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.is_visible_to_employee}
                                onChange={(e) =>
                                    form.setData(
                                        'is_visible_to_employee',
                                        e.target.checked,
                                    )
                                }
                                className="mt-0.5 rounded border-border"
                            />
                            <span>
                                <span className="block font-medium">
                                    Visible to the employee
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    The employee can see this note and acknowledge
                                    it.
                                </span>
                            </span>
                        </label>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & save"
                        blurb="Confirm the supervision note before saving."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={MessagesSquare}
                            title="Session"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Staff" value={employeeName} />
                            <ReviewRow label="Type" value={typeLabel} />
                            <ReviewRow
                                label="Date"
                                value={form.data.session_date}
                            />
                            <ReviewRow
                                label="Duration"
                                value={
                                    form.data.duration_minutes
                                        ? `${form.data.duration_minutes} min`
                                        : undefined
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={CalendarClock}
                            title="Follow-up"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Actions"
                                value={
                                    fromLines(form.data.actions_text).length
                                        ? `${fromLines(form.data.actions_text).length} action(s)`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Next session"
                                value={form.data.next_session_date}
                            />
                            <ReviewRow
                                label="Visible to staff"
                                value={
                                    form.data.is_visible_to_employee
                                        ? 'Yes'
                                        : 'No'
                                }
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default SupervisionDialog;
