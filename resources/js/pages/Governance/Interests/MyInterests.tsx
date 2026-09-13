import { Head } from '@inertiajs/react';
import { FileText, Info, Plus, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityChip,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    type EntityTableColumn,
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
import { PageProps } from '@/types';

import {
    DeclareInterestDialog,
    INTEREST_TYPES,
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

type StandingFilter = 'all' | 'current' | 'ceased';

export default function MyInterests({
    auth,
    interests,
    boardMember,
    canDeclare,
}: Props) {
    // Declaring also needs the manage permission the store route requires.
    const declareAllowed = Boolean(
        canDeclare && boardMember && auth.can?.governance?.interests?.manage,
    );
    const [declareOpen, setDeclareOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [type, setType] = useState('all');
    const [standing, setStanding] = useState<StandingFilter>('all');

    const currentCount = interests.filter((i) => i.is_active).length;

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return interests.filter(
            (i) =>
                (type === 'all' || i.interest_type === type) &&
                (standing === 'all' ||
                    (standing === 'current' ? i.is_active : !i.is_active)) &&
                (q === '' ||
                    i.nature_of_interest.toLowerCase().includes(q) ||
                    (i.organization_name ?? '').toLowerCase().includes(q) ||
                    i.description.toLowerCase().includes(q)),
        );
    }, [interests, search, type, standing]);

    const hasFilters = search.trim() !== '' || type !== 'all' || standing !== 'all';
    const clearFilters = () => {
        setSearch('');
        setType('all');
        setStanding('all');
    };

    const columns: EntityTableColumn<InterestRecord>[] = [
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
            icon={FileText}
            title="My interests"
            subline="Your personal declarations of interest on the board register"
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
                            Declare interest
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
                            recorded on your board record
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Current"
                        ariaLabel="View my current declarations"
                        onClick={() => setStanding('current')}
                    >
                        <PageHeaderMeterBig>{currentCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {interests.length - currentCount} ceased
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Board register"
                        ariaLabel="View the board interests register"
                        href="/governance/interests"
                    >
                        <PageHeaderMeterBig>
                            {boardMember ? 'Linked' : 'Not linked'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {boardMember
                                ? 'your declarations appear there'
                                : 'no board-member record yet'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
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
                    <PageHeaderFilterSelect
                        label="Current & ceased"
                        value={standing}
                        options={[
                            { value: 'all', label: 'Current & ceased' },
                            { value: 'current', label: 'Current only' },
                            { value: 'ceased', label: 'Ceased only' },
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
                { title: 'Interests', href: '/governance/interests' },
                { title: 'My interests', href: '/governance/interests/mine' },
            ]}
        >
            <Head title="My interests" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {!canDeclare ? (
                        <EmptyState
                            variant="inline"
                            icon={Info}
                            title="Your account is not linked to an active board-member record yet, so personal interest declarations are unavailable."
                        />
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
                            icon={FileText}
                            title={
                                hasFilters
                                    ? 'No declarations match your filters'
                                    : 'No interests declared'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : declareAllowed
                                      ? 'Declare any interest that could conflict with your board duties.'
                                      : 'No personal interest declarations are available for this account.'
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" size="sm" onClick={clearFilters}>
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
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
                            minWidth={760}
                        />
                    )}
                </div>
            </PageLayout>

            {declareAllowed && boardMember ? (
                <DeclareInterestDialog
                    open={declareOpen}
                    onClose={() => setDeclareOpen(false)}
                    boardMemberId={boardMember.id}
                />
            ) : null}
        </AppLayout>
    );
}
