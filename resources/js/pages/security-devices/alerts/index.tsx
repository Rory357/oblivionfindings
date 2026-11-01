/**
 * Alerts (S&D rebuild drawing pg-sdr-13, Workspace template, split layout): a work queue of what needs
 * someone, with the chosen alert's next step, story and timeline beside it. Done is a table, and
 * Rules & automations (13b) lists who is told when a check fails. Automations arrive with Phase F;
 * the Quiet times tab (13c) waits for its drawing's approval.
 */
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import {
    AcknowledgeAlert,
    MarkAlertDone,
    AddAlertRule,
    AlertViewer,
    AssignAlert,
    QuietTime,
    ReportIncidentFromAlert,
    RuleOnOff,
    SnoozeAlert,
    alertLine,
} from '@/components/security-devices/alert-popups';
import {
    Queue,
    QuietLine,
    SideBySide,
    Tally,
    type TallyItem,
} from '@/components/signature/blocks';
import { SkeletonCard } from '@/components/ui/skeleton-card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { durationWords, timedText } from '@/lib/security-devices/device-time';
import { Head, router, usePage } from '@inertiajs/react';
import { BellRing, Eye, Plus, Power, PowerOff } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { BlockHead, ListPager } from '../devices/_parts';
import {
    AlertNextBox,
    AlertStory,
    AlertsPerWeek,
    BlockFailed,
    DoneTable,
    RulesTable,
} from './_parts';
import type {
    AlertRuleRow,
    AlertsProps,
    AlertsTab,
    DoneRow,
    TimedLine,
} from './_types';

export type { AlertsProps } from './_types';

const BASE = '/security-devices/alerts';

const TAB_LABEL: Record<AlertsTab, string> = {
    open: 'Open',
    snoozed: 'Snoozed',
    done: 'Done',
    rules: 'Rules & automations',
};

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

/** An optional page prop loaded when a pop-up opens: undefined while it loads; `failed` when it didn't come. */
function useLazyProps(names: string[]) {
    const [failed, setFailed] = useState(false);
    const busy = useRef(false);
    const load = (missing: string[]) => {
        const only = names.filter((name) => missing.includes(name));
        if (only.length === 0 || busy.current) return;
        busy.current = true;
        setFailed(false);
        let arrived = false;
        router.reload({
            only,
            onSuccess: () => {
                arrived = true;
            },
            onFinish: () => {
                busy.current = false;
                if (!arrived) setFailed(true);
            },
        });
    };
    return { failed, load };
}

type Popup =
    | { kind: 'acknowledge' | 'assign' | 'snooze' | 'incident' | 'done' }
    | { kind: 'quiet' }
    | { kind: 'rule' }
    | { kind: 'rule-on-off'; rule: AlertRuleRow }
    | { kind: 'viewer'; id: number }
    | null;

const lineText = (line: string | TimedLine, now: Date) =>
    typeof line === 'string' ? line : timedText(line, now);

export default function AlertsIndex(props: AlertsProps) {
    const {
        tab,
        counts,
        site,
        sites,
        meters,
        queue,
        done,
        selected,
        rules,
        can,
    } = props;
    const now = useNow();
    const actorId = (usePage().props as { auth?: { user?: { id?: number } } })
        .auth?.user?.id;
    const [search, setSearch] = useState(props.q ?? '');
    const searchTimer = useRef<number | null>(null);
    const [popup, setPopup] = useState<Popup>(null);
    const [choosing, setChoosing] = useState<number | null>(null);
    const [chooseFailed, setChooseFailed] = useState<number | null>(null);
    const doneMenu = useEntityContextMenu<DoneRow>();
    const ruleMenu = useEntityContextMenu<AlertRuleRow>();
    const lazy = useLazyProps(['alertDevices', 'ruleForm']);

    const params = (changes: Record<string, string | number | undefined>) => {
        const next: Record<string, string | number | undefined> = {
            tab: tab === 'open' ? undefined : tab,
            site: site === 'all' ? undefined : site,
            q: props.q ?? undefined,
            ...changes,
        };
        return Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) =>
                    value !== undefined && value !== '' && value !== 'all',
            ),
        );
    };
    const go = (changes: Record<string, string | number | undefined>) => {
        doneMenu.close();
        ruleMenu.close();
        router.get(BASE, params(changes), {
            preserveState: true,
            preserveScroll: true,
        });
    };
    const updateSearch = (value: string) => {
        setSearch(value);
        if (searchTimer.current) window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(
            () => go({ q: value.trim() || undefined, page: undefined }),
            350,
        );
    };

    /** Show another alert beside the queue: only its story reloads. */
    const choose = (id: number, then?: () => void) => {
        setChoosing(id);
        setChooseFailed(null);
        let arrived = false;
        router.get(
            BASE,
            params({
                alert: id,
                page: tab === 'done' ? done?.page : undefined,
            }),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['selected'],
                onSuccess: () => {
                    arrived = true;
                    then?.();
                },
                onFinish: () => {
                    setChoosing(null);
                    if (!arrived) setChooseFailed(id);
                },
            },
        );
    };
    const selectedId = choosing ?? selected?.id ?? null;
    const shown =
        selected && (choosing === null || selected.id === choosing)
            ? selected
            : null;

    const openQuiet = () => {
        if (props.alertDevices === undefined) lazy.load(['alertDevices']);
        setPopup({ kind: 'quiet' });
    };
    const openRule = () => {
        lazy.load([
            ...(props.ruleForm === undefined ? ['ruleForm'] : []),
            ...(props.alertDevices === undefined ? ['alertDevices'] : []),
        ]);
        setPopup({ kind: 'rule' });
    };
    const reloadLazy = () =>
        lazy.load([
            ...(props.ruleForm === undefined ? ['ruleForm'] : []),
            ...(props.alertDevices === undefined ? ['alertDevices'] : []),
        ]);

    const openHref = `${BASE}${site !== 'all' ? `?site=${site}` : ''}`;
    const zeros = [meters.needs_now.count === 0, meters.waiting.count === 0];
    const tallyItems: TallyItem[] = [
        {
            value: meters.needs_now.count,
            label: 'Needs someone now',
            sub: meters.needs_now.caption,
            tone: 'critical',
            href: openHref,
            linkLabel: 'Show open alerts that need someone now',
        },
        {
            value: meters.waiting.count,
            label: 'Waiting on someone',
            sub: lineText(meters.waiting.caption, now),
            tone: 'warning',
            href: openHref,
            linkLabel: 'Show open alerts waiting on someone',
        },
        {
            value: meters.quiet.text,
            label: 'Quiet time',
            sub: lineText(meters.quiet.caption, now),
            keep: true,
        },
    ];
    const tally = (
        <Tally
            aria-label="Alert numbers"
            items={tallyItems}
            calm={
                zeros[0] && zeros[1]
                    ? 'Nobody needs to act on an alert'
                    : zeros[0]
                      ? 'Nobody needed right now'
                      : zeros[1]
                        ? 'Nothing waiting'
                        : undefined
            }
        />
    );

    const doneActions = (row: DoneRow): MenuItem[] => [
        {
            label: 'See what happened',
            icon: Eye,
            onClick: () => openViewer(row.id),
        },
    ];
    const openViewer = (id: number) => {
        setPopup({ kind: 'viewer', id });
        if (selected?.id !== id) choose(id);
    };
    const ruleActions = (row: AlertRuleRow): MenuItem[] =>
        row.can_change
            ? [
                  row.on
                      ? {
                            label: 'Turn off',
                            icon: PowerOff,
                            danger: true,
                            onClick: () =>
                                setPopup({ kind: 'rule-on-off', rule: row }),
                        }
                      : {
                            label: 'Turn on',
                            icon: Power,
                            onClick: () =>
                                setPopup({ kind: 'rule-on-off', rule: row }),
                        },
              ]
            : [
                  {
                      label: row.on ? 'Turn off' : 'Turn on',
                      icon: row.on ? PowerOff : Power,
                      disabled:
                          'Only people who manage device checks can change rules',
                  },
              ];

    const ruleRows = useMemo(() => {
        const rows = rules?.rows ?? [];
        const words = (props.q ?? '').trim().toLowerCase();
        return words === ''
            ? rows
            : rows.filter((row) =>
                  `${row.when} ${row.sub} ${row.name} ${row.where} ${row.then}`
                      .toLowerCase()
                      .includes(words),
              );
    }, [rules, props.q]);

    const header = (
        <PageHeader
            icon={BellRing}
            title="Alerts"
            subline="What needs someone now, who has been told and what happened next"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={updateSearch}
                        placeholder="Search alerts"
                    />
                    {can.quiet_time ? (
                        <PageHeaderGlassButton onClick={openQuiet}>
                            Quiet time
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.add_rule ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={openRule}>
                            Add rule
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            filters={
                sites.length > 1 ? (
                    <PageHeaderFilterSelect
                        label="All Sites"
                        value={site}
                        options={sites}
                        onChange={(value) =>
                            go({
                                site: value,
                                alert: undefined,
                                page: undefined,
                            })
                        }
                    />
                ) : undefined
            }
            rail={
                <PageHeaderRail<AlertsTab>
                    ariaLabel="Alerts views"
                    items={(Object.keys(TAB_LABEL) as AlertsTab[]).map(
                        (key) => ({
                            key,
                            label: TAB_LABEL[key],
                            count: key === 'done' ? undefined : counts[key],
                        }),
                    )}
                    value={tab}
                    onSelect={(key) =>
                        go({
                            tab: key === 'open' ? undefined : key,
                            alert: undefined,
                            page: undefined,
                        })
                    }
                />
            }
        />
    );

    const searching = (props.q ?? '').trim() !== '';
    const noMatch = {
        text: 'No alerts match. Try another search.',
        action: { label: 'Clear search', onClick: () => updateSearch('') },
    };

    let body: ReactNode = null;
    if (tab === 'open' || tab === 'snoozed') {
        if (queue.length === 0) {
            body = (
                <div className="flex flex-col gap-5">
                    {tally}
                    <QuietLine
                        title={TAB_LABEL[tab]}
                        text={
                            searching
                                ? noMatch.text
                                : tab === 'open'
                                  ? 'No alerts. Everything is quiet.'
                                  : 'Nothing is snoozed.'
                        }
                        action={searching ? noMatch.action : null}
                    />
                </div>
            );
        } else {
            body = (
                <SideBySide cols="420px minmax(0, 1fr)">
                    <div className="grid min-w-0 content-start gap-5">
                        {tally}
                        <Queue
                            title={TAB_LABEL[tab]}
                            sub={
                                tab === 'open'
                                    ? 'Most urgent first'
                                    : 'Soonest back first'
                            }
                            selected={selectedId}
                            onSelect={(key) => choose(Number(key))}
                            items={queue.map((row) => ({
                                key: row.id,
                                title: row.title,
                                tone: row.tone,
                                age: durationWords(row.opened_at, now),
                                sub: alertLine(row.sub, row.opened_at, now, {
                                    closed: row.closed_at,
                                    snoozed: row.snoozed_until,
                                }),
                                badges: (
                                    <StatusBadge
                                        variant={row.badge.tone}
                                        size="sm"
                                    >
                                        {row.badge.text}
                                    </StatusBadge>
                                ),
                            }))}
                        />
                    </div>
                    <div className="grid min-w-0 gap-5">
                        {chooseFailed !== null ? (
                            <BlockFailed
                                title="This alert"
                                text="This alert couldn’t load."
                                onRetry={() => choose(chooseFailed)}
                            />
                        ) : shown ? (
                            <>
                                <AlertNextBox
                                    alert={shown}
                                    now={now}
                                    onAcknowledge={() =>
                                        setPopup({ kind: 'acknowledge' })
                                    }
                                    onAssign={() =>
                                        setPopup({ kind: 'assign' })
                                    }
                                    onSnooze={() =>
                                        setPopup({ kind: 'snooze' })
                                    }
                                    onDone={() => setPopup({ kind: 'done' })}
                                />
                                <AlertStory
                                    alert={shown}
                                    now={now}
                                    onReportIncident={
                                        can.report_incident
                                            ? () =>
                                                  setPopup({ kind: 'incident' })
                                            : null
                                    }
                                />
                            </>
                        ) : choosing !== null ? (
                            <>
                                <SkeletonCard />
                                <SkeletonCard />
                            </>
                        ) : (
                            <QuietLine text="Choose an alert to see what happened." />
                        )}
                    </div>
                </SideBySide>
            );
        }
    } else if (tab === 'done') {
        body = (
            <div className="flex flex-col gap-5">
                {tally}
                {done === null ? (
                    <BlockFailed
                        title="Done"
                        text="The alerts that are done couldn’t load."
                        onRetry={() => router.reload({ only: ['done'] })}
                    />
                ) : (
                    <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                        <div className="border-b border-border px-4 py-3.5">
                            <BlockHead
                                title="Done"
                                sub="Closed in the last 30 days · newest first"
                            />
                        </div>
                        {done.rows.length === 0 ? (
                            <p className="text-subtle px-4 py-6">
                                {searching
                                    ? noMatch.text
                                    : 'No alert has closed in the last 30 days.'}
                            </p>
                        ) : (
                            <DoneTable
                                rows={done.rows}
                                now={now}
                                actionsFor={doneActions}
                                onOpen={(row) => openViewer(row.id)}
                                onContextMenu={(event, row) =>
                                    doneMenu.open(event, row)
                                }
                            />
                        )}
                        <ListPager
                            meta={{
                                total: done.total,
                                page: done.page,
                                last_page: done.pages,
                                from:
                                    done.total === 0
                                        ? 0
                                        : (done.page - 1) * 25 + 1,
                                to: (done.page - 1) * 25 + done.rows.length,
                            }}
                            noun="alerts"
                            onPage={(page) => go({ tab: 'done', page })}
                        />
                    </section>
                )}
            </div>
        );
    } else if (tab === 'rules') {
        body = (
            <div className="flex flex-col gap-5">
                {tally}
                {rules === null ? (
                    <BlockFailed
                        title="Rules & automations"
                        text="The rules couldn’t load."
                        onRetry={() => router.reload({ only: ['rules'] })}
                    />
                ) : (
                    <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                        <div className="border-b border-border px-4 py-3.5">
                            <BlockHead
                                title="Rules & automations"
                                sub="When a check fails on network gear or a computer, who is told and how"
                            />
                        </div>
                        {ruleRows.length === 0 ? (
                            <p className="text-subtle px-4 py-6">
                                {searching
                                    ? 'No rules match. Try another search.'
                                    : 'No rules yet, so the standard routing tells IT for each Site.'}
                            </p>
                        ) : (
                            <RulesTable
                                rows={ruleRows}
                                actionsFor={ruleActions}
                                onContextMenu={(event, row) =>
                                    ruleMenu.open(event, row)
                                }
                            />
                        )}
                        <ListPager
                            meta={{
                                total: ruleRows.length,
                                page: 1,
                                last_page: 1,
                                from: ruleRows.length === 0 ? 0 : 1,
                                to: ruleRows.length,
                            }}
                            noun="rules"
                            onPage={() => undefined}
                        />
                    </section>
                )}
                {props.per_week ? (
                    <div className="grid gap-5 lg:grid-cols-2">
                        <AlertsPerWeek weeks={props.per_week} />
                    </div>
                ) : null}
            </div>
        );
    }

    const target = shown
        ? { id: shown.id, title: shown.title, opened_at: shown.opened_at }
        : null;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: '/security-devices' },
                { title: 'Alerts', href: BASE },
            ]}
        >
            <Head title="Alerts" />
            <PageLayout hero={header}>{body}</PageLayout>

            {doneMenu.ctx ? (
                <EntityContextMenu
                    x={doneMenu.ctx.x}
                    y={doneMenu.ctx.y}
                    icon={BellRing}
                    title={doneMenu.ctx.record.title}
                    items={doneActions(doneMenu.ctx.record)}
                    onClose={doneMenu.close}
                />
            ) : null}
            {ruleMenu.ctx ? (
                <EntityContextMenu
                    x={ruleMenu.ctx.x}
                    y={ruleMenu.ctx.y}
                    icon={BellRing}
                    title={ruleMenu.ctx.record.when}
                    items={ruleActions(ruleMenu.ctx.record)}
                    onClose={ruleMenu.close}
                />
            ) : null}

            <AcknowledgeAlert
                open={popup?.kind === 'acknowledge'}
                alert={target}
                onClose={() => setPopup(null)}
            />
            <MarkAlertDone
                open={popup?.kind === 'done'}
                alert={target}
                onClose={() => setPopup(null)}
            />
            <AssignAlert
                open={popup?.kind === 'assign'}
                alert={target}
                onClose={() => setPopup(null)}
            />
            <SnoozeAlert
                open={popup?.kind === 'snooze'}
                alert={target}
                onClose={() => setPopup(null)}
            />
            <ReportIncidentFromAlert
                open={popup?.kind === 'incident'}
                alert={shown}
                onClose={() => setPopup(null)}
            />
            <QuietTime
                open={popup?.kind === 'quiet'}
                devices={props.alertDevices}
                devicesFailed={lazy.failed}
                loadDevices={() => lazy.load(['alertDevices'])}
                actorId={actorId}
                onClose={() => setPopup(null)}
            />
            <AddAlertRule
                open={popup?.kind === 'rule'}
                form={props.ruleForm}
                formFailed={lazy.failed}
                sites={sites}
                devices={props.alertDevices}
                devicesFailed={lazy.failed}
                load={reloadLazy}
                onClose={() => setPopup(null)}
            />
            <RuleOnOff
                rule={popup?.kind === 'rule-on-off' ? popup.rule : null}
                onClose={() => setPopup(null)}
            />
            <AlertViewer
                open={popup?.kind === 'viewer'}
                alert={
                    popup?.kind === 'viewer' && shown?.id === popup.id
                        ? shown
                        : null
                }
                failed={popup?.kind === 'viewer' && chooseFailed === popup.id}
                onRetry={() => popup?.kind === 'viewer' && choose(popup.id)}
                onReportIncident={
                    can.report_incident
                        ? () => setPopup({ kind: 'incident' })
                        : null
                }
                onClose={() => setPopup(null)}
            />
        </AppLayout>
    );
}
