import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { applyLiveResponseFilters } from '@/components/control-room/live-response/live-response-filters';
import { longDuration, useNow } from '@/components/control-room/live-response/response-clock';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowUpRight,
    Building2,
    Cpu,
    Filter,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

export type CoverageState = 'reporting' | 'quiet' | 'never' | 'none';

export interface CoverageRow {
    key: string;
    site_id: number | null;
    site_name: string | null;
    /** The kind of source (device type); null on a house with no monitoring. */
    type: string | null;
    type_label: string | null;
    /** The systems these signals come through. */
    sources: string[];
    devices: number;
    reporting: number;
    quiet: number;
    never: number;
    state: CoverageState;
    last_signal_at: string | null;
}

interface Props {
    rows: CoverageRow[];
    stats: {
        houses: number;
        houses_covered: number;
        sources: number;
        reporting: number;
        quiet: number;
        never: number;
        no_monitoring: number;
    };
    filters: { site_id: string; type: string; state: string };
    sites: Array<{ id: number; name: string }>;
    device_types: Record<string, string>;
    quiet_after_hours: number;
    can: { view_canonical_devices: boolean };
    canonicalIndexUrl: string | null;
}

const BASE_PATH = '/control-room/coverage';
const SIGNALS_PATH = '/control-room/devices';

const STATE_BADGE: Record<CoverageState, { label: string; tone: StatusVariant }> = {
    reporting: { label: 'Reporting', tone: 'success' },
    quiet: { label: 'Quiet', tone: 'warning' },
    never: { label: 'Never reported', tone: 'warning' },
    none: { label: 'No monitoring', tone: 'neutral' },
};

/** Device types arrive in Title Case ("Bed Sensor"); the UI is sentence case. */
function sentenceCase(label: string): string {
    return label.charAt(0).toUpperCase() + label.slice(1).toLowerCase();
}

function url(path: string, params: Record<string, string | number | null | undefined>) {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') search.set(key, String(value));
    }
    const value = search.toString();
    return value ? `${path}?${value}` : path;
}

/** "5 min ago", "3 days ago" — an age, never a bare time (24 Oct rule). */
export function reportAge(at: string | null, now: number): string | null {
    if (!at) return null;
    const ms = now - new Date(at).getTime();
    if (!Number.isFinite(ms)) return null;
    if (ms < 60_000) return 'just now';
    return `${longDuration(ms)} ago`;
}

type NextAction = { label: string; href: string | null };

/** What to do about a row, in plain words, linking only to pages that exist. */
export function coverageNextAction(
    row: CoverageRow,
    securityDevicesUrl: string | null,
): NextAction {
    const signals = (activity?: string) =>
        url(SIGNALS_PATH, { site_id: row.site_id, type: row.type, activity });
    switch (row.state) {
        case 'quiet':
            return { label: 'Check the quiet sources', href: signals('quiet') };
        case 'never':
            return { label: 'Check the set-up', href: signals('never') };
        case 'none':
            return securityDevicesUrl && row.site_id
                ? {
                      label: 'Connect a source in Security & Devices',
                      href: url(securityDevicesUrl, { site_id: row.site_id }),
                  }
                : { label: 'Ask a manager to connect a source', href: null };
        default:
            return { label: 'View signals', href: signals() };
    }
}

/**
 * Monitoring › Coverage — which houses are monitored, by which kind of
 * source, and whether each is still reporting, plus the houses with no
 * monitoring at all. Read only: device set-up stays in Security & Devices.
 */
export default function ControlRoomCoverage({
    rows,
    stats,
    filters,
    sites,
    device_types: deviceTypes,
    quiet_after_hours: quietHours,
    canonicalIndexUrl,
}: Props) {
    const now = useNow(60_000);
    const [refreshing, setRefreshing] = useState(false);

    const apply = (next: Partial<Props['filters']>) =>
        applyLiveResponseFilters(BASE_PATH, { ...filters, ...next });
    const fromAll = (value: string) => (value === 'all' ? '' : value);
    const refresh = () => {
        setRefreshing(true);
        router.reload({ only: ['rows', 'stats'], onFinish: () => setRefreshing(false) });
    };

    const site = filters.site_id || undefined;
    const type = filters.type || undefined;
    // Plural for "houses with bed sensors"; every source kind takes an "s".
    const typeLabel = type && deviceTypes[type] ? `${deviceTypes[type].toLowerCase()}s` : null;
    const hoursWords = `${quietHours} ${quietHours === 1 ? 'hour' : 'hours'}`;
    const narrowed = Boolean(filters.site_id || filters.type || filters.state);
    const canPickSite = sites.length > 1 || Boolean(filters.site_id);

    return (
        <ControlRoomPage
            headTitle="Coverage"
            header={{
                subline: `Which houses are monitored, and which sources have gone quiet · Quiet means no signal for ${hoursWords}`,
                actions: (
                    <>
                        {canonicalIndexUrl ? (
                            <PageHeaderGlassButton icon={ShieldCheck} asChild>
                                <Link href={canonicalIndexUrl}>Open Security & Devices</Link>
                            </PageHeaderGlassButton>
                        ) : null}
                        <PageHeaderGlassButton icon={RefreshCw} onClick={refresh} disabled={refreshing}>
                            {refreshing ? 'Refreshing…' : 'Refresh'}
                        </PageHeaderGlassButton>
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Houses covered"
                            value={`${stats.houses_covered}/${stats.houses}`}
                            href={url(BASE_PATH, { site_id: site, type })}
                            ariaLabel="Show every house and source"
                        >
                            <PageHeaderMeterBig>{stats.houses_covered}</PageHeaderMeterBig>
                            <PageHeaderMeterBar
                                percent={
                                    stats.houses
                                        ? Math.round((stats.houses_covered / stats.houses) * 100)
                                        : 0
                                }
                            />
                            <PageHeaderMeterCaption>
                                {stats.houses_covered === 1 ? 'house' : 'houses'} with{' '}
                                {typeLabel ?? 'a source'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Quiet"
                            tone={stats.quiet > 0 ? 'warning' : 'brand'}
                            href={url(BASE_PATH, { site_id: site, type, state: 'quiet' })}
                            ariaLabel="Show sources that have gone quiet"
                        >
                            <PageHeaderMeterBig>{stats.quiet}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>no signal for {hoursWords}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Never reported"
                            tone={stats.never > 0 ? 'warning' : 'brand'}
                            href={url(BASE_PATH, { site_id: site, type, state: 'never' })}
                            ariaLabel="Show sources that have never reported"
                        >
                            <PageHeaderMeterBig>{stats.never}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>no signal recorded yet</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="No monitoring"
                            href={url(BASE_PATH, { site_id: site, type, state: 'none' })}
                            ariaLabel="Show houses with no monitoring"
                        >
                            <PageHeaderMeterBig>{stats.no_monitoring}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {stats.no_monitoring === 1 ? 'house' : 'houses'} with{' '}
                                {typeLabel ? `no ${typeLabel}` : 'no source'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
                filters: (
                    <>
                        {canPickSite ? (
                            <PageHeaderFilterSelect
                                icon={Building2}
                                label="All sites"
                                value={filters.site_id || 'all'}
                                onChange={(value) => apply({ site_id: fromAll(value) })}
                                options={[
                                    { value: 'all', label: 'All sites' },
                                    ...sites.map((s) => ({ value: String(s.id), label: s.name })),
                                ]}
                            />
                        ) : null}
                        <PageHeaderFilterSelect
                            icon={Cpu}
                            label="All sources"
                            value={filters.type || 'all'}
                            onChange={(value) => apply({ type: fromAll(value) })}
                            options={[
                                { value: 'all', label: 'All sources' },
                                ...Object.entries(deviceTypes).map(([value, label]) => ({
                                    value,
                                    label: sentenceCase(label),
                                })),
                            ]}
                        />
                        <PageHeaderFilterSelect
                            icon={Activity}
                            label="All states"
                            value={filters.state || 'all'}
                            onChange={(value) => apply({ state: fromAll(value) })}
                            options={[
                                { value: 'all', label: 'All states' },
                                ...(Object.keys(STATE_BADGE) as CoverageState[]).map((value) => ({
                                    value,
                                    label: STATE_BADGE[value].label,
                                })),
                            ]}
                        />
                    </>
                ),
            }}
        >
            {rows.length === 0 ? (
                <Card className="min-w-0 py-10">
                    <EmptyState
                        icon={narrowed ? Filter : Building2}
                        title={narrowed ? 'Nothing matches these filters' : 'No houses to show'}
                        description={
                            narrowed
                                ? 'Clear a filter, or choose another house, source or state.'
                                : 'Coverage shows the houses you work at. Ask a manager if a house is missing.'
                        }
                    />
                </Card>
            ) : (
                <Card className="min-w-0 gap-0 overflow-hidden py-0">
                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                        <div className="min-w-0">
                            <h2 className="text-section-title">Site coverage</h2>
                            <p className="text-subtle mt-0.5">
                                Each house and kind of source, problems first. A source that reports
                                is not proof that everyone is safe.
                            </p>
                        </div>
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {rows.length} shown
                        </StatusBadge>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">House</TableHead>
                                <TableHead>Source</TableHead>
                                <TableHead>Last report</TableHead>
                                <TableHead>State</TableHead>
                                <TableHead>Next action</TableHead>
                                <TableHead className="w-12 pr-5">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <CoverageTableRow
                                    key={row.key}
                                    row={row}
                                    now={now}
                                    typeLabel={typeLabel}
                                    securityDevicesUrl={canonicalIndexUrl}
                                    onlyThisHouse={
                                        canPickSite && !filters.site_id && row.site_id
                                            ? () => apply({ site_id: String(row.site_id) })
                                            : null
                                    }
                                />
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </ControlRoomPage>
    );
}

function CoverageTableRow({
    row,
    now,
    typeLabel,
    securityDevicesUrl,
    onlyThisHouse,
}: {
    row: CoverageRow;
    now: number;
    typeLabel: string | null;
    securityDevicesUrl: string | null;
    onlyThisHouse: (() => void) | null;
}) {
    const house = row.site_name ?? 'No house recorded';
    const source = row.type_label ? sentenceCase(row.type_label) : null;
    const badge = STATE_BADGE[row.state];
    const next = coverageNextAction(row, securityDevicesUrl);
    const age = reportAge(row.last_signal_at, now);
    const signals = (activity?: string) =>
        url(SIGNALS_PATH, { site_id: row.site_id, type: row.type, activity });

    const items: ControlRoomRowAction[] = [];
    if (row.type) {
        items.push({
            key: 'signals',
            label: 'Open signals',
            icon: ArrowUpRight,
            onSelect: () => router.visit(signals()),
        });
        if (row.quiet > 0)
            items.push({
                key: 'quiet',
                label: 'Open quiet sources',
                icon: AlertTriangle,
                onSelect: () => router.visit(signals('quiet')),
            });
        if (row.never > 0)
            items.push({
                key: 'never',
                label: 'Open sources that never reported',
                icon: AlertTriangle,
                onSelect: () => router.visit(signals('never')),
            });
    }
    if (securityDevicesUrl && row.site_id)
        items.push({
            key: 'devices',
            label: 'Open in Security & Devices',
            icon: ShieldCheck,
            onSelect: () => router.visit(url(securityDevicesUrl, { site_id: row.site_id })),
        });
    if (onlyThisHouse)
        items.push({
            key: 'house',
            label: 'Show only this house',
            icon: Building2,
            onSelect: onlyThisHouse,
        });

    // Partly working: say how many are the problem, once.
    const detail =
        row.state === 'quiet' && row.quiet < row.devices
            ? `${row.quiet} of ${row.devices} quiet`
            : row.state === 'never' && row.never < row.devices
              ? `${row.never} of ${row.devices} never reported`
              : null;

    return (
        <ControlRoomRowActions label={`Actions for ${house}${source ? ` ${source}` : ''}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    data-state={row.state}
                    onContextMenu={rowProps.onContextMenu}
                    onClick={(event) => {
                        if ((event.target as HTMLElement).closest('a,button')) return;
                        if (next.href) router.visit(next.href);
                    }}
                    className={next.href ? 'cursor-pointer' : undefined}
                >
                    <TableCell className="pl-5 font-semibold whitespace-normal text-foreground">{house}</TableCell>
                    <TableCell className="whitespace-normal">
                        {source ? (
                            <>
                                <span className="block">{source}</span>
                                <span className="text-caption block">
                                    {row.devices} {row.devices === 1 ? 'device' : 'devices'}
                                    {row.sources.length ? ` · ${row.sources.join(', ')}` : ''}
                                </span>
                            </>
                        ) : (
                            <span className="text-subtle">{typeLabel ? `No ${typeLabel}` : 'No sources'}</span>
                        )}
                    </TableCell>
                    <TableCell className="whitespace-normal">
                        {age ? (
                            <span className="block" title={formatDateTime(row.last_signal_at)}>
                                {age}
                            </span>
                        ) : row.type ? (
                            <span className="text-subtle block">No report yet</span>
                        ) : null}
                        {detail ? <span className="text-caption block">{detail}</span> : null}
                    </TableCell>
                    <TableCell>
                        <StatusBadge variant={badge.tone} size="sm">
                            {badge.label}
                        </StatusBadge>
                    </TableCell>
                    <TableCell className="whitespace-normal">
                        {next.href ? (
                            <Link
                                href={next.href}
                                className="font-medium text-primary underline-offset-2 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {next.label}
                            </Link>
                        ) : (
                            <span className="text-subtle">{next.label}</span>
                        )}
                    </TableCell>
                    <TableCell className="pr-5 text-right">{items.length ? overflowButton : null}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
