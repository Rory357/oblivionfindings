/* Medication › Settings › Change history (eMAR P11 v5): "Still to decide"
 * (settings nobody has deliberately chosen) and "All changes" (Fleet
 * ChangeHistory: EntityTable with Previous/Next), both with the kebab and
 * the right-click menu. */
import { EntityChip } from '@/components/lists/entity-cells';
import {
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ArrowUpRight,
    Building2,
    Eye,
    HelpCircle,
    History,
    Home,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import { useSettings } from './_context';
import { historyScope, historyWhat, reviewItems, whenText } from './_dialogs';
import {
    canRestore,
    stillToDecide,
    VIEW_LABEL,
    type HistoryEntry,
    type Pending,
} from './_model';
import { sectionLabel } from './_nav';
import { DefaultNotReviewed, NotConfigured, RowMenu, Section } from './_ui';

export type HistoryFilters = { area: string; who: string; where: string };
export const HISTORY_FILTERS: HistoryFilters = {
    area: 'all',
    who: 'all',
    where: 'all',
};

const match = (q: string, ...s: (string | null | undefined)[]) =>
    !q || s.some((x) => (x ?? '').toLowerCase().includes(q.toLowerCase()));

export function StillToDecide({ q }: { q: string }) {
    const { s, go, open, canEdit } = useSettings();
    const menu = useEntityContextMenu<Pending>();
    const all = stillToDecide(s);
    const rows = all.filter((r) =>
        match(q, r.label, r.scope ?? 'All houses', r.until),
    );
    const canKeep = (r: Pending) => r.state === 'default' && canEdit(r.group);
    const actions = (r: Pending): MenuItem[] =>
        compactMenu([
            {
                label: 'Go to the setting',
                icon: ArrowUpRight,
                onClick: () => go(r.view, r.section),
            },
            canKeep(r) && {
                label: 'Keep today’s value',
                icon: ShieldCheck,
                onClick: () =>
                    open({ kind: 'keep', group: r.group, key: r.key }),
            },
        ]);
    return (
        <Section
            id="sc-decide"
            title="Still to decide"
            caption={`${rows.length} settings nobody has deliberately chosen`}
            right={
                reviewItems(s, canEdit).some((i) => i.keepable) ? (
                    <Button
                        size="sm"
                        onClick={() => open({ kind: 'reviewdefaults' })}
                    >
                        <ShieldCheck />
                        Review the defaults one by one
                    </Button>
                ) : undefined
            }
        >
            <p className="text-subtle">
                “Not configured” means screens give no value. “Default — not yet
                reviewed” means today’s behaviour carries on until someone saves
                a choice — or confirms it with “Keep today’s value” in the ⋯
                menu.
            </p>
            {rows.length ? (
                <EntityTable<Pending>
                    rows={rows}
                    rowKey={(r) => `${r.group}.${r.key}`}
                    identityLabel="Setting"
                    identityWidth="3fr"
                    minWidth={1000}
                    rowHeight="content"
                    identity={(r) => ({
                        icon: HelpCircle,
                        name: r.label,
                        subline: r.scope ?? 'All houses',
                    })}
                    columns={[
                        {
                            key: 'where',
                            label: 'Where',
                            width: '1.2fr',
                            cell: (r) => (
                                <span className="py-2 text-[12.5px]">
                                    {VIEW_LABEL[r.view]} ›{' '}
                                    {sectionLabel(r.view, r.section)}
                                </span>
                            ),
                        },
                        {
                            key: 'state',
                            label: 'State',
                            width: '1fr',
                            cell: (r) =>
                                r.state === 'nc' ? (
                                    <NotConfigured />
                                ) : (
                                    <DefaultNotReviewed />
                                ),
                        },
                        {
                            key: 'until',
                            label: 'Until it’s decided',
                            width: '1.5fr',
                            cell: (r) => (
                                <span className="py-2 text-[12.5px]">
                                    {r.until}
                                </span>
                            ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(r) => go(r.view, r.section)}
                    onRowContextMenu={menu.open}
                />
            ) : all.length ? (
                <EmptyState
                    icon={HelpCircle}
                    title={`Nothing still to decide matches “${q}”`}
                    description="Clear the search to see every setting still to decide."
                />
            ) : (
                <EmptyState
                    icon={ShieldCheck}
                    title="Nothing left to decide"
                    description="Every setting has been deliberately chosen. Changes still show in All changes."
                />
            )}
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={HelpCircle}
                title={(r) => r.label}
                items={actions}
            />
        </Section>
    );
}

const PER_PAGE = 8;

export function AllChanges({
    q,
    clearQ,
    filters,
    setFilters,
    page,
    setPage,
}: {
    q: string;
    clearQ: () => void;
    filters: HistoryFilters;
    setFilters: (f: HistoryFilters) => void;
    page: number;
    setPage: (n: number) => void;
}) {
    const { s, draft, go, open, canEdit, freshAfter } = useSettings();
    const menu = useEntityContextMenu<HistoryEntry>();
    const all = s.history;
    const rows = all.filter(
        (h) =>
            (filters.area === 'all' || h.view === filters.area) &&
            (filters.who === 'all' || h.who === filters.who) &&
            (filters.where === 'all' || historyScope(h) === filters.where) &&
            match(q, historyWhat(h), h.after_text, h.who),
    );
    const pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
    const pg = Math.min(page, pages);
    const slice = rows.slice((pg - 1) * PER_PAGE, pg * PER_PAGE);
    const actions = (h: HistoryEntry): MenuItem[] =>
        compactMenu([
            {
                label: 'View before and after',
                icon: Eye,
                onClick: () => open({ kind: 'hist', id: h.id }),
            },
            {
                label: 'Go to the setting',
                icon: ArrowUpRight,
                onClick: () => go(h.view, h.section),
            },
            canRestore(s, draft, h, canEdit) && {
                label: 'Put the earlier value back',
                icon: RotateCcw,
                onClick: () => open({ kind: 'restore', id: h.id }),
            },
        ]);
    if (!all.length)
        return (
            <Section
                id="sc-changes"
                title="All changes"
                caption="Every saved setting, newest first · also in the audit log"
            >
                <EmptyState
                    icon={History}
                    title="No saved changes yet"
                    description="Saved settings appear here with who changed them and when. Operational records — doses, rounds, assessments — keep their own histories."
                />
            </Section>
        );
    return (
        <Section
            id="sc-changes"
            title="All changes"
            caption={`${rows.length} of ${all.length} shown · newest first · also in the audit log`}
        >
            {rows.length ? (
                <>
                    <EntityTable<HistoryEntry>
                        rows={slice}
                        rowKey={(h) => h.id}
                        identityLabel="What changed"
                        identityWidth="2fr"
                        minWidth={960}
                        rowHeight="content"
                        identity={(h) => ({
                            icon: History,
                            name: historyWhat(h),
                            subline: `${h.before_text && h.before_text !== '—' ? `${h.before_text} → ` : ''}${h.after_text}`,
                            extra:
                                h.id > freshAfter || h.loosens ? (
                                    <span className="flex shrink-0 gap-1">
                                        {h.id > freshAfter ? (
                                            <StatusBadge
                                                variant="info"
                                                size="sm"
                                                className="whitespace-nowrap"
                                            >
                                                Just now
                                            </StatusBadge>
                                        ) : null}
                                        {h.loosens ? (
                                            <StatusBadge
                                                variant="warning"
                                                size="sm"
                                                className="whitespace-nowrap"
                                            >
                                                Loosened a check
                                            </StatusBadge>
                                        ) : null}
                                    </span>
                                ) : undefined,
                        })}
                        columns={[
                            {
                                key: 'when',
                                label: 'When (NZ time)',
                                width: '1fr',
                                cell: (h) => (
                                    <span className="text-[13px]">
                                        {whenText(h.at)}
                                    </span>
                                ),
                            },
                            {
                                key: 'who',
                                label: 'Who',
                                width: '1fr',
                                cell: (h) => (
                                    <div>
                                        <div className="text-[13px]">
                                            {h.who ?? 'Someone'}
                                        </div>
                                        {h.note ? (
                                            <div className="text-caption">
                                                {h.note}
                                            </div>
                                        ) : null}
                                    </div>
                                ),
                            },
                            {
                                key: 'where',
                                label: 'Where',
                                width: '0.9fr',
                                cell: (h) => (
                                    <EntityChip
                                        icon={
                                            h.site_id === null
                                                ? Building2
                                                : Home
                                        }
                                    >
                                        {historyScope(h)}
                                    </EntityChip>
                                ),
                            },
                            {
                                key: 'area',
                                label: 'Area',
                                width: '0.9fr',
                                cell: (h) => (
                                    <span className="text-[13px]">
                                        {VIEW_LABEL[h.view]}
                                    </span>
                                ),
                            },
                        ]}
                        actionsFor={actions}
                        onOpen={(h) => open({ kind: 'hist', id: h.id })}
                        onRowContextMenu={menu.open}
                    />
                    <div className="flex items-center justify-end gap-3">
                        <span className="text-caption">
                            Page {pg} of {pages}
                        </span>
                        <Button
                            variant="outline"
                            disabled={pg <= 1}
                            onClick={() => setPage(pg - 1)}
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            disabled={pg >= pages}
                            onClick={() => setPage(pg + 1)}
                        >
                            Next
                        </Button>
                    </div>
                </>
            ) : (
                <EmptyState
                    icon={History}
                    title="No changes match these filters"
                    description="Clear the filters or the search."
                    action={
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                clearQ();
                                setFilters({
                                    area: 'all',
                                    who: 'all',
                                    where: 'all',
                                });
                            }}
                        >
                            Clear filters
                        </Button>
                    }
                />
            )}
            <RowMenu
                ctx={menu.ctx}
                close={menu.close}
                icon={History}
                title={(h) => historyWhat(h)}
                items={actions}
            />
        </Section>
    );
}
