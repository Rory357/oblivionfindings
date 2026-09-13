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
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import type { StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ExternalLink, FolderOpen, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { GenerateBoardPackDialog, type MeetingWithoutPack } from './_dialogs';

interface Pack {
    id: number;
    meeting_id: number;
    revision_number: number;
    supersedes_id: number | null;
    build_status: string;
    is_current: boolean;
    actual_document_count: number;
    meeting: {
        id: number;
        title: string;
        scheduled_at: string;
        meeting_type: string;
    } | null;
    generatedBy: { id: number; name: string } | null;
    distributed_at: string | null;
    created_at: string;
    updated_at: string;
    read_count?: number;
    download_count?: number;
}

interface Props extends PageProps {
    packs: {
        data: Pack[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        status: string | null;
    };
    summary: {
        total: number;
        distributed: number;
        draft: number;
        superseded?: number;
        failed?: number;
    };
    meetings_without_pack: MeetingWithoutPack[];
}

const ALL = '__all';

export function packState(pack: {
    build_status: string;
    is_current: boolean;
    distributed_at: string | null;
}): { variant: StatusVariant; label: string } {
    if (pack.build_status === 'failed')
        return { variant: 'critical', label: 'Failed' };
    if (!pack.is_current) return { variant: 'neutral', label: 'Superseded' };
    if (pack.distributed_at) return { variant: 'success', label: 'Distributed' };
    return { variant: 'warning', label: 'Draft' };
}

const humanise = (value: string) =>
    value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ');

export default function PacksIndex({
    auth,
    packs,
    filters,
    summary,
    meetings_without_pack,
}: Props) {
    const [generateOpen, setGenerateOpen] = useState(false);
    const ctxMenu = useEntityContextMenu<Pack>();
    const canManagePacks = Boolean(auth.can?.governance?.packs?.manage);

    // 'current' is the legacy alias of 'distributed' on the server.
    const statusValue =
        filters.status === 'current' ? 'distributed' : (filters.status ?? ALL);

    const setStatus = (status: string | null) => {
        router.get(
            '/governance/packs',
            status ? { status } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const open = (pack: Pack) => router.visit(`/governance/packs/${pack.id}`);
    const actionsFor = (pack: Pack): MenuItem[] =>
        compactMenu([
            {
                label: 'Open pack',
                icon: ExternalLink,
                onClick: () => open(pack),
            },
        ]);
    const titleFor = (pack: Pack) => pack.meeting?.title ?? 'Untitled meeting';

    const statusOptions = [
        { value: ALL, label: `All packs (${summary.total})` },
        { value: 'distributed', label: `Current (${summary.distributed})` },
        { value: 'draft', label: `Draft (${summary.draft})` },
        {
            value: 'superseded',
            label: `Superseded (${summary.superseded ?? 0})`,
        },
        ...((summary.failed ?? 0) > 0 || filters.status === 'failed'
            ? [{ value: 'failed', label: `Failed (${summary.failed ?? 0})` }]
            : []),
    ];

    const columns = [
        {
            key: 'state',
            label: 'Status',
            width: '0.8fr',
            cell: (pack: Pack) => {
                const state = packState(pack);
                return (
                    <EntityStatusChip variant={state.variant}>
                        {state.label}
                    </EntityStatusChip>
                );
            },
        },
        {
            key: 'revision',
            label: 'Revision',
            width: '0.6fr',
            cell: (pack: Pack) => (
                <EntityChip>Rev {pack.revision_number ?? 1}</EntityChip>
            ),
        },
        {
            key: 'type',
            label: 'Meeting type',
            width: '0.9fr',
            cell: (pack: Pack) =>
                pack.meeting?.meeting_type ? (
                    humanise(pack.meeting.meeting_type)
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'papers',
            label: 'Papers & docs',
            width: '0.7fr',
            align: 'right' as const,
            cell: (pack: Pack) => (
                <span className="tabular-nums">
                    {pack.actual_document_count}
                </span>
            ),
        },
        {
            key: 'distributed',
            label: 'Distributed',
            width: '0.9fr',
            cell: (pack: Pack) =>
                pack.distributed_at ? (
                    formatDateLong(pack.distributed_at)
                ) : (
                    <EmptyValue />
                ),
        },
        ...(canManagePacks
            ? [
                  {
                      key: 'engagement',
                      label: 'Reads · downloads',
                      width: '0.9fr',
                      cell: (pack: Pack) =>
                          pack.read_count !== undefined ? (
                              <span className="tabular-nums">
                                  {pack.read_count} ·{' '}
                                  {pack.download_count ?? 0}
                              </span>
                          ) : (
                              <EmptyValue />
                          ),
                  },
              ]
            : []),
    ];

    const header = (
        <PageHeader
            icon={FolderOpen}
            title="Board Packs"
            subline="Immutable, audience-safe packs assembled for meetings · decision papers, snapshots and reading receipts"
            actions={
                canManagePacks ? (
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setGenerateOpen(true)}
                        dusk="generate-board-pack-button"
                    >
                        Generate board pack
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total packs"
                        href="/governance/packs"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            All editions you can read
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Current"
                        href="/governance/packs?status=distributed"
                        tone={summary.distributed > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {summary.distributed}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Distributed to members
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Draft"
                        href="/governance/packs?status=draft"
                        tone={summary.draft > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            In preparation
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Superseded"
                        href="/governance/packs?status=superseded"
                    >
                        <PageHeaderMeterBig>
                            {summary.superseded ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Archived editions
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {(summary.failed ?? 0) > 0 ? (
                        <PageHeaderMeterBlock
                            label="Failed"
                            href="/governance/packs?status=failed"
                            tone="critical"
                        >
                            <PageHeaderMeterBig>
                                {summary.failed}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Builds needing a retry
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Status"
                    value={statusValue}
                    allValue={ALL}
                    options={statusOptions}
                    onChange={(value) => setStatus(value === ALL ? null : value)}
                />
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Board packs', href: '/governance/packs' },
            ]}
        >
            <Head title="Board Packs" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Board packs"
                        caption={`${packs.data.length} of ${packs.total} shown`}
                    />

                    {packs.data.length === 0 ? (
                        <EmptyState
                            icon={FolderOpen}
                            title={
                                filters.status
                                    ? 'No board packs match this filter'
                                    : 'No board packs yet'
                            }
                            description="Board packs are generated from scheduled meetings with frozen snapshots and decision papers."
                            action={
                                filters.status ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setStatus(null)}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filter
                                    </Button>
                                ) : canManagePacks ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setGenerateOpen(true)}
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        Generate from a meeting
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={packs.data}
                            rowKey={(pack) => pack.id}
                            identityLabel="Meeting"
                            identity={(pack) => ({
                                icon: FolderOpen,
                                name: titleFor(pack),
                                subline: pack.meeting?.scheduled_at
                                    ? `Scheduled ${formatDateLong(pack.meeting.scheduled_at)}`
                                    : 'Not scheduled',
                            })}
                            hrefFor={(pack) => `/governance/packs/${pack.id}`}
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            columns={columns}
                        />
                    )}

                    {packs.links ? (
                        <LaravelPagination
                            links={packs.links}
                            lastPage={packs.last_page}
                            preserveScroll
                        />
                    ) : null}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={FolderOpen}
                    title={titleFor(ctxMenu.ctx.record)}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManagePacks && (
                <GenerateBoardPackDialog
                    isOpen={generateOpen}
                    onClose={() => setGenerateOpen(false)}
                    meetings={meetings_without_pack ?? []}
                />
            )}
        </AppLayout>
    );
}
