import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/datetime';
import { Link, router, useForm } from '@inertiajs/react';
import { CalendarClock, History, KeyRound, ShieldCheck } from 'lucide-react';
import { type FormEvent, useState } from 'react';

export type AccessControlWorkspaceData = {
    restricted: boolean;
    canManage: boolean;
    summary: {
        activeCredentials: number;
        activeSchedules: number;
        coveredDoors: number;
    };
    sites: Array<{ id: number; name: string }>;
    deviceOptions: Array<{ id: number; siteId: number; name: string }>;
    holderOptions: Array<{
        id: number;
        type: 'staff' | 'client';
        label: string;
        siteIds: number[];
    }>;
    schedules: Array<{
        id: number;
        siteId: number;
        siteName: string;
        name: string;
        days: string[];
        startsAt: string;
        endsAt: string;
        timezone: string;
        isActive: boolean;
        activeCredentials: number;
    }>;
    credentials: Array<{
        id: number;
        siteId: number;
        siteName: string;
        label: string;
        holderType: string;
        holderLabel: string;
        referenceKey: string;
        status: string;
        scheduleName: string;
        devices: Array<{ id: number; name: string; href: string }>;
        validFrom: string | null;
        validUntil: string | null;
        revokedAt: string | null;
        revocationReason: string | null;
    }>;
    history: Array<{
        id: number;
        action: string;
        actor: string;
        occurredAt: string | null;
    }>;
};

const days = [
    ['monday', 'Mon'],
    ['tuesday', 'Tue'],
    ['wednesday', 'Wed'],
    ['thursday', 'Thu'],
    ['friday', 'Fri'],
    ['saturday', 'Sat'],
    ['sunday', 'Sun'],
] as const;
const selectClass =
    'frontline-focus min-h-10 w-full rounded-md border border-input bg-background px-3 text-sm';

function ErrorText({ value }: { value?: string }) {
    return value ? (
        <p role="alert" className="text-xs text-destructive">
            {value}
        </p>
    ) : null;
}

function ScheduleForm({ data }: { data: AccessControlWorkspaceData }) {
    const form = useForm<{
        site_id: string;
        name: string;
        days: string[];
        starts_at: string;
        ends_at: string;
    }>({
        site_id: data.sites[0]?.id.toString() ?? '',
        name: '',
        days: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        starts_at: '08:00',
        ends_at: '18:00',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/security-devices/access-control/schedules', {
            preserveScroll: true,
            onSuccess: () => form.reset('name'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="access-schedule-site">Site</Label>
                    <select
                        id="access-schedule-site"
                        className={selectClass}
                        value={form.data.site_id}
                        onChange={(event) =>
                            form.setData('site_id', event.target.value)
                        }
                    >
                        {data.sites.map((site) => (
                            <option key={site.id} value={site.id}>
                                {site.name}
                            </option>
                        ))}
                    </select>
                    <ErrorText value={form.errors.site_id} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-schedule-name">Schedule name</Label>
                    <Input
                        id="access-schedule-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        placeholder="Weekday staff access"
                    />
                    <ErrorText value={form.errors.name} />
                </div>
            </div>
            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">Allowed days</legend>
                <div className="flex flex-wrap gap-2">
                    {days.map(([value, label]) => (
                        <label
                            key={value}
                            className="flex min-h-10 items-center gap-2 rounded-md border px-3 text-sm"
                        >
                            <Checkbox
                                checked={form.data.days.includes(value)}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'days',
                                        checked
                                            ? [...form.data.days, value]
                                            : form.data.days.filter(
                                                  (day) => day !== value,
                                              ),
                                    )
                                }
                            />
                            {label}
                        </label>
                    ))}
                </div>
                <ErrorText value={form.errors.days} />
            </fieldset>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="access-schedule-start">Starts</Label>
                    <Input
                        id="access-schedule-start"
                        type="time"
                        value={form.data.starts_at}
                        onChange={(event) =>
                            form.setData('starts_at', event.target.value)
                        }
                    />
                    <ErrorText value={form.errors.starts_at} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-schedule-end">Ends</Label>
                    <Input
                        id="access-schedule-end"
                        type="time"
                        value={form.data.ends_at}
                        onChange={(event) =>
                            form.setData('ends_at', event.target.value)
                        }
                    />
                    <ErrorText value={form.errors.ends_at} />
                </div>
            </div>
            <Button type="submit" disabled={form.processing || !form.data.name}>
                Create schedule
            </Button>
        </form>
    );
}

function CredentialForm({ data }: { data: AccessControlWorkspaceData }) {
    const form = useForm<{
        site_id: string;
        access_schedule_id: string;
        label: string;
        holder_type: 'staff' | 'client';
        holder_id: string;
        reference_key: string;
        device_ids: number[];
        valid_from: string;
        valid_until: string;
    }>({
        site_id: data.sites[0]?.id.toString() ?? '',
        access_schedule_id: '',
        label: '',
        holder_type: 'staff',
        holder_id: '',
        reference_key: '',
        device_ids: [],
        valid_from: '',
        valid_until: '',
    });
    const siteId = Number(form.data.site_id);
    const availableSchedules = data.schedules.filter(
        (item) => item.siteId === siteId && item.isActive,
    );
    const availableHolders = data.holderOptions.filter(
        (item) =>
            item.type === form.data.holder_type &&
            item.siteIds.includes(siteId),
    );
    const availableDevices = data.deviceOptions.filter(
        (item) => item.siteId === siteId,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/security-devices/access-control/credentials', {
            preserveScroll: true,
            onSuccess: () =>
                form.reset(
                    'label',
                    'holder_id',
                    'reference_key',
                    'device_ids',
                    'valid_from',
                    'valid_until',
                ),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="access-credential-site">Site</Label>
                    <select
                        id="access-credential-site"
                        className={selectClass}
                        value={form.data.site_id}
                        onChange={(event) =>
                            form.setData((current) => ({
                                ...current,
                                site_id: event.target.value,
                                access_schedule_id: '',
                                holder_id: '',
                                device_ids: [],
                            }))
                        }
                    >
                        {data.sites.map((site) => (
                            <option key={site.id} value={site.id}>
                                {site.name}
                            </option>
                        ))}
                    </select>
                    <ErrorText value={form.errors.site_id} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-credential-label">
                        Credential label
                    </Label>
                    <Input
                        id="access-credential-label"
                        value={form.data.label}
                        onChange={(event) =>
                            form.setData('label', event.target.value)
                        }
                        placeholder="Reception staff badge"
                    />
                    <ErrorText value={form.errors.label} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-holder-type">Holder type</Label>
                    <select
                        id="access-holder-type"
                        className={selectClass}
                        value={form.data.holder_type}
                        onChange={(event) =>
                            form.setData((current) => ({
                                ...current,
                                holder_type: event.target.value as
                                    | 'staff'
                                    | 'client',
                                holder_id: '',
                            }))
                        }
                    >
                        <option value="staff">Staff member</option>
                        <option value="client">Client</option>
                    </select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-holder">Holder</Label>
                    <select
                        id="access-holder"
                        className={selectClass}
                        value={form.data.holder_id}
                        onChange={(event) =>
                            form.setData('holder_id', event.target.value)
                        }
                    >
                        <option value="">Select holder</option>
                        {availableHolders.map((holder) => (
                            <option
                                key={`${holder.type}-${holder.id}`}
                                value={holder.id}
                            >
                                {holder.label}
                            </option>
                        ))}
                    </select>
                    <ErrorText value={form.errors.holder_id} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-credential-schedule">Schedule</Label>
                    <select
                        id="access-credential-schedule"
                        className={selectClass}
                        value={form.data.access_schedule_id}
                        onChange={(event) =>
                            form.setData(
                                'access_schedule_id',
                                event.target.value,
                            )
                        }
                    >
                        <option value="">Select schedule</option>
                        {availableSchedules.map((schedule) => (
                            <option key={schedule.id} value={schedule.id}>
                                {schedule.name}
                            </option>
                        ))}
                    </select>
                    <ErrorText value={form.errors.access_schedule_id} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-reference">Provider reference</Label>
                    <Input
                        id="access-reference"
                        value={form.data.reference_key}
                        onChange={(event) =>
                            form.setData('reference_key', event.target.value)
                        }
                        placeholder="unifi:credential/abc-123"
                    />
                    <p className="text-xs text-muted-foreground">
                        Alias or fingerprint only. Never enter a card number,
                        PIN, or secret.
                    </p>
                    <ErrorText value={form.errors.reference_key} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-valid-from">
                        Valid from (optional)
                    </Label>
                    <Input
                        id="access-valid-from"
                        type="datetime-local"
                        value={form.data.valid_from}
                        onChange={(event) =>
                            form.setData('valid_from', event.target.value)
                        }
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="access-valid-until">
                        Valid until (optional)
                    </Label>
                    <Input
                        id="access-valid-until"
                        type="datetime-local"
                        value={form.data.valid_until}
                        onChange={(event) =>
                            form.setData('valid_until', event.target.value)
                        }
                    />
                    <ErrorText value={form.errors.valid_until} />
                </div>
            </div>
            <fieldset className="space-y-2">
                <legend className="text-sm font-medium">
                    Authorised doors and readers
                </legend>
                {availableDevices.length ? (
                    <div className="grid gap-2 sm:grid-cols-2">
                        {availableDevices.map((device) => (
                            <label
                                key={device.id}
                                className="flex min-h-10 items-center gap-2 rounded-md border px-3 text-sm"
                            >
                                <Checkbox
                                    checked={form.data.device_ids.includes(
                                        device.id,
                                    )}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'device_ids',
                                            checked
                                                ? [
                                                      ...form.data.device_ids,
                                                      device.id,
                                                  ]
                                                : form.data.device_ids.filter(
                                                      (id) => id !== device.id,
                                                  ),
                                        )
                                    }
                                />
                                {device.name}
                            </label>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No Site-assigned access-control devices are available.
                    </p>
                )}
                <ErrorText value={form.errors.device_ids} />
            </fieldset>
            <Button
                type="submit"
                disabled={
                    form.processing ||
                    !form.data.holder_id ||
                    !form.data.access_schedule_id ||
                    form.data.device_ids.length === 0
                }
            >
                Issue credential
            </Button>
        </form>
    );
}

function Credentials({ data }: { data: AccessControlWorkspaceData }) {
    const [revoke, setRevoke] = useState<
        AccessControlWorkspaceData['credentials'][number] | null
    >(null);
    const [revocationReasons, setRevocationReasons] = useState<
        Record<number, string>
    >({});
    return (
        <Card>
            <CardHeader>
                <h3 className="flex items-center gap-2 font-semibold">
                    <KeyRound className="h-4 w-4 text-primary" /> Issued
                    credentials
                </h3>
            </CardHeader>
            <CardContent className="space-y-3">
                {data.credentials.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No physical access credentials are registered in the
                        authorised Sites.
                    </p>
                ) : (
                    data.credentials.map((credential) => (
                        <article
                            key={credential.id}
                            className="rounded-xl border p-4"
                        >
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p className="font-semibold">
                                        {credential.label}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {credential.holderLabel} •{' '}
                                        {credential.siteName}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge
                                        variant={
                                            credential.status === 'active'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {credential.status}
                                    </Badge>
                                    {data.canManage &&
                                    credential.status === 'active' ? (
                                        <div className="flex flex-col items-end gap-2">
                                            <Label
                                                htmlFor={`revoke-reason-${credential.id}`}
                                                className="sr-only"
                                            >
                                                Revocation reason for{' '}
                                                {credential.label}
                                            </Label>
                                            <Input
                                                id={`revoke-reason-${credential.id}`}
                                                value={
                                                    revocationReasons[
                                                        credential.id
                                                    ] ?? ''
                                                }
                                                onChange={(event) =>
                                                    setRevocationReasons(
                                                        (current) => ({
                                                            ...current,
                                                            [credential.id]:
                                                                event.target
                                                                    .value,
                                                        }),
                                                    )
                                                }
                                                placeholder="Reason required"
                                                className="w-52"
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={
                                                    !(
                                                        revocationReasons[
                                                            credential.id
                                                        ] ?? ''
                                                    ).trim()
                                                }
                                                onClick={() =>
                                                    setRevoke(credential)
                                                }
                                            >
                                                Revoke
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                            <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Schedule
                                    </dt>
                                    <dd>{credential.scheduleName}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Provider reference
                                    </dt>
                                    <dd className="font-mono text-xs">
                                        {credential.referenceKey}
                                    </dd>
                                </div>
                            </dl>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {credential.devices.map((device) => (
                                    <Link
                                        key={device.id}
                                        href={device.href}
                                        className="frontline-focus rounded-md border px-2 py-1 text-xs hover:text-primary"
                                    >
                                        {device.name}
                                    </Link>
                                ))}
                            </div>
                            {credential.revokedAt ? (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    Revoked{' '}
                                    {formatDateTime(credential.revokedAt)}
                                    {credential.revocationReason
                                        ? ` • ${credential.revocationReason}`
                                        : ''}
                                </p>
                            ) : null}
                        </article>
                    ))
                )}
            </CardContent>
            <ConfirmDialog
                open={revoke !== null}
                onClose={() => setRevoke(null)}
                onConfirm={() => {
                    if (revoke) {
                        router.post(
                            `/security-devices/access-control/credentials/${revoke.id}/revoke`,
                            {
                                reason: (
                                    revocationReasons[revoke.id] ?? ''
                                ).trim(),
                            },
                            { preserveScroll: true },
                        );
                        setRevocationReasons((current) => ({
                            ...current,
                            [revoke.id]: '',
                        }));
                    }
                }}
                title="Revoke physical access credential?"
                description="This records an auditable revocation and prevents the credential from remaining active. Provider-side execution may still require the configured integration."
                confirmText="Revoke credential"
            />
        </Card>
    );
}

export function AccessControlWorkspace({
    data,
}: {
    data: AccessControlWorkspaceData;
}) {
    if (data.restricted)
        return (
            <Card>
                <CardContent className="p-5">
                    <p className="font-semibold">
                        Physical access records are restricted
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Your role can view access-control hardware, but not
                        credential, schedule, or audit details.
                    </p>
                </CardContent>
            </Card>
        );

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-3">
                {[
                    [data.summary.activeCredentials, 'Active credentials'],
                    [data.summary.activeSchedules, 'Active schedules'],
                    [data.summary.coveredDoors, 'Covered doors/readers'],
                ].map(([value, label]) => (
                    <Card key={label}>
                        <CardContent className="p-4">
                            <p className="text-2xl font-semibold">{value}</p>
                            <p className="text-sm text-muted-foreground">
                                {label}
                            </p>
                        </CardContent>
                    </Card>
                ))}
            </div>
            {data.canManage ? (
                <div className="grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <h3 className="flex items-center gap-2 font-semibold">
                                <CalendarClock className="h-4 w-4 text-primary" />{' '}
                                Create access schedule
                            </h3>
                        </CardHeader>
                        <CardContent>
                            <ScheduleForm data={data} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <h3 className="flex items-center gap-2 font-semibold">
                                <ShieldCheck className="h-4 w-4 text-primary" />{' '}
                                Issue credential
                            </h3>
                        </CardHeader>
                        <CardContent>
                            <CredentialForm data={data} />
                        </CardContent>
                    </Card>
                </div>
            ) : null}
            <div className="grid gap-4 xl:grid-cols-2">
                <Card>
                    <CardHeader>
                        <h3 className="flex items-center gap-2 font-semibold">
                            <CalendarClock className="h-4 w-4 text-primary" />{' '}
                            Access schedules
                        </h3>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {data.schedules.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No access schedules are registered.
                            </p>
                        ) : (
                            data.schedules.map((schedule) => (
                                <article
                                    key={schedule.id}
                                    className="rounded-xl border p-3"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold">
                                                {schedule.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {schedule.siteName}
                                            </p>
                                        </div>
                                        <Badge variant="outline">
                                            {schedule.activeCredentials} active
                                        </Badge>
                                    </div>
                                    <p className="mt-2 text-sm">
                                        {schedule.days
                                            .map((day) => day.slice(0, 3))
                                            .join(', ')}{' '}
                                        • {schedule.startsAt}–{schedule.endsAt}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {schedule.timezone}
                                    </p>
                                </article>
                            ))
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <h3 className="flex items-center gap-2 font-semibold">
                            <History className="h-4 w-4 text-primary" /> Access
                            history
                        </h3>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {data.history.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No access-control changes are recorded.
                            </p>
                        ) : (
                            data.history.map((entry) => (
                                <div
                                    key={entry.id}
                                    className="border-l-2 border-primary/30 pl-3 text-sm"
                                >
                                    <p className="font-medium">
                                        {entry.action}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {entry.actor}
                                        {entry.occurredAt
                                            ? ` • ${formatDateTime(entry.occurredAt)}`
                                            : ''}
                                    </p>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>
            <Credentials data={data} />
        </div>
    );
}
