import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ClipboardList,
    Clock,
    Shield,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Props = {
    groups: Record<string, string[]>;
    userPrefs: Record<string, boolean>;
    roleDefaults: Record<string, boolean>;
    canManageRoleDefaults: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Notifications', href: '/settings/notifications' },
];

/** Map of notification key -> friendly name and description */
const NOTIFICATION_META: Record<string, { name: string; description: string }> = {
    'timesheets.created': { name: 'Timesheet Created', description: 'When a new timesheet is created' },
    'timesheets.updated': { name: 'Timesheet Updated', description: 'When a timesheet is modified' },
    'timesheets.submitted': { name: 'Timesheet Submitted', description: 'When a timesheet is submitted for approval' },
    'timesheets.approved': { name: 'Timesheet Approved', description: 'When a timesheet is approved by a manager' },
    'timesheets.rejected': { name: 'Timesheet Rejected', description: 'When a timesheet is rejected and needs changes' },
    'timesheets.returned': { name: 'Timesheet Returned', description: 'When a timesheet is returned for corrections' },
    'incidents.draft_created': { name: 'Incident Draft Created', description: 'When a new incident report draft is started' },
    'incidents.submitted': { name: 'Incident Submitted', description: 'When an incident report is submitted for review' },
    'incidents.reviewed': { name: 'Incident Reviewed', description: 'When an incident report review is completed' },
    'incidents.high_severity_alert': { name: 'High Severity Alert', description: 'Immediate alert for high-severity incidents' },
    'breakglass.daily_report': { name: 'Break Glass Daily Report', description: 'Daily summary of break glass events' },
    'incidents.high_unreviewed_reminder': { name: 'High Severity Unreviewed Reminder', description: 'Reminder for unreviewed high-severity incidents' },
    'followups.created': { name: 'Follow-up Created', description: 'When a new follow-up task is created' },
    'followups.updated': { name: 'Follow-up Updated', description: 'When a follow-up task is modified' },
    'followups.completed': { name: 'Follow-up Completed', description: 'When a follow-up task is marked complete' },
    'followups.overdue_reminder': { name: 'Follow-up Overdue Reminder', description: 'Reminder for overdue follow-up tasks' },
};

/** Module groupings with icons and display names */
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

function friendlyName(key: string): string {
    return NOTIFICATION_META[key]?.name ?? key
        .replace(/\./g, ' ')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function friendlyDescription(key: string): string {
    return NOTIFICATION_META[key]?.description ?? '';
}

/** Group all notification keys into modules. Keys not in any module go into "Other". */
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

export default function NotificationPreferences({
    groups,
    userPrefs,
    roleDefaults,
    canManageRoleDefaults,
}: Props) {
    const { data, setData, put, processing } = useForm({
        prefs: { ...Object.fromEntries(Object.entries(roleDefaults).map(([k, v]) => [k, v])), ...userPrefs },
    });

    const allKeys = Object.values(groups).flat();
    const modules = useMemo(() => groupByModule(allKeys), [allKeys]);

    const [openModules, setOpenModules] = useState<Record<string, boolean>>(() => {
        const initial: Record<string, boolean> = {};
        modules.forEach((m) => (initial[m.moduleKey] = true));
        return initial;
    });

    const [doNotDisturb, setDoNotDisturb] = useState(false);

    const enabledCount = (keys: string[]) => keys.filter((k) => Boolean((data.prefs as any)[k])).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Preferences" />
            <SettingsLayout>
                <div className="space-y-6">
                    {/* Header */}
                    <div className="flex items-start justify-between gap-4">
                        <HeadingSmall
                            title="Notification Preferences"
                            description="Control which notifications you receive and how"
                        />
                        {canManageRoleDefaults && (
                            <Button variant="outline" size="sm" asChild>
                                <a href="/settings/notifications/roles">Role defaults</a>
                            </Button>
                        )}
                    </div>

                    {/* Do Not Disturb */}
                    <Card>
                        <CardContent className="flex items-center justify-between gap-4 pt-6">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/30">
                                    {doNotDisturb ? (
                                        <BellOff className="h-4 w-4 text-violet-600 dark:text-violet-400" />
                                    ) : (
                                        <Bell className="h-4 w-4 text-violet-600 dark:text-violet-400" />
                                    )}
                                </div>
                                <div>
                                    <div className="text-sm font-medium">Do Not Disturb</div>
                                    <div className="text-xs text-muted-foreground">
                                        Mute all non-critical notifications
                                    </div>
                                </div>
                            </div>
                            <Switch
                                checked={doNotDisturb}
                                onCheckedChange={setDoNotDisturb}
                            />
                        </CardContent>
                    </Card>

                    {/* Quick Actions */}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                const next: Record<string, boolean> = {};
                                allKeys.forEach((k) => (next[k] = true));
                                setData('prefs', next);
                            }}
                        >
                            Enable All
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                const next: Record<string, boolean> = {};
                                allKeys.forEach((k) => (next[k] = false));
                                setData('prefs', next);
                            }}
                        >
                            Disable All
                        </Button>
                    </div>

                    {/* Module Groups */}
                    {modules.map((mod) => {
                        const Icon = mod.icon;
                        const enabled = enabledCount(mod.keys);
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
                                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-900/30">
                                                        <Icon className="h-4 w-4 text-violet-600 dark:text-violet-400" />
                                                    </div>
                                                    <div>
                                                        <CardTitle className="text-base">{mod.label}</CardTitle>
                                                        <CardDescription className="text-xs">
                                                            {enabled} of {mod.keys.length} enabled
                                                        </CardDescription>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge
                                                        variant={enabled === mod.keys.length ? 'default' : 'secondary'}
                                                        className={enabled === mod.keys.length ? 'bg-violet-600' : ''}
                                                    >
                                                        {enabled}/{mod.keys.length}
                                                    </Badge>
                                                    {isOpen ? (
                                                        <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                                    ) : (
                                                        <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                                    )}
                                                </div>
                                            </div>
                                        </CardHeader>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <CardContent className="space-y-1 pt-0">
                                            {mod.keys.map((key) => {
                                                const checked = Boolean((data.prefs as any)[key]);
                                                const roleDefault = roleDefaults[key];
                                                return (
                                                    <div
                                                        key={key}
                                                        className="flex items-center justify-between gap-4 rounded-lg px-3 py-3 transition-colors hover:bg-muted/50"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <div className="text-sm font-medium">
                                                                {friendlyName(key)}
                                                            </div>
                                                            {friendlyDescription(key) && (
                                                                <div className="text-xs text-muted-foreground">
                                                                    {friendlyDescription(key)}
                                                                </div>
                                                            )}
                                                            {roleDefault !== undefined && (
                                                                <div className="mt-0.5 text-[11px] text-muted-foreground/70">
                                                                    (Role default: {roleDefault ? 'enabled' : 'disabled'})
                                                                </div>
                                                            )}
                                                        </div>
                                                        <Switch
                                                            checked={checked}
                                                            onCheckedChange={(v) =>
                                                                setData('prefs', {
                                                                    ...(data.prefs as any),
                                                                    [key]: Boolean(v),
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                );
                                            })}
                                        </CardContent>
                                    </CollapsibleContent>
                                </Card>
                            </Collapsible>
                        );
                    })}

                    {/* Save */}
                    <div className="flex justify-end">
                        <Button
                            disabled={processing}
                            onClick={() => put('/settings/notifications')}
                            className="bg-violet-600 hover:bg-violet-700"
                        >
                            Save Preferences
                        </Button>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
