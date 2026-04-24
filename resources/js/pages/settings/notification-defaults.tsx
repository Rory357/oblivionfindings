import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import {
    Bell,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ClipboardList,
    Clock,
    Mail,
    Monitor,
    Search,
    Shield,
    Smartphone,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ChannelPref = { enabled: boolean; inapp: boolean; email: boolean; push: boolean };
type RoleRow = { id: number; name: string; label: string };

type Props = {
    groups: Record<string, string[]>;
    roles: RoleRow[];
    matrix: Record<number, Record<string, ChannelPref>>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Notification Defaults', href: '/settings/notification-defaults' },
];

const DEFAULT_PREF: ChannelPref = { enabled: true, inapp: true, email: false, push: false };

/** Friendly name map */
const NOTIFICATION_META: Record<string, { name: string }> = {
    'timesheets.created': { name: 'Timesheet Created' },
    'timesheets.updated': { name: 'Timesheet Updated' },
    'timesheets.submitted': { name: 'Timesheet Submitted' },
    'timesheets.approved': { name: 'Timesheet Approved' },
    'timesheets.rejected': { name: 'Timesheet Rejected' },
    'timesheets.returned': { name: 'Timesheet Returned' },
    'incidents.draft_created': { name: 'Incident Draft Created' },
    'incidents.submitted': { name: 'Incident Submitted' },
    'incidents.reviewed': { name: 'Incident Reviewed' },
    'incidents.high_severity_alert': { name: 'High Severity Alert' },
    'breakglass.daily_report': { name: 'Break Glass Daily Report' },
    'incidents.high_unreviewed_reminder': { name: 'High Severity Unreviewed Reminder' },
    'followups.created': { name: 'Follow-up Created' },
    'followups.updated': { name: 'Follow-up Updated' },
    'followups.completed': { name: 'Follow-up Completed' },
    'followups.overdue_reminder': { name: 'Follow-up Overdue Reminder' },
};

const MODULE_CONFIG: Record<string, { label: string; icon: typeof Clock; keys: string[] }> = {
    operations: {
        label: 'Operations',
        icon: ClipboardList,
        keys: [
            'timesheets.created', 'timesheets.updated', 'timesheets.submitted',
            'timesheets.approved', 'timesheets.rejected', 'timesheets.returned',
        ],
    },
    incidents: {
        label: 'Incidents & Safety',
        icon: Shield,
        keys: [
            'incidents.draft_created', 'incidents.submitted', 'incidents.reviewed',
            'incidents.high_severity_alert', 'breakglass.daily_report',
            'incidents.high_unreviewed_reminder',
        ],
    },
    followups: {
        label: 'Follow-ups',
        icon: CheckCircle2,
        keys: [
            'followups.created', 'followups.updated', 'followups.completed',
            'followups.overdue_reminder',
        ],
    },
};

const ROLE_COLORS: string[] = [
    'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
    'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
];

function humanize(key: string): string {
    return NOTIFICATION_META[key]?.name ?? key
        .replace(/\./g, ' ')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function groupByModule(allKeys: string[]): { moduleKey: string; label: string; icon: typeof Clock; keys: string[] }[] {
    const assigned = new Set<string>();
    const result: { moduleKey: string; label: string; icon: typeof Clock; keys: string[] }[] = [];

    for (const [moduleKey, config] of Object.entries(MODULE_CONFIG)) {
        const matched = config.keys.filter((k) => allKeys.includes(k));
        if (matched.length > 0) {
            result.push({ moduleKey, label: config.label, icon: config.icon, keys: matched });
            matched.forEach((k) => assigned.add(k));
        }
    }

    const remaining = allKeys.filter((k) => !assigned.has(k));
    if (remaining.length > 0) {
        result.push({ moduleKey: 'other', label: 'Other', icon: Bell, keys: remaining });
    }

    return result;
}

/** Channel dot indicator: green if on, grey if off */
function ChannelDot({ active, channel }: { active: boolean; channel: 'inapp' | 'email' | 'push' }) {
    const colors = active
        ? channel === 'inapp'
            ? 'bg-status-success'
            : channel === 'email'
                ? 'bg-status-info'
                : 'bg-status-warning'
        : 'bg-muted-foreground/30';
    return <div className={`h-2 w-2 rounded-full ${colors}`} />;
}

export default function NotificationDefaults({ groups, roles, matrix }: Props) {
    const allKeys = Object.values(groups).flat();
    const { data, setData, put, processing } = useForm({
        matrix: matrix,
    });

    const [search, setSearch] = useState('');
    const [openModules, setOpenModules] = useState<Record<string, boolean>>(() => {
        const initial: Record<string, boolean> = {};
        Object.keys(MODULE_CONFIG).forEach((k) => (initial[k] = true));
        initial['other'] = true;
        return initial;
    });

    const modules = useMemo(() => groupByModule(allKeys), [allKeys]);

    const filteredModules = useMemo(() => {
        const q = search.toLowerCase().trim();
        if (!q) return modules;
        return modules
            .map((mod) => ({
                ...mod,
                keys: mod.keys.filter(
                    (k) =>
                        k.toLowerCase().includes(q) ||
                        humanize(k).toLowerCase().includes(q) ||
                        mod.label.toLowerCase().includes(q),
                ),
            }))
            .filter((mod) => mod.keys.length > 0);
    }, [modules, search]);

    const matchCount = filteredModules.reduce((sum, m) => sum + m.keys.length, 0);

    const getCellPref = (roleId: number, key: string): ChannelPref => {
        return (data.matrix as any)?.[roleId]?.[key] ?? DEFAULT_PREF;
    };

    const setCellPref = (roleId: number, key: string, field: keyof ChannelPref, value: boolean) => {
        const next: any = { ...(data.matrix as any) };
        next[roleId] = { ...(next[roleId] || {}) };
        next[roleId][key] = {
            ...(next[roleId][key] ?? DEFAULT_PREF),
            [field]: value,
        };
        setData('matrix', next);
    };

    const setCellAllChannels = (roleId: number, key: string, value: boolean) => {
        const next: any = { ...(data.matrix as any) };
        next[roleId] = { ...(next[roleId] || {}) };
        next[roleId][key] = {
            enabled: value,
            inapp: value,
            email: value,
            push: value,
        };
        setData('matrix', next);
    };

    const enableAllForRole = (roleId: number) => {
        const next: any = { ...data.matrix };
        next[roleId] = { ...(next[roleId] || {}) };
        allKeys.forEach((k) => {
            next[roleId][k] = { enabled: true, inapp: true, email: true, push: true };
        });
        setData('matrix', next);
    };

    const disableAllForRole = (roleId: number) => {
        const next: any = { ...data.matrix };
        next[roleId] = { ...(next[roleId] || {}) };
        allKeys.forEach((k) => {
            next[roleId][k] = { enabled: false, inapp: false, email: false, push: false };
        });
        setData('matrix', next);
    };

    const collapseAll = () => {
        const next: Record<string, boolean> = {};
        modules.forEach((m) => (next[m.moduleKey] = false));
        setOpenModules(next);
    };

    const expandAll = () => {
        const next: Record<string, boolean> = {};
        modules.forEach((m) => (next[m.moduleKey] = true));
        setOpenModules(next);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Defaults" />
            <SettingsLayout>
                <div className="space-y-6">
                    {/* Header */}
                    <HeadingSmall
                        title="Role Notification Defaults"
                        description="Set default notification preferences and channels for each role. Users can still override these in their own settings."
                    />

                    {/* Quick Actions */}
                    <Card>
                        <CardContent className="flex flex-wrap items-center gap-2 pt-6">
                            <Button type="button" variant="outline" size="sm" onClick={() => {
                                const next: any = { ...data.matrix };
                                roles.forEach((r) => {
                                    next[r.id] = next[r.id] || {};
                                    allKeys.forEach((k) => {
                                        next[r.id][k] = { enabled: true, inapp: true, email: true, push: true };
                                    });
                                });
                                setData('matrix', next);
                            }}>
                                Enable All
                            </Button>
                            <Button type="button" variant="outline" size="sm" onClick={() => {
                                const next: any = { ...data.matrix };
                                roles.forEach((r) => {
                                    next[r.id] = next[r.id] || {};
                                    allKeys.forEach((k) => {
                                        next[r.id][k] = { enabled: false, inapp: false, email: false, push: false };
                                    });
                                });
                                setData('matrix', next);
                            }}>
                                Disable All
                            </Button>
                            <div className="mx-2 h-6 w-px bg-border" />
                            <Button type="button" variant="ghost" size="sm" onClick={expandAll}>
                                Expand All
                            </Button>
                            <Button type="button" variant="ghost" size="sm" onClick={collapseAll}>
                                Collapse All
                            </Button>
                        </CardContent>
                    </Card>

                    {/* Legend */}
                    <div className="flex items-center gap-4 text-xs text-muted-foreground">
                        <span className="font-medium">Channel dots:</span>
                        <div className="flex items-center gap-1.5">
                            <div className="h-2 w-2 rounded-full bg-status-success" />
                            <span>In-App</span>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <div className="h-2 w-2 rounded-full bg-status-info" />
                            <span>Email</span>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <div className="h-2 w-2 rounded-full bg-status-warning" />
                            <span>Push</span>
                        </div>
                        <span className="text-muted-foreground/50">Click a cell to configure channels</span>
                    </div>

                    {/* Search */}
                    <div>
                        <div className="flex items-center gap-2 rounded-md border px-3">
                            <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
                            <Input
                                placeholder="Search notification events..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="border-0 px-0 shadow-none focus-visible:ring-0"
                            />
                        </div>
                        {search && (
                            <div className="mt-1 text-xs text-muted-foreground">
                                Showing {matchCount} of {allKeys.length} events
                            </div>
                        )}
                    </div>

                    {filteredModules.length === 0 && search && (
                        <div className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                            No notification events match &ldquo;{search}&rdquo;
                        </div>
                    )}

                    {/* Module Groups */}
                    {filteredModules.map((mod) => {
                        const Icon = mod.icon;
                        const isOpen = openModules[mod.moduleKey] ?? true;

                        return (
                            <Collapsible
                                key={mod.moduleKey}
                                open={isOpen}
                                onOpenChange={(open) =>
                                    setOpenModules((prev) => ({ ...prev, [mod.moduleKey]: open }))
                                }
                            >
                                <Card>
                                    <CollapsibleTrigger asChild>
                                        <CardHeader className="cursor-pointer select-none">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/30">
                                                        <Icon className="h-4 w-4 text-primary dark:text-primary" />
                                                    </div>
                                                    <div>
                                                        <CardTitle className="text-base">{mod.label}</CardTitle>
                                                        <CardDescription className="text-xs">
                                                            {mod.keys.length} {mod.keys.length === 1 ? 'event' : 'events'}
                                                        </CardDescription>
                                                    </div>
                                                </div>
                                                {isOpen ? (
                                                    <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                                ) : (
                                                    <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                                )}
                                            </div>
                                        </CardHeader>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <CardContent className="space-y-4 pt-0">
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-sm">
                                                    <thead>
                                                        <tr className="border-b">
                                                            <th className="py-2 pr-4 text-left font-medium text-muted-foreground">
                                                                Event
                                                            </th>
                                                            {roles.map((role, idx) => (
                                                                <th key={role.id} className="px-2 py-2 text-center">
                                                                    <div className="flex flex-col items-center gap-1">
                                                                        <Badge
                                                                            variant="secondary"
                                                                            className={`text-xs ${ROLE_COLORS[idx % ROLE_COLORS.length]}`}
                                                                        >
                                                                            {role.label}
                                                                        </Badge>
                                                                        <div className="flex gap-0.5">
                                                                            <button
                                                                                type="button"
                                                                                onClick={(e) => { e.stopPropagation(); enableAllForRole(role.id); }}
                                                                                className="text-[10px] text-primary hover:underline dark:text-primary"
                                                                            >
                                                                                All
                                                                            </button>
                                                                            <span className="text-[10px] text-muted-foreground">/</span>
                                                                            <button
                                                                                type="button"
                                                                                onClick={(e) => { e.stopPropagation(); disableAllForRole(role.id); }}
                                                                                className="text-[10px] text-muted-foreground hover:underline"
                                                                            >
                                                                                None
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </th>
                                                            ))}
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {mod.keys.map((key) => (
                                                            <tr key={key} className="border-b last:border-0 transition-colors hover:bg-muted/50">
                                                                <td className="py-3 pr-4">
                                                                    <div className="text-sm font-medium">{humanize(key)}</div>
                                                                    <div className="text-[11px] text-muted-foreground">{key}</div>
                                                                </td>
                                                                {roles.map((role) => {
                                                                    const pref = getCellPref(role.id, key);

                                                                    return (
                                                                        <td key={role.id} className="px-2 py-3 text-center">
                                                                            <Popover>
                                                                                <PopoverTrigger asChild>
                                                                                    <button
                                                                                        type="button"
                                                                                        className="mx-auto flex items-center gap-1 rounded-md border px-2 py-1.5 transition-colors hover:bg-muted/80"
                                                                                    >
                                                                                        <ChannelDot active={pref.enabled && pref.inapp} channel="inapp" />
                                                                                        <ChannelDot active={pref.enabled && pref.email} channel="email" />
                                                                                        <ChannelDot active={pref.enabled && pref.push} channel="push" />
                                                                                    </button>
                                                                                </PopoverTrigger>
                                                                                <PopoverContent className="w-56 p-3" align="center">
                                                                                    <div className="space-y-3">
                                                                                        <div className="flex items-center justify-between">
                                                                                            <span className="text-xs font-semibold">
                                                                                                {humanize(key)}
                                                                                            </span>
                                                                                            <Switch
                                                                                                checked={pref.enabled}
                                                                                                onCheckedChange={(v) =>
                                                                                                    setCellPref(role.id, key, 'enabled', Boolean(v))
                                                                                                }
                                                                                            />
                                                                                        </div>
                                                                                        <div className="space-y-2">
                                                                                            <label className="flex items-center gap-2 text-xs">
                                                                                                <Checkbox
                                                                                                    checked={pref.inapp}
                                                                                                    disabled={!pref.enabled}
                                                                                                    onCheckedChange={(v) =>
                                                                                                        setCellPref(role.id, key, 'inapp', Boolean(v))
                                                                                                    }
                                                                                                />
                                                                                                <Monitor className="h-3 w-3" />
                                                                                                In-App
                                                                                            </label>
                                                                                            <label className="flex items-center gap-2 text-xs">
                                                                                                <Checkbox
                                                                                                    checked={pref.email}
                                                                                                    disabled={!pref.enabled}
                                                                                                    onCheckedChange={(v) =>
                                                                                                        setCellPref(role.id, key, 'email', Boolean(v))
                                                                                                    }
                                                                                                />
                                                                                                <Mail className="h-3 w-3" />
                                                                                                Email
                                                                                            </label>
                                                                                            <label className="flex items-center gap-2 text-xs opacity-60">
                                                                                                <Checkbox
                                                                                                    checked={pref.push}
                                                                                                    disabled={!pref.enabled}
                                                                                                    onCheckedChange={(v) =>
                                                                                                        setCellPref(role.id, key, 'push', Boolean(v))
                                                                                                    }
                                                                                                />
                                                                                                <Smartphone className="h-3 w-3" />
                                                                                                Push
                                                                                            </label>
                                                                                        </div>
                                                                                        <div className="flex gap-1 border-t pt-2">
                                                                                            <Button
                                                                                                type="button"
                                                                                                variant="ghost"
                                                                                                size="sm"
                                                                                                className="h-6 text-[10px]"
                                                                                                onClick={() => setCellAllChannels(role.id, key, true)}
                                                                                            >
                                                                                                All On
                                                                                            </Button>
                                                                                            <Button
                                                                                                type="button"
                                                                                                variant="ghost"
                                                                                                size="sm"
                                                                                                className="h-6 text-[10px]"
                                                                                                onClick={() => setCellAllChannels(role.id, key, false)}
                                                                                            >
                                                                                                All Off
                                                                                            </Button>
                                                                                        </div>
                                                                                    </div>
                                                                                </PopoverContent>
                                                                            </Popover>
                                                                        </td>
                                                                    );
                                                                })}
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </CardContent>
                                    </CollapsibleContent>
                                </Card>
                            </Collapsible>
                        );
                    })}

                    {/* Save */}
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" asChild>
                            <a href="/settings/notifications">Back</a>
                        </Button>
                        <Button
                            disabled={processing}
                            onClick={() => put('/settings/notifications/roles')}
                            className="bg-primary hover:bg-primary"
                        >
                            Save Defaults
                        </Button>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
