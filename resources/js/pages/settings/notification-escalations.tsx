import InputError from '@/components/input-error';
import { PageHero } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Bell,
    CheckCircle2,
    ChevronDown,
    Clock,
    Filter,
    Info,
    Layers,
    Mail,
    Plus,
    Search,
    Shield,
    Timer,
    TrendingUp,
    X,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { friendlyNotificationName } from './notification-catalog';

type GroupedEvents = Record<string, string[]>;

type Tier = {
    from_reminder: number;
    role_groups: string[];
};

type Rule = {
    enabled: boolean;
    require_ack: boolean;
    must_ack_before_close: boolean;
    force_delivery: boolean;
    remind_after_minutes: number;
    repeat_every_minutes: number;
    max_reminders: number;
    escalate_to_role_groups: string[];
    tiers: Tier[];
};

type Props = {
    groups: GroupedEvents;
    rules: Record<string, Rule>;
    availableRoleGroups: Record<string, string>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Escalation Rules', href: '/settings/notification-escalations' },
];

type FilterMode = 'all' | 'active' | 'disabled' | 'ack';
type SortMode = 'default' | 'urgent';

/** Visual escalation timeline */
function EscalationTimeline({
    rule,
    availableRoleGroups,
}: {
    rule: Rule;
    availableRoleGroups: Record<string, string>;
}) {
    if (!rule.enabled) return null;

    const steps: {
        icon: typeof Clock;
        label: string;
        sublabel?: string;
        colour: string;
    }[] = [];

    // Notification sent step
    steps.push({
        icon: Bell,
        label: 'Sent',
        sublabel: 'Notification delivered',
        colour: 'text-primary bg-primary/10 dark:bg-primary/30 dark:text-primary border-primary dark:border-primary/30',
    });

    if (rule.require_ack) {
        steps.push({
            icon: Clock,
            label: `Wait ${rule.remind_after_minutes}m`,
            sublabel: 'Before first reminder',
            colour: 'text-status-info bg-status-info-bg dark:bg-status-info-bg dark:text-status-info border-status-info/30 dark:border-status-info/30',
        });

        if (rule.repeat_every_minutes > 0) {
            const maxLabel =
                rule.max_reminders > 0
                    ? `up to ${rule.max_reminders}`
                    : 'unlimited';
            steps.push({
                icon: Mail,
                label: `Every ${rule.repeat_every_minutes}m`,
                sublabel: `Reminders (${maxLabel})`,
                colour: 'text-status-warning bg-status-warning-bg dark:bg-status-warning-bg dark:text-status-warning border-status-warning/30 dark:border-status-warning/30',
            });
        }
    }

    // Tiers
    const tiers = rule.tiers || [];
    if (tiers.length > 0) {
        tiers.forEach((tier, idx) => {
            const tierGroups = (tier.role_groups || []).map(
                (g: string) => availableRoleGroups[g] || g,
            );
            steps.push({
                icon: TrendingUp,
                label: `Tier ${idx + 1}`,
                sublabel: `After reminder #${tier.from_reminder}: ${tierGroups.join(', ') || 'No groups'}`,
                colour:
                    idx === tiers.length - 1
                        ? 'text-status-critical bg-status-critical-bg dark:bg-status-critical-bg dark:text-status-critical border-status-critical/30 dark:border-status-critical/30'
                        : 'text-status-warning bg-status-warning-bg dark:bg-status-warning-bg dark:text-status-warning border-status-warning/30 dark:border-status-warning/30',
            });
        });
    } else {
        const groups = (rule.escalate_to_role_groups || []).map(
            (g) => availableRoleGroups[g] || g,
        );
        if (groups.length > 0) {
            steps.push({
                icon: TrendingUp,
                label: 'Escalate',
                sublabel: groups.join(', '),
                colour: 'text-status-critical bg-status-critical-bg dark:bg-status-critical-bg dark:text-status-critical border-status-critical/30 dark:border-status-critical/30',
            });
        }
    }

    // Calculate total window
    let totalWindow = 0;
    if (rule.require_ack) {
        totalWindow = rule.remind_after_minutes;
        if (rule.repeat_every_minutes > 0 && rule.max_reminders > 0) {
            totalWindow += rule.repeat_every_minutes * rule.max_reminders;
        }
    }

    if (steps.length <= 1) {
        return (
            <div className="rounded-lg border border-dashed border-muted-foreground/30 px-4 py-3 text-sm text-muted-foreground">
                Enabled with default settings. Configure acknowledgement or
                escalation groups below.
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <h4 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                    <Layers className="h-4 w-4 text-primary" />
                    Escalation Flow
                </h4>
                {totalWindow > 0 && (
                    <Badge variant="outline" className="text-xs font-normal">
                        <Timer className="mr-1 h-3 w-3" />
                        Total window:{' '}
                        {totalWindow >= 60
                            ? `${Math.floor(totalWindow / 60)}h ${totalWindow % 60}m`
                            : `${totalWindow}m`}
                    </Badge>
                )}
            </div>
            <div className="rounded-xl border bg-gradient-to-r from-muted/30 to-muted/10 p-4">
                <div className="flex flex-wrap items-center gap-1">
                    {steps.map((step, i) => {
                        const StepIcon = step.icon;
                        return (
                            <div key={i} className="flex items-center gap-1">
                                {i > 0 && (
                                    <div className="flex items-center px-0.5">
                                        <div className="h-px w-3 bg-primary sm:w-6 dark:from-primary dark:to-primary" />
                                        <ArrowRight className="h-3 w-3 text-primary" />
                                        <div className="h-px w-3 bg-primary sm:w-6 dark:from-primary dark:to-primary" />
                                    </div>
                                )}
                                <div
                                    className={`flex items-center gap-2 rounded-xl border px-3 py-2 shadow-sm ${step.colour}`}
                                >
                                    <StepIcon className="h-4 w-4 shrink-0" />
                                    <div className="min-w-0">
                                        <div className="text-xs leading-tight font-bold">
                                            {step.label}
                                        </div>
                                        {step.sublabel && (
                                            <div className="max-w-[120px] truncate text-[10px] leading-tight opacity-80 sm:max-w-none">
                                                {step.sublabel}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Tier progression summary */}
            {tiers.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    {tiers.map((tier, idx) => {
                        const tierGroups = (tier.role_groups || []).map(
                            (g: string) => availableRoleGroups[g] || g,
                        );
                        return (
                            <span key={idx} className="flex items-center gap-1">
                                {idx > 0 && <ArrowRight className="h-3 w-3" />}
                                <span className="font-medium text-foreground">
                                    Tier {idx + 1}
                                </span>
                                <span>
                                    (after {tier.from_reminder} reminder
                                    {tier.from_reminder !== 1 ? 's' : ''}):
                                </span>
                                <span className="font-medium">
                                    {tierGroups.join(', ') || 'None'}
                                </span>
                            </span>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/** Tier editor for a single tier */
function TierEditor({
    tier,
    index,
    availableRoleGroups,
    onUpdate,
    onRemove,
}: {
    tier: Tier;
    index: number;
    availableRoleGroups: Record<string, string>;
    onUpdate: (patch: Partial<Tier>) => void;
    onRemove: () => void;
}) {
    const toggleTierGroup = (groupKey: string, on: boolean) => {
        const existing = new Set(tier.role_groups || []);
        if (on) existing.add(groupKey);
        else existing.delete(groupKey);
        onUpdate({ role_groups: Array.from(existing) });
    };

    return (
        <div className="relative rounded-xl border-2 border-dashed border-primary bg-primary/10 p-4 dark:border-primary/30 dark:bg-primary/20">
            <div className="mb-3 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">
                        {index + 1}
                    </div>
                    <span className="text-sm font-semibold text-foreground">
                        Tier {index + 1}
                    </span>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={onRemove}
                    className="h-7 w-7 rounded-full text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                >
                    <X className="h-4 w-4" />
                </Button>
            </div>

            <div className="space-y-3">
                <div className="space-y-1.5">
                    <Label className="text-xs font-medium">
                        Escalate after reminder #
                    </Label>
                    <Input
                        type="number"
                        min={1}
                        value={tier.from_reminder}
                        onChange={(e) =>
                            onUpdate({
                                from_reminder: Number(e.target.value || 1),
                            })
                        }
                        className="w-32"
                    />
                    <p className="text-[11px] text-muted-foreground">
                        This tier activates after this many reminders have been
                        sent
                    </p>
                </div>

                <div className="space-y-1.5">
                    <Label className="text-xs font-medium">Role Groups</Label>
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(availableRoleGroups).map(
                            ([gKey, gLabel]) => {
                                const isSelected = (
                                    tier.role_groups || []
                                ).includes(gKey);
                                return (
                                    <Button
                                        key={gKey}
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            toggleTierGroup(gKey, !isSelected)
                                        }
                                        className={`h-auto rounded-full px-3 py-1 text-xs font-medium transition-all ${
                                            isSelected
                                                ? 'border-primary bg-primary/10 text-primary shadow-sm dark:border-primary dark:bg-primary/40 dark:text-primary/70'
                                                : 'border-border bg-background text-muted-foreground hover:border-primary hover:bg-primary/10 dark:hover:border-primary/30 dark:hover:bg-primary/30'
                                        }`}
                                    >
                                        {isSelected && (
                                            <CheckCircle2 className="mr-1 h-3 w-3" />
                                        )}
                                        {gLabel}
                                    </Button>
                                );
                            },
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function NotificationEscalations({
    groups,
    rules,
    availableRoleGroups,
}: Props) {
    const form = useForm<{ rules: Record<string, Rule> }>({
        rules,
    });

    const [saveSuccess, setSaveSuccess] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [filterMode, setFilterMode] = useState<FilterMode>('all');
    const [sortMode, setSortMode] = useState<SortMode>('default');
    const [timingOpen, setTimingOpen] = useState<Record<string, boolean>>({});
    const [targetsOpen, setTargetsOpen] = useState<Record<string, boolean>>({});
    // Track the saved baseline as state so the dirty check stays consistent
    // with React's render lifecycle (a ref would trip
    // `react-hooks/refs` because it'd be read during render). The
    // `onSuccess` handler below resets the baseline to the freshly saved
    // value so the form returns to a clean state after a successful save.
    const [savedRulesJson, setSavedRulesJson] = useState(() =>
        JSON.stringify(rules),
    );

    const isDirty = JSON.stringify(form.data.rules) !== savedRulesJson;

    const setRule = useCallback(
        (key: string, patch: Partial<Rule>) => {
            form.setData('rules', {
                ...form.data.rules,
                [key]: {
                    ...form.data.rules[key],
                    ...patch,
                },
            });
        },
        [form],
    );

    const toggleGroup = useCallback(
        (eventKey: string, groupKey: string, on: boolean) => {
            const existing = new Set(
                form.data.rules[eventKey].escalate_to_role_groups || [],
            );
            if (on) existing.add(groupKey);
            else existing.delete(groupKey);
            setRule(eventKey, {
                escalate_to_role_groups: Array.from(existing),
            });
        },
        [form.data.rules, setRule],
    );

    const addTier = useCallback(
        (eventKey: string) => {
            const currentTiers = form.data.rules[eventKey].tiers || [];
            const lastFromReminder =
                currentTiers.length > 0
                    ? currentTiers[currentTiers.length - 1].from_reminder
                    : 0;
            setRule(eventKey, {
                tiers: [
                    ...currentTiers,
                    { from_reminder: lastFromReminder + 1, role_groups: [] },
                ],
            });
        },
        [form.data.rules, setRule],
    );

    const updateTier = useCallback(
        (eventKey: string, tierIndex: number, patch: Partial<Tier>) => {
            const currentTiers = [...(form.data.rules[eventKey].tiers || [])];
            currentTiers[tierIndex] = { ...currentTiers[tierIndex], ...patch };
            setRule(eventKey, { tiers: currentTiers });
        },
        [form.data.rules, setRule],
    );

    const removeTier = useCallback(
        (eventKey: string, tierIndex: number) => {
            const currentTiers = [...(form.data.rules[eventKey].tiers || [])];
            currentTiers.splice(tierIndex, 1);
            setRule(eventKey, { tiers: currentTiers });
        },
        [form.data.rules, setRule],
    );

    const allKeys = useMemo(() => Object.values(groups).flat(), [groups]);

    // Stats
    const stats = useMemo(() => {
        let active = 0,
            ack = 0,
            force = 0;
        for (const k of allKeys) {
            const r = form.data.rules[k];
            if (!r) continue;
            if (r.enabled) active++;
            if (r.enabled && r.require_ack) ack++;
            if (r.enabled && r.force_delivery) force++;
        }
        return { total: allKeys.length, active, ack, force };
    }, [allKeys, form.data.rules]);

    // Filter + Sort + Search
    const filteredKeys = useMemo(() => {
        let keys = [...allKeys];

        // Search
        if (searchQuery.trim()) {
            const q = searchQuery.toLowerCase();
            keys = keys.filter(
                (k) =>
                    friendlyNotificationName(k).toLowerCase().includes(q) ||
                    k.toLowerCase().includes(q),
            );
        }

        // Filter
        if (filterMode === 'active')
            keys = keys.filter((k) => form.data.rules[k]?.enabled);
        else if (filterMode === 'disabled')
            keys = keys.filter((k) => !form.data.rules[k]?.enabled);
        else if (filterMode === 'ack')
            keys = keys.filter(
                (k) =>
                    form.data.rules[k]?.enabled &&
                    form.data.rules[k]?.require_ack,
            );

        // Sort
        if (sortMode === 'urgent') {
            keys.sort((a, b) => {
                const ra = form.data.rules[a];
                const rb = form.data.rules[b];
                if (!ra || !rb) return 0;
                // Enabled first
                if (ra.enabled !== rb.enabled) return ra.enabled ? -1 : 1;
                // Then by remind_after_minutes ASC (most urgent first)
                return (
                    (ra.remind_after_minutes || 999) -
                    (rb.remind_after_minutes || 999)
                );
            });
        } else {
            // Default: enabled first
            keys.sort((a, b) => {
                const aE = form.data.rules[a]?.enabled ? 1 : 0;
                const bE = form.data.rules[b]?.enabled ? 1 : 0;
                return bE - aE;
            });
        }

        return keys;
    }, [allKeys, searchQuery, filterMode, sortMode, form.data.rules]);

    const filterButtons: { mode: FilterMode; label: string }[] = [
        { mode: 'all', label: 'All' },
        { mode: 'active', label: 'Active Only' },
        { mode: 'disabled', label: 'Disabled Only' },
        { mode: 'ack', label: 'Requires Ack' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Escalation Rules" />

            <SettingsLayout>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put('/settings/notifications/escalations', {
                            onSuccess: () => {
                                setSavedRulesJson(
                                    JSON.stringify(form.data.rules),
                                );
                                setSaveSuccess(true);
                                setTimeout(() => setSaveSuccess(false), 3000);
                            },
                        });
                    }}
                    className="space-y-6"
                >
                    {/* ── Header ── */}
                    <PageHero
                        icon={Bell}
                        title="Escalation Rules"
                        description="Configure automatic reminders, acknowledgement requirements, and multi-tier escalation chains. Critical events will automatically escalate if not acknowledged within the configured timeframes."
                        stats={[
                            { label: 'Total Rules', value: stats.total },
                            { label: 'Active', value: stats.active },
                            { label: 'Require Ack', value: stats.ack },
                            { label: 'Force Delivery', value: stats.force },
                        ]}
                    />

                    {/* ── Info Banner ── */}
                    <Card className="border-status-info/30 bg-status-info-bg dark:border-status-info/30">
                        <CardContent className="flex gap-3 p-4">
                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-status-info-bg">
                                <Info className="h-4 w-4 text-status-info dark:text-status-info" />
                            </div>
                            <div className="space-y-2 text-sm text-status-info dark:text-status-info">
                                <p className="font-semibold">
                                    How escalation works
                                </p>
                                <p className="text-xs leading-relaxed text-status-info dark:text-status-info">
                                    When a notification is sent and requires
                                    acknowledgement, the system will:
                                    <span className="font-semibold">
                                        {' '}
                                        (1)
                                    </span>{' '}
                                    Wait for the configured time before sending
                                    the first reminder,
                                    <span className="font-semibold">
                                        {' '}
                                        (2)
                                    </span>{' '}
                                    Send reminders at the configured interval,
                                    <span className="font-semibold">
                                        {' '}
                                        (3)
                                    </span>{' '}
                                    Escalate to progressively higher role groups
                                    if still unacknowledged. Force delivery
                                    bypasses user notification preferences,
                                    ensuring critical alerts are always
                                    received.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ── Filter / Sort Bar ── */}
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        {/* Search */}
                        <div className="relative flex-1">
                            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Search rules by name..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="pl-9"
                            />
                        </div>

                        {/* Filter buttons */}
                        <div className="flex items-center gap-1 rounded-lg border bg-muted/30 p-1">
                            <Filter className="ml-1.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                            {filterButtons.map((fb) => (
                                <Button
                                    key={fb.mode}
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setFilterMode(fb.mode)}
                                    className={`h-auto rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                                        filterMode === fb.mode
                                            ? 'bg-primary text-white shadow-sm'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                    }`}
                                >
                                    {fb.label}
                                </Button>
                            ))}
                        </div>

                        {/* Sort */}
                        <div className="flex items-center gap-1 rounded-lg border bg-muted/30 p-1">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setSortMode('default')}
                                className={`h-auto rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                                    sortMode === 'default'
                                        ? 'bg-primary text-white shadow-sm'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                }`}
                            >
                                Default
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setSortMode('urgent')}
                                className={`h-auto rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                                    sortMode === 'urgent'
                                        ? 'bg-primary text-white shadow-sm'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                }`}
                            >
                                Most Urgent First
                            </Button>
                        </div>
                    </div>

                    {/* ── Results count ── */}
                    <p className="text-xs text-muted-foreground">
                        Showing {filteredKeys.length} of {allKeys.length} rules
                    </p>

                    {/* ── Rule Cards ── */}
                    {filteredKeys.map((k) => {
                        const r = form.data.rules[k];
                        if (!r) return null;

                        const isEnabled = !!r.enabled;
                        const isTimingOpen = timingOpen[k] ?? true;
                        const isTargetsOpen = targetsOpen[k] ?? false;
                        const tiers = r.tiers || [];

                        return (
                            <Card
                                key={k}
                                className={`transition-all duration-200 ${
                                    !isEnabled
                                        ? 'opacity-50 grayscale-[30%]'
                                        : 'border-primary/30 shadow-sm dark:border-primary/40'
                                }`}
                            >
                                {/* ── Card Header ── */}
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex items-start gap-3">
                                            <div
                                                className={`mt-0.5 flex h-10 w-10 items-center justify-center rounded-xl ${
                                                    isEnabled
                                                        ? 'bg-primary/10 dark:bg-primary/30'
                                                        : 'bg-muted'
                                                }`}
                                            >
                                                <AlertTriangle
                                                    className={`h-5 w-5 ${
                                                        isEnabled
                                                            ? 'text-primary dark:text-primary'
                                                            : 'text-muted-foreground'
                                                    }`}
                                                />
                                            </div>
                                            <div>
                                                <CardTitle className="text-base font-bold">
                                                    {friendlyNotificationName(
                                                        k,
                                                    )}
                                                </CardTitle>
                                                <CardDescription className="mt-0.5 font-mono text-[11px]">
                                                    {k}
                                                </CardDescription>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {isEnabled ? (
                                                <Badge className="bg-status-success-bg text-status-success hover:bg-status-success-bg dark:bg-status-success-bg dark:text-status-success">
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">
                                                    Disabled
                                                </Badge>
                                            )}
                                            <Switch
                                                checked={isEnabled}
                                                onCheckedChange={(v) =>
                                                    setRule(k, { enabled: !!v })
                                                }
                                            />
                                        </div>
                                    </div>
                                </CardHeader>

                                {isEnabled ? (
                                    <CardContent className="space-y-4 pt-0">
                                        {/* ── Section 1: Escalation Flow (always visible) ── */}
                                        <EscalationTimeline
                                            rule={r}
                                            availableRoleGroups={
                                                availableRoleGroups
                                            }
                                        />

                                        {/* ── Section 2: Timing & Delivery (collapsible, default open) ── */}
                                        <Collapsible
                                            open={isTimingOpen}
                                            onOpenChange={(open) =>
                                                setTimingOpen((prev) => ({
                                                    ...prev,
                                                    [k]: open,
                                                }))
                                            }
                                        >
                                            <CollapsibleTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="h-auto w-full justify-start gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors hover:bg-muted/50"
                                                >
                                                    <Timer className="h-4 w-4 text-primary" />
                                                    Timing & Delivery
                                                    <ChevronDown
                                                        className={`ml-auto h-4 w-4 text-muted-foreground transition-transform ${isTimingOpen ? 'rotate-180' : ''}`}
                                                    />
                                                </Button>
                                            </CollapsibleTrigger>
                                            <CollapsibleContent>
                                                <div className="mt-3 space-y-5 rounded-lg border bg-muted/20 p-4">
                                                    {/* Timing inputs - 3 column grid */}
                                                    <div className="grid gap-4 sm:grid-cols-3">
                                                        <div className="space-y-1.5">
                                                            <Label
                                                                htmlFor={`${k}-remind`}
                                                                className="text-xs font-semibold"
                                                            >
                                                                First reminder
                                                                after
                                                            </Label>
                                                            <div className="relative">
                                                                <Input
                                                                    id={`${k}-remind`}
                                                                    type="number"
                                                                    min={1}
                                                                    value={
                                                                        r.remind_after_minutes
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setRule(
                                                                            k,
                                                                            {
                                                                                remind_after_minutes:
                                                                                    Number(
                                                                                        e
                                                                                            .target
                                                                                            .value ||
                                                                                            0,
                                                                                    ),
                                                                            },
                                                                        )
                                                                    }
                                                                    className="pr-16"
                                                                />
                                                                <span className="absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted-foreground">
                                                                    minutes
                                                                </span>
                                                            </div>
                                                            <p className="text-[11px] text-muted-foreground">
                                                                Time before
                                                                first reminder
                                                                is sent
                                                            </p>
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label
                                                                htmlFor={`${k}-repeat`}
                                                                className="text-xs font-semibold"
                                                            >
                                                                Repeat every
                                                            </Label>
                                                            <div className="relative">
                                                                <Input
                                                                    id={`${k}-repeat`}
                                                                    type="number"
                                                                    min={1}
                                                                    value={
                                                                        r.repeat_every_minutes
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setRule(
                                                                            k,
                                                                            {
                                                                                repeat_every_minutes:
                                                                                    Number(
                                                                                        e
                                                                                            .target
                                                                                            .value ||
                                                                                            0,
                                                                                    ),
                                                                            },
                                                                        )
                                                                    }
                                                                    className="pr-16"
                                                                />
                                                                <span className="absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted-foreground">
                                                                    minutes
                                                                </span>
                                                            </div>
                                                            <p className="text-[11px] text-muted-foreground">
                                                                Interval between
                                                                subsequent
                                                                reminders
                                                            </p>
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label
                                                                htmlFor={`${k}-max`}
                                                                className="text-xs font-semibold"
                                                            >
                                                                Max reminders
                                                            </Label>
                                                            <Input
                                                                id={`${k}-max`}
                                                                type="number"
                                                                min={0}
                                                                value={
                                                                    r.max_reminders
                                                                }
                                                                onChange={(e) =>
                                                                    setRule(k, {
                                                                        max_reminders:
                                                                            Number(
                                                                                e
                                                                                    .target
                                                                                    .value ||
                                                                                    0,
                                                                            ),
                                                                    })
                                                                }
                                                            />
                                                            <p className="text-[11px] text-muted-foreground">
                                                                0 = unlimited
                                                                reminders until
                                                                acknowledged
                                                            </p>
                                                        </div>
                                                    </div>

                                                    {/* Toggle switches */}
                                                    <div className="space-y-3">
                                                        {/* eslint-disable-next-line no-restricted-syntax -- Switch rows are compact form controls inside an existing settings Card. */}
                                                        <div className="flex items-center justify-between gap-4 rounded-lg border bg-background px-4 py-3">
                                                            <div>
                                                                <Label
                                                                    htmlFor={`${k}-ack`}
                                                                    className="cursor-pointer text-sm font-medium"
                                                                >
                                                                    Require
                                                                    acknowledgement
                                                                </Label>
                                                                <p className="text-[11px] text-muted-foreground">
                                                                    Recipient
                                                                    must
                                                                    explicitly
                                                                    acknowledge
                                                                    this
                                                                    notification
                                                                </p>
                                                            </div>
                                                            <Switch
                                                                id={`${k}-ack`}
                                                                checked={
                                                                    !!r.require_ack
                                                                }
                                                                onCheckedChange={(
                                                                    v,
                                                                ) =>
                                                                    setRule(k, {
                                                                        require_ack:
                                                                            !!v,
                                                                    })
                                                                }
                                                            />
                                                        </div>

                                                        {/* eslint-disable-next-line no-restricted-syntax -- Switch rows are compact form controls inside an existing settings Card. */}
                                                        <div className="flex items-center justify-between gap-4 rounded-lg border bg-background px-4 py-3">
                                                            <div>
                                                                <Label
                                                                    htmlFor={`${k}-ackclose`}
                                                                    className={`cursor-pointer text-sm font-medium ${!r.require_ack ? 'opacity-50' : ''}`}
                                                                >
                                                                    Must
                                                                    acknowledge
                                                                    before close
                                                                </Label>
                                                                <p
                                                                    className={`text-[11px] text-muted-foreground ${!r.require_ack ? 'opacity-50' : ''}`}
                                                                >
                                                                    Cannot close
                                                                    or resolve
                                                                    the source
                                                                    event
                                                                    without
                                                                    acknowledging
                                                                </p>
                                                            </div>
                                                            <Switch
                                                                id={`${k}-ackclose`}
                                                                checked={
                                                                    !!r.must_ack_before_close
                                                                }
                                                                onCheckedChange={(
                                                                    v,
                                                                ) =>
                                                                    setRule(k, {
                                                                        must_ack_before_close:
                                                                            !!v,
                                                                    })
                                                                }
                                                                disabled={
                                                                    !r.require_ack
                                                                }
                                                            />
                                                        </div>

                                                        <div
                                                            className={`flex items-center justify-between gap-4 rounded-lg border px-4 py-3 ${
                                                                r.force_delivery
                                                                    ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30'
                                                                    : 'bg-background'
                                                            }`}
                                                        >
                                                            <div>
                                                                <Label
                                                                    htmlFor={`${k}-force`}
                                                                    className="cursor-pointer text-sm font-medium"
                                                                >
                                                                    Force
                                                                    delivery
                                                                </Label>
                                                                <p className="text-[11px] text-muted-foreground">
                                                                    Bypass user
                                                                    notification
                                                                    preferences
                                                                    &mdash;
                                                                    always
                                                                    deliver
                                                                </p>
                                                                {r.force_delivery && (
                                                                    <div className="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-status-critical dark:text-status-critical">
                                                                        <AlertTriangle className="h-3.5 w-3.5" />
                                                                        This
                                                                        will
                                                                        override
                                                                        individual
                                                                        user
                                                                        preferences
                                                                    </div>
                                                                )}
                                                            </div>
                                                            <Switch
                                                                id={`${k}-force`}
                                                                checked={
                                                                    !!r.force_delivery
                                                                }
                                                                onCheckedChange={(
                                                                    v,
                                                                ) =>
                                                                    setRule(k, {
                                                                        force_delivery:
                                                                            !!v,
                                                                    })
                                                                }
                                                                className={
                                                                    r.force_delivery
                                                                        ? 'data-[state=checked]:bg-status-critical'
                                                                        : ''
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                            </CollapsibleContent>
                                        </Collapsible>

                                        {/* ── Section 3: Escalation Targets (collapsible) ── */}
                                        <Collapsible
                                            open={isTargetsOpen}
                                            onOpenChange={(open) =>
                                                setTargetsOpen((prev) => ({
                                                    ...prev,
                                                    [k]: open,
                                                }))
                                            }
                                        >
                                            <CollapsibleTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="h-auto w-full justify-start gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors hover:bg-muted/50"
                                                >
                                                    <TrendingUp className="h-4 w-4 text-primary" />
                                                    Escalation Targets
                                                    {(r.escalate_to_role_groups
                                                        ?.length > 0 ||
                                                        tiers.length > 0) && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="ml-1 text-[10px]"
                                                        >
                                                            {(r
                                                                .escalate_to_role_groups
                                                                ?.length || 0) +
                                                                tiers.length}{' '}
                                                            configured
                                                        </Badge>
                                                    )}
                                                    <ChevronDown
                                                        className={`ml-auto h-4 w-4 text-muted-foreground transition-transform ${isTargetsOpen ? 'rotate-180' : ''}`}
                                                    />
                                                </Button>
                                            </CollapsibleTrigger>
                                            <CollapsibleContent>
                                                <div className="mt-3 space-y-5 rounded-lg border bg-muted/20 p-4">
                                                    {/* Primary escalation groups */}
                                                    <div className="space-y-2">
                                                        <Label className="text-xs font-semibold">
                                                            Primary Escalation
                                                            Groups
                                                        </Label>
                                                        <p className="text-[11px] text-muted-foreground">
                                                            Select which role
                                                            groups should
                                                            receive escalated
                                                            notifications
                                                        </p>
                                                        <div className="flex flex-wrap gap-2">
                                                            {Object.entries(
                                                                availableRoleGroups,
                                                            ).map(
                                                                ([
                                                                    gKey,
                                                                    gLabel,
                                                                ]) => {
                                                                    const isSelected =
                                                                        (
                                                                            r.escalate_to_role_groups ||
                                                                            []
                                                                        ).includes(
                                                                            gKey,
                                                                        );
                                                                    return (
                                                                        <Button
                                                                            key={
                                                                                gKey
                                                                            }
                                                                            type="button"
                                                                            variant="outline"
                                                                            onClick={() =>
                                                                                toggleGroup(
                                                                                    k,
                                                                                    gKey,
                                                                                    !isSelected,
                                                                                )
                                                                            }
                                                                            className={`h-auto rounded-full px-3 py-1.5 text-xs font-medium transition-all ${
                                                                                isSelected
                                                                                    ? 'border-primary bg-primary/10 text-primary shadow-sm dark:border-primary dark:bg-primary/40 dark:text-primary/70'
                                                                                    : 'border-border bg-background text-muted-foreground hover:border-primary hover:bg-primary/10 dark:hover:border-primary/30 dark:hover:bg-primary/30'
                                                                            }`}
                                                                        >
                                                                            {isSelected && (
                                                                                <CheckCircle2 className="mr-1.5 h-3 w-3" />
                                                                            )}
                                                                            {
                                                                                gLabel
                                                                            }
                                                                        </Button>
                                                                    );
                                                                },
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Tiered escalation */}
                                                    <div className="space-y-3">
                                                        <div className="flex items-center justify-between">
                                                            <div>
                                                                <Label className="text-xs font-semibold">
                                                                    Tiered
                                                                    Escalation
                                                                </Label>
                                                                <p className="text-[11px] text-muted-foreground">
                                                                    Tiers allow
                                                                    progressive
                                                                    escalation
                                                                    &mdash;
                                                                    start with
                                                                    direct team,
                                                                    then
                                                                    coordinators,
                                                                    then
                                                                    managers
                                                                </p>
                                                            </div>
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    addTier(k)
                                                                }
                                                                className="gap-1.5 border-primary text-primary hover:bg-primary/10 dark:border-primary/30 dark:text-primary/70 dark:hover:bg-primary/30"
                                                            >
                                                                <Plus className="h-3.5 w-3.5" />
                                                                Add Escalation
                                                                Tier
                                                            </Button>
                                                        </div>

                                                        {tiers.length > 0 ? (
                                                            <div className="relative space-y-3 pl-4">
                                                                {/* Connecting line */}
                                                                <div className="absolute top-4 bottom-4 left-[1.1rem] w-0.5 bg-primary dark:from-primary dark:via-primary dark:to-primary" />
                                                                {tiers.map(
                                                                    (
                                                                        tier,
                                                                        idx,
                                                                    ) => (
                                                                        <TierEditor
                                                                            key={
                                                                                idx
                                                                            }
                                                                            tier={
                                                                                tier
                                                                            }
                                                                            index={
                                                                                idx
                                                                            }
                                                                            availableRoleGroups={
                                                                                availableRoleGroups
                                                                            }
                                                                            onUpdate={(
                                                                                patch,
                                                                            ) =>
                                                                                updateTier(
                                                                                    k,
                                                                                    idx,
                                                                                    patch,
                                                                                )
                                                                            }
                                                                            onRemove={() =>
                                                                                removeTier(
                                                                                    k,
                                                                                    idx,
                                                                                )
                                                                            }
                                                                        />
                                                                    ),
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <div className="rounded-lg border border-dashed border-muted-foreground/30 px-4 py-6 text-center text-xs text-muted-foreground">
                                                                No escalation
                                                                tiers
                                                                configured. Add
                                                                a tier to enable
                                                                progressive
                                                                escalation.
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </CollapsibleContent>
                                        </Collapsible>
                                    </CardContent>
                                ) : (
                                    <CardContent className="pt-0">
                                        <div className="rounded-lg bg-muted/50 px-4 py-3 text-xs text-muted-foreground">
                                            Enable this rule to configure
                                            escalation settings
                                        </div>
                                    </CardContent>
                                )}
                            </Card>
                        );
                    })}

                    {/* No results */}
                    {filteredKeys.length === 0 && (
                        <div className="rounded-lg border border-dashed px-6 py-12 text-center">
                            <Search className="mx-auto mb-3 h-8 w-8 text-muted-foreground/50" />
                            <p className="text-sm font-medium text-muted-foreground">
                                No rules match your filters
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground/70">
                                Try adjusting your search or filter criteria
                            </p>
                        </div>
                    )}

                    {form.errors?.rules && (
                        <InputError message={form.errors.rules as any} />
                    )}

                    {/* ── Sticky Save Bar ── */}
                    {/* eslint-disable-next-line no-restricted-syntax -- Sticky save bar needs sticky positioning/backdrop styling, not Card spacing. */}
                    <div className="sticky bottom-0 -mx-1 rounded-xl border bg-background/95 px-4 py-3 shadow-lg backdrop-blur-sm">
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                {isDirty && (
                                    <div className="flex items-center gap-1.5 text-xs font-medium text-status-warning dark:text-status-warning">
                                        <div className="h-2 w-2 animate-pulse rounded-full bg-status-warning" />
                                        Unsaved changes
                                    </div>
                                )}
                                {saveSuccess && (
                                    <div className="flex items-center gap-1.5 text-sm font-medium text-status-success dark:text-status-success">
                                        <CheckCircle2 className="h-4 w-4" />
                                        Escalation rules saved successfully
                                    </div>
                                )}
                            </div>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="gap-2 bg-primary px-6 hover:bg-primary"
                            >
                                <Shield className="h-4 w-4" />
                                {form.processing
                                    ? 'Saving...'
                                    : 'Save Escalation Rules'}
                            </Button>
                        </div>
                    </div>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}
