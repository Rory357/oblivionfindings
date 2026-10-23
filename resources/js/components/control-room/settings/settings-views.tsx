import { plainLabel, severityBadge } from '@/components/control-room/alert-record/alert-labels';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import {
    OPTION_GROUPS,
    type ConfigOption,
    type MaintenanceWindowData,
    type OptionGroup,
    type SignalOutboxRow,
    type SignalRuleData,
    type SignalSourceOption,
    type TriageQueueData,
} from '@/components/control-room/settings/types';
import { PopupCallout } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Activity,
    CalendarX,
    Inbox,
    ListChecks,
    Pencil,
    Plus,
    Power,
    Radio,
    RadioTower,
    RefreshCw,
    SlidersHorizontal,
    Trash2,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

/** A titled group: icon, title, one line of what it's for, and its own action. */
function SettingsCard({
    icon: Icon,
    title,
    caption,
    count,
    action,
    children,
}: {
    icon: LucideIcon;
    title: string;
    caption: string;
    count?: number;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="min-w-0 gap-0 overflow-hidden py-0">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div className="flex min-w-0 items-start gap-3">
                    <span className="popup-lens popup-lens--sm mt-0.5" aria-hidden="true">
                        <Icon className="size-[15px]" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="text-section-title">{title}</h2>
                        <p className="text-subtle mt-0.5">{caption}</p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    {count !== undefined ? (
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {count}
                        </StatusBadge>
                    ) : null}
                    {action}
                </div>
            </div>
            {children}
        </Card>
    );
}

/** On/off in a list: the switch with its word. */
function OnOff({
    label,
    checked,
    onChange,
    disabled,
}: {
    label: string;
    checked: boolean;
    onChange: (on: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <span className="inline-flex items-center gap-2">
            <Switch
                aria-label={label}
                checked={checked}
                onCheckedChange={onChange}
                disabled={disabled}
            />
            <span className="text-sm text-muted-foreground" aria-hidden="true">
                {checked ? 'On' : 'Off'}
            </span>
        </span>
    );
}

function RowMenu({
    label,
    items,
    children,
}: {
    label: string;
    items: ControlRoomRowAction[];
    children: (row: {
        onContextMenu: (event: React.MouseEvent<HTMLElement>) => void;
        menu: ReactNode;
    }) => ReactNode;
}) {
    return (
        <ControlRoomRowActions label={label} items={items}>
            {({ rowProps, overflowButton }) =>
                children({ onContextMenu: rowProps.onContextMenu, menu: overflowButton })
            }
        </ControlRoomRowActions>
    );
}

/* ------------------------------------------------------------------ */
/*  Routing                                                            */
/* ------------------------------------------------------------------ */

export function RoutingView({
    rules,
    onEdit,
    onDelete,
    onTurnOff,
}: {
    rules: SignalRuleData[];
    onEdit: (rule: SignalRuleData) => void;
    onDelete: (rule: SignalRuleData) => void;
    onTurnOff: (rule: SignalRuleData) => void;
}) {
    const turnOn = (rule: SignalRuleData) =>
        router.put(
            `/control-room/settings/rules/${rule.id}`,
            { ...rule, conditions: (rule.conditions ?? null) as never, is_active: true },
            { preserveScroll: true },
        );
    return (
        <SettingsCard
            icon={RadioTower}
            title="Routing rules"
            caption="How each kind of signal becomes an alert: its severity, level and procedure."
            count={rules.length}
        >
            {rules.length === 0 ? (
                <div className="px-5 py-10">
                    <EmptyState
                        icon={RadioTower}
                        title="No routing rules yet"
                        description="Without a rule, signals use their own severity and the first matching queue."
                    />
                </div>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-5">Rule</TableHead>
                            <TableHead>Alert severity</TableHead>
                            <TableHead>Procedure</TableHead>
                            <TableHead>Repeat window</TableHead>
                            <TableHead className="text-right">Priority</TableHead>
                            <TableHead>On</TableHead>
                            <TableHead className="w-12 pr-3">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rules.map((rule) => {
                            const severity = rule.output_severity
                                ? severityBadge(rule.output_severity)
                                : null;
                            return (
                                <RowMenu
                                    key={rule.id}
                                    label={`Actions for ${rule.name}`}
                                    items={[
                                        { key: 'edit', label: 'Edit rule', icon: Pencil, onSelect: () => onEdit(rule) },
                                        rule.is_active
                                            ? { key: 'off', label: 'Turn off', icon: Power, onSelect: () => onTurnOff(rule) }
                                            : { key: 'on', label: 'Turn on', icon: Power, onSelect: () => turnOn(rule) },
                                        { key: 'delete', label: 'Delete rule', icon: Trash2, tone: 'critical', onSelect: () => onDelete(rule) },
                                    ]}
                                >
                                    {({ onContextMenu, menu }) => (
                                        <TableRow
                                            onContextMenu={onContextMenu}
                                            onClick={() => onEdit(rule)}
                                            className="cursor-pointer"
                                        >
                                            <TableCell className="pl-5">
                                                <p className="font-semibold text-foreground">{rule.name}</p>
                                                <p className="text-caption">
                                                    {rule.signal_type_name ?? 'Any signal type'} ·{' '}
                                                    {rule.signal_source_name ?? 'any source'}
                                                </p>
                                            </TableCell>
                                            <TableCell>
                                                {severity ? (
                                                    <StatusBadge variant={severity.tone} size="sm">
                                                        {severity.label}
                                                    </StatusBadge>
                                                ) : (
                                                    <span className="text-subtle">Keeps the signal&apos;s</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-subtle">
                                                {rule.playbook_name ?? 'None'}
                                            </TableCell>
                                            <TableCell className="text-subtle tabular-nums">
                                                {rule.deduplicate
                                                    ? `${rule.dedup_window_minutes ?? 30} min`
                                                    : 'Off'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">{rule.priority}</TableCell>
                                            <TableCell onClick={(e) => e.stopPropagation()}>
                                                <OnOff
                                                    label={`Routing rule ${rule.name} on`}
                                                    checked={rule.is_active}
                                                    onChange={(on) => (on ? turnOn(rule) : onTurnOff(rule))}
                                                />
                                            </TableCell>
                                            <TableCell className="pr-3" onClick={(e) => e.stopPropagation()}>
                                                {menu}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </RowMenu>
                            );
                        })}
                    </TableBody>
                </Table>
            )}
        </SettingsCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Queues                                                             */
/* ------------------------------------------------------------------ */

function anyOf(values: string[], all: string) {
    return values.length ? values.map(plainLabel).join(', ') : all;
}

export function QueuesView({
    queues,
    onEdit,
    onTurnOff,
}: {
    queues: TriageQueueData[];
    onEdit: (queue: TriageQueueData) => void;
    onTurnOff: (queue: TriageQueueData) => void;
}) {
    const turnOn = (queue: TriageQueueData) =>
        router.put(
            `/control-room/settings/queues/${queue.id}`,
            { ...queue, is_active: true },
            { preserveScroll: true },
        );
    return (
        <SettingsCard
            icon={Inbox}
            title="Queues"
            caption="Where new alerts wait for a team, and where they move on to if no one picks them up."
            count={queues.length}
        >
            {queues.length === 0 ? (
                <div className="px-5 py-10">
                    <EmptyState icon={Inbox} title="No queues yet" description="Add a queue to give new alerts to a team." />
                </div>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-5">Queue</TableHead>
                            <TableHead>Level</TableHead>
                            <TableHead>Takes</TableHead>
                            <TableHead>Moves on</TableHead>
                            <TableHead className="text-right">Open now</TableHead>
                            <TableHead>On</TableHead>
                            <TableHead className="w-12 pr-3">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {queues.map((queue) => (
                            <RowMenu
                                key={queue.id}
                                label={`Actions for ${queue.name}`}
                                items={[
                                    { key: 'edit', label: 'Edit queue', icon: Pencil, onSelect: () => onEdit(queue) },
                                    queue.is_active
                                        ? { key: 'off', label: 'Turn off', icon: Power, onSelect: () => onTurnOff(queue) }
                                        : { key: 'on', label: 'Turn on', icon: Power, onSelect: () => turnOn(queue) },
                                ]}
                            >
                                {({ onContextMenu, menu }) => (
                                    <TableRow onContextMenu={onContextMenu} onClick={() => onEdit(queue)} className="cursor-pointer">
                                        <TableCell className="pl-5">
                                            <p className="font-semibold text-foreground">{queue.name}</p>
                                            <p className="text-caption">{queue.code}</p>
                                        </TableCell>
                                        <TableCell className="tabular-nums">{queue.tier}</TableCell>
                                        <TableCell className="text-subtle">
                                            {anyOf(queue.handle_severities, 'All severities')}
                                            <span className="block text-caption">
                                                {anyOf(queue.handle_sources, 'All sources')}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-subtle">
                                            {queue.auto_escalate_after_minutes
                                                ? `After ${queue.auto_escalate_after_minutes} min${queue.escalate_to_queue_name ? ` to ${queue.escalate_to_queue_name}` : ''}`
                                                : 'Stays here'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {queue.open_alert_count > 0 ? (
                                                <StatusBadge variant="warning" size="sm" className="tabular-nums">
                                                    {queue.open_alert_count}
                                                </StatusBadge>
                                            ) : (
                                                <span className="text-subtle tabular-nums">0</span>
                                            )}
                                        </TableCell>
                                        <TableCell onClick={(e) => e.stopPropagation()}>
                                            <OnOff
                                                label={`Queue ${queue.name} on`}
                                                checked={queue.is_active}
                                                onChange={(on) => (on ? turnOn(queue) : onTurnOff(queue))}
                                            />
                                        </TableCell>
                                        <TableCell className="pr-3" onClick={(e) => e.stopPropagation()}>
                                            {menu}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </RowMenu>
                        ))}
                    </TableBody>
                </Table>
            )}
        </SettingsCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Sources (read only)                                                */
/* ------------------------------------------------------------------ */

const SOURCE_STATUS: Record<SignalSourceOption['status'], { label: string; tone: StatusVariant }> = {
    active: { label: 'Active', tone: 'success' },
    inactive: { label: 'Off', tone: 'neutral' },
    maintenance: { label: 'In maintenance', tone: 'warning' },
};

/** How fresh a source's heartbeat is: under 5 min fine, under 10 late, else silent. */
function heartbeat(at: string | null): { label: string; tone: StatusVariant } {
    if (!at) return { label: 'Never heard from', tone: 'critical' };
    const minutes = (Date.now() - new Date(at).getTime()) / 60000;
    const when = formatRelative(at);
    if (minutes < 5) return { label: `Heard ${when}`, tone: 'success' };
    if (minutes < 10) return { label: `Late — heard ${when}`, tone: 'warning' };
    return { label: `Silent — last heard ${when}`, tone: 'critical' };
}

export function SourcesView({ sources }: { sources: SignalSourceOption[] }) {
    return (
        <SettingsCard
            icon={Radio}
            title="Signal sources"
            caption="The systems that send signals, so you can check each one is still reporting."
            count={sources.length}
        >
            {sources.length === 0 ? (
                <div className="px-5 py-10">
                    <EmptyState icon={Radio} title="No signal sources" description="Connect a source in Security & Devices." />
                </div>
            ) : (
                <ul className="grid gap-px bg-border sm:grid-cols-2 xl:grid-cols-3">
                    {sources.map((source) => {
                        const status = SOURCE_STATUS[source.status] ?? SOURCE_STATUS.inactive;
                        const beat = heartbeat(source.last_heartbeat_at);
                        return (
                            <li key={source.id} className="grid gap-2 bg-card px-5 py-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="font-semibold text-foreground">{source.name}</p>
                                        {source.vendor ? <p className="text-caption">{source.vendor}</p> : null}
                                    </div>
                                    <StatusBadge variant={status.tone} size="sm">
                                        {status.label}
                                    </StatusBadge>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge variant={beat.tone} size="sm">
                                        {beat.label}
                                    </StatusBadge>
                                </div>
                                <p className="text-subtle">
                                    <span className="font-semibold text-foreground tabular-nums">
                                        {source.signal_count_24h.toLocaleString('en-NZ')}
                                    </span>{' '}
                                    signals in 24 hours · last signal {formatRelative(source.last_signal_at, Date.now(), 'never')}
                                </p>
                                {source.capabilities.length ? (
                                    <p className="text-caption">
                                        Sends: {source.capabilities.map(plainLabel).join(', ')}
                                    </p>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            )}
        </SettingsCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Maintenance                                                        */
/* ------------------------------------------------------------------ */

const WINDOW_STATUS: Record<MaintenanceWindowData['status'], { label: string; tone: StatusVariant }> = {
    scheduled: { label: 'Scheduled', tone: 'info' },
    active: { label: 'On now', tone: 'warning' },
    completed: { label: 'Finished', tone: 'neutral' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

export function MaintenanceView({
    windows,
    sites,
    onEdit,
    onCancel,
}: {
    windows: MaintenanceWindowData[];
    sites: Array<{ id: number; name: string }>;
    onEdit: (window: MaintenanceWindowData) => void;
    onCancel: (window: MaintenanceWindowData) => void;
}) {
    const siteName = (id: number | null) => sites.find((s) => s.id === id)?.name ?? null;
    return (
        <SettingsCard
            icon={Wrench}
            title="Maintenance windows"
            caption="Planned work when a source or house is expected to go quiet or false-alarm, so it doesn't raise alerts."
            count={windows.length}
        >
            {windows.length === 0 ? (
                <div className="px-5 py-10">
                    <EmptyState icon={Wrench} title="No maintenance planned" description="Schedule a window before planned work so it doesn't raise alerts." />
                </div>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-5">Window</TableHead>
                            <TableHead>Covers</TableHead>
                            <TableHead>Starts</TableHead>
                            <TableHead>Ends</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Set up by</TableHead>
                            <TableHead className="w-12 pr-3">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {windows.map((mw) => {
                            const status = WINDOW_STATUS[mw.status] ?? WINDOW_STATUS.completed;
                            const live = mw.status === 'scheduled' || mw.status === 'active';
                            const covers = [mw.signal_source_name ?? 'All sources', siteName(mw.site_id) ?? 'all houses'].join(' · ');
                            return (
                                <RowMenu
                                    key={mw.id}
                                    label={`Actions for ${mw.name}`}
                                    items={
                                        live
                                            ? [
                                                  { key: 'edit', label: 'Edit window', icon: Pencil, onSelect: () => onEdit(mw) },
                                                  { key: 'cancel', label: 'Cancel window', icon: CalendarX, tone: 'critical', onSelect: () => onCancel(mw) },
                                              ]
                                            : []
                                    }
                                >
                                    {({ onContextMenu, menu }) => (
                                        <TableRow
                                            onContextMenu={live ? onContextMenu : undefined}
                                            onClick={live ? () => onEdit(mw) : undefined}
                                            className={live ? 'cursor-pointer' : undefined}
                                        >
                                            <TableCell className="pl-5">
                                                <p className="font-semibold text-foreground">{mw.name}</p>
                                                {mw.description ? <p className="text-caption line-clamp-1">{mw.description}</p> : null}
                                            </TableCell>
                                            <TableCell className="text-subtle">{covers}</TableCell>
                                            <TableCell className="tabular-nums">{formatDateTime(mw.starts_at)}</TableCell>
                                            <TableCell className="tabular-nums">{formatDateTime(mw.ends_at)}</TableCell>
                                            <TableCell>
                                                <StatusBadge variant={status.tone} size="sm">
                                                    {status.label}
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell className="text-subtle">{mw.created_by_name ?? 'Not recorded'}</TableCell>
                                            <TableCell className="pr-3" onClick={(e) => e.stopPropagation()}>
                                                {live ? menu : null}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </RowMenu>
                            );
                        })}
                    </TableBody>
                </Table>
            )}
        </SettingsCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Delivery recovery                                                  */
/* ------------------------------------------------------------------ */

const DELIVERY_STATUS: Record<SignalOutboxRow['status'], { label: string; tone: StatusVariant }> = {
    failed: { label: 'Failed', tone: 'warning' },
    dead_letter: { label: 'Gave up after retries', tone: 'critical' },
    unroutable: { label: 'Nowhere to send it', tone: 'critical' },
};

export function DeliveryView({
    rows,
    onRetry,
}: {
    rows: SignalOutboxRow[];
    onRetry: (row: SignalOutboxRow) => void;
}) {
    return (
        <SettingsCard
            icon={Activity}
            title="Signals that didn't arrive"
            caption="Signals that couldn't be turned into alerts. Check what went wrong before trying again."
            count={rows.length}
        >
            {rows.length === 0 ? (
                <div className="px-5 py-10">
                    <EmptyState icon={Activity} title="Every signal got through" description="Failed deliveries show here so you can try them again." />
                </div>
            ) : (
                <>
                    <div className="px-5 pt-4">
                        <PopupCallout tone="warning">
                            Check what went wrong and that the source is reporting before you
                            try again. A delivery tried again can raise its alert late.
                        </PopupCallout>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Signal</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Tries</TableHead>
                                <TableHead>Last try</TableHead>
                                <TableHead>What went wrong</TableHead>
                                <TableHead className="pr-5">
                                    <span className="sr-only">Action</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => {
                                const status = DELIVERY_STATUS[row.status] ?? DELIVERY_STATUS.failed;
                                return (
                                    <TableRow key={row.id}>
                                        <TableCell className="pl-5">
                                            <p className="font-semibold text-foreground">
                                                {row.signal ? plainLabel(row.signal.signal_type) : `Delivery #${row.id}`}
                                            </p>
                                            <p className="text-caption tabular-nums">
                                                {row.signal
                                                    ? `Signal #${row.signal.id} · asset #${row.signal.asset_id}`
                                                    : 'The original signal is missing'}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge variant={status.tone} size="sm">
                                                {status.label}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{row.attempts}</TableCell>
                                        <TableCell title={formatDateTime(row.last_attempt_at)}>
                                            {formatRelative(row.last_attempt_at, Date.now(), 'Not tried yet')}
                                        </TableCell>
                                        <TableCell className="max-w-[18rem]">
                                            <p className="text-subtle truncate" title={row.last_error ?? undefined}>
                                                {row.last_error ?? 'Not recorded'}
                                            </p>
                                        </TableCell>
                                        <TableCell className="pr-5 text-right">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={!row.can_retry}
                                                title={row.can_retry ? undefined : 'Tried less than 5 minutes ago — wait a moment.'}
                                                aria-label={`Try delivery ${row.id} again`}
                                                onClick={() => onRetry(row)}
                                            >
                                                <RefreshCw aria-hidden="true" />
                                                Try again
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </>
            )}
        </SettingsCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Options                                                            */
/* ------------------------------------------------------------------ */

export function OptionsView({
    options,
    onAdd,
    onDelete,
}: {
    options: Record<string, ConfigOption[]>;
    onAdd: (group: OptionGroup, title: string) => void;
    onDelete: (option: ConfigOption, title: string) => void;
}) {
    return (
        <div className="grid min-w-0 gap-5 xl:grid-cols-3">
            {OPTION_GROUPS.map((group) => {
                const items = options?.[group.key] ?? [];
                return (
                    <SettingsCard
                        key={group.key}
                        icon={group.key === 'task_category' ? ListChecks : SlidersHorizontal}
                        title={group.title}
                        caption={group.caption}
                        count={items.length}
                        action={
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                aria-label={`Add to ${group.title.toLowerCase()}`}
                                onClick={() => onAdd(group.key, group.title)}
                            >
                                <Plus aria-hidden="true" />
                                Add
                            </Button>
                        }
                    >
                        {items.length === 0 ? (
                            <p className="text-subtle px-5 py-6">None yet.</p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {items.map((option) => (
                                    <RowMenu
                                        key={option.id}
                                        label={`Actions for ${option.label}`}
                                        items={[
                                            {
                                                key: 'delete',
                                                label: 'Delete option',
                                                icon: Trash2,
                                                tone: 'critical',
                                                onSelect: () => onDelete(option, group.title),
                                            },
                                        ]}
                                    >
                                        {({ onContextMenu, menu }) => (
                                            <li onContextMenu={onContextMenu} className="flex items-center gap-3 px-5 py-2.5">
                                                <span
                                                    aria-hidden="true"
                                                    className="size-3 shrink-0 rounded-full border border-border"
                                                    style={option.color ? { backgroundColor: option.color } : undefined}
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm font-medium break-words text-foreground">
                                                        {option.label}
                                                    </span>
                                                    <span className="text-caption block break-all">
                                                        {option.value}
                                                        {option.description ? ` · ${option.description}` : ''}
                                                    </span>
                                                </span>
                                                <OnOff
                                                    label={`${group.title} option ${option.label} on`}
                                                    checked={option.is_active}
                                                    onChange={(on) =>
                                                        router.put(
                                                            `/control-room/settings/options/${option.id}`,
                                                            { is_active: on },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                />
                                                {menu}
                                            </li>
                                        )}
                                    </RowMenu>
                                ))}
                            </ul>
                        )}
                    </SettingsCard>
                );
            })}
        </div>
    );
}
