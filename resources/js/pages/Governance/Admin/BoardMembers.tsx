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
import { PageProps } from '@/types';

import {
    BOARD_ROLES,
    BoardMemberWizardDialog,
    boardRoleLabel,
    dateOnly,
    type BoardMemberRecord,
    type BoardMemberUser,
} from './_dialogs';

interface Props extends PageProps {
    boardMembers: BoardMemberRecord[];
    availableUsers: BoardMemberUser[];
}

type StandingFilter = 'all' | 'active' | 'inactive' | 'ending';

const DAY_MS = 24 * 60 * 60 * 1000;

export default function ManageBoardMembers({
    auth,
    boardMembers,
    availableUsers,
}: Props) {
    const [search, setSearch] = useState('');
    const [role, setRole] = useState('all');
    const [standing, setStanding] = useState<StandingFilter>('all');
    const [wizard, setWizard] = useState<{
        open: boolean;
        member: BoardMemberRecord | null;
    }>({ open: false, member: null });
    const [removing, setRemoving] = useState<BoardMemberRecord | null>(null);
    const ctxMenu = useEntityContextMenu<BoardMemberRecord>();

    const today = new Date().toISOString().split('T')[0];
    const in90Days = new Date(Date.now() + 90 * DAY_MS)
        .toISOString()
        .split('T')[0];
    const endingSoon = (m: BoardMemberRecord) => {
        const end = dateOnly(m.term_end);
        return m.is_active && end !== '' && end >= today && end <= in90Days;
    };

    const activeCount = boardMembers.filter((m) => m.is_active).length;
    const endingCount = boardMembers.filter(endingSoon).length;
    const votingSeats = boardMembers.filter(
        (m) => m.is_active && m.board_role !== 'observer',
    ).length;

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return boardMembers.filter((m) => {
            if (role !== 'all' && m.board_role !== role) return false;
            if (standing === 'active' && !m.is_active) return false;
            if (standing === 'inactive' && m.is_active) return false;
            if (standing === 'ending' && !endingSoon(m)) return false;
            if (q === '') return true;
            return (
                (m.user?.name ?? '').toLowerCase().includes(q) ||
                (m.user?.email ?? '').toLowerCase().includes(q)
            );
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [boardMembers, search, role, standing]);

    const hasFilters = search.trim() !== '' || role !== 'all' || standing !== 'all';
    const clearFilters = () => {
        setSearch('');
        setRole('all');
        setStanding('all');
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
            width: '0.8fr',
            cell: (m) => <EntityChip>{boardRoleLabel(m.board_role)}</EntityChip>,
        },
        {
            key: 'term',
            label: 'Term',
            width: '1.3fr',
            cell: (m) =>
                m.term_start ? (
                    <span>
                        {formatDateOnly(dateOnly(m.term_start))} →{' '}
                        {m.term_end
                            ? formatDateOnly(dateOnly(m.term_end))
                            : 'Ongoing'}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (m) =>
                endingSoon(m) ? (
                    <EntityStatusChip variant="warning">Term ending</EntityStatusChip>
                ) : (
                    <EntityStatusChip variant={m.is_active ? 'success' : 'neutral'}>
                        {m.is_active ? 'Active' : 'Inactive'}
                    </EntityStatusChip>
                ),
        },
    ];

    const header = (
        <PageHeader
            icon={Users}
            title="Board members"
            subline="Appointments, roles and terms for the governing board"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search members by name or email…"
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
                        onClick={() => {
                            setStanding('all');
                            setRole('all');
                        }}
                    >
                        <PageHeaderMeterBig>{boardMembers.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {boardMembers.length - activeCount} inactive
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active"
                        value={`${activeCount}/${boardMembers.length}`}
                        ariaLabel="View active board members"
                        onClick={() => setStanding('active')}
                    >
                        <PageHeaderMeterBar
                            percent={
                                boardMembers.length > 0
                                    ? (activeCount / boardMembers.length) * 100
                                    : 0
                            }
                        />
                        <PageHeaderMeterCaption>
                            {votingSeats} voting seats filled
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Terms ending"
                        tone={endingCount > 0 ? 'warning' : 'success'}
                        ariaLabel="View terms ending within 90 days"
                        onClick={() => setStanding('ending')}
                    >
                        <PageHeaderMeterBig>{endingCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            within the next 90 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Eligible staff"
                        ariaLabel="Appoint a board member"
                        onClick={() => setWizard({ open: true, member: null })}
                    >
                        <PageHeaderMeterBig>{availableUsers.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            not yet on the board
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="All roles"
                        value={role}
                        options={[
                            { value: 'all', label: 'All roles' },
                            ...BOARD_ROLES.map((r) => ({
                                value: r.key,
                                label: r.label,
                            })),
                        ]}
                        onChange={setRole}
                    />
                    <PageHeaderFilterSelect
                        label="Any standing"
                        value={standing}
                        options={[
                            { value: 'all', label: 'Any standing' },
                            { value: 'active', label: 'Active' },
                            { value: 'inactive', label: 'Inactive' },
                            { value: 'ending', label: 'Term ending ≤ 90 days' },
                        ]}
                        onChange={(v) => setStanding(v as StandingFilter)}
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
                        title="Current appointments"
                        caption={`${visible.length} of ${boardMembers.length} shown`}
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
                            icon={Users}
                            title={
                                hasFilters
                                    ? 'No board members match your filters'
                                    : 'No board members appointed yet'
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
                                name: m.user?.name ?? 'Unknown user',
                                subline: m.user?.email ?? 'No email recorded',
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            onOpen={(m) => setWizard({ open: true, member: m })}
                            onRowContextMenu={(e, m) => ctxMenu.open(e, m)}
                            mutedFor={(m) => !m.is_active}
                            minWidth={720}
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
                title="Remove this board member?"
                description={`${removing?.user?.name ?? 'This member'} will be marked inactive and removed from the board. They can be re-appointed later.`}
                confirmText="Remove from board"
            />
        </AppLayout>
    );
}
