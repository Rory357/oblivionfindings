/* HR Reports wizards — the Schedule-report (subscription create/edit) stepper
 * modal, built on the shared HR wizard kit (WizardShell + primitives) so it is
 * visually identical to the Add-Client / asset lifecycle modals. Submits to the
 * existing subscription endpoints (POST /hr/reports/subscriptions and
 * PUT /hr/reports/subscriptions/{id}) with the exact same payload contract as
 * the old inline form. */
import { useForm } from '@inertiajs/react';
import {
    BarChart3,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    Search,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    FieldErr,
    ReviewCard,
    ReviewRow,
    Segmented,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { fireConfetti } from '@/lib/confetti';

/* ------------------------------------------------------------------ */
/*  Shared types (imported by the Reports index page)                  */
/* ------------------------------------------------------------------ */

export interface AvailableReport {
    key: string;
    title: string;
    description: string;
    category: string;
}

export interface ReportSubscription {
    id: number;
    report_type: string;
    cadence: 'daily' | 'weekly' | 'monthly';
    day_of_week: number | null;
    day_of_month: number | null;
    run_at: string;
    timezone: string;
    is_active: boolean;
    next_run_at: string | null;
    last_run_at: string | null;
    last_status: string | null;
    last_error: string | null;
    recipient_user_ids: number[];
    recipient_names: string[];
    filters: {
        date_from: string | null;
        date_to: string | null;
    };
}

export interface RecipientUser {
    id: number;
    name: string;
    email: string;
}

export const WEEKDAY_LABELS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
] as const;

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

/** Flash error carried by an Inertia redirect — `back()->with('error')` fires
 *  onSuccess, not onError (see reference_inertia_flash_error). */
function pageFlashError(page: { props: Record<string, unknown> }): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

/* ================================================================== */
/*  Schedule report (subscription create / edit)                       */
/* ================================================================== */

const SCHEDULE_STEPS: readonly WizardStep[] = [
    { key: 'report', label: 'Report', blurb: 'What to send', icon: BarChart3 },
    { key: 'schedule', label: 'Schedule', blurb: 'Cadence & time', icon: CalendarClock },
    { key: 'recipients', label: 'Recipients', blurb: 'Who receives it', icon: Users },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: CheckCircle2 },
];

export function ScheduleReportWizard({
    subscription,
    reports,
    recipients,
    defaultFilters,
    onClose,
}: {
    /** Existing subscription = edit mode; null = create. */
    subscription: ReportSubscription | null;
    reports: AvailableReport[];
    recipients: RecipientUser[];
    defaultFilters: { date_from: string; date_to: string };
    onClose: () => void;
}) {
    const isEdit = subscription !== null;
    const wizard = useWizard(SCHEDULE_STEPS.length);
    const [done, setDone] = useState(false);
    const [search, setSearch] = useState('');

    const form = useForm({
        report_type: subscription?.report_type ?? reports[0]?.key ?? 'headcount',
        cadence: (subscription?.cadence ?? 'weekly') as 'daily' | 'weekly' | 'monthly',
        day_of_week: String(subscription?.day_of_week ?? 1),
        day_of_month: String(subscription?.day_of_month ?? 1),
        run_at: (subscription?.run_at ?? '08:00').slice(0, 5),
        timezone: subscription?.timezone || 'Pacific/Auckland',
        date_from: subscription?.filters.date_from ?? defaultFilters.date_from ?? '',
        date_to: subscription?.filters.date_to ?? defaultFilters.date_to ?? '',
        recipient_user_ids: (subscription?.recipient_user_ids ?? []).map(String),
    });
    const serverErrors = form.errors as Record<string, string | undefined>;

    const pickedReport = reports.find((r) => r.key === form.data.report_type) ?? null;
    const pickedRecipients = recipients.filter((r) =>
        form.data.recipient_user_ids.includes(String(r.id)),
    );

    const filteredRecipients = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return recipients;
        return recipients.filter((r) =>
            `${r.name} ${r.email}`.toLowerCase().includes(q),
        );
    }, [search, recipients]);

    const toggleRecipient = (id: number) => {
        const key = String(id);
        form.setData(
            'recipient_user_ids',
            form.data.recipient_user_ids.includes(key)
                ? form.data.recipient_user_ids.filter((v) => v !== key)
                : [...form.data.recipient_user_ids, key],
        );
    };

    const scheduleLabel = useMemo(() => {
        if (form.data.cadence === 'daily') return `Daily at ${form.data.run_at}`;
        if (form.data.cadence === 'weekly') {
            const day = WEEKDAY_LABELS[Number(form.data.day_of_week)] ?? 'Monday';
            return `Every ${day} at ${form.data.run_at}`;
        }
        return `Day ${form.data.day_of_month} of each month at ${form.data.run_at}`;
    }, [form.data.cadence, form.data.day_of_week, form.data.day_of_month, form.data.run_at]);

    const scheduleValid =
        /^\d{2}:\d{2}$/.test(form.data.run_at) &&
        form.data.timezone.trim() !== '' &&
        (form.data.cadence !== 'monthly' ||
            (Number(form.data.day_of_month) >= 1 && Number(form.data.day_of_month) <= 28));

    const submit = () => {
        form.transform((data) => ({
            report_type: data.report_type,
            cadence: data.cadence,
            day_of_week: data.cadence === 'weekly' ? Number(data.day_of_week) : null,
            day_of_month: data.cadence === 'monthly' ? Number(data.day_of_month) : null,
            run_at: data.run_at,
            timezone: data.timezone.trim() || 'Pacific/Auckland',
            date_from: data.date_from || null,
            date_to: data.date_to || null,
            recipient_user_ids: data.recipient_user_ids.map(Number),
            is_active: subscription ? subscription.is_active : true,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                if (!isEdit) fireConfetti();
            },
        };

        if (isEdit) {
            form.put(`/hr/reports/subscriptions/${subscription.id}`, options);
        } else {
            form.post('/hr/reports/subscriptions', options);
        }
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={isEdit ? 'Edit report schedule' : 'Schedule a report'}
            description="Deliver an HR report to recipients on a recurring schedule."
            railIcon={CalendarClock}
            railTitle={isEdit ? 'Edit schedule' : 'Schedule report'}
            railSub="Automated delivery"
            steps={SCHEDULE_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={isEdit ? 'Schedule updated' : 'Report scheduled'}
                        blurb={
                            <>
                                “{pickedReport?.title ?? form.data.report_type}” will run{' '}
                                {scheduleLabel.toLowerCase()} ({form.data.timezone}).
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !form.data.report_type || !scheduleValid}
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Update schedule'
                                  : 'Schedule report'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={
                                (wizard.index === 0 && !form.data.report_type) ||
                                (wizard.index === 1 && !scheduleValid)
                            }
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={BarChart3}
                        title="Which report?"
                        blurb="Pick the report to deliver, plus an optional default date window."
                    />
                    <TilePicker
                        value={form.data.report_type}
                        onChange={(v) => form.setData('report_type', v)}
                        options={reports.map((r) => ({
                            key: r.key,
                            label: r.title,
                            description: r.description,
                        }))}
                    />
                    <FieldErr>{serverErrors.report_type}</FieldErr>
                    <div className="mt-4 grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label="Default date from"
                            hint="optional"
                            error={serverErrors.date_from}
                        >
                            <Input
                                type="date"
                                value={form.data.date_from}
                                onChange={(e) => form.setData('date_from', e.target.value)}
                            />
                        </Field>
                        <Field
                            label="Default date to"
                            hint="optional"
                            error={serverErrors.date_to}
                        >
                            <Input
                                type="date"
                                value={form.data.date_to}
                                onChange={(e) => form.setData('date_to', e.target.value)}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="When should it run?"
                        blurb="Choose the cadence and delivery time."
                    />
                    <Field label="Cadence" required>
                        <Segmented
                            value={form.data.cadence}
                            onChange={(v) => form.setData('cadence', v)}
                            options={[
                                { value: 'daily', label: 'Daily' },
                                { value: 'weekly', label: 'Weekly' },
                                { value: 'monthly', label: 'Monthly' },
                            ]}
                        />
                    </Field>
                    <div className="mt-4 grid gap-3.5 sm:grid-cols-2">
                        {form.data.cadence === 'weekly' && (
                            <Field label="Weekday" required error={serverErrors.day_of_week}>
                                <Segmented
                                    value={form.data.day_of_week}
                                    onChange={(v) => form.setData('day_of_week', v)}
                                    options={WEEKDAY_LABELS.map((day, index) => ({
                                        value: String(index),
                                        label: day.slice(0, 3),
                                    }))}
                                />
                            </Field>
                        )}
                        {form.data.cadence === 'monthly' && (
                            <Field
                                label="Day of month"
                                required
                                hint="1–28"
                                error={serverErrors.day_of_month}
                            >
                                <Input
                                    type="number"
                                    min={1}
                                    max={28}
                                    value={form.data.day_of_month}
                                    onChange={(e) => form.setData('day_of_month', e.target.value)}
                                />
                            </Field>
                        )}
                        <Field label="Run at" required error={serverErrors.run_at}>
                            <Input
                                type="time"
                                value={form.data.run_at}
                                onChange={(e) => form.setData('run_at', e.target.value)}
                            />
                        </Field>
                        <Field
                            label="Timezone"
                            required
                            error={serverErrors.timezone}
                        >
                            <Input
                                value={form.data.timezone}
                                onChange={(e) => form.setData('timezone', e.target.value)}
                                placeholder="Pacific/Auckland"
                            />
                        </Field>
                    </div>
                    <p className="mt-3 text-[12.5px] text-muted-foreground">{scheduleLabel}.</p>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Who receives it?"
                        blurb="Pick one or more recipients. Leave empty to deliver the report to you."
                    />
                    <div className="relative mb-3">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search recipients by name or email…"
                            className="pl-8"
                        />
                    </div>
                    <div className="flex max-h-64 flex-col gap-1.5 overflow-y-auto">
                        {filteredRecipients.map((r) => {
                            const active = form.data.recipient_user_ids.includes(String(r.id));
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- selector card (whole-row toggle), matches the AssignWizard staff picker
                                <button
                                    key={r.id}
                                    type="button"
                                    onClick={() => toggleRecipient(r.id)}
                                    className={`flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 text-left transition-colors ${active ? 'border-primary bg-primary/[0.06]' : 'border-border hover:border-primary/50'}`}
                                >
                                    <span className="grid h-9 w-9 flex-none place-items-center rounded-full bg-primary/12 text-[12.5px] font-bold text-primary">
                                        {initials(r.name)}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[13.5px] font-bold">{r.name}</div>
                                        <div className="truncate text-[11.5px] text-muted-foreground">
                                            {r.email}
                                        </div>
                                    </div>
                                    {active ? (
                                        <CheckCircle2 className="h-5 w-5 text-primary" />
                                    ) : null}
                                </button>
                            );
                        })}
                        {filteredRecipients.length === 0 ? (
                            <div className="py-6 text-center text-[13px] text-muted-foreground">
                                No recipients match “{search}”.
                            </div>
                        ) : null}
                    </div>
                    <FieldErr>{serverErrors.recipient_user_ids}</FieldErr>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm the schedule"
                        blurb="Check the details, then save."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={BarChart3} title="Report" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Report" value={pickedReport?.title} />
                            <ReviewRow
                                label="Default window"
                                value={
                                    form.data.date_from || form.data.date_to
                                        ? `${form.data.date_from || '—'} → ${form.data.date_to || '—'}`
                                        : undefined
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={CalendarClock}
                            title="Schedule"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow label="Runs" value={scheduleLabel} />
                            <ReviewRow label="Timezone" value={form.data.timezone} />
                        </ReviewCard>
                        <ReviewCard icon={Users} title="Recipients" onEdit={() => wizard.goTo(2)} span>
                            <ReviewRow
                                label="Delivered to"
                                value={
                                    pickedRecipients.length > 0
                                        ? pickedRecipients.map((r) => r.name).join(', ')
                                        : 'You (current user)'
                                }
                            />
                        </ReviewCard>
                    </div>
                    {form.hasErrors ? (
                        <FieldErr>
                            Some fields need attention — use Edit to jump back to the
                            highlighted step.
                        </FieldErr>
                    ) : null}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
