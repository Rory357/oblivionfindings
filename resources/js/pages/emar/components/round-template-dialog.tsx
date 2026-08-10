import type {
    RoundTemplate,
    StaffOption,
} from '@/components/emar/rounds/types';
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, SelectInput, StepHead } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { CalendarDays, ClipboardList, Clock, MapPin } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

const DAYS: { iso: number; label: string }[] = [
    { iso: 1, label: 'Mon' },
    { iso: 2, label: 'Tue' },
    { iso: 3, label: 'Wed' },
    { iso: 4, label: 'Thu' },
    { iso: 5, label: 'Fri' },
    { iso: 6, label: 'Sat' },
    { iso: 7, label: 'Sun' },
];

const STEPS = [
    {
        key: 'schedule',
        label: 'Schedule',
        blurb: 'Name, time, window',
        icon: Clock,
    },
    { key: 'days', label: 'Days', blurb: 'Which weekdays', icon: CalendarDays },
    { key: 'coverage', label: 'Coverage', blurb: 'Site & staff', icon: MapPin },
    { key: 'review', label: 'Review', blurb: 'Confirm', icon: ClipboardList },
];

type Props = {
    template: RoundTemplate | null;
    staff: StaffOption[];
    sites: { id: number; name: string }[];
    onClose: () => void;
};

export default function RoundTemplateDialog({
    template,
    staff,
    sites,
    onClose,
}: Props) {
    const [step, setStep] = useState(0);
    const editing = !!template;
    const form = useForm({
        name: template?.name ?? '',
        scheduled_time: template?.scheduled_time ?? '08:00',
        window_minutes: template?.window_minutes ?? 60,
        days_of_week: template?.days_of_week ?? [],
        site_id: template?.site_id ? String(template.site_id) : '',
        default_assigned_to: template?.default_assigned_to
            ? String(template.default_assigned_to)
            : '',
    });

    const toggleDay = (iso: number) => {
        const set = new Set(form.data.days_of_week);
        if (set.has(iso)) set.delete(iso);
        else set.add(iso);
        form.setData(
            'days_of_week',
            Array.from(set).sort((a, b) => a - b),
        );
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            site_id: data.site_id ? Number(data.site_id) : null,
            default_assigned_to: data.default_assigned_to
                ? Number(data.default_assigned_to)
                : null,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editing ? 'Template updated' : 'Template created',
                );
                onClose();
            },
            onError: () => toast.error('Please check the template details'),
        };
        if (editing) form.put(`/emar/rounds/templates/${template!.id}`, opts);
        else form.post('/emar/rounds/templates', opts);
    };

    const daysLabel =
        form.data.days_of_week.length === 0
            ? 'Every day'
            : DAYS.filter((d) => form.data.days_of_week.includes(d.iso))
                  .map((d) => d.label)
                  .join(', ');

    const footer = (
        <>
            <Button
                variant="ghost"
                onClick={step === 0 ? onClose : () => setStep(step - 1)}
                disabled={form.processing}
            >
                {step === 0 ? 'Cancel' : 'Back'}
            </Button>
            {step < 3 ? (
                <Button
                    onClick={() => setStep(step + 1)}
                    disabled={step === 0 && !form.data.name}
                >
                    Continue
                </Button>
            ) : (
                <Button onClick={submit} disabled={form.processing}>
                    {editing ? 'Save template' : 'Create template'}
                </Button>
            )}
        </>
    );

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={editing ? 'Edit round template' : 'New round template'}
            description="Define a recurring medication round schedule."
            railIcon={ClipboardList}
            railTitle={editing ? 'Edit template' : 'New template'}
            railSubtitle="Round schedule"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 && (
                <>
                    <StepHead
                        icon={Clock}
                        title="Schedule"
                        blurb="Name the round and set its time and window."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Template name"
                            required
                            span
                            error={form.errors.name}
                        >
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Morning round"
                            />
                        </Field>
                        <Field
                            label="Scheduled time"
                            required
                            error={form.errors.scheduled_time}
                        >
                            <Input
                                type="time"
                                value={form.data.scheduled_time}
                                onChange={(e) =>
                                    form.setData(
                                        'scheduled_time',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Window (± minutes)"
                            required
                            error={form.errors.window_minutes}
                        >
                            <Input
                                type="number"
                                min={5}
                                max={120}
                                value={form.data.window_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'window_minutes',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                    </div>
                </>
            )}

            {step === 1 && (
                <>
                    <StepHead
                        icon={CalendarDays}
                        title="Days"
                        blurb="Select the weekdays this round runs. Leave empty for every day."
                    />
                    <div className="flex flex-wrap gap-2">
                        {DAYS.map((d) => {
                            const active = form.data.days_of_week.includes(
                                d.iso,
                            );
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- weekday toggle chip (custom pill, not a <Button>)
                                <button
                                    key={d.iso}
                                    type="button"
                                    onClick={() => toggleDay(d.iso)}
                                    className={cn(
                                        'rounded-full border px-3.5 py-1.5 text-sm font-medium transition',
                                        active
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'text-muted-foreground hover:bg-accent',
                                    )}
                                >
                                    {d.label}
                                </button>
                            );
                        })}
                    </div>
                    <p className="mt-3 text-xs text-muted-foreground">
                        {daysLabel}
                    </p>
                </>
            )}

            {step === 2 && (
                <>
                    <StepHead
                        icon={MapPin}
                        title="Coverage"
                        blurb="Scope the round to a site and a default staff member."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Site" span>
                            <SelectInput
                                value={form.data.site_id}
                                onChange={(v) => form.setData('site_id', v)}
                                placeholder="All sites"
                                options={sites.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field label="Default staff (med-competent)" span>
                            <SelectInput
                                value={form.data.default_assigned_to}
                                onChange={(v) =>
                                    form.setData('default_assigned_to', v)
                                }
                                placeholder="Unassigned"
                                options={staff.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                    </div>
                </>
            )}

            {step === 3 && (
                <>
                    <StepHead
                        icon={ClipboardList}
                        title="Review"
                        blurb="Confirm the round template."
                    />
                    <div className="rounded-lg border px-4">
                        <SummaryRow label="Name" value={form.data.name} />
                        <SummaryRow
                            label="Time"
                            value={`${form.data.scheduled_time} · ±${form.data.window_minutes} min`}
                        />
                        <SummaryRow label="Days" value={daysLabel} />
                        <SummaryRow
                            label="Site"
                            value={
                                sites.find(
                                    (s) => String(s.id) === form.data.site_id,
                                )?.name ?? 'All sites'
                            }
                        />
                        <SummaryRow
                            label="Default staff"
                            value={
                                staff.find(
                                    (s) =>
                                        String(s.id) ===
                                        form.data.default_assigned_to,
                                )?.name ?? 'Unassigned'
                            }
                        />
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}
