/* eslint-disable no-restricted-syntax -- The teammate picker tiles and wizard
 * footer/clear buttons are bespoke selector cards per the design handoff; the
 * shadcn <Button> can't express the avatar-tile layout. */
import { router } from '@inertiajs/react';
import {
    Award,
    CheckCircle2,
    Crown,
    Heart,
    Lightbulb,
    Search,
    Send,
    Sparkles,
    User,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { MedsWizardDialog } from '@/components/meds/wizard-shell';
import { Field, StepHead, type IconType } from '@/components/wizard/primitives';
import { fireConfetti } from '@/lib/confetti';
import { cn } from '@/lib/utils';

import { hueFromId } from './my-hr-utils';

export type KudosTeammate = {
    id: number;
    name: string;
    initials: string;
    role: string | null;
    site: string | null;
};

const STEPS = [
    { key: 'who', label: 'Who', blurb: 'Pick a teammate', icon: User },
    { key: 'value', label: 'Value', blurb: 'What they showed', icon: Award },
    { key: 'message', label: 'Message', blurb: 'Say thanks', icon: Send },
];

const VALUES: {
    key: string;
    label: string;
    description: string;
    icon: IconType;
}[] = [
    { key: 'teamwork', label: 'Teamwork', description: 'Lifted the whole team', icon: Users },
    { key: 'innovation', label: 'Innovation', description: 'Found a better way', icon: Lightbulb },
    { key: 'leadership', label: 'Leadership', description: 'Led by example', icon: Crown },
    { key: 'customer_focus', label: 'Customer Focus', description: 'Put manaaki first', icon: Heart },
    { key: 'going_above', label: 'Going Above & Beyond', description: 'Went the extra mile', icon: Sparkles },
    { key: 'other', label: 'Other', description: 'Something else worth a shout-out', icon: Award },
];

export function MyHrKudosWizard({
    open,
    onClose,
    teammates,
}: {
    open: boolean;
    onClose: () => void;
    teammates: KudosTeammate[];
}) {
    const [step, setStep] = useState(0);
    const [toUserId, setToUserId] = useState<number | null>(null);
    const [category, setCategory] = useState('');
    const [message, setMessage] = useState('');
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);

    const selected = teammates.find((t) => t.id === toUserId) ?? null;

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return teammates;
        return teammates.filter(
            (t) =>
                t.name.toLowerCase().includes(q) ||
                (t.role ?? '').toLowerCase().includes(q) ||
                (t.site ?? '').toLowerCase().includes(q),
        );
    }, [teammates, search]);

    function reset() {
        setStep(0);
        setToUserId(null);
        setCategory('');
        setMessage('');
        setSearch('');
    }

    function close() {
        reset();
        onClose();
    }

    const canContinue =
        step === 0 ? toUserId != null : step === 1 ? category !== '' : message.trim() !== '';

    function next() {
        if (step < STEPS.length - 1) {
            setStep((s) => s + 1);
            return;
        }
        if (!toUserId || !category || !message.trim()) return;
        setProcessing(true);
        router.post(
            '/hr/my/kudos',
            { to_user_id: toUserId, category, message },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Kudos sent 🎉', {
                        description: `Your shout-out to ${selected?.name ?? 'your teammate'} is live on the team feed.`,
                    });
                    fireConfetti();
                    close();
                },
                onError: () => toast.error('Could not send kudos'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Send kudos"
            description="Recognise a teammate"
            railIcon={Send}
            railTitle="Send kudos"
            railSubtitle="Recognition"
            railFooter={
                <p className="text-[11px] leading-relaxed text-muted-foreground">
                    Posted to the team feed for the whole team to see.
                </p>
            }
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i <= step && setStep(i)}
            footer={
                <>
                    <button
                        type="button"
                        onClick={() => setStep((s) => Math.max(0, s - 1))}
                        disabled={step === 0}
                        className="rounded-[10px] border border-border bg-card px-4 py-2 text-[13px] font-semibold disabled:opacity-40"
                    >
                        Back
                    </button>
                    <div className="flex gap-2.5">
                        <button
                            type="button"
                            onClick={close}
                            className="rounded-[10px] border border-border bg-card px-4 py-2 text-[13px] font-semibold"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={next}
                            disabled={!canContinue || processing}
                            className="inline-flex items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                        >
                            {step === STEPS.length - 1 ? (
                                <>
                                    <Send className="h-3.5 w-3.5" /> Send kudos
                                </>
                            ) : (
                                'Continue →'
                            )}
                        </button>
                    </div>
                </>
            }
        >
            {step === 0 ? (
                <div>
                    <StepHead
                        icon={User}
                        title="Who deserves a shout-out?"
                        blurb="Search your team by name, role or site."
                    />
                    <div className="relative mb-3.5">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search teammates…"
                            className="w-full rounded-[11px] border border-border bg-card px-9 py-3 text-sm outline-none focus:border-primary"
                        />
                        {search ? (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        ) : null}
                    </div>
                    <p className="mb-2 px-0.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        {search ? `${filtered.length} result${filtered.length === 1 ? '' : 's'}` : `All teammates · ${teammates.length}`}
                    </p>
                    {filtered.length === 0 ? (
                        <div className="py-8 text-center text-muted-foreground">
                            <div className="text-2xl">🔍</div>
                            <p className="mt-1.5 text-[13px]">
                                No teammates match “{search}”
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {filtered.map((t) => {
                                const active = toUserId === t.id;
                                return (
                                    <button
                                        key={t.id}
                                        type="button"
                                        onClick={() => setToUserId(t.id)}
                                        className={cn(
                                            'flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition-all',
                                            active
                                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                                : 'border-border bg-card hover:border-primary/50',
                                        )}
                                    >
                                        <span
                                            className="grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold text-white"
                                            style={{
                                                backgroundColor: `oklch(0.62 0.17 ${hueFromId(t.id)})`,
                                            }}
                                        >
                                            {t.initials}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13.5px] font-semibold">
                                                {t.name}
                                            </span>
                                            <span className="block truncate text-[11px] text-muted-foreground">
                                                {[t.role, t.site].filter(Boolean).join(' · ')}
                                            </span>
                                        </span>
                                        {active ? (
                                            <CheckCircle2 className="h-4.5 w-4.5 shrink-0 text-primary" />
                                        ) : null}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>
            ) : null}

            {step === 1 ? (
                <div>
                    <StepHead
                        icon={Award}
                        title="Which value did they show?"
                        blurb="Tie your kudos to one of our values."
                    />
                    <div className="grid gap-2 sm:grid-cols-2">
                        {VALUES.map((v) => {
                            const Icon = v.icon;
                            const active = category === v.key;
                            return (
                                <button
                                    key={v.key}
                                    type="button"
                                    onClick={() => setCategory(v.key)}
                                    className={cn(
                                        'flex items-center gap-3 rounded-xl border p-3 text-left transition-all',
                                        active
                                            ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                            : 'border-border bg-card hover:border-primary/50',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                                            active
                                                ? 'bg-primary/15 text-primary'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <Icon className="h-4 w-4" />
                                    </span>
                                    <span className="min-w-0">
                                        <span
                                            className={cn(
                                                'block text-[13.5px] font-bold',
                                                active && 'text-primary',
                                            )}
                                        >
                                            {v.label}
                                        </span>
                                        <span className="block text-[11.5px] text-muted-foreground">
                                            {v.description}
                                        </span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            ) : null}

            {step === 2 ? (
                <div>
                    <StepHead
                        icon={Send}
                        title="Say thanks"
                        blurb="Make it specific — the best kudos name the moment."
                    />
                    <Field label="Your message">
                        <textarea
                            value={message}
                            onChange={(e) => setMessage(e.target.value)}
                            rows={4}
                            placeholder="e.g. Thanks for covering my round at short notice — you settled Mr Patel like a pro. Total legend. 🙌"
                            className="w-full resize-y rounded-[10px] border border-border bg-card px-3 py-3 text-[13.5px] leading-relaxed outline-none focus:border-primary"
                        />
                    </Field>
                    <div className="mt-1 overflow-hidden rounded-[14px] border border-border">
                        <div className="px-3.5 pt-2.5 text-[10.5px] font-bold uppercase tracking-wide text-muted-foreground">
                            Preview · how it appears on the feed
                        </div>
                        <div className="flex gap-3 p-3.5">
                            <span
                                className="grid h-10 w-10 shrink-0 place-items-center rounded-full text-[13px] font-bold text-white"
                                style={{
                                    backgroundColor: selected
                                        ? `oklch(0.62 0.17 ${hueFromId(selected.id)})`
                                        : 'var(--muted)',
                                }}
                            >
                                {selected?.initials ?? '?'}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <span className="text-[13.5px] font-bold">
                                        {selected?.name ?? 'Pick a teammate'}
                                    </span>
                                    {category ? (
                                        <span className="rounded-full bg-accent px-2 py-0.5 text-[10.5px] font-bold text-primary">
                                            {VALUES.find((v) => v.key === category)?.label}
                                        </span>
                                    ) : null}
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    just now
                                </div>
                                <div className="mt-1.5 text-[13px] leading-relaxed">
                                    {message || 'Your message will appear here…'}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}
        </MedsWizardDialog>
    );
}

export default MyHrKudosWizard;
