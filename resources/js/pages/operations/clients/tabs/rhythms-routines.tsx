import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    Coffee,
    Moon,
    Save,
    ShieldAlert,
    Sun,
    Sunrise,
    ThumbsUp,
    TimerReset,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Card as GuardrailCard } from '@/components/ui/card';

export type ClientRoutine = {
    id?: number;
    time_block: string;
    body?: string | null;
    display_order?: number | null;
    updated_at?: string | null;
    updater?: { id: number; name: string } | null;
};

type RhythmsRoutinesTabProps = {
    clientId: number;
    routines: ClientRoutine[];
    canEdit?: boolean;
    isLoading?: boolean;
};

const blocks = [
    {
        key: 'morning',
        label: 'Morning',
        prompt: 'Wake-up routine, hygiene, meds, breakfast, transport.',
        icon: Sunrise,
    },
    {
        key: 'day',
        label: 'Day',
        prompt: 'Day activities, appointments, meals, support rhythm.',
        icon: Sun,
    },
    {
        key: 'evening',
        label: 'Evening',
        prompt: 'Dinner, wind-down, personal care, bedtime prep.',
        icon: Coffee,
    },
    {
        key: 'overnight',
        label: 'Overnight',
        prompt: 'Sleep pattern, checks, alerts, and escalation steps.',
        icon: Moon,
    },
    {
        key: 'preferences',
        label: 'Preferences',
        prompt: 'How they like support to be offered and paced.',
        icon: ThumbsUp,
    },
    {
        key: 'triggers',
        label: 'Triggers',
        prompt: 'Known stressors, early warning signs, and context.',
        icon: ShieldAlert,
    },
    {
        key: 'calming',
        label: 'Calming',
        prompt: 'What helps settle, redirect, or reassure.',
        icon: TimerReset,
    },
    {
        key: 'what_works',
        label: 'What Works',
        prompt: 'Reliable language, routines, and approaches.',
        icon: CheckCircle2,
    },
    {
        key: 'avoid',
        label: 'Avoid',
        prompt: 'Things that make support harder or unsafe.',
        icon: XCircle,
    },
];

function updatedLabel(value?: string | null) {
    if (!value) return 'Not updated yet';
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

export function RhythmsRoutinesTab({
    clientId,
    routines,
    canEdit = false,
    isLoading = false,
}: RhythmsRoutinesTabProps) {
    const routineByBlock = useMemo(
        () => new Map(routines.map((routine) => [routine.time_block, routine])),
        [routines],
    );
    const [drafts, setDrafts] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            blocks.map((block) => [
                block.key,
                routineByBlock.get(block.key)?.body ?? '',
            ]),
        ),
    );

    const save = (block: string, index: number) => {
        router.post(
            `/operations/clients/${clientId}/routines/${block}`,
            {
                body: drafts[block] ?? '',
                display_order: (index + 1) * 10,
            },
            {
                preserveScroll: true,
                preserveState: false,
            },
        );
    };

    const completed = blocks.filter((block) =>
        (routineByBlock.get(block.key)?.body ?? '').trim(),
    ).length;

    if (isLoading) {
        return (
            <div className="space-y-6" aria-busy="true">
                <Skeleton className="h-24 rounded-lg" />
                <div className="grid gap-4 lg:grid-cols-2">
                    {blocks.slice(0, 4).map((block) => (
                        <Skeleton key={block.key} className="h-56 rounded-lg" />
                    ))}
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <GuardrailCard unstyled className="rounded-lg border bg-card p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Rhythms & Routines
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Worker-facing guidance for daily support, triggers,
                            calming strategies, and what to avoid.
                        </p>
                    </div>
                    <Badge className="w-fit bg-primary/10 text-primary">
                        {completed}/{blocks.length} complete
                    </Badge>
                </div>
            </GuardrailCard>

            <div className="grid gap-4 lg:grid-cols-2">
                {blocks.map((block, index) => {
                    const Icon = block.icon;
                    const routine = routineByBlock.get(block.key);
                    const body = drafts[block.key] ?? '';
                    const unchanged = body === (routine?.body ?? '');

                    return (
                        <section
                            key={block.key}
                            className="rounded-lg border bg-card p-4"
                        >
                            <div className="flex items-start gap-3">
                                <span
                                    className={cn(
                                        'rounded-lg p-2',
                                        routine?.body
                                            ? 'bg-status-success-bg text-status-success'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    <Icon className="h-5 w-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="font-semibold">
                                            {block.label}
                                        </h3>
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Clock className="h-3.5 w-3.5" />
                                            {updatedLabel(routine?.updated_at)}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {block.prompt}
                                    </p>
                                </div>
                            </div>

                            <Textarea
                                value={body}
                                onChange={(event) =>
                                    setDrafts((current) => ({
                                        ...current,
                                        [block.key]: event.target.value,
                                    }))
                                }
                                placeholder={block.prompt}
                                className="mt-4 min-h-36"
                                disabled={!canEdit}
                            />

                            <div className="mt-3 flex items-center justify-between gap-3">
                                <span className="text-xs text-muted-foreground">
                                    {routine?.updater?.name
                                        ? `Last updated by ${routine.updater.name}`
                                        : 'No saved guidance yet'}
                                </span>
                                {canEdit ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            unchanged ? 'outline' : 'default'
                                        }
                                        disabled={unchanged}
                                        onClick={() => save(block.key, index)}
                                    >
                                        <Save className="mr-2 h-4 w-4" />
                                        Save
                                    </Button>
                                ) : null}
                            </div>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
