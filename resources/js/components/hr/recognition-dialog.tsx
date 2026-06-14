/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import {
    ClipboardCheck,
    Gift,
    Heart,
    Lightbulb,
    Sparkles,
    Star,
    Trophy,
    Users,
} from 'lucide-react';
import { useMemo } from 'react';

import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { PeoplePicker, type PersonOption } from './people-picker';
import {
    Field,
    ReviewCard,
    ReviewRow,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    type IconType,
    type WizardStep,
} from './wizard';

export interface RecognitionPerson {
    id: number;
    name: string;
    email?: string;
}

const STEPS: readonly WizardStep[] = [
    { key: 'recipient', label: 'Recipient', blurb: 'Who to recognise', icon: Users },
    { key: 'recognition', label: 'Recognition', blurb: 'Category & message', icon: Sparkles },
    { key: 'review', label: 'Review', blurb: 'Confirm & send', icon: ClipboardCheck },
];

// Icons for the known KUDOS_CATEGORIES keys; falls back to Gift.
const CATEGORY_ICONS: Record<string, IconType> = {
    teamwork: Users,
    innovation: Lightbulb,
    leadership: Star,
    customer_focus: Heart,
    going_above: Trophy,
    other: Gift,
};

/**
 * Give-recognition (kudos) WizardShell modal, replacing the flat Send-Kudos
 * dialog on the community feed. Posts {to_user_id, category, message} to
 * hr.feed.kudos (the existing FeedController@sendKudos endpoint).
 */
export function RecognitionDialog({
    open,
    onClose,
    employees,
    kudosCategories,
}: {
    open: boolean;
    onClose: () => void;
    employees: RecognitionPerson[];
    /** Raw KUDOS_CATEGORIES map: snake_case key → label. */
    kudosCategories: Record<string, string>;
}) {
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        to_user_id: string;
        category: string;
        message: string;
    }>({
        to_user_id: '',
        category: '',
        message: '',
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () =>
            employees.map((e) => ({
                value: String(e.id),
                label: e.name,
                sub: e.email,
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

    const recipientName =
        employees.find((e) => String(e.id) === form.data.to_user_id)?.name ?? '—';
    const categoryLabel = kudosCategories[form.data.category] ?? '—';

    const canSubmit =
        form.data.to_user_id !== '' &&
        form.data.category !== '' &&
        form.data.message.trim() !== '';

    const submit = () => {
        form.post('/hr/feed/kudos', {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (form.errors.to_user_id) wizard.goTo(0);
                else if (form.errors.category || form.errors.message) {
                    wizard.goTo(1);
                }
            },
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Give recognition"
            description="Send kudos to a colleague for their great work."
            railIcon={Heart}
            railTitle="Give recognition"
            railSub="Community"
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
                            {form.processing ? 'Sending…' : 'Send kudos'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            disabled={
                                wizard.index === 0 && form.data.to_user_id === ''
                            }
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground',
                                wizard.index === 0 &&
                                    form.data.to_user_id === '' &&
                                    'cursor-not-allowed opacity-50',
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
                        blurb="Pick the colleague you want to send kudos to."
                    />
                    <Field label="Recipient" required error={form.errors.to_user_id}>
                        <PeoplePicker
                            value={form.data.to_user_id}
                            onChange={(v) => form.setData('to_user_id', v)}
                            people={people}
                            placeholder="Select a colleague…"
                        />
                    </Field>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Sparkles}
                        title="What are you recognising?"
                        blurb="Choose a category and write a short message."
                    />
                    <Field label="Category" required error={form.errors.category}>
                        <TilePicker
                            value={form.data.category}
                            onChange={(v) => form.setData('category', v)}
                            options={categoryTiles}
                            cols={3}
                        />
                    </Field>
                    <Field label="Message" required error={form.errors.message}>
                        <Textarea
                            rows={4}
                            value={form.data.message}
                            onChange={(e) => form.setData('message', e.target.value)}
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
                        blurb="This kudos will appear on the community feed."
                    />
                    <ReviewCard
                        icon={Heart}
                        title="Recognition"
                        onEdit={() => wizard.goTo(0)}
                    >
                        <ReviewRow label="To" value={recipientName} />
                        <ReviewRow label="Category" value={categoryLabel} />
                        <ReviewRow label="Message" value={form.data.message} />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default RecognitionDialog;
