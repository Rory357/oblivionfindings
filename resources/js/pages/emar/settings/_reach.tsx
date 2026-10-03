/* Medication › Settings › Alerts & access › Delivery › Who can't be reached
 * (eMAR P11 v5, B2 C5): the contact gaps that stop an alert or an on-call
 * call getting through — no work email, push not set up, no work phone, and
 * an on-call backup on approved leave. The server sends what it knows about
 * each person with a gap; which gaps matter is worked out from the draft,
 * so it follows the Alerts table as it's edited. Leave only ever says "On
 * leave" and the dates, never the type of leave. */
import {
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import {
    ArrowUpRight,
    CalendarX,
    Eye,
    Mail,
    Phone,
    Smartphone,
    UserCheck,
    UserX,
} from 'lucide-react';
import { useSettings, type Dialog } from './_context';
import { NotFound } from './_dialogs';
import {
    definitionOf,
    draftValue,
    parseAlert,
    parsePeople,
    siteDraftValue,
    type Draft,
    type SettingsPayload,
} from './_model';
import { RowMenu, Section } from './_ui';

/** Someone who can get alerts and has a contact gap (from the server). */
export type ReachGap = {
    id: number;
    name: string;
    role: string;
    houses: string;
    site_ids: number[];
    /** Controlled-medicine access: controlled alerts reach only them. */
    controlled: boolean;
    /** The alert groups they can be in. */
    groups: string[];
    work_email: boolean;
    push: boolean;
    phone: boolean;
    /** "On leave Thu 1 – Mon 5 Oct (Leave hub)" — never the type of leave. */
    leave: string | null;
    /** Houses whose on-call backup they are. */
    backup_for: string[];
    /** Needed on call: rostered on call or as the team lead, or a backup. */
    on_call: boolean;
};

type IssueKind = 'email' | 'push' | 'phone' | 'leave';
export type ReachIssue = {
    kind: IssueKind;
    text: string;
    fix: string;
    matters: string | null;
};
export type Reach = { gap: ReachGap; issues: ReachIssue[] };

const types = (n: number) => `${n} alert ${n === 1 ? 'type' : 'types'}`;

/** Every person with a gap, and why each gap matters with the draft's alerts. */
export function reachRows(
    s: SettingsPayload,
    draft: Draft,
    gaps: ReachGap[],
): Reach[] {
    const keys = s.groups.alerts?.keys ?? [];
    // Alert types this person would get on this channel.
    const gets = (g: ReachGap, ch: 'email' | 'push') =>
        keys.filter((k) => {
            const meta = definitionOf(s, 'alerts', k)?.alert;
            const a = parseAlert(draftValue(s, draft, 'alerts', k));
            if (!meta || !a || !a[ch] || !meta.channels.includes(ch))
                return false;
            if (meta.controlled && !g.controlled) return false;
            return (
                a.groups.some((x) => g.groups.includes(x)) ||
                a.people.includes(g.id) ||
                g.site_ids.some((sid) =>
                    (
                        parsePeople(
                            siteDraftValue(s, draft, 'alertExtra', k, sid),
                        ) ?? []
                    ).includes(g.id),
                )
            );
        }).length;
    return gaps
        .map((g) => {
            const issues: ReachIssue[] = [];
            if (!g.work_email) {
                const n = gets(g, 'email');
                issues.push({
                    kind: 'email',
                    text: 'No work email',
                    fix: 'An HR admin adds a work email in HR › People.',
                    matters: n ? `Misses ${types(n)} sent by email` : null,
                });
            }
            if (!g.push) {
                const n = gets(g, 'push');
                issues.push({
                    kind: 'push',
                    text: 'Push not set up',
                    fix: 'They turn on push for their phone or browser in their account › Notifications.',
                    matters: n ? `Misses ${types(n)} sent by push` : null,
                });
            }
            if (!g.phone)
                issues.push({
                    kind: 'phone',
                    text: 'No work phone',
                    fix: 'An HR admin adds a work phone in HR › People.',
                    matters: g.on_call
                        ? 'Screens would show no number when they’re on call'
                        : null,
                });
            if (g.leave)
                issues.push({
                    kind: 'leave',
                    text: g.leave,
                    fix: 'Choose another backup, or roster an on-call shift for those nights.',
                    matters: g.backup_for.length
                        ? `On-call backup for ${g.backup_for.join(' and ')} — nights they’re away show nobody`
                        : null,
                });
            return { gap: g, issues };
        })
        .filter((r) => r.issues.length);
}

export const reachMatters = (r: Reach) => r.issues.some((i) => i.matters);

/** Part of Delivery, under its settings (v5): every person with a gap, whatever the tab's search. */
export function WhoCantBeReached({ gaps }: { gaps: ReachGap[] }) {
    const { s, draft, open } = useSettings();
    const menu = useEntityContextMenu<Reach>();
    // Those who matter now first; the rest keep their order.
    const rows = reachRows(s, draft, gaps).sort(
        (a, b) => Number(reachMatters(b)) - Number(reachMatters(a)),
    );
    const now = rows.filter(reachMatters);
    const actions = (r: Reach): MenuItem[] => [
        {
            label: 'Why, and who fixes it',
            icon: Eye,
            onClick: () => open({ kind: 'reach', id: r.gap.id }),
        },
    ];
    return (
        <Section
            id="sc-reach"
            title="Who can’t be reached"
            caption={`${now.length} ${now.length === 1 ? 'person matters' : 'people matter'} now · ${rows.length} with a gap`}
        >
            <p className="text-subtle">
                From staff records, push set-up and approved leave. A gap only
                matters once a channel they’d need is on, or they’re needed on
                call.
            </p>
            {rows.length ? (
                <EntityTable<Reach>
                    rows={rows}
                    rowKey={(r) => String(r.gap.id)}
                    identityLabel="Person"
                    identityWidth="1.6fr"
                    minWidth={900}
                    rowHeight="content"
                    identity={(r) => ({
                        icon: UserX,
                        name: r.gap.name,
                        subline: `${r.gap.role} · ${r.gap.houses}`,
                    })}
                    columns={[
                        {
                            key: 'gap',
                            label: 'Gap',
                            width: '1.6fr',
                            cell: (r) => (
                                <span className="py-2 text-[12.5px]">
                                    {r.issues.map((i) => i.text).join(' · ')}
                                </span>
                            ),
                        },
                        {
                            key: 'matters',
                            label: 'Why it matters',
                            width: '1.8fr',
                            cell: (r) => (
                                <span className="py-2 text-[12.5px]">
                                    {r.issues
                                        .filter((i) => i.matters)
                                        .map((i) => i.matters)
                                        .join(' · ') ||
                                        'Not needed with today’s settings'}
                                </span>
                            ),
                        },
                        {
                            key: 'state',
                            label: 'Status',
                            width: '1fr',
                            cell: (r) =>
                                reachMatters(r) ? (
                                    <StatusBadge variant="warning" size="sm">
                                        Can’t be reached
                                    </StatusBadge>
                                ) : (
                                    <StatusBadge variant="neutral" size="sm">
                                        Not needed yet
                                    </StatusBadge>
                                ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(r) => open({ kind: 'reach', id: r.gap.id })}
                    onRowContextMenu={menu.open}
                />
            ) : (
                <EmptyState
                    icon={UserCheck}
                    title="Everyone can be reached"
                    description="Every person in an alert’s groups has what their alerts need."
                />
            )}
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={UserX}
                title={(r) => r.gap.name}
                items={actions}
            />
        </Section>
    );
}

const REACH_ICON = {
    email: Mail,
    push: Smartphone,
    phone: Phone,
    leave: CalendarX,
};

export function ReachDetail({ id, gaps }: { id: number; gaps: ReachGap[] }) {
    const { s, draft, close, go } = useSettings();
    const r = reachRows(s, draft, gaps).find((x) => x.gap.id === id);
    if (!r) return <NotFound what="person" />;
    return (
        <SettingsModal
            title={`Why ${r.gap.name} can’t always be reached`}
            description={`${r.gap.role} · ${r.gap.houses}`}
            onClose={close}
            footer={
                <>
                    {r.issues.some(
                        (i) => i.kind === 'leave' || i.kind === 'phone',
                    ) ? (
                        <Button
                            variant="outline"
                            onClick={() => {
                                close();
                                go('alerts', 'oncall');
                            }}
                        >
                            On-call contacts
                            <ArrowUpRight />
                        </Button>
                    ) : null}
                    <Button onClick={close}>Close</Button>
                </>
            }
        >
            {r.issues.map((i) => (
                <ReviewCard
                    key={i.kind}
                    icon={REACH_ICON[i.kind]}
                    title={i.text}
                >
                    <ReviewRow
                        label="Why it matters"
                        value={i.matters ?? 'Not needed with today’s settings'}
                    />
                    <ReviewRow label="Who fixes it" value={i.fix} />
                </ReviewCard>
            ))}
        </SettingsModal>
    );
}

export function ReachDialogHost({
    dialog,
    gaps,
}: {
    dialog: Dialog | null;
    gaps: ReachGap[];
}) {
    return dialog?.kind === 'reach' ? (
        <ReachDetail id={dialog.id} gaps={gaps} />
    ) : null;
}
