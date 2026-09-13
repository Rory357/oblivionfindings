import { Head } from '@inertiajs/react';
import { ClipboardList, Plus, UserRound, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityChip,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
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
import { PageProps } from '@/types';

import {
    DeclareInterestDialog,
    INTEREST_TYPES,
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
    const [search, setSearch] = useState('');
    const [member, setMember] = useState('all');
    const [type, setType] = useState('all');

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

    const declaringMembers = new Set(interests.map((i) => i.board_member_id));
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

    const columns: EntityTableColumn<InterestRecord>[] = [
        {
            key: 'member',
            label: 'Board member',
            width: '1.1fr',
            cell: (i) => <PersonCell name={i.member_name} />,
        },
        {
            key: 'type',
            label: 'Type',
            width: '0.8fr',
            cell: (i) => (
                <EntityChip icon={interestTypeIcon(i.interest_type)}>
                    {interestTypeLabel(i.interest_type)}
                </EntityChip>
            ),
        },
        {
            key: 'organisation',
            label: 'Organisation',
            width: '1fr',
            cell: (i) =>
                i.organization_name ? (
                    <span className="truncate">{i.organization_name}</span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'period',
            label: 'Period',
            width: '1.1fr',
            cell: (i) => interestPeriod(i),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.6fr',
            cell: (i) => (
                <EntityStatusChip variant={i.is_active ? 'success' : 'neutral'}>
                    {i.is_active ? 'Current' : 'Ceased'}
                </EntityStatusChip>
            ),
        },
    ];

    const header = (
        <PageHeader
            icon={ClipboardList}
            title="Interests register"
            subline="Current declarations of interest across the board"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search interests, organisations, members…"
                    />
                    {canDeclare ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setDeclareOpen(true)}
                        >
                            Declare interest
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
                            current interests on the register
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Members declaring"
                        value={`${declaringMembers.size}/${boardMembers.length}`}
                        ariaLabel="View declarations by board member"
                        onClick={clearFilters}
                    >
                        <PageHeaderMeterBar
                            percent={
                                boardMembers.length > 0
                                    ? (declaringMembers.size / boardMembers.length) *
                                      100
                                    : 0
                            }
                        />
                        <PageHeaderMeterCaption>
                            of active board members
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Financial"
                        ariaLabel="View financial interests"
                        onClick={() => setType('financial')}
                    >
                        <PageHeaderMeterBig>{financialCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            shares, loans or paid roles
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
                                ? 'current on your record'
                                : 'no board-member record linked'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={UserRound}
                        label="All members"
                        value={member}
                        options={[
                            { value: 'all', label: 'All members' },
                            ...boardMembers.map((m) => ({
                                value: String(m.id),
                                label: m.user?.name ?? `Member #${m.id}`,
                            })),
                        ]}
                        onChange={setMember}
                    />
                    <PageHeaderFilterSelect
                        label="All types"
                        value={type}
                        options={[
                            { value: 'all', label: 'All types' },
                            ...INTEREST_TYPES.map((t) => ({
                                value: t.key,
                                label: t.label,
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
            <Head title="Interests register" />
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
                                    className="text-xs text-muted-foreground"
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
                                    : 'Declarations made by board members appear here.'
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
                                        Declare interest
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={visible}
                            rowKey={(i) => i.id}
                            identityLabel="Interest"
                            identity={(i) => ({
                                icon: interestTypeIcon(i.interest_type),
                                name: i.nature_of_interest,
                                subline: i.description,
                            })}
                            columns={columns}
                            actionsFor={() => []}
                            minWidth={900}
                        />
                    )}
                </div>
            </PageLayout>

            {canDeclare && myBoardMemberId ? (
                <DeclareInterestDialog
                    open={declareOpen}
                    onClose={() => setDeclareOpen(false)}
                    boardMemberId={myBoardMemberId}
                />
            ) : null}
        </AppLayout>
    );
}
