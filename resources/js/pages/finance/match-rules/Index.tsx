import { ConfirmDialog, FinanceSectionRail } from '@/components/finance';
import {
    EntityChip,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Pencil, Plus, Trash2, Wand2 } from 'lucide-react';
import { useMemo, useState } from 'react';

import {
    MatchRuleDialog,
    RULE_TYPES,
    ruleTypeLabel,
    type MatchRuleRecord,
} from './_dialogs';

type MatchRule = MatchRuleRecord & {
    match_count: number;
    created_by_name: string | null;
    created_at: string;
};

type PageProps = {
    rules: MatchRule[];
    unreconciledTransactions: number;
};

const ALL = '__all';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Settings', href: '/finance/settings' },
    { title: 'Match rules', href: '/finance/match-rules' },
];

export default function MatchRulesIndex({
    rules,
    unreconciledTransactions = 0,
}: PageProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editRule, setEditRule] = useState<MatchRule | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<MatchRule | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [search, setSearch] = useState('');
    const [ruleType, setRuleType] = useState(ALL);
    const [includeInactive, setIncludeInactive] = useState(true);
    const ctxMenu = useEntityContextMenu<MatchRule>();

    const activeCount = rules.filter((rule) => rule.is_active).length;
    const totalMatches = rules.reduce((sum, rule) => sum + rule.match_count, 0);

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/finance/match-rules/${deleteTarget.id}`, {
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const actionsFor = (rule: MatchRule): MenuItem[] =>
        compactMenu([
            {
                label: 'Edit rule',
                icon: Pencil,
                onClick: () => setEditRule(rule),
            },
            {
                label: 'Open payment matching',
                icon: ArrowLeftRight,
                onClick: () => router.visit('/finance/payment-matching'),
            },
            { separator: true },
            {
                label: 'Delete rule',
                icon: Trash2,
                danger: true,
                onClick: () => setDeleteTarget(rule),
            },
        ]);

    const term = search.trim().toLowerCase();
    const visible = useMemo(
        () =>
            rules.filter((rule) => {
                if (!includeInactive && !rule.is_active) return false;
                if (ruleType !== ALL && rule.rule_type !== ruleType)
                    return false;
                if (!term) return true;
                return [rule.name, ruleTypeLabel(rule.rule_type)]
                    .join(' ')
                    .toLowerCase()
                    .includes(term);
            }),
        [rules, includeInactive, ruleType, term],
    );

    const clearFilters = () => {
        setSearch('');
        setRuleType(ALL);
        setIncludeInactive(true);
    };

    const columns: EntityTableColumn<MatchRule>[] = [
        {
            key: 'type',
            label: 'Rule type',
            width: '1fr',
            cell: (rule) => (
                <EntityChip>{ruleTypeLabel(rule.rule_type)}</EntityChip>
            ),
        },
        {
            key: 'priority',
            label: 'Priority',
            width: '0.6fr',
            align: 'right',
            cell: (rule) => (
                <span className="tabular-nums">{rule.priority}</span>
            ),
        },
        {
            key: 'threshold',
            label: 'Auto-confirm',
            width: '0.8fr',
            align: 'right',
            cell: (rule) => (
                <span className="tabular-nums">
                    {rule.auto_confirm_threshold}%
                </span>
            ),
        },
        {
            key: 'matches',
            label: 'Matches made',
            width: '0.8fr',
            align: 'right',
            cell: (rule) => (
                <span className="tabular-nums">{rule.match_count}</span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (rule) => (
                <StatusBadge status={rule.is_active ? 'active' : 'inactive'} />
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={Wand2}
            title="Match rules"
            titleChip={
                <PageHeaderStatusChip
                    variant={activeCount > 0 ? 'success' : 'warning'}
                >
                    {activeCount} active
                </PageHeaderStatusChip>
            }
            subline={`Finance settings · ${rules.length} rules · ${totalMatches} matches made`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search rules…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setCreateOpen(true)}
                    >
                        Add rule
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Rules"
                        href="/finance/match-rules"
                        ariaLabel="View every match rule"
                    >
                        <PageHeaderMeterBig>{rules.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Evaluated in priority order
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        onClick={() => setIncludeInactive(false)}
                        ariaLabel="Show only active rules"
                    >
                        <PageHeaderMeterBig>{activeCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {rules.length - activeCount} switched off
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Matches made"
                        href="/finance/payment-matching"
                        ariaLabel="View payment matching"
                    >
                        <PageHeaderMeterBig>{totalMatches}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Suggestions raised by these rules
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Unreconciled"
                        tone={
                            unreconciledTransactions > 0 ? 'warning' : 'brand'
                        }
                        href="/finance/bank-transactions?status=unreconciled"
                        ariaLabel="View unreconciled bank transactions"
                    >
                        <PageHeaderMeterBig>
                            {unreconciledTransactions}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Bank lines these rules run against
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Rule type"
                        value={ruleType}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any rule type' },
                            ...RULE_TYPES,
                        ]}
                        onChange={setRuleType}
                    />
                    <PageHeaderFilterCheck
                        label="Include inactive"
                        checked={includeInactive}
                        onChange={setIncludeInactive}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Match rules" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {rules.length === 0 ? (
                        <EmptyList
                            icon={Wand2}
                            itemName="match rule"
                            title="No match rules yet"
                            description="Add a rule to control how bank transactions are matched to bills and invoices, and when a match confirms itself."
                            action={
                                <Button
                                    size="sm"
                                    onClick={() => setCreateOpen(true)}
                                >
                                    Add rule
                                </Button>
                            }
                        />
                    ) : (
                        <>
                            <ListCaption
                                title="Match rules"
                                caption={`${visible.length} of ${rules.length} shown`}
                            />
                            {visible.length === 0 ? (
                                <EmptySearch
                                    onClear={clearFilters}
                                    title="No rules match your filters"
                                />
                            ) : (
                                <EntityTable
                                    rows={visible}
                                    rowKey={(rule) => rule.id}
                                    identityLabel="Rule"
                                    identity={(rule) => ({
                                        icon: Wand2,
                                        name: rule.name,
                                        subline: rule.created_by_name
                                            ? `Added by ${rule.created_by_name} · ${rule.created_at}`
                                            : `Added ${rule.created_at}`,
                                    })}
                                    columns={columns}
                                    actionsFor={actionsFor}
                                    onOpen={(rule) => setEditRule(rule)}
                                    onRowContextMenu={ctxMenu.open}
                                    mutedFor={(rule) => !rule.is_active}
                                    minWidth={1020}
                                />
                            )}
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Wand2}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <MatchRuleDialog
                open={createOpen}
                onClose={() => setCreateOpen(false)}
            />

            {editRule ? (
                <MatchRuleDialog
                    key={editRule.id}
                    open
                    rule={editRule}
                    onClose={() => setEditRule(null)}
                />
            ) : null}

            <ConfirmDialog
                open={!!deleteTarget}
                onClose={() => setDeleteTarget(null)}
                title="Delete match rule?"
                description={
                    <>
                        This permanently deletes{' '}
                        <span className="font-medium text-foreground">
                            {deleteTarget?.name}
                        </span>
                        . New bank transactions will no longer be matched by it.
                    </>
                }
                confirmText="Delete rule"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
