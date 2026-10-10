import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Field } from '@/components/wizard/primitives';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime, toDateInput } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Toggle } from '../connected/_forms';
import {
    BoundedTable,
    ConnectedHeader,
    RecordPicker,
    RemotePicker,
    ReviewWizard,
    ServerPages,
    useCommand,
    useWorkspaceView,
} from '../connected/_shared';
type Recipient = {
    user_id: number;
    name: string;
    /** Their HR work email: backups never go to a sign-in email. */
    email: string | null;
    /** Authenticator two-step sign-in, without which they can't get a password (EA-141). */
    can_open: boolean;
    status: string;
};
type Schedule = {
    id: number;
    site_id: number;
    local_time: string;
    timezone: string;
    enabled: boolean;
    version: number;
    retention_days: number;
    recipients: Recipient[];
    /** Who the daily job runs as, and whether they still can (EA-142). */
    runs_as?: string | null;
    runs_as_current?: boolean | null;
    last_run?: {
        nz_date: string;
        state: string;
        code: string | null;
        at: string | null;
    } | null;
};
type Delivery = {
    id: number;
    /** "B12": also in the email subject and file name (EA-140). */
    reference?: string;
    version: number;
    site_id: number;
    nz_date: string;
    state: string;
    attempt_count: number;
    failure_code: string | null;
    created_at: string;
    sent_at: string | null;
    expires_at: string | null;
    can_send: boolean;
    can_retry: boolean;
    can_download: boolean;
    can_reveal: boolean;
};
type Props = {
    sites: { id: number; name: string }[];
    schedules: Schedule[];
    recipient_candidates: {
        id: number;
        name: string;
        email: string;
        site_ids: number[];
    }[];
    deliveries: Delivery[];
    readiness: {
        encryption_ready: boolean;
        send_enabled: boolean;
        reason: string | null;
        email_ready?: boolean;
        email_capture_mode?: 'array' | 'log' | null;
        email_reason?: string | null;
        email_source?: 'saved' | 'server';
    };
    can_view_email_settings?: boolean;
    can_manage: boolean;
    notice: string;
    deliveries_meta?: {
        current_page: number;
        last_page: number;
        total: number;
    };
};
/** EA-142/EA-143: what the daily job is really doing, not just its switch. */
function scheduleStatus(s: Schedule, readiness: Props['readiness']): string {
    if (!s.enabled) return 'Schedule off';
    if (s.runs_as_current === false)
        return (
            'Stopped — ' +
            (s.runs_as ?? 'whoever set it') +
            ' can no longer manage backups. Save the schedule again.'
        );
    if (!readiness.encryption_ready)
        return 'Not running — strong PDF encryption isn’t set up';
    const today = toDateInput(new Date());
    const run = s.last_run && s.last_run.nz_date === today ? s.last_run : null;
    if (run?.state === 'sent') return 'Delivered today';
    if (run?.state === 'failed')
        return 'Today’s backup failed — see Delivery history';
    if (run?.state === 'uncertain')
        return 'Today’s email result is unknown — see Delivery history';
    if (run?.state === 'prepared_not_emailed')
        return 'Prepared today, not emailed — email sending is off';
    if (!readiness.send_enabled)
        return 'Daily — prepared, not emailed (email sending is off)';

    return 'Daily schedule enabled';
}

export default function Backups(props: Props) {
    const [view, setView] = useWorkspaceView('schedules', [
        'schedules',
        'history',
    ]);
    const [q, setQ] = useState('');
    const [schedule, setSchedule] = useState<Schedule | 'new' | null>(null);
    const [recipient, setRecipient] = useState<Schedule | null>(null);
    const [prepare, setPrepare] = useState<Schedule | null>(null);
    const [delivery, setDelivery] = useState<Delivery | null>(null);
    const [action, setAction] = useState<'send' | 'retry' | 'password' | null>(
        null,
    );
    const house = (id: number) =>
        props.sites.find((s) => s.id === id)?.name ?? 'House unavailable';
    const refresh = () => {
        setSchedule(null);
        setRecipient(null);
        setPrepare(null);
        setDelivery(null);
        setAction(null);
        router.reload();
    };
    return (
        <ConnectedHeader
            title="Protected chart backups"
            parent={{ title: 'Reports & audit', href: '/emar/reports' }}
            subline="House schedules, approved recipients and encrypted medication charts"
            view={view}
            tabs={[
                { key: 'schedules', label: 'Schedules and recipients' },
                { key: 'history', label: 'Delivery history' },
            ]}
            onView={setView}
            query={q}
            onQuery={setQ}
            actions={
                <>
                    <PageHeaderGlassButton asChild>
                        <Link href="/emar/reports">Medication reports</Link>
                    </PageHeaderGlassButton>
                    {props.can_manage && (
                        <PageHeaderPrimaryButton
                            onClick={() => setSchedule('new')}
                        >
                            Set house schedule
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={[
                {
                    label: 'House schedules',
                    value: props.schedules.length,
                    caption: 'Configured houses',
                    view: 'schedules',
                },
                {
                    label: 'Enabled',
                    value: props.schedules.filter((s) => s.enabled).length,
                    caption: 'Daily schedule setting',
                    view: 'schedules',
                },
                {
                    label: 'Recipients',
                    value: props.schedules.reduce(
                        (n, s) =>
                            n +
                            s.recipients.filter((r) => r.status === 'approved')
                                .length,
                        0,
                    ),
                    caption: 'Approved recipient records',
                    view: 'schedules',
                },
                {
                    label: 'Need attention',
                    value: props.deliveries.filter((d) =>
                        ['failed', 'uncertain'].includes(d.state),
                    ).length,
                    caption: 'In this history batch',
                    view: 'history',
                },
            ]}
        >
            {(props.readiness.reason || props.readiness.email_reason) && (
                <SettingsNotice>
                    {props.readiness.reason && <p>{props.readiness.reason}</p>}
                    {props.readiness.email_reason && (
                        <p>{props.readiness.email_reason}</p>
                    )}
                </SettingsNotice>
            )}
            <SettingsNotice role="note">
                <p>
                    Email provider and sender are configured in Main Settings →
                    Email. Manage house schedules, approved recipients and
                    backup protection here.
                </p>
                {props.readiness.email_source === 'server' && (
                    <p>
                        No shared email settings are saved; server mail settings
                        apply.
                    </p>
                )}
                {props.can_view_email_settings && (
                    <Button asChild variant="link" size="sm">
                        <Link href="/settings/email">Open email settings</Link>
                    </Button>
                )}
            </SettingsNotice>
            <SettingsNotice>
                {props.notice ||
                    'Passwords are never included in the email. Each recipient must still have current access to the complete chart when it is prepared and sent.'}
            </SettingsNotice>
            {view === 'schedules' ? (
                <BoundedTable
                    rows={props.schedules.filter((s) =>
                        house(s.site_id)
                            .toLowerCase()
                            .includes(q.toLowerCase()),
                    )}
                    identity={(s) => ({
                        name: house(s.site_id),
                        subline: scheduleStatus(s, props.readiness),
                    })}
                    columns={[
                        {
                            key: 'time',
                            label: 'New Zealand time',
                            width: '1fr',
                            cell: (s) => s.local_time + ' · Pacific/Auckland',
                        },
                        {
                            key: 'recipients',
                            label: 'Recipients',
                            width: '2fr',
                            cell: (s) =>
                                s.recipients
                                    .filter((r) => r.status === 'approved')
                                    .map(
                                        (r) =>
                                            r.name +
                                            (r.can_open
                                                ? ''
                                                : ' (can’t open yet)'),
                                    )
                                    .join(', ') || 'None approved',
                        },
                        {
                            key: 'retention',
                            label: 'Backup retention',
                            width: '1fr',
                            cell: (s) => s.retention_days + ' days',
                        },
                        {
                            key: 'actions',
                            label: 'Actions',
                            width: '270px',
                            cell: (s) =>
                                props.can_manage ? (
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setRecipient(s)}
                                        >
                                            Recipients
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={
                                                !props.readiness
                                                    .encryption_ready
                                            }
                                            onClick={() => setPrepare(s)}
                                        >
                                            Prepare
                                        </Button>
                                    </div>
                                ) : null,
                        },
                    ]}
                    open={props.can_manage ? setSchedule : undefined}
                    empty="No house backup schedule is configured."
                />
            ) : (
                <>
                    <BoundedTable
                        rows={props.deliveries.filter((d) =>
                            (house(d.site_id) + ' ' + d.state)
                                .toLowerCase()
                                .includes(q.toLowerCase()),
                        )}
                        identity={(d) => ({
                            name: house(d.site_id),
                            subline:
                                formatDateOnly(d.nz_date) +
                                (d.reference ? ' · ' + d.reference : ''),
                        })}
                        columns={[
                            {
                                key: 'state',
                                label: 'Delivery',
                                width: '1fr',
                                cell: (d) => <StatusBadge status={d.state} />,
                            },
                            {
                                key: 'attempts',
                                label: 'Attempts',
                                width: '100px',
                                cell: (d) => d.attempt_count,
                            },
                            {
                                key: 'sent',
                                label: 'Sent',
                                width: '1fr',
                                cell: (d) =>
                                    d.sent_at
                                        ? formatDateTime(d.sent_at)
                                        : 'Not confirmed',
                            },
                            {
                                key: 'expiry',
                                label: 'Expires',
                                width: '1fr',
                                cell: (d) =>
                                    d.expires_at
                                        ? formatDateTime(d.expires_at)
                                        : '—',
                            },
                        ]}
                        open={setDelivery}
                    />
                    <ServerPages
                        name="deliveries"
                        path="/emar/backups"
                        meta={props.deliveries_meta}
                    />
                </>
            )}
            {schedule && (
                <ScheduleWizard
                    sites={props.sites}
                    schedule={schedule === 'new' ? null : schedule}
                    encryptionReady={props.readiness.encryption_ready}
                    onClose={refresh}
                />
            )}
            {recipient && (
                <RecipientWizard
                    schedule={recipient}
                    candidates={props.recipient_candidates}
                    house={house(recipient.site_id)}
                    onClose={refresh}
                />
            )}
            {prepare && (
                <PrepareWizard
                    schedule={prepare}
                    house={house(prepare.site_id)}
                    onClose={refresh}
                />
            )}
            {delivery && !action && (
                <SettingsModal
                    width={720}
                    title="Protected backup"
                    description={
                        house(delivery.site_id) +
                        ' · ' +
                        formatDateOnly(delivery.nz_date) +
                        (delivery.reference ? ' · ' + delivery.reference : '')
                    }
                    onClose={refresh}
                    footer={
                        <>
                            <Button variant="outline" onClick={refresh}>
                                Close
                            </Button>
                            {delivery.can_download && (
                                <Button asChild variant="outline">
                                    <a
                                        href={
                                            '/emar/backups/deliveries/' +
                                            delivery.id +
                                            '/download'
                                        }
                                    >
                                        Download encrypted PDF
                                    </a>
                                </Button>
                            )}
                            {delivery.can_reveal && (
                                <Button
                                    variant="outline"
                                    onClick={() => setAction('password')}
                                >
                                    Show password securely
                                </Button>
                            )}
                            {delivery.can_send &&
                                props.readiness.email_ready !== false && (
                                    <Button onClick={() => setAction('send')}>
                                        Review email delivery
                                    </Button>
                                )}
                            {delivery.can_retry &&
                                props.readiness.email_ready !== false && (
                                    <Button onClick={() => setAction('retry')}>
                                        Review retry
                                    </Button>
                                )}
                        </>
                    }
                >
                    <ReviewRow
                        label="State"
                        value={<StatusBadge status={delivery.state} />}
                    />
                    <ReviewRow
                        label="Attempts"
                        value={delivery.attempt_count}
                    />
                    <ReviewRow
                        label="Recorded at"
                        value={formatDateTime(delivery.created_at)}
                    />
                    <ReviewRow
                        label="Issue"
                        value={delivery.failure_code?.replaceAll('_', ' ')}
                    />
                    {delivery.state === 'uncertain' && (
                        <SettingsNotice>
                            Delivery could not be confirmed. Check with the
                            recipients and mail service. This backup will not be
                            automatically sent again.
                        </SettingsNotice>
                    )}
                </SettingsModal>
            )}
            {delivery && action === 'password' && (
                <PasswordDialog delivery={delivery} onClose={refresh} />
            )}
            {delivery && (action === 'send' || action === 'retry') && (
                <DeliveryWizard
                    delivery={delivery}
                    recipients={
                        props.schedules
                            .find((s) => s.site_id === delivery.site_id)
                            ?.recipients.filter(
                                (r) => r.status === 'approved',
                            ) ?? []
                    }
                    action={action}
                    house={house(delivery.site_id)}
                    onClose={refresh}
                />
            )}
        </ConnectedHeader>
    );
}
function ScheduleWizard({
    sites,
    schedule: s,
    encryptionReady,
    onClose,
}: {
    sites: Props['sites'];
    schedule: Schedule | null;
    encryptionReady: boolean;
    onClose: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [site, setSite] = useState(s ? String(s.site_id) : '');
    const [time, setTime] = useState(s?.local_time ?? '');
    const [days, setDays] = useState(s?.retention_days ?? 7);
    const [enabled, setEnabled] = useState(s?.enabled ?? false);
    return (
        <ReviewWizard
            title="House backup schedule"
            description="Daily encrypted chart backup · Pacific/Auckland"
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            onSave={async () => {
                if (
                    await command.run(
                        '/emar/backups/sites/' + site + '/schedule',
                        {
                            version: s?.version ?? 0,
                            local_time: time,
                            enabled,
                            retention_days: days,
                        },
                        'put',
                    )
                )
                    setSaved(true);
            }}
            steps={[
                {
                    label: 'House and time',
                    valid: !!site && !!time && days >= 1 && days <= 30,
                    content: (
                        <div className="space-y-4">
                            <RecordPicker
                                label="House"
                                value={site}
                                disabled={!!s}
                                options={sites.map((h) => ({
                                    value: String(h.id),
                                    label: h.name,
                                }))}
                                onChange={setSite}
                            />
                            <Field label="Daily backup time" required>
                                <TimePicker
                                    compact
                                    id="backup-time"
                                    label="Daily backup time"
                                    value={time}
                                    onChange={setTime}
                                />
                            </Field>
                            <Field label="Keep backup PDFs for (days)" required>
                                <Input
                                    type="number"
                                    min="1"
                                    max="30"
                                    value={days}
                                    onChange={(e) =>
                                        setDays(Number(e.target.value))
                                    }
                                />
                            </Field>
                            <Toggle
                                label="Enable daily preparation and delivery"
                                checked={enabled}
                                disabled={!encryptionReady && !enabled}
                                onChange={setEnabled}
                            />
                            {!encryptionReady && (
                                <SettingsNotice>
                                    Strong PDF encryption isn’t set up yet, so
                                    the daily backup can’t be switched on.
                                </SettingsNotice>
                            )}
                            <SettingsNotice>
                                Recipients must be approved separately. Sending
                                also requires the organisation’s encryption and
                                email settings to be ready. During
                                daylight-saving changes, repeated times run once
                                and skipped times run at the next valid time.
                            </SettingsNotice>
                        </div>
                    ),
                },
            ]}
            review={[
                {
                    label: 'House',
                    value: sites.find((h) => String(h.id) === site)?.name,
                },
                { label: 'Daily time', value: time + ' · Pacific/Auckland' },
                { label: 'PDF retention', value: days + ' days' },
                { label: 'Schedule', value: enabled ? 'Enabled' : 'Off' },
            ]}
        />
    );
}
function RecipientWizard({
    schedule: s,
    candidates,
    house,
    onClose,
}: {
    schedule: Schedule;
    candidates: Props['recipient_candidates'];
    house: string;
    onClose: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [user, setUser] = useState('');
    const [approve, setApprove] = useState(true);
    const [chosen, setChosen] = useState<{
        id: number;
        name: string;
        email: string;
    } | null>(null);
    const options = [
        ...candidates
            .filter((c) => c.site_ids.includes(s.site_id))
            .map((c) => ({
                value: String(c.id),
                label: c.name,
                description: c.email,
            })),
        ...s.recipients
            .filter((r) => !candidates.some((c) => c.id === r.user_id))
            .map((r) => ({
                value: String(r.user_id),
                label: r.name,
                description: r.email ?? undefined,
            })),
    ];
    return (
        <ReviewWizard
            title="Approve backup recipient"
            description={house}
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            onSave={async () => {
                if (
                    await command.run(
                        '/emar/backups/schedules/' + s.id + '/recipients',
                        {
                            version: s.version,
                            user_id: Number(user),
                            approved: approve,
                        },
                    )
                )
                    setSaved(true);
            }}
            steps={[
                {
                    label: 'Recipient',
                    valid: !!user,
                    content: (
                        <div className="space-y-4">
                            {approve ? (
                                <RemotePicker<{
                                    id: number;
                                    name: string;
                                    email: string;
                                }>
                                    label="Staff recipient"
                                    url={
                                        '/emar/backups/sites/' +
                                        s.site_id +
                                        '/recipients'
                                    }
                                    value={user}
                                    queryKey="search"
                                    onChange={(id, row) => {
                                        setUser(id);
                                        setChosen(row);
                                    }}
                                    rows={(data) =>
                                        data.recipient_candidates as {
                                            id: number;
                                            name: string;
                                            email: string;
                                        }[]
                                    }
                                    meta={(data) =>
                                        data.meta as {
                                            current_page: number;
                                            last_page: number;
                                            total: number;
                                        }
                                    }
                                    option={(row) => ({
                                        id: String(row.id),
                                        label: row.name,
                                        description: row.email,
                                    })}
                                />
                            ) : (
                                <RecordPicker
                                    label="Existing recipient"
                                    value={user}
                                    options={options}
                                    onChange={setUser}
                                />
                            )}
                            <Toggle
                                label="Approve this recipient"
                                checked={approve}
                                onChange={(v) => {
                                    setApprove(v);
                                    setUser('');
                                    setChosen(null);
                                }}
                            />
                            <SettingsNotice>
                                Only current staff with a work email on their HR
                                profile, authenticator two-step sign-in and
                                complete chart-export authority can receive this
                                house’s backup. It goes to their work email,
                                never their sign-in email. Switching approval
                                off revokes this recipient.
                            </SettingsNotice>
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'House', value: house },
                {
                    label: 'Recipient',
                    value:
                        chosen?.name ??
                        options.find((o) => o.value === user)?.label,
                },
                {
                    label: 'Work email',
                    value:
                        chosen?.email ??
                        options.find((o) => o.value === user)?.description,
                },
                { label: 'Approval', value: approve ? 'Approve' : 'Revoke' },
            ]}
        />
    );
}
function PrepareWizard({
    schedule: s,
    house,
    onClose,
}: {
    schedule: Schedule;
    house: string;
    onClose: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [day, setDay] = useState(toDateInput(new Date()));
    return (
        <ReviewWizard
            title="Prepare protected chart"
            description={house}
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            saveLabel="Prepare encrypted PDF"
            onSave={async () => {
                if (
                    await command.run(
                        '/emar/backups/sites/' + s.site_id + '/prepare',
                        { version: s.version, nz_date: day },
                    )
                )
                    setSaved(true);
            }}
            success="Preparation recorded"
            successDetail="Open delivery history to check the result and review delivery. Preparing a PDF does not send an email."
            steps={[
                {
                    label: 'Chart day',
                    valid: !!day,
                    content: (
                        <DatePicker
                            compact
                            id="backup-day"
                            label="Chart day"
                            value={day}
                            onChange={setDay}
                        />
                    ),
                },
            ]}
            review={[
                { label: 'House', value: house },
                { label: 'Chart day', value: formatDateOnly(day) },
                {
                    label: 'Approved recipients',
                    value:
                        s.recipients
                            .filter((r) => r.status === 'approved')
                            .map((r) => r.name)
                            .join(', ') || 'None',
                },
            ]}
        />
    );
}
function DeliveryWizard({
    delivery: d,
    recipients,
    action,
    house,
    onClose,
}: {
    delivery: Delivery;
    action: 'send' | 'retry';
    recipients: Recipient[];
    house: string;
    onClose: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [checked, setChecked] = useState(false);
    return (
        <ReviewWizard
            title="Email protected backup"
            description={house}
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            saveLabel={
                action === 'retry' ? 'Retry delivery' : 'Send protected backup'
            }
            onSave={async () => {
                if (
                    await command.run(
                        '/emar/backups/deliveries/' + d.id + '/' + action,
                        { version: d.version },
                    )
                )
                    setSaved(true);
            }}
            steps={[
                {
                    label: 'Check delivery',
                    valid: checked && recipients.length > 0,
                    content: (
                        <div className="space-y-4">
                            <SettingsNotice>
                                The current chart, approved recipient list and
                                access are checked again before email. The
                                attachment is encrypted; its password is
                                accessed separately. An uncertain delivery
                                cannot be automatically retried.
                            </SettingsNotice>
                            <ReviewRow label="House" value={house} />
                            <ReviewRow
                                label="Chart date"
                                value={formatDateOnly(d.nz_date)}
                            />
                            <ReviewRow
                                label="Approved recipients"
                                value={
                                    recipients
                                        .map((r) => r.name + ' · ' + r.email)
                                        .join('; ') || 'None approved'
                                }
                            />
                            <Toggle
                                label="I reviewed the house, chart date and approved recipients"
                                checked={checked}
                                onChange={setChecked}
                            />
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'House', value: house },
                { label: 'Chart date', value: formatDateOnly(d.nz_date) },
                { label: 'Current state', value: d.state },
                {
                    label: 'Approved recipients',
                    value: recipients
                        .map((r) => r.name + ' · ' + r.email)
                        .join('; '),
                },
                {
                    label: 'Action',
                    value:
                        action === 'retry'
                            ? 'Retry known failure'
                            : 'Send to approved recipients',
                },
            ]}
        />
    );
}
function PasswordDialog({
    delivery: d,
    onClose,
}: {
    delivery: Delivery;
    onClose: () => void;
}) {
    const command = useCommand();
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [secret, setSecret] = useState('');
    useEffect(() => {
        if (!secret) return;
        const timer = setTimeout(() => setSecret(''), 60000);
        return () => clearTimeout(timer);
    }, [secret]);
    const reveal = async () => {
        const result = await command.run<{ password: string }>(
            '/emar/backups/deliveries/' + d.id + '/password',
            { password, verification_code: code },
        );
        setPassword('');
        setCode('');
        if (result) setSecret(result.password);
    };
    return (
        <SettingsModal
            width={480}
            title="Open backup password"
            description="Confirm your own account and authenticator"
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {!secret && (
                        <Button
                            disabled={
                                !password ||
                                code.length !== 6 ||
                                command.busy ||
                                command.uncertain
                            }
                            onClick={() => void reveal()}
                        >
                            Verify and show
                        </Button>
                    )}
                </>
            }
        >
            {command.error && (
                <SettingsNotice role="alert">{command.error}</SettingsNotice>
            )}
            {secret ? (
                <>
                    <p className="text-caption">
                        This password hides after one minute. Keep it separate
                        from the backup email.
                    </p>
                    <Input
                        aria-label="Backup PDF password"
                        readOnly
                        value={secret}
                        autoComplete="off"
                    />
                </>
            ) : (
                <>
                    <Field label="Your account password" required>
                        <Input
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                    </Field>
                    <Field label="Your authenticator code" required>
                        <Input
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={code}
                            onChange={(e) =>
                                setCode(e.target.value.replace(/\D/g, ''))
                            }
                        />
                    </Field>
                </>
            )}
        </SettingsModal>
    );
}
