import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
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
    Mail,
    Settings2,
    Shield,
    TrendingUp,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type GroupedEvents = Record<string, string[]>;

type Rule = {
    enabled: boolean;
    require_ack: boolean;
    must_ack_before_close: boolean;
    force_delivery: boolean;
    remind_after_minutes: number;
    repeat_every_minutes: number;
    max_reminders: number;
    escalate_to_role_groups: string[];
    tiers: any[];
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

/** Friendly name map */
const NOTIFICATION_META: Record<string, string> = {
    'timesheets.created': 'Timesheet Created',
    'timesheets.updated': 'Timesheet Updated',
    'timesheets.submitted': 'Timesheet Submitted',
    'timesheets.approved': 'Timesheet Approved',
    'timesheets.rejected': 'Timesheet Rejected',
    'timesheets.returned': 'Timesheet Returned',
    'incidents.draft_created': 'Incident Draft Created',
    'incidents.submitted': 'Incident Submitted',
    'incidents.reviewed': 'Incident Reviewed',
    'incidents.high_severity_alert': 'High Severity Alert',
    'breakglass.daily_report': 'Break Glass Daily Report',
    'incidents.high_unreviewed_reminder': 'High Severity Unreviewed Reminder',
    'followups.created': 'Follow-up Created',
    'followups.updated': 'Follow-up Updated',
    'followups.completed': 'Follow-up Completed',
    'followups.overdue_reminder': 'Follow-up Overdue Reminder',
};

function friendlyName(key: string): string {
    return NOTIFICATION_META[key] ?? key
        .replace(/\./g, ' ')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Visual escalation timeline component */
function EscalationTimeline({ rule, availableRoleGroups }: { rule: Rule; availableRoleGroups: Record<string, string> }) {
    if (!rule.enabled) return null;

    const steps: { icon: typeof Clock; label: string; sublabel?: string; colour: string }[] = [];

    if (rule.require_ack) {
        // Step 1: Initial wait
        steps.push({
            icon: Clock,
            label: `${rule.remind_after_minutes} min`,
            sublabel: 'First reminder',
            colour: 'text-blue-600 bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 border-blue-200 dark:border-blue-800',
        });

        // Step 2: Repeat reminders
        if (rule.repeat_every_minutes > 0) {
            const maxLabel = rule.max_reminders > 0 ? `${rule.max_reminders}x max` : 'unlimited';
            steps.push({
                icon: Mail,
                label: `Every ${rule.repeat_every_minutes} min`,
                sublabel: `Remind (${maxLabel})`,
                colour: 'text-amber-600 bg-amber-100 dark:bg-amber-900/30 dark:text-amber-400 border-amber-200 dark:border-amber-800',
            });
        }
    }

    // Step 3: Escalation groups
    const groups = (rule.escalate_to_role_groups || [])
        .map((g) => availableRoleGroups[g] || g);
    if (groups.length > 0) {
        steps.push({
            icon: TrendingUp,
            label: 'Escalate',
            sublabel: groups.join(', '),
            colour: 'text-red-600 bg-red-100 dark:bg-red-900/30 dark:text-red-400 border-red-200 dark:border-red-800',
        });
    }

    if (steps.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-muted-foreground/30 px-4 py-3 text-sm text-muted-foreground">
                Enabled with default settings. Configure acknowledgement or escalation groups below.
            </div>
        );
    }

    return (
        <div className="rounded-lg border bg-muted/20 p-4">
            <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Escalation Flow</div>
            <div className="flex flex-wrap items-center gap-1">
                {steps.map((step, i) => {
                    const StepIcon = step.icon;
                    return (
                        <div key={i} className="flex items-center gap-1">
                            {i > 0 && (
                                <div className="flex items-center px-1">
                                    <div className="h-px w-4 bg-border" />
                                    <ArrowRight className="h-3 w-3 text-muted-foreground/50" />
                                    <div className="h-px w-4 bg-border" />
                                </div>
                            )}
                            <div className={`flex items-center gap-2 rounded-lg border px-3 py-2 ${step.colour}`}>
                                <StepIcon className="h-4 w-4 shrink-0" />
                                <div className="min-w-0">
                                    <div className="text-xs font-semibold">{step.label}</div>
                                    {step.sublabel && (
                                        <div className="text-[11px] opacity-80">{step.sublabel}</div>
                                    )}
                                </div>
                            </div>
                        </div>
                    );
                })}
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

    const [tierErrors, setTierErrors] = useState<Record<string, string>>({});
    const [advancedOpen, setAdvancedOpen] = useState<Record<string, boolean>>({});
    const [saveSuccess, setSaveSuccess] = useState(false);

    const setRule = (key: string, patch: Partial<Rule>) => {
        form.setData('rules', {
            ...form.data.rules,
            [key]: {
                ...form.data.rules[key],
                ...patch,
            },
        });
    };

    const toggleGroup = (eventKey: string, groupKey: string, on: boolean) => {
        const existing = new Set(
            form.data.rules[eventKey].escalate_to_role_groups || [],
        );
        if (on) existing.add(groupKey);
        else existing.delete(groupKey);
        setRule(eventKey, { escalate_to_role_groups: Array.from(existing) });
    };

    const allKeys = Object.values(groups).flat();

    // Compute stats
    const totalRules = allKeys.length;
    const activeRules = useMemo(() => allKeys.filter((k) => form.data.rules[k]?.enabled).length, [allKeys, form.data.rules]);
    const ackRequired = useMemo(() => allKeys.filter((k) => form.data.rules[k]?.enabled && form.data.rules[k]?.require_ack).length, [allKeys, form.data.rules]);

    // Sort: enabled first, disabled at bottom
    const sortedKeys = useMemo(() => {
        return [...allKeys].sort((a, b) => {
            const aEnabled = form.data.rules[a]?.enabled ? 1 : 0;
            const bEnabled = form.data.rules[b]?.enabled ? 1 : 0;
            return bEnabled - aEnabled;
        });
    }, [allKeys, form.data.rules]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Escalation Rules" />

            <SettingsLayout>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put('/settings/notifications/escalations', {
                            onSuccess: () => {
                                setSaveSuccess(true);
                                setTimeout(() => setSaveSuccess(false), 3000);
                            },
                        });
                    }}
                    className="space-y-6"
                >
                    {/* Header */}
                    <HeadingSmall
                        title="Escalation Rules"
                        description="Configure automatic reminders and escalation chains for operational notifications"
                    />

                    {/* Stats Row */}
                    <div className="grid grid-cols-3 gap-3">
                        <Card className="border-indigo-200 dark:border-indigo-800">
                            <CardContent className="flex items-center gap-3 p-4">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-900/30">
                                    <Shield className="h-4 w-4 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <p className="text-xl font-bold text-indigo-600 dark:text-indigo-400">{totalRules}</p>
                                    <p className="text-xs text-muted-foreground">Total Rules</p>
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-emerald-200 dark:border-emerald-800">
                            <CardContent className="flex items-center gap-3 p-4">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/30">
                                    <Zap className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <div>
                                    <p className="text-xl font-bold text-emerald-600 dark:text-emerald-400">{activeRules}</p>
                                    <p className="text-xs text-muted-foreground">Active Rules</p>
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-amber-200 dark:border-amber-800">
                            <CardContent className="flex items-center gap-3 p-4">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/30">
                                    <Bell className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                                </div>
                                <div>
                                    <p className="text-xl font-bold text-amber-600 dark:text-amber-400">{ackRequired}</p>
                                    <p className="text-xs text-muted-foreground">Require Ack</p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Event Cards - sorted: active first */}
                    {sortedKeys.map((k) => {
                        const r = form.data.rules[k];
                        if (!r) return null;

                        const isEnabled = !!r.enabled;
                        const isAdvancedOpen = advancedOpen[k] ?? false;

                        return (
                            <Card
                                key={k}
                                className={`transition-opacity ${!isEnabled ? 'opacity-50' : ''}`}
                            >
                                <CardHeader>
                                    <div className="flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-3">
                                            <div className={`flex h-9 w-9 items-center justify-center rounded-lg ${isEnabled ? 'bg-violet-100 dark:bg-violet-900/30' : 'bg-muted'}`}>
                                                <AlertTriangle className={`h-4 w-4 ${isEnabled ? 'text-violet-600 dark:text-violet-400' : 'text-muted-foreground'}`} />
                                            </div>
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <CardTitle className="text-base">
                                                        {friendlyName(k)}
                                                    </CardTitle>
                                                    {isEnabled ? (
                                                        <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400 text-[10px]">
                                                            Active
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary" className="text-[10px]">
                                                            Disabled
                                                        </Badge>
                                                    )}
                                                </div>
                                                <CardDescription className="text-xs">
                                                    {k}
                                                </CardDescription>
                                            </div>
                                        </div>
                                        <Switch
                                            checked={isEnabled}
                                            onCheckedChange={(v) => setRule(k, { enabled: !!v })}
                                        />
                                    </div>
                                </CardHeader>

                                {isEnabled ? (
                                    <CardContent className="space-y-4 pt-0">
                                        {/* Visual Escalation Timeline */}
                                        <EscalationTimeline rule={r} availableRoleGroups={availableRoleGroups} />

                                        {/* Advanced Settings */}
                                        <Collapsible
                                            open={isAdvancedOpen}
                                            onOpenChange={(open) =>
                                                setAdvancedOpen((prev) => ({ ...prev, [k]: open }))
                                            }
                                        >
                                            <CollapsibleTrigger asChild>
                                                <button
                                                    type="button"
                                                    className="flex w-full items-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-medium transition-colors hover:bg-muted/50"
                                                >
                                                    <Settings2 className="h-4 w-4 text-muted-foreground" />
                                                    Advanced Settings
                                                    <ChevronDown className={`ml-auto h-4 w-4 text-muted-foreground transition-transform ${isAdvancedOpen ? 'rotate-180' : ''}`} />
                                                </button>
                                            </CollapsibleTrigger>
                                            <CollapsibleContent>
                                                <div className="mt-3 space-y-4 rounded-lg border bg-muted/30 p-4">
                                                    {/* Toggle options */}
                                                    <div className="grid gap-4 sm:grid-cols-3">
                                                        <div className="flex items-center justify-between gap-3 rounded-lg border bg-background px-3 py-2.5">
                                                            <Label htmlFor={`${k}-ack`} className="text-xs font-medium cursor-pointer">
                                                                Require acknowledgement
                                                            </Label>
                                                            <Switch
                                                                id={`${k}-ack`}
                                                                checked={!!r.require_ack}
                                                                onCheckedChange={(v) => setRule(k, { require_ack: !!v })}
                                                            />
                                                        </div>
                                                        <div className="flex items-center justify-between gap-3 rounded-lg border bg-background px-3 py-2.5">
                                                            <Label htmlFor={`${k}-ackclose`} className="text-xs font-medium cursor-pointer">
                                                                Must acknowledge before close
                                                            </Label>
                                                            <Switch
                                                                id={`${k}-ackclose`}
                                                                checked={!!r.must_ack_before_close}
                                                                onCheckedChange={(v) => setRule(k, { must_ack_before_close: !!v })}
                                                                disabled={!r.require_ack}
                                                            />
                                                        </div>
                                                        <div className="flex items-center justify-between gap-3 rounded-lg border bg-background px-3 py-2.5">
                                                            <Label htmlFor={`${k}-force`} className="text-xs font-medium cursor-pointer">
                                                                Force delivery (bypass preferences)
                                                            </Label>
                                                            <Switch
                                                                id={`${k}-force`}
                                                                checked={!!r.force_delivery}
                                                                onCheckedChange={(v) => setRule(k, { force_delivery: !!v })}
                                                            />
                                                        </div>
                                                    </div>

                                                    {/* Timing inputs */}
                                                    <div className="grid gap-4 sm:grid-cols-3">
                                                        <div className="space-y-1.5">
                                                            <Label className="text-xs">Remind after (minutes)</Label>
                                                            <Input
                                                                type="number"
                                                                min={1}
                                                                value={r.remind_after_minutes}
                                                                onChange={(e) =>
                                                                    setRule(k, {
                                                                        remind_after_minutes: Number(e.target.value || 0),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label className="text-xs">Repeat every (minutes)</Label>
                                                            <Input
                                                                type="number"
                                                                min={1}
                                                                value={r.repeat_every_minutes}
                                                                onChange={(e) =>
                                                                    setRule(k, {
                                                                        repeat_every_minutes: Number(e.target.value || 0),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label className="text-xs">Max reminders (0 = unlimited)</Label>
                                                            <Input
                                                                type="number"
                                                                min={0}
                                                                value={r.max_reminders}
                                                                onChange={(e) =>
                                                                    setRule(k, {
                                                                        max_reminders: Number(e.target.value || 0),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                    </div>

                                                    {/* Escalate to role groups */}
                                                    <div className="space-y-2">
                                                        <Label className="text-xs font-medium">
                                                            Escalate to role groups
                                                        </Label>
                                                        <div className="flex flex-wrap gap-2">
                                                            {Object.entries(availableRoleGroups).map(([gKey, gLabel]) => {
                                                                const isSelected = (r.escalate_to_role_groups || []).includes(gKey);
                                                                return (
                                                                    <button
                                                                        key={gKey}
                                                                        type="button"
                                                                        onClick={() => toggleGroup(k, gKey, !isSelected)}
                                                                        className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                                                                            isSelected
                                                                                ? 'border-violet-300 bg-violet-100 text-violet-800 dark:border-violet-700 dark:bg-violet-900/40 dark:text-violet-300'
                                                                                : 'border-border bg-background text-muted-foreground hover:bg-muted'
                                                                        }`}
                                                                    >
                                                                        {gLabel}
                                                                    </button>
                                                                );
                                                            })}
                                                        </div>
                                                    </div>

                                                    {/* Escalation tiers */}
                                                    <div className="space-y-1.5">
                                                        <Label className="text-xs font-medium">
                                                            Escalation tiers (JSON)
                                                        </Label>
                                                        <div className="text-[11px] text-muted-foreground">
                                                            Each tier: {'{'}from_reminder, role_groups{'}'} - widens recipients as reminders increase
                                                        </div>
                                                        <textarea
                                                            className="min-h-[80px] w-full rounded-md border bg-background p-2 font-mono text-xs focus:border-violet-400 focus:ring-1 focus:ring-violet-400 focus:outline-none"
                                                            value={JSON.stringify(r.tiers || [], null, 2)}
                                                            onChange={(e) => {
                                                                try {
                                                                    const parsed = JSON.parse(e.target.value || '[]');
                                                                    setTierErrors((prev) => ({ ...prev, [k]: '' }));
                                                                    setRule(k, { tiers: Array.isArray(parsed) ? parsed : [] });
                                                                } catch {
                                                                    setTierErrors((prev) => ({ ...prev, [k]: 'Invalid JSON' }));
                                                                }
                                                            }}
                                                        />
                                                        {tierErrors[k] && (
                                                            <div className="text-xs text-destructive">{tierErrors[k]}</div>
                                                        )}
                                                    </div>
                                                </div>
                                            </CollapsibleContent>
                                        </Collapsible>
                                    </CardContent>
                                ) : (
                                    <CardContent className="pt-0">
                                        <div className="rounded-lg bg-muted/50 px-4 py-2 text-xs text-muted-foreground">
                                            Disabled &mdash; enable to configure escalation settings
                                        </div>
                                    </CardContent>
                                )}
                            </Card>
                        );
                    })}

                    {form.errors?.rules && (
                        <InputError message={form.errors.rules as any} />
                    )}

                    {/* Save */}
                    <div className="flex items-center justify-end gap-3">
                        {saveSuccess && (
                            <div className="flex items-center gap-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                <CheckCircle2 className="h-4 w-4" />
                                Escalation rules saved
                            </div>
                        )}
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="bg-violet-600 hover:bg-violet-700"
                        >
                            {form.processing ? 'Saving...' : 'Save Escalation Rules'}
                        </Button>
                    </div>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}
