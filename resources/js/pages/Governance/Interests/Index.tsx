import { Head } from '@inertiajs/react';
import {
    CalendarX2,
    ClipboardList,
    Eye,
    Pencil,
    Plus,
    UserRound,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
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

interface BoardMember {
    id: number;
    user: { id: number; name: string } | null;
}

interface Props extends PageProps {
    interestsByMember: Record<string, InterestRecord[]> | [];
    boardMembers: BoardMember[];
    myBoardMemberId: number | null;
}

export default function InterestsIndex({
    auth,
    interestsByMember,
    boardMembers,
    myBoardMemberId,
}: Props) {
    const canDeclare = Boolean(
        auth.can?.governance?.interests?.manage && myBoardMemberId,
    );
    const [declareOpen, setDeclareOpen] = useState(false);
    const [editing, setEditing] = useState<InterestRecord | null>(null);
    const [ending, setEnding] = useState<InterestRecord | null>(null);
    const [viewing, setViewing] = useState<InterestRecord | null>(null);
    const [search, setSearch] = useState('');
    const [member, setMember] = useState('all');
    const [type, setType] = useState('all');
    const ctxMenu = useEntityContextMenu<InterestRecord>();

    const memberName = (memberId: string | number, fallback?: string | null) =>
        boardMembers.find((m) => String(m.id) === String(memberId))?.user
            ?.name ??
        fallback ??
        'Former board member';

    const interests = useMemo(
        () =>
            Object.entries(interestsByMember).flatMap(([memberId, list]) =>
                (list as InterestRecord[]).map((interest) => ({
                    ...interest,
                    board_member_id: Number(memberId),
                    member_name: memberName(memberId, interest.member_name),
                })),
            ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [interestsByMember, boardMembers],
    );

    const financialCount = interests.filter(
        (i) => i.interest_type === 'financial',
    ).length;
    const myCount = myBoardMemberId
        ? interests.filter((i) => i.board_member_id === myBoardMemberId).length
        : 0;

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return interests.filter(
            (i) =>
                (member === 'all' || String(i.board_member_id) === member) &&
                (type === 'all' || i.interest_type === type) &&
                (q === '' ||
                    i.nature_of_interest.toLowerCase().includes(q) ||
                    (i.organization_name ?? '').toLowerCase().includes(q) ||
                    (i.member_name ?? '').toLowerCase().includes(q) ||
                    i.description.toLowerCase().includes(q)),
        );
    }, [interests, search, member, type]);

    const hasFilters = search.trim() !== '' || member !== 'all' || type !== 'all';
    const clearFilters = () => {
        setSearch('');
        setMember('all');
        setType('all');
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
                      {
                          label: 'This interest has ended…',
                          icon: CalendarX2,
                          onClick: () => setEnding(i),
                      },
                  ]
                : [{ label: 'View', icon: Eye, onClick: () => setViewing(i) }],
        );

    const columns: EntityTableColumn<InterestRecord>[] = [
        {
            key: 'member',
            label: 'Board member',
            width: '1fr',
            cell: (i) => <PersonCell name={i.member_name} />,
        },
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
            label: 'Started',
            width: '0.9fr',
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
            icon={ClipboardList}
            title="Interests"
            subline="Board members' current declarations of interest — things that could affect, or look like they affect, their decisions"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search interests, organisations or members…"
                    />
                    {canDeclare ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
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
                        ariaLabel="View all current declarations"
                        onClick={clearFilters}
                    >
                        <PageHeaderMeterBig>{interests.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            On the register now
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Financial"
                        ariaLabel="View financial interests"
                        onClick={() => setType('financial')}
                    >
                        <PageHeaderMeterBig>{financialCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Shares, loans or paid roles
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="My declarations"
                        ariaLabel="View my declarations"
                        href="/governance/interests/mine"
                    >
                        <PageHeaderMeterBig>{myCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {myBoardMemberId
                                ? 'Current, on your record'
                                : "You're not listed as a board member"}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={UserRound}
                        label="Board member"
                        value={member}
                        allValue="all"
                        options={[
                            { value: 'all', label: 'Any board member' },
                            ...boardMembers.map((m) => ({
                                value: String(m.id),
                                label: m.user?.name ?? 'Board member',
                            })),
                        ]}
                        onChange={setMember}
                    />
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
            ]}
        >
            <Head title="Interests" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Current declarations"
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
                            icon={ClipboardList}
                            title={
                                hasFilters
                                    ? 'No declarations match your filters'
                                    : 'No interests declared yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'When board members declare an interest, it appears here.'
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" size="sm" onClick={clearFilters}>
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : canDeclare ? (
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
                            minWidth={1040}
                        />
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={ClipboardList}
                    title={
                        ctxMenu.ctx.record.organization_name ??
                        ctxMenu.ctx.record.nature_of_interest
                    }
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canDeclare && myBoardMemberId ? (
                <DeclareInterestDialog
                    open={declareOpen}
                    onClose={() => setDeclareOpen(false)}
                    boardMemberId={myBoardMemberId}
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
