import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    ClipboardList,
    Clock,
    Info,
    Mail,
    Monitor,
    Shield,
    ShieldAlert,
    Smartphone,
    Volume2,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ChannelPref = { enabled: boolean; inapp: boolean; email: boolean; push: boolean };

type DeliveryPrefs = {
    dnd_enabled: boolean;
    dnd_until: string | null;
    desktop_notifications_enabled: boolean;
    notification_sounds_enabled: boolean;
    email_digest_frequency: 'instant' | 'daily' | 'weekly' | 'off';
} & Record<string, boolean | string | null>;

type Props = {
    groups: Record<string, string[]>;
    delivery: DeliveryPrefs;
    userPrefs: Record<string, ChannelPref>;
    roleDefaults: Record<string, ChannelPref>;
    canManageRoleDefaults: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Notifications', href: '/settings/notifications' },
];

/** Critical notification keys that cannot be disabled */
const CRITICAL_KEYS = new Set(['incidents.high_severity_alert', 'breakglass.daily_report']);

const DEFAULT_PREF: ChannelPref = { enabled: true, inapp: true, email: false, push: false };

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
const MODULE_CONFIG: Record<string, { label: string; icon: typeof Clock; colour: string; keys: string[] }> = {
    operations: {
        label: 'Operations',
        icon: ClipboardList,
        colour: 'violet',
        keys: [
            'timesheets.created', 'timesheets.updated', 'timesheets.submitted',
            'timesheets.approved', 'timesheets.rejected', 'timesheets.returned',
        ],
    },
    incidents: {
        label: 'Incidents & Safety',
        icon: Shield,
        colour: 'red',
        keys: [
            'incidents.draft_created', 'incidents.submitted', 'incidents.reviewed',
            'incidents.high_severity_alert', 'breakglass.daily_report',
            'incidents.high_unreviewed_reminder',
        ],
    },
    followups: {
        label: 'Follow-ups',
        icon: CheckCircle2,
        colour: 'emerald',
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
function groupByModule(allKeys: string[]): { moduleKey: string; label: string; icon: typeof Clock; colour: string; keys: string[] }[] {
    const assigned = new Set<string>();
    const result: { moduleKey: string; label: string; icon: typeof Clock; colour: string; keys: string[] }[] = [];

    for (const [moduleKey, config] of Object.entries(MODULE_CONFIG)) {
        const matched = config.keys.filter((k) => allKeys.includes(k));
        if (matched.length > 0) {
            result.push({ moduleKey, label: config.label, icon: config.icon, colour: config.colour, keys: matched });
            matched.forEach((k) => assigned.add(k));
        }
    }

    const remaining = allKeys.filter((k) => !assigned.has(k));
    if (remaining.length > 0) {
        result.push({ moduleKey: 'other', label: 'Other', icon: Bell, colour: 'slate', keys: remaining });
    }

    return result;
}

export default function NotificationPreferences({
    groups,
    delivery,
    userPrefs,
    roleDefaults,
    canManageRoleDefaults,
}: Props) {
    // Build initial prefs: merge role defaults with user prefs, with channel data
    const buildInitialPrefs = (): Record<string, ChannelPref> => {
        const allKeys = Object.values(groups).flat();
        const prefs: Record<string, ChannelPref> = {};
        allKeys.forEach((k) => {
            const roleDef = roleDefaults[k];
            const userPref = userPrefs[k];
            prefs[k] = userPref ?? roleDef ?? { ...DEFAULT_PREF };
        });
        return prefs;
    };

    const { data, setData, put, processing } = useForm({
        prefs: buildInitialPrefs(),
    });

    // Local state for delivery prefs so each toggle updates the UI immediately.
    // Persistence happens via router.put on each change (live save).
    const [deliveryState, setDeliveryState] = useState<DeliveryPrefs>({
        dnd_enabled: delivery.dnd_enabled,
        dnd_until: delivery.dnd_until,
        desktop_notifications_enabled: delivery.desktop_notifications_enabled,
        notification_sounds_enabled: delivery.notification_sounds_enabled,
        email_digest_frequency: delivery.email_digest_frequency,
    });

    const saveDelivery = (patch: Partial<DeliveryPrefs>) => {
        const next = { ...deliveryState, ...patch } as DeliveryPrefs;
        setDeliveryState(next);
        router.put('/settings/notifications/delivery', next, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const allKeys = Object.values(groups).flat();
    const modules = useMemo(() => groupByModule(allKeys), [allKeys]);

    const [openModules, setOpenModules] = useState<Record<string, boolean>>(() => {
        const initial: Record<string, boolean> = {};
        modules.forEach((m) => (initial[m.moduleKey] = true));
        return initial;
    });

    const [saveSuccess, setSaveSuccess] = useState(false);

    const enabledCount = (keys: string[]) => keys.filter((k) => data.prefs[k]?.enabled).length;

    const setPref = (key: string, field: keyof ChannelPref, value: boolean) => {
        setData('prefs', {
            ...data.prefs,
            [key]: {
                ...(data.prefs[key] ?? DEFAULT_PREF),
                [field]: value,
            },
        });
    };

    const handleSave = () => {
        put('/settings/notifications', {
            onSuccess: () => {
                setSaveSuccess(true);
                setTimeout(() => setSaveSuccess(false), 3000);
            },
        });
    };

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

                    {/* Description Card */}
                    <Card className="border-status-info/30 bg-status-info-bg/30">
                        <CardContent className="flex items-start gap-3 p-4">
                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-status-info-bg">
                                <Info className="h-4 w-4 text-status-info" />
                            </div>
                            <div>
                                <p className="text-sm font-medium text-foreground">
                                    Configure which notifications you receive
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Notifications marked as critical (incidents, emergencies) cannot be disabled to ensure safety compliance.
                                    Use the toggles below to customise your notification preferences per module. Each notification can be
                                    delivered via In-App, Email, or Push channels independently.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Delivery preferences — DND, desktop, sounds, digest frequency.
                        Each toggle persists immediately (separate endpoint from the
                        per-event matrix below). */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Delivery Preferences</CardTitle>
                            <CardDescription>
                                Global delivery options that apply across every notification
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {/* Do Not Disturb */}
                            <div className="flex items-center justify-between gap-4 rounded-lg border px-4 py-3">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10">
                                        {deliveryState.dnd_enabled ? (
                                            <BellOff className="h-4 w-4 text-primary" />
                                        ) : (
                                            <Bell className="h-4 w-4 text-primary" />
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
                                    checked={deliveryState.dnd_enabled}
                                    onCheckedChange={(v) =>
                                        saveDelivery({ dnd_enabled: Boolean(v) })
                                    }
                                />
                            </div>

                            {/* Desktop notifications */}
                            <div className="flex items-center justify-between gap-4 rounded-lg border px-4 py-3">
                                <div className="flex items-center gap-3">
                                    <Monitor className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <div className="text-sm font-medium">Desktop notifications</div>
                                        <div className="text-xs text-muted-foreground">
                                            Allow browser notifications while the app is open
                                        </div>
                                    </div>
                                </div>
                                <Switch
                                    checked={deliveryState.desktop_notifications_enabled}
                                    onCheckedChange={(v) =>
                                        saveDelivery({
                                            desktop_notifications_enabled: Boolean(v),
                                        })
                                    }
                                />
                            </div>

                            {/* Notification sounds */}
                            <div className="flex items-center justify-between gap-4 rounded-lg border px-4 py-3">
                                <div className="flex items-center gap-3">
                                    <Volume2 className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <div className="text-sm font-medium">Notification sounds</div>
                                        <div className="text-xs text-muted-foreground">
                                            Play sounds for new notifications
                                        </div>
                                    </div>
                                </div>
                                <Switch
                                    checked={deliveryState.notification_sounds_enabled}
                                    onCheckedChange={(v) =>
                                        saveDelivery({
                                            notification_sounds_enabled: Boolean(v),
                                        })
                                    }
                                />
                            </div>

                            {/* Email digest */}
                            <div className="flex items-center justify-between gap-4 rounded-lg border px-4 py-3">
                                <div className="flex items-center gap-3">
                                    <Mail className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <div className="text-sm font-medium">Email digest</div>
                                        <div className="text-xs text-muted-foreground">
                                            Bundle email notifications into a summary
                                        </div>
                                    </div>
                                </div>
                                <select
                                    value={deliveryState.email_digest_frequency}
                                    onChange={(e) =>
                                        saveDelivery({
                                            email_digest_frequency:
                                                e.target.value as DeliveryPrefs['email_digest_frequency'],
                                        })
                                    }
                                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                >
                                    <option value="instant">Instant</option>
                                    <option value="daily">Daily Summary</option>
                                    <option value="weekly">Weekly Summary</option>
                                    <option value="off">Off</option>
                                </select>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Quick Actions */}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                const next: Record<string, ChannelPref> = {};
                                allKeys.forEach((k) => {
                                    next[k] = { enabled: true, inapp: true, email: true, push: true };
                                });
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
                                const next: Record<string, ChannelPref> = {};
                                allKeys.forEach((k) => {
                                    // Critical keys stay enabled
                                    if (CRITICAL_KEYS.has(k)) {
                                        next[k] = { enabled: true, inapp: true, email: false, push: false };
                                    } else {
                                        next[k] = { enabled: false, inapp: true, email: false, push: false };
                                    }
                                });
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
                                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                                                        <Icon className="h-4 w-4 text-primary" />
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
                                                        className={enabled === mod.keys.length ? 'bg-primary' : ''}
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
                                        <CardContent className="pt-0">
                                            {/* Channel header row */}
                                            <div className="mb-2 flex items-center gap-4 border-b pb-2">
                                                <div className="min-w-0 flex-1">
                                                    <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Notification</span>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-4">
                                                    <div className="flex w-16 flex-col items-center gap-0.5">
                                                        <Monitor className="h-3.5 w-3.5 text-muted-foreground" />
                                                        <span className="text-[10px] font-medium text-muted-foreground">In-App</span>
                                                    </div>
                                                    <div className="flex w-16 flex-col items-center gap-0.5">
                                                        <Mail className="h-3.5 w-3.5 text-muted-foreground" />
                                                        <span className="text-[10px] font-medium text-muted-foreground">Email</span>
                                                    </div>
                                                    <div className="flex w-16 flex-col items-center gap-0.5">
                                                        <Smartphone className="h-3.5 w-3.5 text-muted-foreground/60" />
                                                        <span className="text-[10px] font-medium text-muted-foreground/60">Push</span>
                                                    </div>
                                                    <div className="w-14 text-center">
                                                        <span className="text-[10px] font-medium text-muted-foreground">Enable</span>
                                                    </div>
                                                </div>
                                            </div>
                                            {mod.keys.map((key, idx) => {
                                                const pref = data.prefs[key] ?? DEFAULT_PREF;
                                                const isCritical = CRITICAL_KEYS.has(key);
                                                const masterEnabled = isCritical ? true : pref.enabled;
                                                const roleDefault = roleDefaults[key];

                                                return (
                                                    <div
                                                        key={key}
                                                        className={`flex items-center gap-4 rounded-lg px-3 py-3 transition-colors hover:bg-muted/50 ${
                                                            idx < mod.keys.length - 1 ? 'border-b border-border/40' : ''
                                                        }`}
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className={`text-sm font-medium ${!masterEnabled ? 'text-muted-foreground' : ''}`}>
                                                                    {friendlyName(key)}
                                                                </span>
                                                                {isCritical && (
                                                                    <Badge className="bg-status-critical-bg text-status-critical hover:bg-status-critical-bg text-[10px] px-1.5 py-0">
                                                                        <ShieldAlert className="mr-0.5 h-3 w-3" />
                                                                        Critical
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            {friendlyDescription(key) && (
                                                                <div className={`text-xs ${!masterEnabled ? 'text-muted-foreground/50' : 'text-muted-foreground'}`}>
                                                                    {friendlyDescription(key)}
                                                                </div>
                                                            )}
                                                            {roleDefault !== undefined && (
                                                                <div className="mt-0.5 text-[11px] text-muted-foreground/70">
                                                                    (Role default: {roleDefault.enabled ? 'enabled' : 'disabled'})
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="flex shrink-0 items-center gap-4">
                                                            {/* In-App checkbox */}
                                                            <div className="flex w-16 justify-center">
                                                                <Checkbox
                                                                    checked={pref.inapp}
                                                                    disabled={!masterEnabled}
                                                                    onCheckedChange={(v) => setPref(key, 'inapp', Boolean(v))}
                                                                />
                                                            </div>
                                                            {/* Email checkbox */}
                                                            <div className="flex w-16 justify-center">
                                                                <Checkbox
                                                                    checked={pref.email}
                                                                    disabled={!masterEnabled}
                                                                    onCheckedChange={(v) => setPref(key, 'email', Boolean(v))}
                                                                />
                                                            </div>
                                                            {/* Push checkbox (slightly muted) */}
                                                            <div className="flex w-16 justify-center opacity-60">
                                                                <Checkbox
                                                                    checked={pref.push}
                                                                    disabled={!masterEnabled}
                                                                    onCheckedChange={(v) => setPref(key, 'push', Boolean(v))}
                                                                />
                                                            </div>
                                                            {/* Master toggle */}
                                                            <div className="w-14 flex justify-center">
                                                                <Switch
                                                                    checked={masterEnabled}
                                                                    disabled={isCritical}
                                                                    onCheckedChange={(v) => setPref(key, 'enabled', Boolean(v))}
                                                                />
                                                            </div>
                                                        </div>
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
                    <div className="flex items-center justify-end gap-3">
                        {saveSuccess && (
                            <div className="flex items-center gap-1.5 text-sm font-medium text-status-success">
                                <CheckCircle2 className="h-4 w-4" />
                                Preferences saved successfully
                            </div>
                        )}
                        <Button
                            disabled={processing}
                            onClick={handleSave}
                        >
                            {processing ? 'Saving...' : 'Save Preferences'}
                        </Button>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
