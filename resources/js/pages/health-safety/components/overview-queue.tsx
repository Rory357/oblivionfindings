/**
 * G2 (B2.2, approved boards M1 and M3): "Your queue" — the work this person can move on now, in D1's blocks, WorkSafe
 * first: WorkSafe: decide or tell · Need an owner · Incidents need an owner (provider managers) · Actions to check ·
 * Close-anyway requests (approvers) · Other things due. A row appears once, in its first block. Only steps this person
 * can take are buttons (IR3b); otherwise the row says who does it. Blocks with nothing in them fold into one sentence.
 */
import { ActionLink, Ring, Surface, type SgAction, type SgTone } from '@/components/signature/blocks';
import { formatDateOnly } from '@/lib/datetime';
import { hsLabel, humaniseHsValue } from '@/lib/health-safety/hs-labels';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import type { WorklistsPayload } from './worklists';

export interface QueueRow {
    id: number;
    reference: string | null;
    title: string;
}
export interface OverviewQueueData {
    set: 'queue' | 'compliance' | 'incidents' | 'hazards';
    can_manage: boolean;
    worksafe: {
        count: number;
        rows: Array<QueueRow & { severity: string | null; site: string | null; owner: string | null; reported_at: string | null; step: 'decide' | 'tell'; awaiting_owner: boolean }>;
    };
    to_check: { count: number; rows: Array<QueueRow & { completed_by: string | null; completed_at: string | null; event_reference: string | null }> } | null;
    incidents_need_owner: {
        count: number;
        rows: Array<QueueRow & { type: string | null; severity: string | null; site: string | null; occurred_at: string | null }>;
    } | null;
    close_anyway: number | null;
    stages: { open: number; investigating: number; corrective_action: number; monitoring: number; closed_30d: number };
}

interface Row {
    key: string;
    when: string;
    tone: SgTone;
    title: string;
    sub: string;
    action: SgAction | null;
    who?: string;
}
interface Block {
    key: string;
    label: string;
    count: number;
    rows: Row[];
    more?: SgAction;
}

const day = (iso: string | null | undefined) => (iso ? formatDateOnly(iso.slice(0, 10)) : '');
const join = (...parts: Array<string | null | undefined | false>) => parts.filter(Boolean).join(' · ');

/** Builds the blocks from the queue part and the work lists, without repeating a review that already sits higher up. */
export function queueBlocks(queue: OverviewQueueData, lists: WorklistsPayload, link: (path: string, params?: Record<string, string>) => string): Block[] {
    const seen = new Set<string>();
    const manage = queue.can_manage;
    const blocks: Block[] = [];

    const worksafeRows: Row[] = queue.worksafe.rows.map((r) => {
        seen.add(`ev-${r.id}`);
        const href = (action: string) => `/health-safety/events/${r.id}?action=${action}`;
        const action: SgAction | null = !manage
            ? null
            : r.awaiting_owner
              ? { label: 'Take it on', href: href('accept-handover'), ariaLabel: `Take it on: ${r.title}` }
              : r.step === 'tell'
                ? { label: 'Record that WorkSafe was told', href: href('worksafe-notify'), ariaLabel: `Record that WorkSafe was told: ${r.title}` }
                : { label: 'Record WorkSafe decision', href: href('worksafe-decision'), ariaLabel: `Record WorkSafe decision: ${r.title}` };
        return {
            key: `ws-${r.id}`,
            when: day(r.reported_at),
            tone: 'critical',
            title: r.title,
            sub: join(r.awaiting_owner ? 'Nobody has taken it on yet' : r.owner ? `${r.owner} took it on` : null, r.site, r.reference,
                r.step === 'tell' ? 'Tell WorkSafe' : 'WorkSafe: decide', r.severity ? hsLabel('severity', r.severity) : null),
            action,
            who: action ? undefined : 'Health and safety record this',
        };
    });
    blocks.push({ key: 'worksafe', label: 'WorkSafe: decide or tell', count: queue.worksafe.count, rows: worksafeRows,
        more: queue.worksafe.count > worksafeRows.length ? { label: `All ${queue.worksafe.count}`, href: link('/health-safety/events', { tab: 'decide_or_tell' }) } : undefined });

    const owner = lists.attention[0];
    const ownerRows: Row[] = (owner?.items ?? [])
        .filter((i) => !seen.has(`ev-${i.id}`))
        .map((i) => {
            seen.add(`ev-${i.id}`);
            return {
                key: `own-${i.id}`,
                when: day(i.reported_at),
                tone: 'warning',
                title: i.title,
                sub: join('Waiting for Health and safety to take it on', i.site, i.event_reference),
                action: manage ? { label: 'Take it on', href: i.action_url, ariaLabel: `Take it on: ${i.title}` } : null,
                who: manage ? undefined : 'Health and safety take it on',
            };
        });
    // Reviews already listed under WorkSafe aren't counted again here.
    const listedAbove = (owner?.items.length ?? 0) - ownerRows.length;
    blocks.push({ key: 'need_owner', label: 'Need an owner', count: Math.max(0, (owner?.count ?? 0) - listedAbove), rows: ownerRows,
        more: (owner?.count ?? 0) > (owner?.items.length ?? 0) ? { label: `All ${owner?.count}`, href: link('/health-safety/events', { tab: 'needs_owner' }) } : undefined });

    if (queue.incidents_need_owner) {
        const inc = queue.incidents_need_owner;
        blocks.push({
            key: 'incidents_need_owner',
            label: 'Incidents need an owner',
            count: inc.count,
            rows: inc.rows.map((r) => ({
                key: `inc-${r.id}`,
                when: day(r.occurred_at),
                tone: 'warning',
                title: r.title,
                sub: join(r.type ? humaniseHsValue(r.type) : null, r.site, r.reference),
                action: { label: 'Assign someone', href: `/incidents/${r.id}`, ariaLabel: `Assign someone: ${r.title}` },
            })),
            more: inc.count > inc.rows.length ? { label: `All ${inc.count}`, href: link('/incidents', { owner: 'needs_owner' }) } : undefined,
        });
    }

    if (queue.to_check) {
        const check = queue.to_check;
        blocks.push({
            key: 'to_check',
            label: 'Actions to check',
            count: check.count,
            rows: check.rows.map((r) => ({
                key: `chk-${r.id}`,
                when: day(r.completed_at),
                tone: 'info',
                title: r.title,
                sub: join(r.completed_by ? `Done by ${r.completed_by}` : 'Done', r.event_reference, r.reference),
                action: { label: 'Check it worked', href: `/health-safety/corrective-actions/${r.id}?action=verify`, ariaLabel: `Check it worked: ${r.title}` },
            })),
            more: check.count > check.rows.length ? { label: `All ${check.count}`, href: link('/health-safety/corrective-actions', { tab: 'awaiting_verification' }) } : undefined,
        });
    }

    if (queue.close_anyway !== null) {
        blocks.push({
            key: 'close_anyway',
            label: 'Close-anyway requests',
            count: queue.close_anyway,
            rows: queue.close_anyway > 0
                ? [{ key: 'ca', when: '', tone: 'critical', title: `${queue.close_anyway} waiting for a decision`, sub: 'Someone other than the asker decides each one',
                     action: { label: 'Decide', href: link('/health-safety/events', { tab: 'close_anyway' }) } }]
                : [],
        });
    }

    const due = lists.overdue_corrective_actions;
    blocks.push({
        key: 'due',
        label: 'Other things due',
        count: due.length,
        rows: due.slice(0, 5).map((c) => ({
            key: `due-${c.id}`,
            when: day(c.due_date),
            tone: 'critical',
            title: c.title ?? c.reference ?? `Action ${c.id}`,
            sub: join(c.owner ? `Owner ${c.owner}` : 'No owner', c.reference, c.days_overdue != null ? `${c.days_overdue} days overdue` : 'Overdue'),
            action: { label: 'Open', href: `/health-safety/corrective-actions/${c.id}`, ariaLabel: `Open action: ${c.title ?? c.reference ?? c.id}` },
        })),
        more: due.length > 5 ? { label: `All ${due.length}`, href: link('/health-safety/corrective-actions', { tab: 'overdue' }) } : undefined,
    });

    return blocks;
}

function BlockRows({ block }: { block: Block }) {
    return (
        <section aria-label={block.label} className="border-t border-border">
            <h4 className="text-subtle flex items-center gap-2 px-[18px] pt-3 pb-1 font-semibold">
                {block.label} <span className="font-normal">· {block.count}</span>
            </h4>
            <ol className="sg-log">
                {block.rows.map((r) => (
                    <li key={r.key} className="sg-lr">
                        <time>{r.when}</time>
                        <Ring tone={r.tone} />
                        <div className="min-w-0">
                            <b>{r.title}</b>
                            <small>{r.sub}</small>
                        </div>
                        <span className="inline-flex items-center gap-2">
                            {r.action ? <ActionLink action={r.action} className="ml-0" /> : <small className="text-subtle">{r.who}</small>}
                        </span>
                    </li>
                ))}
            </ol>
            {block.more ? (
                <p className="px-[18px] pb-3">
                    <ActionLink action={block.more} className="ml-0" />
                </p>
            ) : null}
        </section>
    );
}

export function OverviewQueue({
    queue,
    lists,
    link,
    title = 'Your queue',
}: {
    queue: OverviewQueueData;
    lists: WorklistsPayload;
    link: (path: string, params?: Record<string, string>) => string;
    title?: string;
}) {
    const blocks = queueBlocks(queue, lists, link);
    const shown = blocks.filter((b) => b.count > 0);
    const empty = blocks.filter((b) => b.count === 0);
    const total = shown.reduce((sum, b) => sum + b.count, 0);
    return (
        <Surface
            title={title}
            sub={total === 0 ? 'Nothing waiting for you now' : `${total} ${total === 1 ? 'thing' : 'things'} to move on, most urgent first`}
            action={{ label: 'All tasks', href: '/tasks' }}
        >
            {shown.map((b) => (
                <BlockRows key={b.key} block={b} />
            ))}
            {empty.length ? (
                <p className="text-subtle border-t border-border px-[18px] py-3">
                    Nothing waiting in {empty.map((b) => (b.label === 'WorkSafe: decide or tell' ? b.label : b.label.charAt(0).toLowerCase() + b.label.slice(1))).join(', ')}.
                </p>
            ) : null}
        </Surface>
    );
}

/** "Where safety reviews are up to": open reviews by stage now, and closed in the last 30 days; each number opens its list. */
export function ReviewStages({ stages, link }: { stages: OverviewQueueData['stages']; link: (path: string, params?: Record<string, string>) => string }) {
    const cells: Array<{ key: string; label: string; value: number; tab: string }> = [
        { key: 'open', label: 'Open', value: stages.open, tab: 'open' },
        { key: 'investigating', label: 'Being investigated', value: stages.investigating, tab: 'investigating' },
        { key: 'corrective_action', label: 'Actions under way', value: stages.corrective_action, tab: 'corrective_actions' },
        { key: 'monitoring', label: 'Checking it worked', value: stages.monitoring, tab: 'monitoring' },
        { key: 'closed_30d', label: 'Closed (30 days)', value: stages.closed_30d, tab: 'closed' },
    ];
    return (
        <Surface title="Where safety reviews are up to" sub="Open now · closed in the last 30 days · each number opens the list">
            <div className="grid grid-cols-2 border-t border-border sm:grid-cols-5">
                {cells.map((c): ReactNode => (
                    <Link
                        key={c.key}
                        href={link('/health-safety/events', { tab: c.tab })}
                        className="grid gap-0.5 border-border px-[18px] py-3.5 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring sm:border-r sm:last:border-r-0"
                    >
                        <span className="text-section-title tabular-nums">{c.value}</span>
                        <span className="text-subtle">{c.label}</span>
                    </Link>
                ))}
            </div>
        </Surface>
    );
}
