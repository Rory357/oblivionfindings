/* eslint-disable no-restricted-syntax -- Wizard footer/option rows use native
 * buttons + on-card surfaces to match the Add-Client modal chrome (see
 * components/wizard/shell.tsx). Every colour is a semantic design token. */
import { useForm } from '@inertiajs/react';
import {
    Bell,
    ClipboardCheck,
    Megaphone,
    Pin,
    SlidersHorizontal,
    Type as TypeIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';

export interface AnnouncementSite {
    id: number;
    name: string;
}

const STEPS: readonly WizardStep[] = [
    { key: 'title', label: 'Title', blurb: 'The headline', icon: TypeIcon },
    { key: 'details', label: 'Details', blurb: 'Body & options', icon: SlidersHorizontal },
    { key: 'review', label: 'Review', blurb: 'Check & publish', icon: ClipboardCheck },
];

const PRIORITIES: { value: string; label: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'normal', label: 'Normal' },
    { value: 'high', label: 'High' },
    { value: 'urgent', label: 'Urgent' },
];

const ALL_SITES = 'all';

type AnnounceForm = {
    title: string;
    content: string;
    priority: string;
    target_audience: string;
    target_value: string | null;
    is_pinned: boolean;
    requires_acknowledgement: boolean;
};

/**
 * The shared **Make announcement** wizard — gated by `hr.announcements.manage`
 * (the caller controls visibility). Publishes through the Announcements module
 * (`AnnouncementController@store`) — not a fork — so the notice appears on the
 * feed wall with acknowledgement tracking and fires the published notification.
 */
export function AnnounceWizard({
    open,
    onClose,
    onSuccess,
    sites,
}: {
    open: boolean;
    onClose: () => void;
    onSuccess?: () => void;
    sites: AnnouncementSite[];
}) {
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);
    // Single audience token: 'all' or a site id (string). Mapped to
    // target_audience/target_value on submit.
    const [audience, setAudience] = useState<string>(ALL_SITES);
    const form = useForm<AnnounceForm>({
        title: '',
        content: '',
        priority: 'normal',
        target_audience: 'all',
        target_value: null,
        is_pinned: false,
        requires_acknowledgement: false,
    });
    const { setData, reset, clearErrors } = form;

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

    const audienceOptions = useMemo(
        () => [
            { value: ALL_SITES, label: 'All sites' },
            ...sites.map((s) => ({ value: String(s.id), label: s.name })),
        ],
        [sites],
    );

    const audienceLabel =
        audienceOptions.find((o) => o.value === audience)?.label ?? 'All sites';

    const pct = useMemo(() => {
        let filled = 0;
        if (form.data.title.trim()) filled++;
        if (form.data.content.trim()) filled++;
        filled++; // audience always has a value
        return Math.round((filled / 3) * 100);
    }, [form.data.title, form.data.content]);

    const stepValid = (i: number): boolean => {
        if (i === 0) return form.data.title.trim() !== '';
        if (i === 1) return form.data.content.trim() !== '';
        return true;
    };

    const submit = () => {
        // Map the single audience token onto target_audience/target_value.
        const isAll = audience === ALL_SITES;
        form.transform((data) => ({
            ...data,
            target_audience: isAll ? 'all' : 'site',
            target_value: isAll ? null : audience,
        }));
        form.post('/hr/announcements', {
            preserveScroll: true,
            onSuccess: () => {
                onSuccess?.();
                setDone(true);
            },
            onError: () => {
                if (form.errors.title) wizard.goTo(0);
                else if (form.errors.content) wizard.goTo(1);
            },
        });
    };

    const successPane = (
        <WizardSuccessPane
            title="Announcement published"
            blurb={
                <>
                    <strong>{form.data.title || 'Your announcement'}</strong> is now pinned to
                    the wall for {audienceLabel.toLowerCase()}
                    {form.data.requires_acknowledgement ? ', and acknowledgement is being tracked.' : '.'}
                </>
            }
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
            title="Make announcement"
            description="Publish an announcement to the community wall."
            railIcon={Megaphone}
            railTitle="Make announcement"
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
                            disabled={!stepValid(0) || !stepValid(1) || form.processing}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                (!stepValid(0) || !stepValid(1) || form.processing) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {form.processing ? 'Publishing…' : 'Publish announcement'}
                        </button>
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
                        icon={TypeIcon}
                        title="What's the headline?"
                        blurb="A short, clear title your team will see first."
                    />
                    <Field label="Title" required error={form.errors.title}>
                        <Input
                            value={form.data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="e.g. Q3 all-hands on Friday 27 June"
                            maxLength={255}
                        />
                    </Field>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={SlidersHorizontal}
                        title="Body & options"
                        blurb="Write the detail and choose how it's delivered."
                    />
                    <Field label="Announcement" required error={form.errors.content}>
                        <Textarea
                            rows={5}
                            value={form.data.content}
                            onChange={(e) => setData('content', e.target.value)}
                            placeholder="Share the details your team needs to know…"
                            maxLength={10000}
                        />
                    </Field>
                    <Field label="Audience">
                        <Segmented value={audience} onChange={setAudience} options={audienceOptions} />
                    </Field>
                    <Field label="Priority">
                        <Segmented
                            value={form.data.priority}
                            onChange={(v) => setData('priority', v)}
                            options={PRIORITIES}
                        />
                    </Field>
                    <ToggleRow
                        icon={Bell}
                        label="Require acknowledgement"
                        hint="Track who has read and confirmed this."
                        checked={form.data.requires_acknowledgement}
                        onCheckedChange={(v) => setData('requires_acknowledgement', v)}
                    />
                    <ToggleRow
                        icon={Pin}
                        label="Pin to top of the wall"
                        hint="Keep it above other posts until unpinned."
                        checked={form.data.is_pinned}
                        onCheckedChange={(v) => setData('is_pinned', v)}
                    />
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & publish"
                        blurb="This will be published to the community wall right away."
                    />
                    <ReviewCard icon={Megaphone} title="Announcement" onEdit={() => wizard.goTo(0)}>
                        <ReviewRow label="Title" value={form.data.title} />
                        <ReviewRow label="Body" value={form.data.content} />
                        <ReviewRow label="Audience" value={audienceLabel} />
                        <ReviewRow
                            label="Priority"
                            value={PRIORITIES.find((p) => p.value === form.data.priority)?.label}
                        />
                        <ReviewRow
                            label="Acknowledgement"
                            value={form.data.requires_acknowledgement ? 'Required' : 'Not required'}
                        />
                        <ReviewRow label="Pinned" value={form.data.is_pinned ? 'Yes' : 'No'} />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

function ToggleRow({
    icon: Icon,
    label,
    hint,
    checked,
    onCheckedChange,
}: {
    icon: typeof Bell;
    label: string;
    hint: string;
    checked: boolean;
    onCheckedChange: (v: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border border-border bg-card/50 p-3">
            <div className="flex items-start gap-2.5">
                <span className="mt-0.5 shrink-0 rounded-lg bg-muted p-1.5">
                    <Icon className="h-4 w-4 text-muted-foreground" />
                </span>
                <span className="min-w-0">
                    <span className="block text-sm font-semibold">{label}</span>
                    <span className="mt-0.5 block text-xs leading-snug text-muted-foreground">
                        {hint}
                    </span>
                </span>
            </div>
            <Switch checked={checked} onCheckedChange={onCheckedChange} />
        </div>
    );
}

export default AnnounceWizard;
