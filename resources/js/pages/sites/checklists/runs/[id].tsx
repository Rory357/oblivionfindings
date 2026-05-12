import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Camera,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    ClipboardCheck,
    Loader2,
    PenLine,
    Save,
    Sparkles,
    StickyNote,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Run = {
    id: number;
    scheduled_date: string;
    status: 'scheduled' | 'in_progress' | 'completed';
    completion_percentage: number;
};

type Template = {
    id: number;
    name: string;
};

type Site = {
    id: number;
    name: string;
};

type Item = {
    id: number;
    question: string;
    response_type:
        | 'yes_no'
        | 'yes_no_na'
        | 'pass_fail'
        | 'numeric'
        | 'text'
        | 'photo';
    response_config?: { min?: number; max?: number };
    is_required: boolean;
    guidance?: string;
    failure_creates_hazard?: boolean;
};

type Response = {
    id?: number;
    template_item_id: number;
    response_value: string;
    notes: string;
    photo_path?: string;
    is_failed: boolean;
    create_hazard?: boolean;
};

type Props = {
    site: Site;
    template: Template;
    run: Run;
    items: Item[];
    responses: Response[];
};

function isAnswered(resp: Response | undefined): boolean {
    return !!resp && resp.response_value !== undefined && resp.response_value !== '';
}

function ProgressRing({
    value,
    size = 96,
    strokeWidth = 8,
}: {
    value: number;
    size?: number;
    strokeWidth?: number;
}) {
    const clamped = Math.max(0, Math.min(100, value));
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const offset = circumference - (clamped / 100) * circumference;

    return (
        <div className="relative inline-flex items-center justify-center">
            <svg width={size} height={size} className="-rotate-90">
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={strokeWidth}
                    className="stroke-muted"
                />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={strokeWidth}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={offset}
                    className={cn(
                        'transition-all duration-500 ease-out',
                        clamped === 100
                            ? 'stroke-emerald-500'
                            : 'stroke-primary',
                    )}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-xl font-semibold tabular-nums">
                    {Math.round(clamped)}%
                </span>
            </div>
        </div>
    );
}

function ChoiceButton({
    active,
    tone,
    icon: Icon,
    label,
    onClick,
}: {
    active: boolean;
    tone: 'pass' | 'fail' | 'neutral';
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    onClick: () => void;
}) {
    const toneActive = {
        pass: 'border-emerald-500 bg-emerald-500 text-white shadow-sm shadow-emerald-500/30',
        fail: 'border-rose-500 bg-rose-500 text-white shadow-sm shadow-rose-500/30',
        neutral: 'border-slate-400 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    };
    const toneIdle = {
        pass: 'border-border bg-background text-foreground hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 dark:hover:bg-emerald-950/40 dark:hover:text-emerald-300',
        fail: 'border-border bg-background text-foreground hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-950/40 dark:hover:text-rose-300',
        neutral:
            'border-border bg-background text-foreground hover:border-slate-300 hover:bg-slate-50 dark:hover:bg-slate-900',
    };
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'group inline-flex flex-1 items-center justify-center gap-2 rounded-lg border-2 px-4 py-2.5 text-sm font-medium transition active:scale-[0.98]',
                active ? toneActive[tone] : toneIdle[tone],
            )}
        >
            <Icon className="h-4 w-4" />
            {label}
        </button>
    );
}

export default function ChecklistRun({
    site,
    template,
    run,
    items,
    responses,
}: Props) {
    const [currentResponses, setCurrentResponses] = useState<Record<number, Response>>(
        () => {
            const map: Record<number, Response> = {};
            responses.forEach((r) => {
                map[r.template_item_id] = r;
            });
            return map;
        },
    );
    const [overallNotes, setOverallNotes] = useState('');
    const [signatureName, setSignatureName] = useState('');
    const [signatureConfirmed, setSignatureConfirmed] = useState(false);
    const [expandedNotes, setExpandedNotes] = useState<Set<number>>(new Set());
    const [lastSavedAt, setLastSavedAt] = useState<Date | null>(null);

    const requiredItems = useMemo(
        () => items.filter((item) => item.is_required),
        [items],
    );
    const completedRequired = useMemo(
        () =>
            requiredItems.filter((item) => isAnswered(currentResponses[item.id])).length,
        [requiredItems, currentResponses],
    );
    const totalAnswered = useMemo(
        () => items.filter((item) => isAnswered(currentResponses[item.id])).length,
        [items, currentResponses],
    );
    const failedItems = useMemo(
        () => items.filter((item) => currentResponses[item.id]?.is_failed),
        [items, currentResponses],
    );
    const progressPercentage = useMemo(() => {
        if (requiredItems.length === 0) return 0;
        return Math.round((completedRequired / requiredItems.length) * 100);
    }, [requiredItems, completedRequired]);

    const allRequiredAnswered = completedRequired === requiredItems.length;
    const canComplete =
        allRequiredAnswered && signatureConfirmed && signatureName.trim() !== '';
    const isReadyToReview = allRequiredAnswered;

    const updateResponse = (itemId: number, updates: Partial<Response>) => {
        setCurrentResponses((prev) => ({
            ...prev,
            [itemId]: {
                ...prev[itemId],
                template_item_id: itemId,
                ...updates,
            },
        }));
    };

    const toggleNotesExpanded = (itemId: number) => {
        setExpandedNotes((prev) => {
            const next = new Set(prev);
            next.has(itemId) ? next.delete(itemId) : next.add(itemId);
            return next;
        });
    };

    const form = useForm({
        responses: [] as Response[],
        overall_notes: '',
        signature_name: '',
    });

    const handleSave = () => {
        form.transform(() => ({
            responses: Object.values(currentResponses),
            overall_notes: overallNotes,
            signature_name: signatureName,
        }));
        form.post(`/checklists/runs/${run.id}/responses`, {
            preserveScroll: true,
            onSuccess: () => setLastSavedAt(new Date()),
        });
    };

    const handleComplete = () => {
        form.transform(() => ({
            responses: Object.values(currentResponses),
            overall_notes: overallNotes,
            signature_name: signatureName,
        }));
        form.post(`/checklists/runs/${run.id}/complete`);
    };

    const scrollToItem = (itemId: number) => {
        const el = document.getElementById(`item-${itemId}`);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add('ring-2', 'ring-primary/40');
            setTimeout(() => el.classList.remove('ring-2', 'ring-primary/40'), 1200);
        }
    };

    // Auto-scroll to next unanswered item after answering current one
    const [lastInteractedItem, setLastInteractedItem] = useState<number | null>(null);
    useEffect(() => {
        if (lastInteractedItem === null) return;
        const idx = items.findIndex((i) => i.id === lastInteractedItem);
        if (idx === -1) return;
        const next = items.slice(idx + 1).find((i) => !isAnswered(currentResponses[i.id]));
        if (next) {
            const t = setTimeout(() => {
                document
                    .getElementById(`item-${next.id}`)
                    ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 200);
            return () => clearTimeout(t);
        }
    }, [lastInteractedItem]); // eslint-disable-line react-hooks/exhaustive-deps

    const handleChoice = (itemId: number, value: string, isFailed: boolean) => {
        updateResponse(itemId, { response_value: value, is_failed: isFailed });
        setLastInteractedItem(itemId);
    };

    const renderResponseInput = (item: Item) => {
        const response = currentResponses[item.id];
        const value = response?.response_value || '';

        switch (item.response_type) {
            case 'yes_no':
                return (
                    <div className="flex gap-2">
                        <ChoiceButton
                            active={value === 'yes'}
                            tone="pass"
                            icon={Check}
                            label="Yes"
                            onClick={() => handleChoice(item.id, 'yes', false)}
                        />
                        <ChoiceButton
                            active={value === 'no'}
                            tone="fail"
                            icon={X}
                            label="No"
                            onClick={() => handleChoice(item.id, 'no', true)}
                        />
                    </div>
                );
            case 'yes_no_na':
                return (
                    <div className="flex gap-2">
                        <ChoiceButton
                            active={value === 'yes'}
                            tone="pass"
                            icon={Check}
                            label="Yes"
                            onClick={() => handleChoice(item.id, 'yes', false)}
                        />
                        <ChoiceButton
                            active={value === 'no'}
                            tone="fail"
                            icon={X}
                            label="No"
                            onClick={() => handleChoice(item.id, 'no', true)}
                        />
                        <ChoiceButton
                            active={value === 'na'}
                            tone="neutral"
                            icon={ChevronDown}
                            label="N/A"
                            onClick={() => handleChoice(item.id, 'na', false)}
                        />
                    </div>
                );
            case 'pass_fail':
                return (
                    <div className="flex gap-2">
                        <ChoiceButton
                            active={value === 'pass'}
                            tone="pass"
                            icon={Check}
                            label="Pass"
                            onClick={() => handleChoice(item.id, 'pass', false)}
                        />
                        <ChoiceButton
                            active={value === 'fail'}
                            tone="fail"
                            icon={X}
                            label="Fail"
                            onClick={() => handleChoice(item.id, 'fail', true)}
                        />
                    </div>
                );
            case 'numeric':
                return (
                    <Input
                        type="number"
                        value={value}
                        min={item.response_config?.min}
                        max={item.response_config?.max}
                        onChange={(e) =>
                            updateResponse(item.id, { response_value: e.target.value })
                        }
                        onBlur={() => setLastInteractedItem(item.id)}
                        className="w-40 text-base"
                        placeholder={
                            item.response_config
                                ? `${item.response_config.min ?? '–'} to ${item.response_config.max ?? '–'}`
                                : 'Enter value'
                        }
                    />
                );
            case 'text':
                return (
                    <Textarea
                        value={value}
                        onChange={(e) =>
                            updateResponse(item.id, { response_value: e.target.value })
                        }
                        onBlur={() => setLastInteractedItem(item.id)}
                        placeholder="Enter response…"
                        rows={3}
                        className="resize-none"
                    />
                );
            case 'photo':
                return (
                    <label
                        className={cn(
                            'group relative flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 transition',
                            value
                                ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30'
                                : 'border-border hover:border-primary/40 hover:bg-accent/40',
                        )}
                    >
                        <span
                            className={cn(
                                'flex h-12 w-12 items-center justify-center rounded-full transition',
                                value
                                    ? 'bg-emerald-500 text-white'
                                    : 'bg-muted text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary',
                            )}
                        >
                            {value ? (
                                <Check className="h-6 w-6" />
                            ) : (
                                <Camera className="h-6 w-6" />
                            )}
                        </span>
                        <span className="text-sm font-medium">
                            {value ? 'Photo uploaded' : 'Tap to add a photo'}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {value ? 'Tap again to replace' : 'JPG, PNG up to 10 MB'}
                        </span>
                        <input
                            type="file"
                            accept="image/*"
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file) {
                                    handleChoice(item.id, 'photo_uploaded', false);
                                }
                            }}
                            className="hidden"
                        />
                    </label>
                );
            default:
                return null;
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Checklists', href: `/sites/${site.id}/checklists` },
                { title: 'Run', href: '#' },
            ]}
        >
            <Head title={`${template.name} — Checklist Run`} />

            <div className="relative pb-32">
                {/* HERO HEADER — matches site show purple gradient */}
                <div className="px-4 pt-4 md:px-6">
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
                    >
                        <Link href={`/sites/${site.id}/checklists`}>
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Back to checklists
                        </Link>
                    </Button>

                    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
                        <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-white/5" />
                        <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
                        <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-white/5" />

                        <div className="relative flex flex-col items-center gap-6 md:flex-row md:items-start">
                            {/* Run icon avatar */}
                            <div className="flex h-24 w-24 shrink-0 items-center justify-center rounded-full border-4 border-white/20 bg-white/10 shadow-xl md:h-28 md:w-28">
                                <ClipboardCheck className="h-12 w-12 text-white md:h-14 md:w-14" />
                            </div>

                            {/* Info */}
                            <div className="min-w-0 flex-1 text-center md:text-left">
                                <h1 className="text-2xl font-bold md:text-3xl">
                                    {template.name}
                                </h1>
                                <p className="mt-0.5 text-sm text-white/70">
                                    {site.name}
                                    {run.scheduled_date && (
                                        <>
                                            {' · Scheduled '}
                                            {new Date(
                                                run.scheduled_date,
                                            ).toLocaleDateString(undefined, {
                                                weekday: 'short',
                                                month: 'short',
                                                day: 'numeric',
                                                year: 'numeric',
                                            })}
                                        </>
                                    )}
                                </p>

                                <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                                    {run.status === 'in_progress' && (
                                        <Badge className="border-amber-200/40 bg-amber-50/15 text-amber-100">
                                            In progress
                                        </Badge>
                                    )}
                                    {run.status === 'scheduled' && (
                                        <Badge className="border-white/20 bg-white/10 text-white">
                                            Scheduled
                                        </Badge>
                                    )}
                                    {run.status === 'completed' && (
                                        <Badge className="border-emerald-200/40 bg-emerald-50/15 text-emerald-100">
                                            Completed
                                        </Badge>
                                    )}
                                </div>
                            </div>

                            {/* Progress ring + stats — frosted card on the purple */}
                            <div className="flex items-center gap-5 rounded-xl border border-white/20 bg-white/10 px-5 py-3 text-white backdrop-blur">
                                <ProgressRing
                                    value={progressPercentage}
                                    size={84}
                                    strokeWidth={7}
                                />
                                <div className="space-y-1.5">
                                    <div className="flex items-center gap-3 text-sm">
                                        <span className="flex h-2 w-2 rounded-full bg-emerald-300" />
                                        <span className="tabular-nums font-medium">
                                            {completedRequired -
                                                failedItems.filter(
                                                    (i) => i.is_required,
                                                ).length}
                                        </span>
                                        <span className="text-white/70">
                                            passing
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 text-sm">
                                        <span className="flex h-2 w-2 rounded-full bg-rose-300" />
                                        <span className="tabular-nums font-medium">
                                            {failedItems.length}
                                        </span>
                                        <span className="text-white/70">
                                            failing
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 text-sm">
                                        <span className="flex h-2 w-2 rounded-full bg-white/40" />
                                        <span className="tabular-nums font-medium">
                                            {requiredItems.length -
                                                completedRequired}
                                        </span>
                                        <span className="text-white/70">
                                            remaining
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Failed items strip — kept below the hero, light surface for readability */}
                    {failedItems.length > 0 && (
                        <div className="mt-4 flex items-center gap-3 rounded-lg border border-rose-200 bg-rose-50/80 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/30">
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400">
                                <AlertTriangle className="h-4 w-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-rose-900 dark:text-rose-100">
                                    {failedItems.length} item
                                    {failedItems.length === 1 ? '' : 's'}{' '}
                                    failed
                                </p>
                                <p className="text-xs text-rose-700/80 dark:text-rose-300/80">
                                    Review failures below — some may need a
                                    hazard report.
                                </p>
                            </div>
                        </div>
                    )}
                </div>

                <div className="grid gap-6 px-4 py-6 md:grid-cols-2 md:px-6">
                    {/* MAIN COLUMN */}
                    <div className="space-y-3">
                        {items.map((item, index) => {
                            const response = currentResponses[item.id];
                            const answered = isAnswered(response);
                            const isFailed = !!response?.is_failed;
                            const isNotesExpanded = expandedNotes.has(item.id);
                            const hasNote = !!response?.notes?.trim();

                            return (
                                <Card
                                    key={item.id}
                                    id={`item-${item.id}`}
                                    className={cn(
                                        'overflow-hidden border-l-4 transition',
                                        isFailed
                                            ? 'border-l-rose-500 bg-rose-50/30 dark:bg-rose-950/10'
                                            : answered
                                              ? 'border-l-emerald-500'
                                              : 'border-l-transparent hover:border-l-primary/30',
                                    )}
                                >
                                    <CardContent className="p-4 md:p-5">
                                        <div className="flex items-start gap-4">
                                            {/* Numbered status badge */}
                                            <div
                                                className={cn(
                                                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold transition',
                                                    isFailed
                                                        ? 'bg-rose-500 text-white'
                                                        : answered
                                                          ? 'bg-emerald-500 text-white'
                                                          : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {answered ? (
                                                    isFailed ? (
                                                        <X className="h-4 w-4" />
                                                    ) : (
                                                        <Check className="h-4 w-4" />
                                                    )
                                                ) : (
                                                    index + 1
                                                )}
                                            </div>

                                            <div className="min-w-0 flex-1 space-y-3">
                                                <div>
                                                    <h3 className="text-base font-medium leading-snug">
                                                        {item.question}
                                                        {item.is_required && (
                                                            <span className="ml-1 text-rose-500">
                                                                *
                                                            </span>
                                                        )}
                                                    </h3>
                                                    {item.guidance && (
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            {item.guidance}
                                                        </p>
                                                    )}
                                                </div>

                                                {renderResponseInput(item)}

                                                {/* Notes + hazard side-by-side */}
                                                <div className="flex flex-wrap items-center gap-3 pt-1">
                                                    <Collapsible
                                                        open={isNotesExpanded}
                                                        onOpenChange={() =>
                                                            toggleNotesExpanded(item.id)
                                                        }
                                                        className="flex-1 min-w-full"
                                                    >
                                                        <CollapsibleTrigger asChild>
                                                            <button
                                                                type="button"
                                                                className={cn(
                                                                    'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium transition',
                                                                    hasNote
                                                                        ? 'bg-primary/10 text-primary hover:bg-primary/15'
                                                                        : 'text-muted-foreground hover:bg-muted',
                                                                )}
                                                            >
                                                                <StickyNote className="h-3.5 w-3.5" />
                                                                {hasNote
                                                                    ? 'Note added'
                                                                    : 'Add note'}
                                                                {isNotesExpanded ? (
                                                                    <ChevronUp className="h-3.5 w-3.5" />
                                                                ) : (
                                                                    <ChevronDown className="h-3.5 w-3.5" />
                                                                )}
                                                            </button>
                                                        </CollapsibleTrigger>
                                                        <CollapsibleContent className="mt-2">
                                                            <Textarea
                                                                placeholder="Add any context or observations…"
                                                                value={response?.notes || ''}
                                                                onChange={(e) =>
                                                                    updateResponse(item.id, {
                                                                        notes: e.target.value,
                                                                    })
                                                                }
                                                                rows={2}
                                                                className="text-sm resize-none"
                                                            />
                                                        </CollapsibleContent>
                                                    </Collapsible>
                                                </div>

                                                {/* Hazard checkbox */}
                                                {isFailed && item.failure_creates_hazard && (
                                                    <div className="flex items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-900 dark:bg-amber-950/30">
                                                        <Checkbox
                                                            id={`hazard-${item.id}`}
                                                            checked={
                                                                response?.create_hazard || false
                                                            }
                                                            onCheckedChange={(checked) =>
                                                                updateResponse(item.id, {
                                                                    create_hazard: !!checked,
                                                                })
                                                            }
                                                        />
                                                        <Label
                                                            htmlFor={`hazard-${item.id}`}
                                                            className="flex-1 cursor-pointer text-sm text-amber-800 dark:text-amber-200"
                                                        >
                                                            Create a hazard report for this
                                                            failure
                                                        </Label>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}

                        {/* Overall notes */}
                        <Card className="mt-4">
                            <CardContent className="p-4 md:p-5">
                                <div className="mb-3 flex items-center gap-2">
                                    <PenLine className="h-4 w-4 text-muted-foreground" />
                                    <h3 className="text-sm font-semibold">Overall notes</h3>
                                    <span className="text-xs text-muted-foreground">
                                        (optional)
                                    </span>
                                </div>
                                <Textarea
                                    value={overallNotes}
                                    onChange={(e) => setOverallNotes(e.target.value)}
                                    placeholder="Anything else worth recording about this walkthrough?"
                                    rows={3}
                                    className="resize-none"
                                />
                            </CardContent>
                        </Card>

                        {/* Sign-off */}
                        <Card
                            className={cn(
                                'border-2 transition',
                                isReadyToReview
                                    ? 'border-primary/40 shadow-sm'
                                    : 'border-dashed',
                            )}
                        >
                            <CardContent className="p-4 md:p-5">
                                <div className="mb-4 flex items-center gap-2">
                                    <span
                                        className={cn(
                                            'flex h-7 w-7 items-center justify-center rounded-full',
                                            isReadyToReview
                                                ? 'bg-primary/15 text-primary'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <Sparkles className="h-4 w-4" />
                                    </span>
                                    <h3 className="text-sm font-semibold">
                                        Sign off & complete
                                    </h3>
                                </div>

                                {!isReadyToReview && (
                                    <p className="mb-4 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                                        Answer the remaining{' '}
                                        {requiredItems.length - completedRequired} required
                                        item{requiredItems.length - completedRequired === 1
                                            ? ''
                                            : 's'}{' '}
                                        to enable sign-off.
                                    </p>
                                )}

                                <div className="space-y-3">
                                    <div>
                                        <Label
                                            htmlFor="signature-name"
                                            className="mb-1.5 block text-xs font-medium"
                                        >
                                            Name / signature *
                                        </Label>
                                        <Input
                                            id="signature-name"
                                            type="text"
                                            value={signatureName}
                                            onChange={(e) =>
                                                setSignatureName(e.target.value)
                                            }
                                            placeholder="Type your full name to sign"
                                            className="font-medium"
                                        />
                                    </div>
                                    <label
                                        htmlFor="confirm-accuracy"
                                        className={cn(
                                            'flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 transition',
                                            signatureConfirmed
                                                ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30'
                                                : 'border-border bg-muted/40',
                                        )}
                                    >
                                        <Checkbox
                                            id="confirm-accuracy"
                                            checked={signatureConfirmed}
                                            onCheckedChange={(checked) =>
                                                setSignatureConfirmed(!!checked)
                                            }
                                        />
                                        <span className="flex-1 text-sm">
                                            I confirm this checklist has been completed
                                            accurately and honestly.
                                        </span>
                                    </label>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* SIDE RAIL — desktop only */}
                    <aside className="hidden md:block">
                        <div className="sticky top-4 space-y-4">
                            <Card>
                                <CardContent className="p-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            Items
                                        </h4>
                                        <span className="text-xs tabular-nums text-muted-foreground">
                                            {totalAnswered}/{items.length}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap gap-1.5">
                                        {items.map((item, idx) => {
                                            const r = currentResponses[item.id];
                                            const ans = isAnswered(r);
                                            const fail = !!r?.is_failed;
                                            return (
                                                <button
                                                    key={item.id}
                                                    type="button"
                                                    onClick={() => scrollToItem(item.id)}
                                                    title={item.question}
                                                    className={cn(
                                                        'flex h-8 w-8 items-center justify-center rounded-md text-[11px] font-semibold transition',
                                                        fail
                                                            ? 'bg-rose-500 text-white hover:bg-rose-600'
                                                            : ans
                                                              ? 'bg-emerald-500 text-white hover:bg-emerald-600'
                                                              : 'bg-muted text-muted-foreground hover:bg-muted-foreground/20',
                                                    )}
                                                >
                                                    {idx + 1}
                                                </button>
                                            );
                                        })}
                                    </div>

                                    <div className="mt-4 space-y-2 border-t pt-3 text-xs">
                                        <div className="flex items-center justify-between">
                                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                                <span className="h-2 w-2 rounded-full bg-emerald-500" />
                                                Passed
                                            </span>
                                            <span className="tabular-nums font-medium">
                                                {totalAnswered - failedItems.length}
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                                <span className="h-2 w-2 rounded-full bg-rose-500" />
                                                Failed
                                            </span>
                                            <span className="tabular-nums font-medium">
                                                {failedItems.length}
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                                <span className="h-2 w-2 rounded-full bg-muted-foreground/40" />
                                                Remaining
                                            </span>
                                            <span className="tabular-nums font-medium">
                                                {items.length - totalAnswered}
                                            </span>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {failedItems.length > 0 && (
                                <Card className="border-rose-200 dark:border-rose-900/60">
                                    <CardContent className="p-4">
                                        <div className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-300">
                                            <AlertTriangle className="h-3.5 w-3.5" />
                                            Failed items
                                        </div>
                                        <ul className="space-y-1.5">
                                            {failedItems.map((it) => {
                                                const idx = items.findIndex(
                                                    (i) => i.id === it.id,
                                                );
                                                return (
                                                    <li key={it.id}>
                                                        <button
                                                            type="button"
                                                            onClick={() => scrollToItem(it.id)}
                                                            className="flex w-full items-start gap-2 rounded-md px-2 py-1 text-left text-xs hover:bg-rose-50 dark:hover:bg-rose-950/30"
                                                        >
                                                            <span className="shrink-0 font-mono text-rose-600 dark:text-rose-400">
                                                                {String(idx + 1).padStart(2, '0')}
                                                            </span>
                                                            <span className="line-clamp-2 text-foreground">
                                                                {it.question}
                                                            </span>
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </aside>
                </div>

                {/* STICKY ACTION BAR */}
                <div className="fixed bottom-0 left-0 right-0 z-30 border-t bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                    <div className="flex items-center justify-between gap-3 px-4 py-3 md:px-6">
                        <div className="hidden flex-col text-xs text-muted-foreground md:flex">
                            {form.processing ? (
                                <span className="flex items-center gap-1.5">
                                    <Loader2 className="h-3 w-3 animate-spin" />
                                    Saving…
                                </span>
                            ) : lastSavedAt ? (
                                <span className="flex items-center gap-1.5">
                                    <CheckCircle2 className="h-3 w-3 text-emerald-500" />
                                    Saved {lastSavedAt.toLocaleTimeString()}
                                </span>
                            ) : (
                                <span>Changes are saved when you click Save Draft</span>
                            )}
                        </div>
                        <div className="flex flex-1 items-center justify-end gap-2 md:flex-none">
                            <Button
                                variant="outline"
                                onClick={handleSave}
                                disabled={form.processing}
                            >
                                {form.processing ? (
                                    <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                ) : (
                                    <Save className="mr-1 h-4 w-4" />
                                )}
                                Save draft
                            </Button>
                            <Button
                                onClick={handleComplete}
                                disabled={form.processing || !canComplete}
                                className={cn(
                                    'gap-1.5',
                                    canComplete
                                        ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                        : '',
                                )}
                            >
                                <CheckCircle2 className="h-4 w-4" />
                                Complete checklist
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
