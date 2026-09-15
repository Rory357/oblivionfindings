import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, UserMinus, Users, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonDisc,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
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
import { formatDateOnly } from '@/lib/datetime';
import { boardRoleLabel, governanceStatus } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import {
    BOARD_ROLES,
    BoardMemberWizardDialog,
    dateOnly,
    type BoardMemberRecord,
    type BoardMemberUser,
} from './_dialogs';

interface Props extends PageProps {
    boardMembers: BoardMemberRecord[];
    availableUsers: BoardMemberUser[];
    canInvitePeople?: boolean;
    endingSoonDays?: number;
}

type StatusFilter = 'all' | 'active' | 'inactive' | 'ending';

/** One chip per appointment, from the shared Governance labels. */
export function appointmentChip(member: BoardMemberRecord) {
    if (member.ending_soon) {
        return { label: 'Term ending soon', variant: 'warning' as const };
    }
    return governanceStatus(
        'board_member_standing',
        member.standing ?? (member.is_active ? 'active' : 'inactive'),
    );
}

export default function ManageBoardMembers({
    auth,
    boardMembers,
    availableUsers,
    canInvitePeople = false,
    endingSoonDays = 90,
}: Props) {
    const [search, setSearch] = useState('');
    const [role, setRole] = useState('all');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [wizard, setWizard] = useState<{
        open: boolean;
        member: BoardMemberRecord | null;
    }>({ open: false, member: null });
    const [removing, setRemoving] = useState<BoardMemberRecord | null>(null);
    const ctxMenu = useEntityContextMenu<BoardMemberRecord>();

    const isActive = (m: BoardMemberRecord) =>
        (m.standing ?? (m.is_active ? 'active' : 'inactive')) === 'active';
    const activeCount = boardMembers.filter(isActive).length;
    const endingCount = boardMembers.filter((m) => m.ending_soon).length;
    const votingCount = boardMembers.filter((m) => m.can_vote).length;

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return boardMembers.filter((m) => {
            if (role !== 'all' && m.board_role !== role) return false;
            if (status === 'active' && !isActive(m)) return false;
            if (status === 'inactive' && isActive(m)) return false;
            if (status === 'ending' && !m.ending_soon) return false;
            if (q === '') return true;
            return (
                (m.user?.name ?? '').toLowerCase().includes(q) ||
                (m.user?.email ?? '').toLowerCase().includes(q)
            );
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [boardMembers, search, role, status]);

    const hasFilters = search.trim() !== '' || role !== 'all' || status !== 'all';
    const clearFilters = () => {
        setSearch('');
        setRole('all');
        setStatus('all');
    };

    const actionsFor = (m: BoardMemberRecord): MenuItem[] =>
        compactMenu([
            {
                label: 'Edit appointment',
                icon: Pencil,
                onClick: () => setWizard({ open: true, member: m }),
            },
            { separator: true },
            {
                label: 'Remove from board',
                icon: UserMinus,
                danger: true,
                onClick: () => setRemoving(m),
            },
        ]);

    const columns: EntityTableColumn<BoardMemberRecord>[] = [
        {
            key: 'role',
            label: 'Role',
            width: '0.9fr',
            cell: (m) => <EntityChip>{boardRoleLabel(m.board_role)}</EntityChip>,
        },
        {
            key: 'term',
            label: 'Term',
            width: '1.3fr',
            cell: (m) =>
                m.term_start ? (
                    <span>
                        {formatDateOnly(dateOnly(m.term_start))} –{' '}
                        {m.term_end
                            ? formatDateOnly(dateOnly(m.term_end))
                            : 'ongoing'}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.9fr',
            cell: (m) => {
                const chip = appointmentChip(m);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
        },
        {
            key: 'vote',
            label: 'Can vote',
            width: '0.6fr',
            cell: (m) => (m.can_vote ? 'Yes' : 'No'),
        },
    ];

    const header = (
        <PageHeader
            icon={Users}
            title="Board members"
            subline={`Who is on the board, their roles and their terms · ${votingCount} can vote`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search by name or email…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setWizard({ open: true, member: null })}
                    >
                        Appoint member
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Appointments"
                        ariaLabel="View all board appointments"
                        onClick={clearFilters}
                    >
                        <PageHeaderMeterBig>{boardMembers.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {boardMembers.length - activeCount} not current
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Current members"
                        value={`${activeCount} of ${boardMembers.length}`}
                        ariaLabel="View current board members"
                        onClick={() => setStatus('active')}
                    >
                        <PageHeaderMeterBar
                            percent={
                                boardMembers.length > 0
                                    ? (activeCount / boardMembers.length) * 100
                                    : 0
                            }
                        />
                        <PageHeaderMeterCaption>
                            {votingCount} can vote
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Terms ending soon"
                        tone={endingCount > 0 ? 'warning' : 'brand'}
                        ariaLabel={`View terms ending in the next ${endingSoonDays} days`}
                        onClick={() => setStatus('ending')}
                    >
                        <PageHeaderMeterBig>{endingCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            In the next {endingSoonDays} days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Role"
                        value={role}
                        allValue="all"
                        options={[
                            { value: 'all', label: 'Any role' },
                            ...BOARD_ROLES.map((r) => ({
                                value: r.key,
                                label: boardRoleLabel(r.key),
                            })),
                        ]}
                        onChange={setRole}
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={status}
                        allValue="all"
                        options={[
                            { value: 'all', label: 'Any status' },
                            { value: 'active', label: 'Active' },
                            { value: 'inactive', label: 'Inactive' },
                            { value: 'ending', label: 'Term ending soon' },
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
                {
                    title: 'Board members',
                    href: '/governance/admin/board-members',
                },
            ]}
        >
            <Head title="Board members" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Appointments"
                        caption={`${visible.length} of ${boardMembers.length} shown`}
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
                            icon={Users}
                            title={
                                hasFilters
                                    ? 'No board members match your filters'
                                    : 'No one has been appointed yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'Appoint the chair, secretary and members to set up the board.'
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" size="sm" onClick={clearFilters}>
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            setWizard({ open: true, member: null })
                                        }
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        Appoint member
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={visible}
                            rowKey={(m) => m.id}
                            identityLabel="Member"
                            identity={(m) => ({
                                mark: <PersonDisc name={m.user?.name} size={30} />,
                                name: m.user?.name ?? 'Person without a login',
                                subline: m.user?.email ?? 'No email recorded',
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            onOpen={(m) => setWizard({ open: true, member: m })}
                            onRowContextMenu={(e, m) => ctxMenu.open(e, m)}
                            mutedFor={(m) => !isActive(m)}
                            minWidth={760}
                        />
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Users}
                    title={ctxMenu.ctx.record.user?.name ?? 'Board member'}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <BoardMemberWizardDialog
                open={wizard.open}
                member={wizard.member}
                availableUsers={availableUsers}
                canInvitePeople={canInvitePeople}
                onClose={() => setWizard({ open: false, member: null })}
            />

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (!removing) return;
                    router.delete(
                        `/governance/admin/board-members/${removing.id}`,
                        { preserveScroll: true },
                    );
                }}
                title="Remove this person from the board?"
                description={`${removing?.user?.name ?? 'This person'} stops being a board member straight away. They lose board access that comes from this appointment, including voting and board packs. You can appoint them again later.`}
                confirmText="Remove from board"
                variant="destructive"
            />
        </AppLayout>
    );
}
