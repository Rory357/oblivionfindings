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
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong } from '@/lib/datetime';
import {
    governanceStatus,
    meetingTypeLabel,
    type GovernanceStatusChip,
} from '@/lib/governance-labels';
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
    my_read_at?: string | null;
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
    read_count?: number | null;
    download_count?: number | null;
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
        unread?: number;
    };
    is_recipient?: boolean;
    next_meeting_pack?: {
        id: number;
        meeting_title: string;
        scheduled_at: string | null;
        read: boolean;
    } | null;
    meetings_without_pack: MeetingWithoutPack[];
}

const ALL = '__all';

/** One status chip per pack, from the shared Governance labels. */
export function packState(pack: {
    build_status: string;
    is_current: boolean;
    distributed_at: string | null;
}): GovernanceStatusChip {
    if (pack.build_status === 'failed')
        return governanceStatus('board_pack_status', 'failed');
    if (!pack.is_current)
        return governanceStatus('board_pack_status', 'superseded');
    if (pack.distributed_at)
        return governanceStatus('board_pack_status', 'distributed');
    return governanceStatus('board_pack_status', 'draft');
}

export default function PacksIndex({
    auth,
    packs,
    filters,
    summary,
    is_recipient = false,
    next_meeting_pack = null,
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
    const titleFor = (pack: Pack) =>
        pack.meeting?.title ? `${pack.meeting.title}` : 'Meeting not found';

    const statusOptions = canManagePacks
        ? [
              { value: ALL, label: `All packs (${summary.total})` },
              {
                  value: 'distributed',
                  label: `Sent to members (${summary.distributed})`,
              },
              { value: 'draft', label: `Draft (${summary.draft})` },
              {
                  value: 'superseded',
                  label: `Replaced by a newer version (${summary.superseded ?? 0})`,
              },
              ...((summary.failed ?? 0) > 0 || filters.status === 'failed'
                  ? [
                        {
                            value: 'failed',
                            label: `Couldn't be prepared (${summary.failed ?? 0})`,
                        },
                    ]
                  : []),
          ]
        : [
              { value: ALL, label: `All packs (${summary.total})` },
              ...(is_recipient
                  ? [
                        {
                            value: 'unread',
                            label: `Not yet read by you (${summary.unread ?? 0})`,
                        },
                    ]
                  : []),
          ];

    const columns: EntityTableColumn<Pack>[] = [
        {
            key: 'state',
            label: 'Status',
            width: '1fr',
            cell: (pack) => {
                const state = packState(pack);
                return (
                    <EntityStatusChip variant={state.variant}>
                        {state.label}
                    </EntityStatusChip>
                );
            },
        },
        {
            key: 'version',
            label: 'Version',
            width: '0.6fr',
            cell: (pack) => (
                <EntityChip>Version {pack.revision_number ?? 1}</EntityChip>
            ),
        },
        ...(is_recipient
            ? [
                  {
                      key: 'reading',
                      label: 'Your reading',
                      width: '0.9fr',
                      cell: (pack: Pack) =>
                          pack.my_read_at ? (
                              <EntityStatusChip variant="success">
                                  Read {formatDateLong(pack.my_read_at)}
                              </EntityStatusChip>
                          ) : pack.distributed_at ? (
                              <EntityStatusChip variant="warning">
                                  Not read
                              </EntityStatusChip>
                          ) : (
                              <EmptyValue />
                          ),
                  },
              ]
            : []),
        {
            key: 'type',
            label: 'Meeting type',
            width: '0.9fr',
            cell: (pack) =>
                pack.meeting?.meeting_type ? (
                    meetingTypeLabel(pack.meeting.meeting_type)
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'distributed',
            label: 'Sent to members',
            width: '0.9fr',
            cell: (pack) =>
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
                      label: 'Read · downloaded',
                      width: '0.9fr',
                      cell: (pack: Pack) =>
                          pack.read_count != null ? (
                              <span className="tabular-nums">
                                  {pack.read_count} · {pack.download_count ?? 0}
                              </span>
                          ) : (
                              <EmptyValue />
                          ),
                  },
              ]
            : []),
    ];

    const memberMeters = (
        <>
            <PageHeaderMeterBlock
                label="Next meeting pack"
                href={
                    next_meeting_pack
                        ? `/governance/packs/${next_meeting_pack.id}`
                        : '/governance/packs'
                }
                tone={
                    next_meeting_pack && !next_meeting_pack.read
                        ? 'warning'
                        : 'brand'
                }
                ariaLabel={
                    next_meeting_pack
                        ? `Open the pack for ${next_meeting_pack.meeting_title}`
                        : 'View all board packs'
                }
            >
                <PageHeaderMeterBig>
                    {next_meeting_pack?.scheduled_at
                        ? formatDateLong(next_meeting_pack.scheduled_at)
                        : 'None yet'}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {next_meeting_pack
                        ? `${next_meeting_pack.meeting_title} · ${next_meeting_pack.read ? 'Read' : 'Not read yet'}`
                        : 'No pack sent for an upcoming meeting'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            {is_recipient ? (
                <PageHeaderMeterBlock
                    label="Not yet read by you"
                    href="/governance/packs?status=unread"
                    tone={(summary.unread ?? 0) > 0 ? 'warning' : 'brand'}
                    ariaLabel="View packs you haven't read yet"
                >
                    <PageHeaderMeterBig>{summary.unread ?? 0}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {(summary.unread ?? 0) === 0
                            ? "You're up to date"
                            : 'Confirm once you have read them'}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
            <PageHeaderMeterBlock
                label="All packs"
                href="/governance/packs"
                ariaLabel="View all board packs"
            >
                <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>Sent to you</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );

    const managerMeters = (
        <>
            <PageHeaderMeterBlock
                label="Sent to members"
                href="/governance/packs?status=distributed"
                tone={summary.distributed > 0 ? 'success' : 'brand'}
            >
                <PageHeaderMeterBig>{summary.distributed}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    The version members are reading
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Draft"
                href="/governance/packs?status=draft"
            >
                <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>Not sent yet</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Replaced"
                href="/governance/packs?status=superseded"
            >
                <PageHeaderMeterBig>{summary.superseded ?? 0}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Earlier versions kept for the record
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="All packs" href="/governance/packs">
                <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>Every version</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            {(summary.failed ?? 0) > 0 ? (
                <PageHeaderMeterBlock
                    label="Couldn't be prepared"
                    href="/governance/packs?status=failed"
                    tone="critical"
                >
                    <PageHeaderMeterBig>{summary.failed}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        Create a new version to try again
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
        </>
    );

    const header = (
        <PageHeader
            icon={FolderOpen}
            title="Board packs"
            subline="The reading pack for each board meeting"
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
            meters={canManagePacks ? managerMeters : memberMeters}
            filters={
                <PageHeaderFilterSelect
                    label="Show"
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
            <Head title="Board packs" />

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
                            description={
                                filters.status === 'unread'
                                    ? "You've confirmed reading every pack sent to you."
                                    : canManagePacks
                                      ? 'Generate a pack from a meeting once its agenda is ready.'
                                      : 'Packs appear here once the secretary sends them for a meeting.'
                            }
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
                                        Generate board pack
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
                                    ? `Meeting on ${formatDateLong(pack.meeting.scheduled_at)}`
                                    : 'Date not set',
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
