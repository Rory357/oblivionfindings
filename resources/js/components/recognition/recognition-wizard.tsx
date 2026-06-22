/* eslint-disable no-restricted-syntax -- Wizard footer/preview use native
 * buttons + on-card surfaces to match the Add-Client modal chrome (see
 * components/wizard/shell.tsx). Every colour is a semantic design token. */
import { useForm } from '@inertiajs/react';
import {
    ClipboardCheck,
    Gift,
    Hand,
    Heart,
    Lightbulb,
    Sparkles,
    Star,
    ThumbsUp,
    Trophy,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { PeopleMultiPicker, type PersonOption } from '@/components/hr/people-picker';
import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type IconType,
    type WizardStep,
} from '@/components/hr/wizard';

export interface RecognitionPerson {
    id: number;
    name: string;
    role?: string | null;
    site?: string | null;
    email?: string | null;
}

export type RecognitionDefaults = {
    recipients?: string[];
    category?: string;
    impact?: string;
    message?: string;
    /** Open the wizard at this step (e.g. 1 when the recipient is pre-filled). */
    openStep?: number;
};

const STEPS: readonly WizardStep[] = [
    { key: 'recipients', label: 'Recipients', blurb: 'Who to recognise', icon: Users },
    { key: 'recognition', label: 'Recognition', blurb: 'Value, impact & message', icon: Sparkles },
    { key: 'review', label: 'Review', blurb: 'Confirm & send', icon: ClipboardCheck },
];

const CATEGORY_ICONS: Record<string, IconType> = {
    teamwork: Users,
    innovation: Lightbulb,
    leadership: Star,
    customer_focus: Heart,
    going_above: Trophy,
    other: Gift,
};

const IMPACT_ICONS: Record<string, IconType> = {
    thank_you: Hand,
    good_job: ThumbsUp,
    impressive: Star,
    exceptional: Trophy,
};

const DEFAULT_IMPACT = 'good_job';

type RecognitionForm = {
    to_user_ids: string[];
    category: string;
    impact: string;
    message: string;
};

/**
 * The shared **Give recognition** wizard — multi-recipient kudos with a value
 * (category) + impact + message, on the Add-Client modal shell. Posts to
 * `hr.feed.kudos` (FeedController@sendKudos, multi-recipient + impact aware), the
 * single backend path every recognition surface writes through. Mount it from the
 * feed hero, `/hr/my`, `/hr/my/shoutouts` and any "recognise" button — pass
 * `defaults` to pre-fill (e.g. congratulate a celebrant).
 */
export function RecognitionWizard({
    open,
    onClose,
    onSuccess,
    employees,
    kudosCategories,
    kudosImpacts,
    defaults,
}: {
    open: boolean;
    onClose: () => void;
    onSuccess?: () => void;
    employees: RecognitionPerson[];
    kudosCategories: Record<string, string>;
    kudosImpacts: Record<string, string>;
    defaults?: RecognitionDefaults;
}) {
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);
    const form = useForm<RecognitionForm>({
        to_user_ids: defaults?.recipients ?? [],
        category: defaults?.category ?? '',
        impact: defaults?.impact ?? DEFAULT_IMPACT,
        message: defaults?.message ?? '',
    });
    const { setData, reset, clearErrors } = form;

    // Re-seed from defaults each time the wizard opens.
    useEffect(() => {
        if (!open) return;
        setData({
            to_user_ids: defaults?.recipients ?? [],
            category: defaults?.category ?? '',
            impact: defaults?.impact ?? DEFAULT_IMPACT,
            message: defaults?.message ?? '',
        });
        setDone(false);
        wizard.goTo(defaults?.openStep ?? 0);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        reset();
        clearErrors();
        wizard.reset();
        setDone(false);
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () =>
            employees.map((e) => ({
                value: String(e.id),
                label: e.name,
                sub: [e.role, e.site].filter(Boolean).join(' · ') || e.email || undefined,
            })),
        [employees],
    );

    const categoryTiles = useMemo(
        () =>
            Object.entries(kudosCategories).map(([key, label]) => ({
                key,
                label,
                icon: CATEGORY_ICONS[key] ?? Gift,
            })),
        [kudosCategories],
    );

    const impactOptions = useMemo(
        () =>
            Object.entries(kudosImpacts).map(([value, label]) => ({
                value,
                label,
                icon: IMPACT_ICONS[value],
            })),
        [kudosImpacts],
    );

    const recipientNames = form.data.to_user_ids
        .map((id) => employees.find((e) => String(e.id) === id)?.name)
        .filter(Boolean)
        .join(', ');
    const categoryLabel = kudosCategories[form.data.category] ?? '—';
    const impactLabel = kudosImpacts[form.data.impact] ?? '—';

    // Required-field completeness for the rail meter.
    const pct = useMemo(() => {
        let filled = 0;
        if (form.data.to_user_ids.length > 0) filled++;
        if (form.data.category) filled++;
        if (form.data.message.trim()) filled++;
        return Math.round((filled / 3) * 100);
    }, [form.data]);

    const stepValid = (i: number): boolean => {
        if (i === 0) return form.data.to_user_ids.length > 0;
        if (i === 1) return form.data.category !== '' && form.data.message.trim() !== '';
        return true;
    };

    const submit = (addAnother: boolean) => {
        form.post('/hr/feed/kudos', {
            preserveScroll: true,
            onSuccess: () => {
                onSuccess?.();
                if (addAnother) {
                    reset();
                    clearErrors();
                    wizard.goTo(0);
                } else {
                    setDone(true);
                }
            },
            onError: () => {
                if (form.errors.to_user_ids || form.errors['to_user_ids.0']) wizard.goTo(0);
                else if (form.errors.category || form.errors.message || form.errors.impact) {
                    wizard.goTo(1);
                }
            },
        });
    };

    const successPane = (
        <WizardSuccessPane
            title="Recognition sent 🎉"
            blurb={
                <>
                    Your kudos to <strong>{recipientNames || 'your colleagues'}</strong> is
                    now on the community wall and counts toward this month's recognition.
                </>
            }
            actions={
                <>
                    <button
                        type="button"
                        onClick={() => {
                            reset();
                            clearErrors();
                            wizard.goTo(0);
                            setDone(false);
                        }}
                        className="rounded-md border border-border px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted"
                    >
                        Recognise someone else
                    </button>
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90"
                    >
                        Done
                    </button>
                </>
            }
        />
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Give recognition"
            description="Send kudos to colleagues for their great work."
            railIcon={Heart}
            railTitle="Give recognition"
            railSub="Community & Recognition"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={pct}
            success={done ? successPane : undefined}
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
                        <>
                            <button
                                type="button"
                                onClick={() => submit(true)}
                                disabled={!stepValid(0) || !stepValid(1) || form.processing}
                                className={cn(
                                    'rounded-md border border-border px-3 py-2 text-sm font-semibold text-foreground hover:bg-muted',
                                    (!stepValid(0) || !stepValid(1) || form.processing) &&
                                        'cursor-not-allowed opacity-50',
                                )}
                            >
                                Save &amp; add another
                            </button>
                            <button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={!stepValid(0) || !stepValid(1) || form.processing}
                                className={cn(
                                    'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                    (!stepValid(0) || !stepValid(1) || form.processing) &&
                                        'cursor-not-allowed opacity-50',
                                )}
                            >
                                {form.processing ? 'Sending…' : 'Send recognition'}
                            </button>
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            disabled={!stepValid(wizard.index)}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground',
                                !stepValid(wizard.index) && 'cursor-not-allowed opacity-50',
                            )}
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
                        icon={Users}
                        title="Who are you recognising?"
                        blurb="Pick one or more colleagues — they'll each get their own kudos."
                    />
                    <Field
                        label="Recipients"
                        required
                        error={form.errors.to_user_ids ?? form.errors['to_user_ids.0']}
                        hint={
                            form.data.to_user_ids.length > 0
                                ? `${form.data.to_user_ids.length} selected`
                                : undefined
                        }
                    >
                        <PeopleMultiPicker
                            value={form.data.to_user_ids}
                            onChange={(v) => setData('to_user_ids', v)}
                            people={people}
                            placeholder="Select colleagues…"
                        />
                    </Field>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Sparkles}
                        title="What are you recognising?"
                        blurb="Choose the value they showed, how strong the impact was, and say why."
                    />
                    <Field label="Value" required error={form.errors.category}>
                        <TilePicker
                            value={form.data.category}
                            onChange={(v) => setData('category', v)}
                            options={categoryTiles}
                            cols={3}
                        />
                    </Field>
                    <Field label="Impact" error={form.errors.impact}>
                        <Segmented
                            value={form.data.impact}
                            onChange={(v) => setData('impact', v)}
                            options={impactOptions}
                        />
                    </Field>
                    <Field label="Message" required error={form.errors.message}>
                        <Textarea
                            rows={4}
                            value={form.data.message}
                            onChange={(e) => setData('message', e.target.value)}
                            placeholder="What did they do that was awesome?"
                            maxLength={2000}
                        />
                    </Field>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & send"
                        blurb="This recognition will appear on the community wall."
                    />
                    <ReviewCard icon={Heart} title="Recognition" onEdit={() => wizard.goTo(1)}>
                        <ReviewRow
                            label="To"
                            value={recipientNames || '—'}
                        />
                        <ReviewRow label="Value" value={categoryLabel} />
                        <ReviewRow label="Impact" value={impactLabel} />
                        <ReviewRow label="Message" value={form.data.message} />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default RecognitionWizard;
