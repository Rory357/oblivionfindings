/* eslint-disable no-restricted-syntax -- the warden multi-select chips are an
 * intentional custom toggle surface on semantic tokens (mirrors wizard ChipMulti). */
/* Schedule drill wizard — the Add-Client idiom on WizardShell (4 steps, stepper
 * rail, Back/Cancel/Continue footer, last step commits). Replaces the old full-page
 * create form. Posts to /health-safety/drills. */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import {
    SCHEDULE_TYPE_KEYS,
    localToUtcIso,
    typeMeta,
    type StaffOption,
} from '@/pages/health-safety/drills/shared';
import { useForm } from '@inertiajs/react';
import {
    CalendarCheck,
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    MapPin,
    Siren,
    Users,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

const STEPS: WizardStep[] = [
    {
        key: 'type',
        label: 'Site & type',
        blurb: 'Where & what kind',
        icon: MapPin,
    },
    {
        key: 'schedule',
        label: 'Scenario & schedule',
        blurb: 'When it runs',
        icon: CalendarClock,
    },
    {
        key: 'people',
        label: 'Wardens & people',
        blurb: 'Who takes part',
        icon: Users,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & schedule',
        icon: CheckCircle2,
    },
];

export function DrillScheduleDialog({
    open,
    onClose,
    sites,
    staff,
    defaultSiteId = null,
}: {
    open: boolean;
    onClose: () => void;
    sites: { id: number; name: string }[];
    staff: StaffOption[];
    defaultSiteId?: number | null;
}) {
    const [step, setStep] = useState(0);
    const last = STEPS.length - 1;

    const form = useForm({
        site_id: defaultSiteId ? String(defaultSiteId) : '',
        drill_type: 'fire_evacuation',
        date: '',
        time: '',
        title: '',
        scenario_description: '',
        assembly_point: '',
        is_unannounced: false as boolean,
        conducted_by: '',
        total_participants: '',
        warden_ids: [] as number[],
    });

    const typeTiles = useMemo(
        () =>
            SCHEDULE_TYPE_KEYS.map((key) => {
                const m = typeMeta(key);
                return {
                    key,
                    label: m.label,
                    icon: m.icon,
                    description: TYPE_DESCRIPTIONS[key],
                };
            }),
        [],
    );

    const canContinue = (s: number): boolean => {
        if (s === 0) return !!form.data.site_id && !!form.data.drill_type;
        if (s === 1) return !!form.data.date && !!form.data.time;
        if (s === 2) return !!form.data.conducted_by;
        return true;
    };

    const goNext = () => {
        if (step < last && canContinue(step)) setStep((s) => s + 1);
    };
    const goBack = () => setStep((s) => Math.max(0, s - 1));

    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        form.transform((data) => ({
            ...data,
            scheduled_at:
                data.date && data.time
                    ? localToUtcIso(`${data.date}T${data.time}`)
                    : data.date,
        }));
        form.post('/health-safety/drills', {
            preserveScroll: true,
            onSuccess: (page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                ) {
                    form.reset();
                    setStep(0);
                    onClose();
                }
            },
            onError: () => jumpToFirstError(),
        });
    };

    const jumpToFirstError = () => {
        const errs = form.errors as Record<string, string>;
        const stepOf: Record<string, number> = {
            site_id: 0,
            drill_type: 0,
            scheduled_at: 1,
            date: 1,
            time: 1,
            title: 1,
            scenario_description: 1,
            assembly_point: 1,
            conducted_by: 2,
            total_participants: 2,
        };
        const first = Object.keys(errs)
            .map((k) => stepOf[k] ?? last)
            .sort((a, b) => a - b)[0];
        if (first != null) setStep(first);
    };

    const siteName =
        sites.find((s) => String(s.id) === form.data.site_id)?.name ?? '—';
    const coordinatorName =
        staff.find((s) => String(s.id) === form.data.conducted_by)?.name ?? '—';
    const wardenNames = staff
        .filter((s) => form.data.warden_ids.includes(s.id))
        .map((s) => s.name);

    const footerEnd = (
        <>
            {step > 0 ? (
                <Button type="button" variant="outline" onClick={goBack}>
                    <ChevronLeft className="mr-1 h-4 w-4" /> Back
                </Button>
            ) : null}
            <Button type="button" variant="ghost" onClick={onClose}>
                Cancel
            </Button>
            {step < last ? (
                <Button
                    type="button"
                    onClick={goNext}
                    disabled={!canContinue(step)}
                >
                    Continue <ChevronRight className="ml-1 h-4 w-4" />
                </Button>
            ) : (
                <Button
                    type="button"
                    onClick={() => submit()}
                    disabled={form.processing}
                >
                    <Check className="mr-1 h-4 w-4" /> Schedule drill
                </Button>
            )}
        </>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Schedule drill"
            description="New emergency drill"
            railIcon={Siren}
            railTitle="Schedule drill"
            railSub="New emergency drill"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i <= step && setStep(i)}
            pct={null}
            footerEnd={footerEnd}
        >
            {step === 0 ? (
                <WizardStepPane>
                    <StepHead
                        icon={MapPin}
                        title="Site & drill type"
                        blurb="Where, and what kind of drill"
                    />
                    <div className="flex flex-col gap-4">
                        <Field
                            label="Site"
                            required
                            error={form.errors.site_id}
                        >
                            <SelectInput
                                value={form.data.site_id}
                                onChange={(v) => form.setData('site_id', v)}
                                placeholder="Select site"
                                options={sites.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Drill type"
                            required
                            error={form.errors.drill_type}
                        >
                            <TilePicker
                                value={form.data.drill_type}
                                onChange={(v) => form.setData('drill_type', v)}
                                options={typeTiles}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="Scenario & schedule"
                        blurb="When it runs and the scenario brief"
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Date"
                            required
                            error={
                                form.errors.date ??
                                (
                                    form.errors as Record<
                                        string,
                                        string | undefined
                                    >
                                ).scheduled_at
                            }
                        >
                            <Input
                                type="date"
                                value={form.data.date}
                                onChange={(e) =>
                                    form.setData('date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Start time"
                            required
                            error={form.errors.time}
                        >
                            <Input
                                type="time"
                                value={form.data.time}
                                onChange={(e) =>
                                    form.setData('time', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Drill title"
                            error={form.errors.title}
                            span
                        >
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Q2 unannounced fire evacuation"
                            />
                        </Field>
                        <Field
                            label="Assembly point"
                            error={form.errors.assembly_point}
                        >
                            <Input
                                value={form.data.assembly_point}
                                onChange={(e) =>
                                    form.setData(
                                        'assembly_point',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Front car park"
                            />
                        </Field>
                        <Field
                            label="Scenario brief"
                            error={form.errors.scenario_description}
                            span
                        >
                            <Textarea
                                rows={4}
                                value={form.data.scenario_description}
                                onChange={(e) =>
                                    form.setData(
                                        'scenario_description',
                                        e.target.value,
                                    )
                                }
                                placeholder="Describe the scenario, objectives and any special instructions"
                            />
                        </Field>
                        <label className="col-span-full flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_unannounced}
                                onCheckedChange={(v) =>
                                    form.setData('is_unannounced', !!v)
                                }
                            />
                            Unannounced drill (do not notify site staff in
                            advance)
                        </label>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Wardens & participants"
                        blurb="Who runs it and who's expected"
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Drill coordinator"
                            required
                            error={form.errors.conducted_by}
                        >
                            <SelectInput
                                value={form.data.conducted_by}
                                onChange={(v) =>
                                    form.setData('conducted_by', v)
                                }
                                placeholder="Select coordinator"
                                options={staff.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Expected residents"
                            error={form.errors.total_participants}
                        >
                            <Input
                                type="number"
                                min={0}
                                value={form.data.total_participants}
                                onChange={(e) =>
                                    form.setData(
                                        'total_participants',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Fire wardens on shift"
                            hint="Seeds the roll-call"
                            span
                        >
                            <div className="flex flex-wrap gap-1.5">
                                {staff.slice(0, 40).map((s) => {
                                    const active =
                                        form.data.warden_ids.includes(s.id);
                                    return (
                                        <button
                                            key={s.id}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() =>
                                                form.setData(
                                                    'warden_ids',
                                                    active
                                                        ? form.data.warden_ids.filter(
                                                              (id) =>
                                                                  id !== s.id,
                                                          )
                                                        : [
                                                              ...form.data
                                                                  .warden_ids,
                                                              s.id,
                                                          ],
                                                )
                                            }
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                                active
                                                    ? 'border-primary bg-primary/10 text-primary'
                                                    : 'border-border bg-card text-foreground hover:border-primary/50',
                                            )}
                                        >
                                            {active ? (
                                                <Check className="h-3 w-3" />
                                            ) : null}
                                            {s.name}
                                        </button>
                                    );
                                })}
                            </div>
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Review & schedule"
                        blurb="Confirm the drill before it's added to the calendar"
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard
                            icon={MapPin}
                            title="Drill"
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow label="Site" value={siteName} />
                            <ReviewRow
                                label="Drill type"
                                value={typeMeta(form.data.drill_type).label}
                            />
                            <ReviewRow label="Title" value={form.data.title} />
                        </ReviewCard>
                        <ReviewCard
                            icon={CalendarClock}
                            title="Schedule"
                            onEdit={() => setStep(1)}
                        >
                            <ReviewRow label="Date" value={form.data.date} />
                            <ReviewRow
                                label="Start time"
                                value={form.data.time}
                            />
                            <ReviewRow
                                label="Assembly point"
                                value={form.data.assembly_point}
                            />
                            <ReviewRow
                                label="Unannounced"
                                value={form.data.is_unannounced ? 'Yes' : 'No'}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Users}
                            title="People"
                            onEdit={() => setStep(2)}
                            span
                        >
                            <ReviewRow
                                label="Coordinator"
                                value={coordinatorName}
                            />
                            <ReviewRow
                                label="Expected residents"
                                value={form.data.total_participants}
                            />
                            <ReviewRow
                                label="Wardens"
                                value={
                                    wardenNames.length
                                        ? wardenNames.join(', ')
                                        : undefined
                                }
                            />
                        </ReviewCard>
                    </div>
                    <div className="mt-4">
                        <InfoCard icon={CalendarCheck} tone="info">
                            This drill will appear on the site's calendar and
                            the site profile's Drills tab as soon as it's
                            scheduled.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

const TYPE_DESCRIPTIONS: Record<string, string> = {
    fire_evacuation: 'Full building evacuation to the assembly point',
    earthquake: 'Drop, cover and hold',
    lockdown: 'Secure-in-place response',
    tsunami: 'Evacuate to high ground',
};
