import { EntityChip } from '@/components/lists/entity-cells';
import {
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import {
    Bell,
    Eye,
    History,
    Home,
    LockKeyhole,
    ScrollText,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useSettings } from './_context';
import { RowMenu, Section } from './_ui';

export type AlertLogRow = {
    id: number;
    concealed: boolean;
    type: string | null;
    label: string | null;
    about: string | null;
    action_url: string | null;
    site_name: string | null;
    raised_at: string;
    sent: string;
    after_hours: boolean;
    status: 'open' | 'attended' | 'dealt_with';
    waited: string | null;
    attended_by: string | null;
    attended_at: string | null;
    dealt_with_at: string | null;
    reached_nobody: boolean;
    told: string[];
    via: string[];
    events: {
        id: number;
        occurred_at: string;
        at: string;
        what: string;
        who: string;
    }[];
};
export type AlertLogPage = {
    data: AlertLogRow[];
    total: number;
    from: number | null;
    to: number | null;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
};
export const LOG_SHOW = [
    { value: 'all', label: 'All alerts' },
    { value: 'open', label: 'Not attended' },
    { value: 'attended', label: 'Attended or dealt with' },
    { value: 'afterhours', label: 'After hours' },
];
export const LOG_RANGE = [
    { value: 'recent', label: 'Last 30 days and open alerts' },
    { value: 'all', label: 'All retained history' },
];
const title = (r: AlertLogRow) =>
    r.concealed ? 'Controlled-medicine alert' : (r.label ?? 'Medication alert');
const status = (r: AlertLogRow) => (
    <StatusBadge
        variant={r.status === 'open' ? 'warning' : 'success'}
        size="sm"
    >
        {r.status === 'open'
            ? 'Not attended'
            : r.status === 'dealt_with'
              ? 'Dealt with'
              : 'Attended'}
    </StatusBadge>
);

export function AlertLog({
    page,
    q,
    house,
    show,
    range,
    clear,
}: {
    page: AlertLogPage | null;
    q: string;
    house: string;
    show: string;
    range: string;
    clear: () => void;
}) {
    const { open, s } = useSettings();
    const menu = useEntityContextMenu<AlertLogRow>();
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const [retry, setRetry] = useState(0);
    const key = JSON.stringify([q, house, show, range, retry]);
    const initial = new URLSearchParams(window.location.search);
    const matchesInitial =
        page !== null &&
        initial.get('log') === '1' &&
        (initial.get('log_q') ?? '') === q &&
        (initial.get('log_house') || 'all') === house &&
        (initial.get('log_show') || 'all') === show &&
        (initial.get('log_range') || 'recent') === range;
    const last = useRef(matchesInitial ? key : '');
    useEffect(() => {
        if (last.current === key && page !== null) return;
        let active = true;
        let pending = false;
        const fail = () => {
            if (active && pending) {
                setFailed(true);
                setBusy(false);
                return false;
            }
        };
        const removeInvalid = router.on('invalid', (event) => {
            if (
                event.detail.response.config.url?.includes('/emar/settings') &&
                active &&
                pending
            ) {
                fail();
                event.preventDefault();
            }
        });
        const removeException = router.on('exception', (event) => {
            if (active && pending) {
                fail();
                event.preventDefault();
            }
        });
        let cancel: (() => void) | undefined;
        const timer = window.setTimeout(() => {
            pending = true;
            setBusy(true);
            setFailed(false);
            router.get(
                '/emar/settings#alerts/log',
                {
                    log: 1,
                    log_q: q,
                    log_house: house === 'all' ? '' : house,
                    log_show: show,
                    log_range: range,
                },
                {
                    only: ['alertLog', 'alertLogSummary'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    onCancelToken: (token) => {
                        cancel = () => token.cancel();
                    },
                    onSuccess: () => {
                        if (active) {
                            last.current = key;
                            setBusy(false);
                        }
                    },
                    onError: () => {
                        if (active) setFailed(true);
                    },
                    onFinish: () => {
                        pending = false;
                        if (active) setBusy(false);
                    },
                },
            );
        }, 250);
        return () => {
            active = false;
            pending = false;
            window.clearTimeout(timer);
            cancel?.();
            removeInvalid();
            removeException();
        };
    }, [key, q, house, show, range, retry, page]);
    const actions = (r: AlertLogRow): MenuItem[] =>
        compactMenu([
            {
                label: 'View what happened',
                icon: Eye,
                onClick: () => open({ kind: 'alertlog', row: r }),
            },
            !r.concealed &&
                !!r.type &&
                !!s.definitions.alerts?.[r.type] && {
                    label: 'Who gets this alert',
                    icon: Users,
                    onClick: () => open({ kind: 'alertwho', key: r.type! }),
                },
        ]);
    return (
        <Section
            id="sc-log"
            title="Alert log"
            caption={
                page
                    ? `${page.from ?? 0}–${page.to ?? 0} of ${page.total} shown · times in Pacific/Auckland`
                    : 'Loading alert history'
            }
        >
            <p className="text-subtle">
                Every medication alert: who was told, how, and who attended.
                Kept as long as the audit log. It never changes the medication
                record.
            </p>
            {failed ? (
                <ErrorState
                    title="Alert history could not be loaded"
                    message="Your settings draft is kept. Try again."
                    onRetry={() => setRetry((n) => n + 1)}
                />
            ) : busy || !page ? (
                <div role="status" aria-label="Loading alert history">
                    <SkeletonTable rows={5} columns={5} />
                </div>
            ) : page.data.length ? (
                <>
                    <EntityTable<AlertLogRow>
                        rows={page.data}
                        rowKey={(r) => r.id}
                        identityLabel="Alert"
                        identityWidth="2.2fr"
                        minWidth={1000}
                        rowHeight="content"
                        identity={(r) => ({
                            icon: r.concealed ? LockKeyhole : Bell,
                            name: title(r),
                            subline: r.concealed
                                ? 'Details need controlled-medicine access'
                                : (r.about ?? ''),
                        })}
                        columns={[
                            {
                                key: 'sent',
                                label: 'Raised',
                                width: '1fr',
                                cell: (r) => (
                                    <div>
                                        <div>{r.sent}</div>
                                        {r.after_hours && (
                                            <div className="text-caption">
                                                After hours
                                            </div>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'told',
                                label: 'Told',
                                width: '1.3fr',
                                cell: (r) =>
                                    r.concealed ? (
                                        <span className="text-caption">
                                            Hidden
                                        </span>
                                    ) : (
                                        <div>
                                            {r.told.join(', ') ||
                                                'Nobody told yet'}
                                            <div className="text-caption">
                                                {r.via.join(' and ')}
                                            </div>
                                        </div>
                                    ),
                            },
                            {
                                key: 'where',
                                label: 'Where',
                                width: '0.9fr',
                                cell: (r) => (
                                    <EntityChip icon={Home}>
                                        {r.site_name ?? 'Organisation'}
                                    </EntityChip>
                                ),
                            },
                            {
                                key: 'status',
                                label: 'Attended',
                                width: '1fr',
                                cell: (r) => (
                                    <div>
                                        {status(r)}
                                        {r.status === 'open' ? (
                                            <div className="text-caption">
                                                {r.waited}
                                            </div>
                                        ) : (
                                            !r.concealed && (
                                                <div className="text-caption">
                                                    {r.attended_by}
                                                </div>
                                            )
                                        )}
                                    </div>
                                ),
                            },
                        ]}
                        actionsFor={actions}
                        onOpen={(r) => open({ kind: 'alertlog', row: r })}
                        onRowContextMenu={menu.open}
                    />
                    <LaravelPagination
                        links={page.links}
                        lastPage={page.last_page}
                        preserveScroll
                        only={['alertLog', 'alertLogSummary']}
                    />
                </>
            ) : (
                <EmptyState
                    icon={ScrollText}
                    title="No alerts match these filters"
                    description="Clear the filters or search, or choose all retained history."
                    action={
                        <Button variant="outline" onClick={clear}>
                            Clear filters
                        </Button>
                    }
                />
            )}
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={Bell}
                title={title}
                items={actions}
            />
        </Section>
    );
}

export function AlertLogView({ row }: { row: AlertLogRow }) {
    const { close, go } = useSettings();
    return (
        <SettingsModal
            frontline
            title={title(row)}
            description={
                row.concealed
                    ? `${row.site_name ?? 'Organisation'} · raised ${row.sent}`
                    : `${row.about ?? ''} · ${row.site_name ?? 'Organisation'} · raised ${row.sent}`
            }
            onClose={close}
            footer={<Button onClick={close}>Close</Button>}
        >
            {row.concealed ? (
                <SettingsNotice>
                    Only people with controlled-medicine access can see what
                    this alert was about, who was told and what happened.
                </SettingsNotice>
            ) : (
                <>
                    <ReviewCard icon={History} title="What happened">
                        {row.events.map((e) => (
                            <ReviewRow
                                key={e.id}
                                label={e.at}
                                value={
                                    <span className="text-right">
                                        <b>{e.what}</b>
                                        <span className="text-caption block">
                                            {e.who}
                                        </span>
                                    </span>
                                }
                            />
                        ))}
                        {row.events.length === 0 && (
                            <p className="text-subtle">
                                No delivery events were recorded for this alert.
                            </p>
                        )}
                        {row.status === 'open' && (
                            <ReviewRow
                                label="Now"
                                value={<b>Not attended — {row.waited}</b>}
                            />
                        )}
                    </ReviewCard>
                    {row.reached_nobody && (
                        <SettingsNotice>
                            Nobody could be told.{' '}
                            <Button
                                variant="link"
                                onClick={() => {
                                    close();
                                    go('alerts', 'delivery');
                                }}
                            >
                                Review delivery and follow-up
                            </Button>
                        </SettingsNotice>
                    )}
                    {row.action_url && (
                        <Button variant="outline" asChild>
                            <a href={row.action_url}>Open the source record</a>
                        </Button>
                    )}
                    <p className="text-caption">
                        Alert #{row.id}. This history comes from the alert's
                        recorded events and never changes the medication record.
                    </p>
                </>
            )}
        </SettingsModal>
    );
}
