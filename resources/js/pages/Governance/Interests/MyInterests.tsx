import { Head } from '@inertiajs/react';
import { CalendarX2, Eye, FileText, Info, Pencil, Plus, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { InfoCard } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';

import {
    DeclareInterestDialog,
    EndInterestDialog,
    INTEREST_TYPES,
    InterestDetailsDialog,
    interestChip,
    interestPeriod,
    interestTypeIcon,
    interestTypeLabel,
    type InterestRecord,
} from './_dialogs';

interface Props extends PageProps {
    interests: InterestRecord[];
    boardMember: { id: number } | null;
    canDeclare: boolean;
}

type StatusFilter = 'all' | 'current' | 'ended';

/** Why this person can't declare, in words that say who can help — or null. */
export function declarationBlockedReason(
    hasBoardRecord: boolean,
    canManageInterests: boolean,
): string | null {
    if (!hasBoardRecord) {
        return "You're not listed as a board member yet, so you can't declare interests here. Ask the board secretary to add you as a board member.";
    }
    if (!canManageInterests) {
        return "Your account can't add declarations yet. Ask the board secretary to give you access.";
    }
    return null;
}

export default function MyInterests({
    auth,
    interests,
    boardMember,
    canDeclare,
}: Props) {
    const canManageInterests = Boolean(auth.can?.governance?.interests?.manage);
    // Declaring also needs the manage permission the store route requires.
    const declareAllowed = Boolean(canDeclare && boardMember && canManageInterests);
    const blockedReason = declarationBlockedReason(
        Boolean(canDeclare && boardMember),
        canManageInterests,
    );
    const [declareOpen, setDeclareOpen] = useState(false);
    const [editing, setEditing] = useState<InterestRecord | null>(null);
    const [ending, setEnding] = useState<InterestRecord | null>(null);
    const [viewing, setViewing] = useState<InterestRecord | null>(null);
    const [search, setSearch] = useState('');
    const [type, setType] = useState('all');
    const [status, setStatus] = useState<StatusFilter>('all');
    const ctxMenu = useEntityContextMenu<InterestRecord>();

    const currentCount = interests.filter((i) => i.is_active).length;
    const endedCount = interests.length - currentCount;

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return interests.filter(
            (i) =>
                (type === 'all' || i.interest_type === type) &&
                (status === 'all' ||
                    (status === 'current' ? i.is_active : !i.is_active)) &&
                (q === '' ||
                    i.nature_of_interest.toLowerCase().includes(q) ||
                    (i.organization_name ?? '').toLowerCase().includes(q) ||
                    i.description.toLowerCase().includes(q)),
        );
    }, [interests, search, type, status]);

    const hasFilters = search.trim() !== '' || type !== 'all' || status !== 'all';
    const clearFilters = () => {
        setSearch('');
        setType('all');
        setStatus('all');
    };

    const actionsFor = (i: InterestRecord): MenuItem[] =>
        compactMenu(
            i.can_update
                ? [
                      {
                          label: 'Update details',
                          icon: Pencil,
                          onClick: () => setEditing(i),
                      },
                      i.is_active
                          ? {
                                label: 'This interest has ended…',
                                icon: CalendarX2,
                                onClick: () => setEnding(i),
                            }
                          : null,
                  ]
                : [{ label: 'View', icon: Eye, onClick: () => setViewing(i) }],
        );

    const columns: EntityTableColumn<InterestRecord>[] = [
        {
            key: 'type',
            label: 'Kind',
            width: '0.8fr',
            cell: (i) => (
                <EntityChip icon={interestTypeIcon(i.interest_type)}>
                    {interestTypeLabel(i.interest_type)}
                </EntityChip>
            ),
        },
        {
            key: 'effect',
            label: 'How it could affect decisions',
            width: '1.4fr',
            cell: (i) =>
                i.description ? (
                    <span className="truncate" title={i.description}>
                        {i.description}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'period',
            label: 'When',
            width: '1fr',
            cell: (i) => interestPeriod(i),
        },
        {
            key: 'declared',
            label: 'Declared',
            width: '0.7fr',
            cell: (i) =>
                i.declared_at ? formatDateOnly(i.declared_at) : <EmptyValue />,
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.6fr',
            cell: (i) => {
                const chip = interestChip(i.is_active);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
        },
    ];

    const header = (
        <PageHeader
            icon={FileText}
            title="My interests"
            subline="Your declarations of interest. Keep them up to date, and mark an interest as ended when it stops."
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search my declarations…"
                    />
                    {declareAllowed ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            dusk="declare-interest"
                            onClick={() => setDeclareOpen(true)}
                        >
                            Declare an interest
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Declarations"
                        ariaLabel="View all my declarations"
                        onClick={clearFilters}
                    >
                        <PageHeaderMeterBig>{interests.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Everything you have declared
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Current"
                        ariaLabel="View my current declarations"
                        onClick={() => setStatus('current')}
                    >
                        <PageHeaderMeterBig>{currentCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            On the board’s register now
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Ended"
                        ariaLabel="View my ended declarations"
                        onClick={() => setStatus('ended')}
                    >
                        <PageHeaderMeterBig>{endedCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Kept for the record
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Kind"
                        value={type}
                        allValue="all"
                        options={[
                            { value: 'all', label: 'Any kind' },
                            ...INTEREST_TYPES.map((t) => ({
                                value: t.key,
                                label: interestTypeLabel(t.key),
                            })),
                        ]}
                        onChange={setType}
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={status}
                        allValue="all"
                        options={[
                            { value: 'all', label: 'Current and ended' },
                            { value: 'current', label: 'Current' },
                            { value: 'ended', label: 'Ended' },
                        ]}
                        onChange={(v) => setStatus(v as StatusFilter)}
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Interests', href: '/governance/interests' },
                { title: 'My interests', href: '/governance/interests/mine' },
            ]}
        >
            <Head title="My interests" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {blockedReason ? (
                        <InfoCard icon={Info}>{blockedReason}</InfoCard>
                    ) : null}

                    <ListCaption
                        title="My declarations"
                        caption={`${visible.length} of ${interests.length} shown`}
                        right={
                            hasFilters ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={clearFilters}
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Clear filters
                                </Button>
                            ) : null
                        }
                    />

                    {visible.length === 0 ? (
                        <EmptyState
                            icon={FileText}
                            title={
                                hasFilters
                                    ? 'No declarations match your filters'
                                    : 'You have not declared any interests'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : declareAllowed
                                      ? 'Declare anything that could affect, or look like it affects, your board decisions.'
                                      : (blockedReason ?? 'Nothing to show yet.')
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" size="sm" onClick={clearFilters}>
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : declareAllowed ? (
                                    <Button size="sm" onClick={() => setDeclareOpen(true)}>
                                        <Plus className="h-3.5 w-3.5" />
                                        Declare an interest
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={visible}
                            rowKey={(i) => i.id}
                            identityLabel="Organisation or person"
                            identity={(i) => ({
                                icon: interestTypeIcon(i.interest_type),
                                name: i.organization_name ?? i.nature_of_interest,
                                subline: i.nature_of_interest,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            onOpen={(i) =>
                                i.can_update ? setEditing(i) : setViewing(i)
                            }
                            onRowContextMenu={(e, i) => ctxMenu.open(e, i)}
                            mutedFor={(i) => !i.is_active}
                            minWidth={900}
                        />
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={FileText}
                    title={
                        ctxMenu.ctx.record.organization_name ??
                        ctxMenu.ctx.record.nature_of_interest
                    }
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {declareAllowed && boardMember ? (
                <DeclareInterestDialog
                    open={declareOpen}
                    onClose={() => setDeclareOpen(false)}
                    boardMemberId={boardMember.id}
                />
            ) : null}
            <DeclareInterestDialog
                open={editing !== null}
                onClose={() => setEditing(null)}
                interest={editing}
            />
            <EndInterestDialog interest={ending} onClose={() => setEnding(null)} />
            <InterestDetailsDialog
                interest={viewing}
                onClose={() => setViewing(null)}
            />
        </AppLayout>
    );
}
