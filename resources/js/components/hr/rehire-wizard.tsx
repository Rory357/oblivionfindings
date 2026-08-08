/* Re-hire wizard — the full welcome-back workflow for a former employee.
 * Built on the shared HR wizard kit (WizardShell + primitives) so it matches
 * every other HR stepper modal. Archives the outgoing stint into the profile's
 * employment history server-side, reactivates the profile onto the new
 * engagement, restores login access, and (optionally) sends an invite and
 * generates a fresh onboarding checklist for the new stint. */
import { useForm } from '@inertiajs/react';
import {
    Briefcase,
    CalendarDays,
    CheckCircle2,
    History,
    Settings2,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { fireConfetti } from '@/lib/confetti';

/* ------------------------------------------------------------------ */
/*  Public types                                                      */
/* ------------------------------------------------------------------ */

export interface EmploymentStint {
    start_date: string | null;
    end_date: string | null;
    position_title: string | null;
    position_role: string | null;
    employment_type: string | null;
    archived_at?: string | null;
}

export interface RehireTarget {
    profileId: number;
    name: string;
    /** Previous stint (the profile's current columns before re-hire). */
    startDate: string | null;
    endDate: string | null;
    positionTitle: string | null;
    positionRole: string | null;
    employmentType: string | null;
    hoursPerWeek: number | null;
    primarySiteId: number | null;
    /** Earlier archived stints, oldest first. */
    employmentHistory: EmploymentStint[];
}

const STEPS: readonly WizardStep[] = [
    {
        key: 'welcome',
        label: 'Welcome back',
        blurb: 'Previous employment',
        icon: History,
    },
    {
        key: 'engagement',
        label: 'New engagement',
        blurb: 'Start date, role & site',
        icon: Briefcase,
    },
    {
        key: 'options',
        label: 'Options',
        blurb: 'Invite & onboarding',
        icon: Settings2,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & re-hire',
        icon: CheckCircle2,
    },
];

const NO_SITE = '__none__';

const EMPLOYMENT_TYPES = [
    { value: 'full_time', label: 'Full time' },
    { value: 'part_time', label: 'Part time' },
    { value: 'casual', label: 'Casual' },
    { value: 'fixed_term', label: 'Fixed term' },
    { value: 'contractor', label: 'Contractor' },
];

const today = () => new Date().toISOString().slice(0, 10);

function fdate(v?: string | null): string {
    if (!v) return '—';
    const d = new Date(v);
    return isNaN(d.getTime())
        ? v
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}

function typeLabel(v?: string | null): string {
    if (!v) return '—';
    return v.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Flash error carried by an Inertia redirect (logic-guard). Read from the page
 *  passed to onSuccess — `back()->with('error')` fires onSuccess, not onError
 *  (see reference_inertia_flash_error). */
function pageFlashError(page: {
    props: Record<string, unknown>;
}): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

/* ------------------------------------------------------------------ */
/*  Wizard                                                            */
/* ------------------------------------------------------------------ */

export function RehireWizard({
    target,
    sites,
    onClose,
}: {
    target: RehireTarget;
    sites: Array<{ id: number; name: string }>;
    onClose: () => void;
}) {
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);

    const typeOptions = EMPLOYMENT_TYPES.some(
        (t) => t.value === target.employmentType,
    )
        ? EMPLOYMENT_TYPES
        : target.employmentType
          ? [
                {
                    value: target.employmentType,
                    label: typeLabel(target.employmentType),
                },
                ...EMPLOYMENT_TYPES,
            ]
          : EMPLOYMENT_TYPES;

    const form = useForm({
        start_date: today(),
        position_title: target.positionTitle ?? '',
        position_role: target.positionRole ?? '',
        employment_type: target.employmentType ?? 'full_time',
        primary_site_id:
            target.primarySiteId != null
                ? String(target.primarySiteId)
                : NO_SITE,
        hours_per_week:
            target.hoursPerWeek != null ? String(target.hoursPerWeek) : '',
        send_invite: true,
        start_onboarding: true,
    });

    // All stints, newest first: the outgoing engagement (still on the live
    // columns) followed by anything already archived.
    const stints: EmploymentStint[] = [
        {
            start_date: target.startDate,
            end_date: target.endDate,
            position_title: target.positionTitle,
            position_role: target.positionRole,
            employment_type: target.employmentType,
        },
        ...[...target.employmentHistory].reverse(),
    ];

    const siteName =
        form.data.primary_site_id !== NO_SITE
            ? (sites.find((s) => String(s.id) === form.data.primary_site_id)
                  ?.name ?? '—')
            : 'No primary site';

    const canSubmit = form.data.start_date.trim() !== '';

    const submit = () => {
        form.transform((data) => ({
            ...data,
            primary_site_id:
                data.primary_site_id === NO_SITE
                    ? null
                    : Number(data.primary_site_id),
            hours_per_week:
                data.hours_per_week === '' ? null : Number(data.hours_per_week),
        }));
        form.post(`/hr/people/${target.profileId}/rehire`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                fireConfetti();
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Re-hire employee"
            description="Bring a former employee back onto the team."
            railIcon={UserCheck}
            railTitle="Re-hire"
            railSub={target.name}
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Welcome back!"
                        blurb={
                            <>
                                {target.name} has been re-hired starting{' '}
                                {fdate(form.data.start_date)}.
                                {form.data.start_onboarding
                                    ? ' A fresh onboarding checklist has been generated.'
                                    : ''}
                                {form.data.send_invite
                                    ? ' A login invite is on its way.'
                                    : ''}
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
                            disabled={form.processing || !canSubmit}
                        >
                            {form.processing
                                ? 'Re-hiring…'
                                : 'Re-hire employee'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={wizard.index === 1 && !canSubmit}
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
                        icon={History}
                        title={`Welcome back, ${target.name.split(' ')[0]}`}
                        blurb="Their previous employment stays on record — a re-hire starts a new stint on the same profile."
                    />
                    <div className="space-y-2.5">
                        {stints.map((stint, i) => (
                            // eslint-disable-next-line no-restricted-syntax -- compact stint summary row matching the wizard-kit ReviewCard chrome, not a Card
                            <div
                                key={i}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border bg-card/70 px-4 py-3"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold">
                                        {stint.position_title || 'Employee'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {typeLabel(stint.employment_type)}
                                    </p>
                                </div>
                                <span className="text-xs font-medium text-muted-foreground tabular-nums">
                                    {fdate(stint.start_date)} →{' '}
                                    {fdate(stint.end_date)}
                                </span>
                            </div>
                        ))}
                    </div>
                    <div className="mt-4">
                        <InfoCard icon={History}>
                            Re-hiring archives this stint into the profile's
                            employment history, restores their login, and starts
                            a fresh engagement — compliance, training and
                            document records carry over.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Briefcase}
                        title="New engagement"
                        blurb="Confirm the terms they're coming back on — prefilled from their previous stint."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label="Start date"
                            required
                            error={form.errors.start_date}
                        >
                            <Input
                                type="date"
                                value={form.data.start_date}
                                onChange={(e) =>
                                    form.setData('start_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Employment type"
                            error={form.errors.employment_type}
                        >
                            <SelectInput
                                value={form.data.employment_type}
                                onChange={(v) =>
                                    form.setData('employment_type', v)
                                }
                                placeholder="Employment type"
                                options={typeOptions}
                            />
                        </Field>
                        <Field
                            label="Position title"
                            error={form.errors.position_title}
                        >
                            <Input
                                value={form.data.position_title}
                                onChange={(e) =>
                                    form.setData(
                                        'position_title',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Support Worker"
                            />
                        </Field>
                        <Field
                            label="Position role"
                            hint="System role key, e.g. support_worker"
                            error={form.errors.position_role}
                        >
                            <Input
                                value={form.data.position_role}
                                onChange={(e) =>
                                    form.setData(
                                        'position_role',
                                        e.target.value,
                                    )
                                }
                                placeholder="support_worker"
                                className="font-mono"
                            />
                        </Field>
                        <Field
                            label="Primary site"
                            error={form.errors.primary_site_id}
                        >
                            <SelectInput
                                value={form.data.primary_site_id}
                                onChange={(v) =>
                                    form.setData('primary_site_id', v)
                                }
                                placeholder="Primary site"
                                options={[
                                    {
                                        value: NO_SITE,
                                        label: 'No primary site',
                                    },
                                    ...sites.map((s) => ({
                                        value: String(s.id),
                                        label: s.name,
                                    })),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Hours per week"
                            error={form.errors.hours_per_week}
                        >
                            <Input
                                type="number"
                                min={0}
                                max={168}
                                step="0.5"
                                value={form.data.hours_per_week}
                                onChange={(e) =>
                                    form.setData(
                                        'hours_per_week',
                                        e.target.value,
                                    )
                                }
                                placeholder="40"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={Settings2}
                        title="Options"
                        blurb="How much of the new-starter flow should run again?"
                    />
                    <div className="space-y-2.5">
                        <label className="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-border bg-card/70 px-4 py-3.5">
                            <span>
                                <span className="block text-sm font-semibold">
                                    Send login invite
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    Emails a set-your-password link — their old
                                    access was revoked when they left.
                                </span>
                            </span>
                            <Switch
                                checked={form.data.send_invite}
                                onCheckedChange={(v) =>
                                    form.setData('send_invite', v)
                                }
                                aria-label="Send login invite"
                            />
                        </label>
                        <label className="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-border bg-card/70 px-4 py-3.5">
                            <span>
                                <span className="block text-sm font-semibold">
                                    Start onboarding
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    Generates a fresh onboarding checklist for
                                    this stint — even though they've onboarded
                                    before.
                                </span>
                            </span>
                            <Switch
                                checked={form.data.start_onboarding}
                                onCheckedChange={(v) =>
                                    form.setData('start_onboarding', v)
                                }
                                aria-label="Start onboarding"
                            />
                        </label>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Review & confirm"
                        blurb={`One last look before ${target.name} rejoins the team.`}
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <ReviewCard
                            icon={CalendarDays}
                            title="New engagement"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Start date"
                                value={fdate(form.data.start_date)}
                            />
                            <ReviewRow
                                label="Position"
                                value={form.data.position_title}
                            />
                            <ReviewRow
                                label="Type"
                                value={typeLabel(form.data.employment_type)}
                            />
                            <ReviewRow label="Site" value={siteName} />
                            <ReviewRow
                                label="Hours / week"
                                value={form.data.hours_per_week || undefined}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Settings2}
                            title="Options"
                            onEdit={() => wizard.goTo(2)}
                        >
                            <ReviewRow
                                label="Login invite"
                                value={
                                    form.data.send_invite ? 'Send now' : 'Skip'
                                }
                            />
                            <ReviewRow
                                label="Onboarding"
                                value={
                                    form.data.start_onboarding
                                        ? 'Fresh checklist'
                                        : 'Skip'
                                }
                            />
                            <ReviewRow
                                label="Previous stint"
                                value={`${fdate(target.startDate)} → ${fdate(target.endDate)}`}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default RehireWizard;
