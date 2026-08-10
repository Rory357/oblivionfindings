/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). Every
 * colour is a semantic design token. */
import { useForm } from '@inertiajs/react';
import {
    ClipboardCheck,
    HelpCircle,
    MessageSquarePlus,
    Pencil,
    Trophy,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

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

const ALL_SITES = 'all';

const STEPS: readonly WizardStep[] = [
    {
        key: 'type',
        label: 'Type',
        blurb: 'What kind of update',
        icon: MessageSquarePlus,
    },
    {
        key: 'compose',
        label: 'Compose',
        blurb: 'Write your update',
        icon: Pencil,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & post',
        icon: ClipboardCheck,
    },
];

type ComposeKind = 'update' | 'question' | 'win';

const KIND_TILES: {
    key: ComposeKind;
    label: string;
    description: string;
    icon: IconType;
}[] = [
    {
        key: 'update',
        label: 'Update',
        description: 'Share news with your team',
        icon: MessageSquarePlus,
    },
    {
        key: 'question',
        label: 'Question',
        description: 'Ask the wider team',
        icon: HelpCircle,
    },
    {
        key: 'win',
        label: 'Win',
        description: 'Celebrate a team win',
        icon: Trophy,
    },
];

const KIND_PLACEHOLDER: Record<ComposeKind, string> = {
    update: 'Share an update with your team…',
    question: 'What would you like to ask the team?',
    win: 'What did the team achieve? 🎉',
};

/**
 * The shared **Post update** wizard — a lightweight team-wall post on the
 * Add-Client modal shell. Posts `{content, post_type:'update'}` to `hr.feed.store`
 * (FeedController@store). The Update / Question / Win framing shapes the prompt;
 * all post as team updates on the community wall.
 */
export function ComposeWizard({
    open,
    onClose,
    onSuccess,
    sites = [],
}: {
    open: boolean;
    onClose: () => void;
    onSuccess?: () => void;
    sites?: Array<{ id: number; name: string }>;
}) {
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);
    // Single audience token: 'all' or a site id (string).
    const [audience, setAudience] = useState<string>(ALL_SITES);
    const form = useForm<{
        content: string;
        post_type: string;
        kind: ComposeKind;
        attachment: File | null;
    }>({
        content: '',
        post_type: 'update',
        kind: 'update',
        attachment: null,
    });
    const { setData, reset, clearErrors } = form;
    const kind = form.data.kind;

    const audienceOptions = useMemo(
        () => [
            { value: ALL_SITES, label: 'All sites' },
            ...sites.map((s) => ({ value: String(s.id), label: s.name })),
        ],
        [sites],
    );

    useEffect(() => {
        if (!open) return;
        reset();
        clearErrors();
        setAudience(ALL_SITES);
        setDone(false);
        wizard.goTo(0);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        reset();
        clearErrors();
        wizard.reset();
        setDone(false);
        onClose();
    };

    const pct = useMemo(() => {
        let filled = 1; // a kind is always chosen
        if (form.data.content.trim()) filled++;
        return Math.round((filled / 2) * 100);
    }, [form.data.content]);

    const stepValid = (i: number): boolean => {
        if (i === 1) return form.data.content.trim() !== '';
        return true;
    };

    const submit = () => {
        const isAll = audience === ALL_SITES;
        form.transform((data) => ({
            ...data,
            target_audience: isAll ? 'all' : 'site',
            target_value: isAll ? null : audience,
        }));
        form.post('/hr/feed', {
            preserveScroll: true,
            onSuccess: () => {
                onSuccess?.();
                setDone(true);
            },
            onError: () => {
                if (form.errors.content) wizard.goTo(1);
            },
        });
    };

    const kindLabel = KIND_TILES.find((t) => t.key === kind)?.label ?? 'Update';

    const successPane = (
        <WizardSuccessPane
            title="Posted to the wall"
            blurb="Your update is now on the community wall for your team to see."
            actions={
                <button
                    type="button"
                    onClick={close}
                    className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90"
                >
                    Done
                </button>
            }
        />
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Post update"
            description="Share an update, question or win with your team."
            railIcon={MessageSquarePlus}
            railTitle="Post update"
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
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!stepValid(1) || form.processing}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                (!stepValid(1) || form.processing) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {form.processing ? 'Posting…' : 'Post update'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            disabled={!stepValid(wizard.index)}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground',
                                !stepValid(wizard.index) &&
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
                        icon={MessageSquarePlus}
                        title="What would you like to share?"
                        blurb="Pick the kind of update — it just shapes the prompt."
                    />
                    <Field label="Type">
                        <TilePicker
                            value={kind}
                            onChange={(v) => setData('kind', v as ComposeKind)}
                            options={KIND_TILES}
                            cols={3}
                        />
                    </Field>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Pencil}
                        title={`Write your ${kindLabel.toLowerCase()}`}
                        blurb="Keep it friendly — this goes to the whole team."
                    />
                    <Field label="Message" required error={form.errors.content}>
                        <Textarea
                            rows={6}
                            value={form.data.content}
                            onChange={(e) => setData('content', e.target.value)}
                            placeholder={KIND_PLACEHOLDER[kind]}
                            maxLength={5000}
                        />
                    </Field>
                    <Field
                        label="Photo"
                        hint="Optional — JPG, PNG, GIF or WebP, up to 10MB"
                        error={form.errors.attachment}
                    >
                        {form.data.attachment ? (
                            <StagedFileCard
                                file={form.data.attachment}
                                onRemove={() => setData('attachment', null)}
                            />
                        ) : (
                            <FileDropzone
                                onFiles={(files) =>
                                    setData('attachment', files[0] ?? null)
                                }
                                accept="image/*"
                                multiple={false}
                                title="Add a photo"
                                hint="JPG, PNG, GIF, WebP"
                            />
                        )}
                    </Field>
                    {sites.length > 0 ? (
                        <Field
                            label="Audience"
                            hint="Who sees this — the whole org or a single site."
                        >
                            <Segmented
                                value={audience}
                                onChange={setAudience}
                                options={audienceOptions}
                            />
                        </Field>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & post"
                        blurb="This will appear on the community wall."
                    />
                    <ReviewCard
                        icon={MessageSquarePlus}
                        title="Update"
                        onEdit={() => wizard.goTo(1)}
                    >
                        <ReviewRow label="Type" value={kindLabel} />
                        <ReviewRow label="Message" value={form.data.content} />
                        <ReviewRow
                            label="Photo"
                            value={form.data.attachment?.name}
                        />
                        <ReviewRow
                            label="Audience"
                            value={
                                audienceOptions.find(
                                    (o) => o.value === audience,
                                )?.label
                            }
                        />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default ComposeWizard;
