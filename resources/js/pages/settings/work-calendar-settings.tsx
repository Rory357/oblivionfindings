import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { formatDateTime } from '@/lib/datetime';
import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, RefreshCw, Users } from 'lucide-react';
import { useState } from 'react';

export interface WorkCalendarProps {
    settings: {
        enabled: boolean;
        provider: string;
        domain: string;
        checked_at: string | null;
        last_synced_at: string | null;
        last_error: string | null;
    };
    providers: { key: string; label: string; configured: boolean }[];
    staff: { id: number; name: string; email: string }[];
}

export default function WorkCalendarSettings({
    settings,
    providers,
    staff,
}: WorkCalendarProps) {
    const form = useForm({
        provider: settings.provider,
        domain: settings.domain,
        enabled: settings.enabled,
    });
    const [staffId, setStaffId] = useState('');
    const [checking, setChecking] = useState(false);
    const selectedProvider = providers.find(
        (provider) => provider.key === form.data.provider,
    );
    const changed =
        form.data.provider !== settings.provider ||
        form.data.domain !== settings.domain;

    return (
        <Card id="staff-work-calendars">
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle className="flex items-center gap-2">
                        <Users className="size-5" /> Staff work calendars
                    </CardTitle>
                    <StatusBadge
                        variant={
                            settings.last_error
                                ? 'warning'
                                : settings.enabled
                                  ? 'success'
                                  : 'neutral'
                        }
                    >
                        {settings.last_error
                            ? 'Needs attention'
                            : settings.enabled
                              ? 'Automatic sync enabled'
                              : 'Not enabled'}
                    </StatusBadge>
                </div>
                <CardDescription>
                    Set up Google Workspace or Microsoft 365 once for the
                    organisation. Each current staff member’s assigned shifts
                    sync to their work calendar every 15 minutes, without
                    individual sign-in.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                <p className="text-sm text-muted-foreground">
                    Includes shift times for the next 90 days and updates from
                    the last 7 days. Changes and cancellations follow the
                    roster. Client names, locations, care notes, meds and leave
                    details stay in Oblivion Findings.
                </p>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put('/settings/calendar-sync/work-calendar', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="work-calendar-provider">
                                Work calendar provider
                            </Label>
                            <select
                                id="work-calendar-provider"
                                className="h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                                value={form.data.provider}
                                onChange={(event) => {
                                    form.setData({
                                        ...form.data,
                                        provider: event.target.value,
                                        enabled: false,
                                    });
                                }}
                            >
                                {providers.map((provider) => (
                                    <option
                                        key={provider.key}
                                        value={provider.key}
                                    >
                                        {provider.label}
                                    </option>
                                ))}
                            </select>
                            <p className="text-caption text-muted-foreground">
                                {selectedProvider?.configured
                                    ? 'Organisation credentials configured'
                                    : 'Organisation credentials need setup'}
                            </p>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="work-calendar-domain">
                                Staff work email domain
                            </Label>
                            <Input
                                id="work-calendar-domain"
                                placeholder="your-organisation.org.nz"
                                value={form.data.domain}
                                onChange={(event) =>
                                    form.setData({
                                        ...form.data,
                                        domain: event.target.value
                                            .trim()
                                            .toLowerCase(),
                                        enabled: false,
                                    })
                                }
                            />
                            <p className="text-caption text-muted-foreground">
                                Uses the work email in each current staff
                                member’s HR profile.
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <Switch
                            id="work-calendar-enabled"
                            checked={form.data.enabled}
                            disabled={
                                !settings.enabled &&
                                (changed ||
                                    !settings.checked_at ||
                                    !selectedProvider?.configured)
                            }
                            onCheckedChange={(enabled) =>
                                form.setData('enabled', enabled)
                            }
                        />
                        <Label htmlFor="work-calendar-enabled">
                            Automatically sync staff shifts
                        </Label>
                    </div>
                    <p className="text-caption text-muted-foreground">
                        Save the provider and domain, check access below, then
                        enable sync. Pausing keeps existing calendar copies.
                    </p>
                    {Object.values(form.errors).map((error) => (
                        <p
                            role="alert"
                            key={error}
                            className="text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    ))}
                    <Button type="submit" disabled={form.processing}>
                        Save staff calendar settings
                    </Button>
                </form>
                <div className="space-y-3 border-t pt-4">
                    <p className="text-sm font-medium">
                        {staff.length} staff work{' '}
                        {staff.length === 1 ? 'calendar' : 'calendars'} eligible
                    </p>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-0 flex-1 space-y-2">
                            <Label htmlFor="work-calendar-check-staff">
                                Check access to a staff work calendar
                            </Label>
                            <select
                                id="work-calendar-check-staff"
                                value={staffId}
                                onChange={(event) =>
                                    setStaffId(event.target.value)
                                }
                                className="h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">Choose a staff member</option>
                                {staff.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name} · {person.email}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button
                            variant="outline"
                            disabled={
                                checking ||
                                changed ||
                                !staffId ||
                                !selectedProvider?.configured
                            }
                            onClick={() => {
                                setChecking(true);
                                router.post(
                                    '/settings/calendar-sync/work-calendar/check',
                                    { user_id: Number(staffId) },
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setChecking(false),
                                    },
                                );
                            }}
                        >
                            <CheckCircle2 className="mr-2 size-4" />
                            {checking ? 'Checking…' : 'Check access'}
                        </Button>
                        {settings.enabled && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/settings/calendar-sync/work-calendar/sync',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RefreshCw className="mr-2 size-4" />
                                Sync staff calendars now
                            </Button>
                        )}
                    </div>
                    <p className="text-caption text-muted-foreground">
                        The check reads access only and adds no events. Write
                        access is confirmed by the first sync. Staff with
                        missing, duplicate or out-of-domain work emails are
                        excluded.
                    </p>
                    <p className="text-caption text-muted-foreground">
                        Last access check:{' '}
                        {formatDateTime(settings.checked_at, 'Not checked')} ·
                        Last successful sync:{' '}
                        {formatDateTime(settings.last_synced_at, 'Not synced')}
                    </p>
                    {settings.last_error && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {settings.last_error}
                        </p>
                    )}
                </div>
                <details className="rounded-lg border p-4 text-sm">
                    <summary className="cursor-pointer font-medium">
                        Organisation setup for your IT administrator
                    </summary>
                    <div className="mt-3 space-y-3 text-muted-foreground">
                        <p>
                            Google Workspace: authorise a service account with
                            domain-wide delegation for the Calendar events
                            scope. Personal Gmail accounts cannot use this
                            organisation setup.
                        </p>
                        <p>
                            Microsoft 365: register an application with calendar
                            read/write application access, scoped to the
                            approved staff mailboxes in Exchange Online.
                        </p>
                        <p>
                            Your IT administrator must install the organisation
                            credentials on the server. Shared site calendar
                            connections below do not grant access to each
                            employee’s calendar.
                        </p>
                        <div className="flex flex-wrap gap-4">
                            <a
                                className="underline"
                                href="https://developers.google.com/identity/protocols/oauth2/service-account#delegatingauthority"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Google Workspace setup guide
                            </a>
                            <a
                                className="underline"
                                href="https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Microsoft 365 access guide
                            </a>
                        </div>
                    </div>
                </details>
            </CardContent>
        </Card>
    );
}
