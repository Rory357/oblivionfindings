/* Generate-rounds modal — first of the eMAR Action-centre accelerators built on
 * the shared Add-Client wizard chrome (MedsWizardDialog + wizard/primitives).
 * Posts to emar.rounds.generate. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Field,
    InfoCard,
    Segmented,
    StepHead,
} from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import { CalendarClock, Info, RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

const STEPS = [
    {
        key: 'configure',
        label: 'Configure',
        blurb: 'Date & scope',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review & generate',
        blurb: 'Confirm',
        icon: RefreshCw,
    },
];

export function GenerateRoundsModal({
    open,
    onClose,
    defaultDate,
}: {
    open: boolean;
    onClose: () => void;
    defaultDate: string;
}) {
    const [step, setStep] = useState(0);
    const [date, setDate] = useState(defaultDate);
    const [scope, setScope] = useState<'today' | 'all'>('today');
    const [saving, setSaving] = useState(false);

    const dateLabel = useMemo(() => {
        const d = new Date(`${date}T00:00:00`);
        return Number.isNaN(d.getTime())
            ? date
            : d.toLocaleDateString('en-NZ', {
                  weekday: 'long',
                  day: 'numeric',
                  month: 'long',
              });
    }, [date]);

    const close = () => {
        setStep(0);
        onClose();
    };

    const submit = () => {
        setSaving(true);
        router.post(
            '/emar/rounds/generate',
            { date, generate_all: scope === 'all' },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Rounds generated for ' + dateLabel);
                    close();
                },
                onError: () => toast.error('Could not generate rounds'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const footer = (
        <>
            <Button
                variant="ghost"
                onClick={step === 0 ? close : () => setStep(0)}
                disabled={saving}
            >
                {step === 0 ? 'Cancel' : 'Back'}
            </Button>
            {step === 0 ? (
                <Button onClick={() => setStep(1)}>Continue</Button>
            ) : (
                <Button onClick={submit} disabled={saving}>
                    <RefreshCw className="h-4 w-4" />
                    {saving ? 'Generating…' : 'Generate rounds'}
                </Button>
            )}
        </>
    );

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Generate medication rounds"
            description="Create the day's medication rounds from active round templates."
            railIcon={RefreshCw}
            railTitle="Generate rounds"
            railSubtitle="From round templates"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={CalendarClock}
                        title="Configure the rounds"
                        blurb="Pick the day and which templates to apply."
                    />
                    <Field label="Round date" required span>
                        <Label className="sr-only">Round date</Label>
                        {/* eslint-disable-next-line no-restricted-syntax -- native date input; no shadcn date control in the wizard primitives. */}
                        <input
                            type="date"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                            className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                        />
                    </Field>
                    <Field
                        label="Which templates"
                        hint="Day-matched applies only templates scheduled for that weekday"
                        span
                    >
                        <Segmented
                            value={scope}
                            onChange={setScope}
                            options={[
                                {
                                    value: 'today',
                                    label: 'Day-matched templates',
                                },
                                { value: 'all', label: 'All active templates' },
                            ]}
                        />
                    </Field>
                    <InfoCard icon={Info}>
                        Existing rounds for a template on this day are skipped,
                        so it's safe to re-run. Generated rounds start as{' '}
                        <strong>pending</strong> and can be assigned afterwards.
                    </InfoCard>
                </div>
            ) : (
                <div>
                    <StepHead
                        icon={RefreshCw}
                        title="Review & generate"
                        blurb="Confirm the rounds to create."
                    />
                    <div className="rounded-lg border border-border">
                        <div className="px-4">
                            <SummaryRow label="Date" value={dateLabel} />
                            <SummaryRow
                                label="Templates"
                                value={
                                    scope === 'all'
                                        ? 'All active templates'
                                        : 'Templates scheduled for this weekday'
                                }
                            />
                            <SummaryRow
                                label="Duplicates"
                                value="Skipped (idempotent)"
                                tone="success"
                            />
                        </div>
                    </div>
                </div>
            )}
        </MedsWizardDialog>
    );
}

export default GenerateRoundsModal;
