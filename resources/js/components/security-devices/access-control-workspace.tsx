import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/datetime';
import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    Clock3,
    History,
    KeyRound,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
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
    providerActions: {
        issue: { available: boolean; reason: string };
        revoke: { available: boolean; reason: string };
    };
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
        version: number;
        activeCredentials: number;
        impact: {
            activeCredentials: number;
            requiresExactConfirmation: boolean;
            updateConfirmation: string | null;
            deactivateConfirmation: string | null;
        };
        providerReconciliation: {
            status: string;
            label: string;
            tone: 'positive' | 'warning' | 'danger';
            requiredAt: string | null;
            message: string;
            failureReason: string | null;
            providerConfirmed: boolean;
        };
        deactivatedAt: string | null;
        deactivationReason: string | null;
        revisionHistory: Array<{
            id: number;
            version: number;
            action: string;
            reason: string;
            activeCredentialsAffected: number;
            actor: string;
            occurredAt: string | null;
        }>;
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
        providerLifecycle: {
            state: 'active' | 'revoked' | 'pending' | 'failed';
            label: string;
            tone: 'positive' | 'neutral' | 'warning' | 'danger';
            message: string;
            requestedAt: string | null;
            confirmedAt: string | null;
            failureReason: string | null;
            accessStillConfirmed: boolean;
        };
        scheduleName: string;
        devices: Array<{ id: number; name: string; href: string }>;
        validFrom: string | null;
        validUntil: string | null;
        revokedAt: string | null;
        revocationReason: string | null;
    }>;
    history: Array<{
        id: string;
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
            <p className="text-xs text-muted-foreground">
                This records the schedule in Oblivion Findings. Provider-side
                execution is not claimed and will remain marked for
                reconciliation.
            </p>
        </form>
    );
}

function ScheduleLifecycleActions({
    schedule,
}: {
    schedule: AccessControlWorkspaceData['schedules'][number];
}) {
    const [mode, setMode] = useState<'edit' | 'deactivate' | null>(null);
    const update = useForm<{
        expected_version: number;
        name: string;
        days: string[];
        starts_at: string;
        ends_at: string;
        reason: string;
        confirmed_active_credentials: number | null;
        confirmation_text: string;
    }>({
        expected_version: schedule.version,
        name: schedule.name,
        days: schedule.days,
        starts_at: schedule.startsAt,
        ends_at: schedule.endsAt,
        reason: '',
        confirmed_active_credentials: schedule.impact.requiresExactConfirmation
            ? schedule.impact.activeCredentials
            : null,
        confirmation_text: '',
    });
    const deactivate = useForm<{
        expected_version: number;
        reason: string;
        confirmed_active_credentials: number | null;
        confirmation_text: string;
    }>({
        expected_version: schedule.version,
        reason: '',
        confirmed_active_credentials: schedule.impact.requiresExactConfirmation
            ? schedule.impact.activeCredentials
            : null,
        confirmation_text: '',
    });

    if (!schedule.isActive) return null;

    const impact = (
        <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
            <p className="font-medium">Current impact preview</p>
            <p className="mt-1">
                {schedule.impact.activeCredentials === 0
                    ? 'No active credentials currently use this schedule.'
                    : `${schedule.impact.activeCredentials} active credential${schedule.impact.activeCredentials === 1 ? '' : 's'} currently use this schedule.`}
            </p>
            <p className="mt-1 text-xs">
                The server rechecks this count under lock before saving. A
                changed count requires a fresh review.
            </p>
        </div>
    );

    return (
        <div className="mt-3 space-y-3 border-t pt-3">
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setMode(mode === 'edit' ? null : 'edit')}
                >
                    Edit schedule
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        setMode(mode === 'deactivate' ? null : 'deactivate')
                    }
                >
                    Deactivate
                </Button>
            </div>
            {mode === 'edit' ? (
                <form
                    className="space-y-3 rounded-lg border p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        update.put(
                            `/security-devices/access-control/schedules/${schedule.id}`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setMode(null),
                            },
                        );
                    }}
                >
                    {impact}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor={`schedule-name-${schedule.id}`}>
                                Schedule name
                            </Label>
                            <Input
                                id={`schedule-name-${schedule.id}`}
                                value={update.data.name}
                                onChange={(event) =>
                                    update.setData('name', event.target.value)
                                }
                            />
                            <ErrorText value={update.errors.name} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`schedule-reason-${schedule.id}`}>
                                Reason for change
                            </Label>
                            <Input
                                id={`schedule-reason-${schedule.id}`}
                                value={update.data.reason}
                                onChange={(event) =>
                                    update.setData('reason', event.target.value)
                                }
                                placeholder="Why this access window is changing"
                            />
                            <ErrorText value={update.errors.reason} />
                        </div>
                    </div>
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Allowed days
                        </legend>
                        <div className="flex flex-wrap gap-2">
                            {days.map(([value, label]) => (
                                <label
                                    key={value}
                                    className="flex min-h-10 items-center gap-2 rounded-md border px-3 text-sm"
                                >
                                    <Checkbox
                                        checked={update.data.days.includes(
                                            value,
                                        )}
                                        onCheckedChange={(checked) =>
                                            update.setData(
                                                'days',
                                                checked
                                                    ? [
                                                          ...update.data.days,
                                                          value,
                                                      ]
                                                    : update.data.days.filter(
                                                          (day) =>
                                                              day !== value,
                                                      ),
                                            )
                                        }
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                        <ErrorText value={update.errors.days} />
                    </fieldset>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor={`schedule-start-${schedule.id}`}>
                                Starts
                            </Label>
                            <Input
                                id={`schedule-start-${schedule.id}`}
                                type="time"
                                value={update.data.starts_at}
                                onChange={(event) =>
                                    update.setData(
                                        'starts_at',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={`schedule-end-${schedule.id}`}>
                                Ends
                            </Label>
                            <Input
                                id={`schedule-end-${schedule.id}`}
                                type="time"
                                value={update.data.ends_at}
                                onChange={(event) =>
                                    update.setData(
                                        'ends_at',
                                        event.target.value,
                                    )
                                }
                            />
                            <ErrorText value={update.errors.ends_at} />
                        </div>
                    </div>
                    {schedule.impact.requiresExactConfirmation ? (
                        <div className="space-y-1.5">
                            <Label
                                htmlFor={`schedule-confirm-update-${schedule.id}`}
                            >
                                Type {schedule.impact.updateConfirmation}{' '}
                                exactly
                            </Label>
                            <Input
                                id={`schedule-confirm-update-${schedule.id}`}
                                value={update.data.confirmation_text}
                                onChange={(event) =>
                                    update.setData(
                                        'confirmation_text',
                                        event.target.value,
                                    )
                                }
                                autoComplete="off"
                            />
                            <ErrorText
                                value={update.errors.confirmation_text}
                            />
                        </div>
                    ) : null}
                    <ErrorText value={update.errors.expected_version} />
                    <Button
                        type="submit"
                        disabled={
                            update.processing ||
                            !update.data.reason.trim() ||
                            (schedule.impact.requiresExactConfirmation &&
                                update.data.confirmation_text !==
                                    schedule.impact.updateConfirmation)
                        }
                    >
                        Save new version
                    </Button>
                </form>
            ) : null}
            {mode === 'deactivate' ? (
                <form
                    className="space-y-3 rounded-lg border border-destructive/40 p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        deactivate.post(
                            `/security-devices/access-control/schedules/${schedule.id}/deactivate`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setMode(null),
                            },
                        );
                    }}
                >
                    {impact}
                    <p className="text-sm text-muted-foreground">
                        This stops future use of the schedule in Oblivion
                        Findings. Existing credentials are not falsely presented
                        as revoked; provider reconciliation remains required.
                    </p>
                    <div className="space-y-1.5">
                        <Label htmlFor={`schedule-deactivate-${schedule.id}`}>
                            Deactivation reason
                        </Label>
                        <Input
                            id={`schedule-deactivate-${schedule.id}`}
                            value={deactivate.data.reason}
                            onChange={(event) =>
                                deactivate.setData('reason', event.target.value)
                            }
                        />
                        <ErrorText value={deactivate.errors.reason} />
                    </div>
                    {schedule.impact.requiresExactConfirmation ? (
                        <div className="space-y-1.5">
                            <Label
                                htmlFor={`schedule-confirm-deactivate-${schedule.id}`}
                            >
                                Type {schedule.impact.deactivateConfirmation}{' '}
                                exactly
                            </Label>
                            <Input
                                id={`schedule-confirm-deactivate-${schedule.id}`}
                                value={deactivate.data.confirmation_text}
                                onChange={(event) =>
                                    deactivate.setData(
                                        'confirmation_text',
                                        event.target.value,
                                    )
                                }
                                autoComplete="off"
                            />
                            <ErrorText
                                value={deactivate.errors.confirmation_text}
                            />
                        </div>
                    ) : null}
                    <ErrorText value={deactivate.errors.expected_version} />
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={
                            deactivate.processing ||
                            !deactivate.data.reason.trim() ||
                            (schedule.impact.requiresExactConfirmation &&
                                deactivate.data.confirmation_text !==
                                    schedule.impact.deactivateConfirmation)
                        }
                    >
                        Deactivate schedule
                    </Button>
                </form>
            ) : null}
        </div>
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

    if (!data.providerActions.issue.available) {
        return (
            <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 text-sm text-status-warning">
                <p className="flex items-center gap-2 font-medium">
                    <AlertTriangle className="h-4 w-4" /> Provider issue action
                    unavailable
                </p>
                <p className="mt-2">{data.providerActions.issue.reason}</p>
                <p className="mt-2 text-xs">
                    Provider-synchronised credentials may still appear below,
                    but Oblivion Findings will not create a local row that
                    implies access was granted.
                </p>
            </div>
        );
    }

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
                Request provider issue
            </Button>
            <ErrorText
                value={(form.errors as Record<string, string>).provider_action}
            />
        </form>
    );
}

function CredentialLifecycleBadge({
    lifecycle,
}: {
    lifecycle: AccessControlWorkspaceData['credentials'][number]['providerLifecycle'];
}) {
    const Icon =
        lifecycle.state === 'active'
            ? CheckCircle2
            : lifecycle.state === 'failed'
              ? XCircle
              : lifecycle.state === 'pending'
                ? Clock3
                : ShieldCheck;
    const classes =
        lifecycle.tone === 'positive'
            ? 'border-status-success/30 bg-status-success-bg text-status-success'
            : lifecycle.tone === 'danger'
              ? 'border-destructive/30 bg-destructive/10 text-destructive'
              : lifecycle.tone === 'warning'
                ? 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                : 'border-border bg-muted text-muted-foreground';

    return (
        <Badge variant="outline" className={classes}>
            <Icon className="mr-1 h-3.5 w-3.5" aria-hidden="true" />
            {lifecycle.label}
        </Badge>
    );
}

function ScheduleReconciliationBadge({
    reconciliation,
}: {
    reconciliation: AccessControlWorkspaceData['schedules'][number]['providerReconciliation'];
}) {
    const Icon = reconciliation.providerConfirmed
        ? CheckCircle2
        : reconciliation.tone === 'danger'
          ? XCircle
          : Clock3;
    const classes =
        reconciliation.tone === 'positive'
            ? 'border-status-success/30 bg-status-success-bg text-status-success'
            : reconciliation.tone === 'danger'
              ? 'border-destructive/30 bg-destructive/10 text-destructive'
              : 'border-status-warning/30 bg-status-warning-bg text-status-warning';

    return (
        <Badge variant="outline" className={classes}>
            <Icon className="mr-1 h-3.5 w-3.5" aria-hidden="true" />
            {reconciliation.label}
        </Badge>
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
                    <KeyRound className="h-4 w-4 text-primary" /> Credential
                    lifecycle
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
                                    <CredentialLifecycleBadge
                                        lifecycle={credential.providerLifecycle}
                                    />
                                    {data.canManage &&
                                    credential.providerLifecycle.state ===
                                        'active' &&
                                    data.providerActions.revoke.available ? (
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
                                                Request revocation
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                            <div
                                className="mt-3 rounded-lg border bg-muted/30 p-3 text-sm"
                                role="status"
                            >
                                <p>{credential.providerLifecycle.message}</p>
                                {credential.providerLifecycle.requestedAt ? (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Provider action requested{' '}
                                        {formatDateTime(
                                            credential.providerLifecycle
                                                .requestedAt,
                                        )}
                                    </p>
                                ) : null}
                                {credential.providerLifecycle.confirmedAt ? (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Provider evidence confirmed{' '}
                                        {formatDateTime(
                                            credential.providerLifecycle
                                                .confirmedAt,
                                        )}
                                    </p>
                                ) : null}
                                {credential.providerLifecycle.failureReason ? (
                                    <p className="mt-1 text-xs text-destructive">
                                        {
                                            credential.providerLifecycle
                                                .failureReason
                                        }
                                    </p>
                                ) : null}
                            </div>
                            {data.canManage &&
                            credential.providerLifecycle.state === 'active' &&
                            !data.providerActions.revoke.available ? (
                                <p className="mt-3 flex items-start gap-2 text-xs text-status-warning">
                                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    {data.providerActions.revoke.reason}
                                </p>
                            ) : null}
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
                                {credential.devices.length ? (
                                    credential.devices.map((device) => (
                                        <Link
                                            key={device.id}
                                            href={device.href}
                                            className="frontline-focus min-h-11 rounded-md border px-3 py-2 text-xs hover:text-primary"
                                        >
                                            <ShieldCheck
                                                className="mr-1 inline h-3.5 w-3.5"
                                                aria-hidden="true"
                                            />
                                            {device.name}
                                        </Link>
                                    ))
                                ) : (
                                    <p className="flex items-center gap-2 text-xs text-status-warning">
                                        <AlertTriangle
                                            className="h-3.5 w-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        No current provider-confirmed Site-bound
                                        reader is attached.
                                    </p>
                                )}
                            </div>
                            {credential.revokedAt ? (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    Provider-confirmed revocation{' '}
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
                title="Request provider credential revocation?"
                description="This creates a governed provider request. The credential remains active until fresh provider evidence confirms revocation."
                confirmText="Request revocation"
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
                    [
                        data.summary.activeCredentials,
                        'Provider-confirmed active credentials',
                    ],
                    [
                        data.summary.activeSchedules,
                        'Provider-confirmed schedules',
                    ],
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
                                Provider credential actions
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
                                                {schedule.siteName} • Version{' '}
                                                {schedule.version}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <Badge
                                                variant={
                                                    schedule.isActive
                                                        ? 'outline'
                                                        : 'secondary'
                                                }
                                            >
                                                {schedule.isActive
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </Badge>
                                            <Badge variant="outline">
                                                {schedule.activeCredentials}{' '}
                                                active
                                            </Badge>
                                        </div>
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
                                    <div className="mt-3 rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <ScheduleReconciliationBadge
                                                reconciliation={
                                                    schedule.providerReconciliation
                                                }
                                            />
                                            {schedule.providerReconciliation
                                                .requiredAt ? (
                                                <span>
                                                    State tracked since{' '}
                                                    {formatDateTime(
                                                        schedule
                                                            .providerReconciliation
                                                            .requiredAt,
                                                    )}
                                                </span>
                                            ) : null}
                                        </div>
                                        <p className="mt-2">
                                            {
                                                schedule.providerReconciliation
                                                    .message
                                            }
                                        </p>
                                        {schedule.providerReconciliation
                                            .failureReason ? (
                                            <p className="mt-2 flex items-start gap-2 text-destructive">
                                                <XCircle
                                                    className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                                    aria-hidden="true"
                                                />
                                                {
                                                    schedule
                                                        .providerReconciliation
                                                        .failureReason
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                    {!schedule.isActive &&
                                    schedule.deactivationReason ? (
                                        <p className="mt-3 text-xs text-muted-foreground">
                                            Deactivated
                                            {schedule.deactivatedAt
                                                ? ` ${formatDateTime(schedule.deactivatedAt)}`
                                                : ''}
                                            {' • '}
                                            {schedule.deactivationReason}
                                        </p>
                                    ) : null}
                                    {schedule.revisionHistory.length ? (
                                        <details className="mt-3 rounded-lg border p-3 text-sm">
                                            <summary className="frontline-focus cursor-pointer font-medium">
                                                Version history
                                            </summary>
                                            <ol className="mt-3 space-y-3">
                                                {schedule.revisionHistory.map(
                                                    (revision) => (
                                                        <li
                                                            key={revision.id}
                                                            className="border-l-2 border-primary/30 pl-3"
                                                        >
                                                            <p className="font-medium capitalize">
                                                                Version{' '}
                                                                {
                                                                    revision.version
                                                                }{' '}
                                                                {
                                                                    revision.action
                                                                }
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {
                                                                    revision.reason
                                                                }
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {revision.actor}
                                                                {revision.occurredAt
                                                                    ? ` • ${formatDateTime(revision.occurredAt)}`
                                                                    : ''}
                                                                {' • '}
                                                                {
                                                                    revision.activeCredentialsAffected
                                                                }{' '}
                                                                provider-confirmed
                                                                access
                                                                credential
                                                                {revision.activeCredentialsAffected ===
                                                                1
                                                                    ? ''
                                                                    : 's'}{' '}
                                                                affected
                                                            </p>
                                                        </li>
                                                    ),
                                                )}
                                            </ol>
                                        </details>
                                    ) : null}
                                    {data.canManage ? (
                                        <ScheduleLifecycleActions
                                            schedule={schedule}
                                        />
                                    ) : null}
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
