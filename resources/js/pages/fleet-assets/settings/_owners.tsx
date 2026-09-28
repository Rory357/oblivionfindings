import {
    EntityContextMenu,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTimeLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Activity,
    ArrowUpRight,
    Building2,
    Car,
    ClipboardList,
    Database,
    Eye,
    FileUp,
    History,
    MapPin,
    Radio,
    Settings2,
    Shield,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Page, Policy } from './_types';
import { api, Modal, Notice, Sections } from './_ui';

type Device = {
    id: number;
    name: string;
    model: string | null;
    firmware: string | null;
    lastContact: string | null;
    href: string;
};
function useRemote<T>(path: string | null) {
    const [data, setData] = useState<T | null>(null),
        [error, setError] = useState(''),
        [retry, setRetry] = useState(0);
    useEffect(() => {
        if (!path) return;
        let current = true;
        const request = new AbortController();
        setData(null);
        setError('');
        const timer = setTimeout(
            () =>
                api<T>(path, 'GET', undefined, request.signal)
                    .then((result) => {
                        if (current) setData(result);
                    })
                    .catch((problem) => {
                        if (current) setError(problem.message);
                    }),
            200,
        );
        return () => {
            current = false;
            request.abort();
            clearTimeout(timer);
        };
    }, [path, retry]);
    return { data, error, reload: () => setRetry((value) => value + 1) };
}
export function Tracking({
    policies,
    query,
    canViewDevices,
}: {
    policies: Policy[];
    query: string;
    canViewDevices: boolean;
}) {
    const [section, setSection] = useState('overview'),
        [page, setPage] = useState(1),
        [policy, setPolicy] = useState<Policy | null>(null),
        [device, setDevice] = useState<Device | null>(null);
    const devices = useRemote<Page<Device>>(
        canViewDevices && section === 'devices'
            ? `tracking-devices?q=${encodeURIComponent(query)}&page=${page}`
            : null,
    );
    const context = useEntityContextMenu<Policy>(),
        deviceContext = useEntityContextMenu<Device>();
    useEffect(() => setPage(1), [query]);
    const policyActions = (row: Policy) => [
        {
            label: 'Review interpretation',
            icon: Eye,
            onClick: () => setPolicy(row),
        },
    ];
    const deviceActions = (row: Device) => [
        {
            label: 'Review reporting details',
            icon: Eye,
            onClick: () => setDevice(row),
        },
        {
            label: 'Open device controls',
            icon: ArrowUpRight,
            onClick: () => router.visit(row.href),
        },
    ];
    return (
        <div className="space-y-5">
            <Sections
                value={section}
                onChange={setSection}
                tabs={[
                    { key: 'overview', label: 'Overview', icon: Activity },
                    {
                        key: 'devices',
                        label: 'Devices & reporting',
                        icon: Radio,
                    },
                    { key: 'policies', label: 'Data policies', icon: Database },
                    {
                        key: 'profiles',
                        label: 'Profiles & controls',
                        icon: Settings2,
                    },
                ]}
            />
            {section === 'overview' && (
                <>
                    <ListCaption
                        title="Tracking & data"
                        caption="Source-owned settings and evidence"
                    />
                    <div className="grid gap-5 lg:grid-cols-2">
                        <ReviewCard
                            icon={Radio}
                            title="Contact is not a location"
                        >
                            <p className="text-subtle">
                                A tracker can contact the service without a
                                usable fix. Review the last contact and last
                                permitted position separately in the device or
                                vehicle record.
                            </p>
                            <Button
                                variant="link"
                                onClick={() => setSection('devices')}
                            >
                                Review devices{' '}
                                <ArrowUpRight className="size-4" />
                            </Button>
                        </ReviewCard>
                        <ReviewCard
                            icon={MapPin}
                            title="Unknown and last known"
                        >
                            <p className="text-subtle">
                                Delayed reports, sleeping devices, stale
                                positions and privacy-withheld data have
                                different meanings. A missing position is never
                                shown as a current one.
                            </p>
                            <Button
                                variant="link"
                                onClick={() => setSection('policies')}
                            >
                                Review interpretation{' '}
                                <ArrowUpRight className="size-4" />
                            </Button>
                        </ReviewCard>
                        <ReviewCard
                            icon={Settings2}
                            title="Each tracker has its own capabilities"
                        >
                            <p className="text-subtle">
                                Model, firmware, connection and protocol
                                determine available controls. Review
                                compatibility, approval and verification in the
                                existing device workflow.
                            </p>
                            <Button
                                variant="link"
                                onClick={() => setSection('profiles')}
                            >
                                Review device control ownership{' '}
                                <ArrowUpRight className="size-4" />
                            </Button>
                        </ReviewCard>
                        <ReviewCard
                            icon={Shield}
                            title="Personal location authority"
                        >
                            <p className="text-subtle">
                                Client Location and People Locations own
                                personal source selection, assignment, consent
                                and history. Fleet settings cannot change those
                                permissions.
                            </p>
                        </ReviewCard>
                    </div>
                    <Notice>
                        Map accuracy is only meaningful when the source reports
                        supported units. A dilution-of-precision value is not an
                        accuracy distance. Review source evidence before
                        interpreting it as metres.
                    </Notice>
                </>
            )}
            {section === 'devices' && (
                <>
                    <ListCaption
                        title="Permitted trackers"
                        caption={
                            canViewDevices && devices.data
                                ? `${devices.data.data.length} of ${devices.data.total} shown`
                                : 'Canonical device visibility'
                        }
                    />
                    {!canViewDevices ? (
                        <EmptyState
                            icon={Shield}
                            title="Device view is not available for your role"
                            description="Your Fleet device permissions are required to view this directory."
                        />
                    ) : devices.error ? (
                        <Notice>
                            {devices.error}{' '}
                            <Button variant="outline" onClick={devices.reload}>
                                Retry
                            </Button>
                        </Notice>
                    ) : !devices.data ? (
                        <SkeletonTable />
                    ) : devices.data.data.length ? (
                        <>
                            <EntityTable
                                rows={devices.data.data}
                                rowKey={(row) => row.id}
                                identity={(row) => ({
                                    icon: Radio,
                                    name: row.name,
                                    subline: row.model || 'Model not recorded',
                                })}
                                identityLabel="Tracker"
                                minWidth={740}
                                actionsFor={deviceActions}
                                onOpen={setDevice}
                                onRowContextMenu={deviceContext.open}
                                columns={[
                                    {
                                        key: 'firmware',
                                        label: 'Firmware',
                                        width: '1fr',
                                        cell: (row) =>
                                            row.firmware || 'Not recorded',
                                    },
                                    {
                                        key: 'contact',
                                        label: 'Last contact',
                                        width: '1.4fr',
                                        cell: (row) =>
                                            row.lastContact
                                                ? formatDateTimeLong(
                                                      row.lastContact,
                                                  )
                                                : 'Not recorded',
                                    },
                                    {
                                        key: 'position',
                                        label: 'Position evidence',
                                        width: '1.1fr',
                                        cell: () => (
                                            <span className="text-caption">
                                                Review source record
                                            </span>
                                        ),
                                    },
                                ]}
                            />
                            <div className="flex items-center justify-end gap-3">
                                <Button
                                    variant="outline"
                                    disabled={page <= 1}
                                    onClick={() => setPage(page - 1)}
                                >
                                    Previous
                                </Button>
                                <span className="text-caption">
                                    Page {page} of {devices.data.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    disabled={page >= devices.data.last_page}
                                    onClick={() => setPage(page + 1)}
                                >
                                    Next
                                </Button>
                            </div>
                        </>
                    ) : (
                        <EmptyState
                            icon={Radio}
                            title="No matching permitted trackers"
                            description="Change the search, or review device assignment in the source workspace."
                        />
                    )}
                    <Notice>
                        This directory shows contact metadata only. Open the
                        source record for permitted location evidence,
                        capability details and command status.
                    </Notice>
                </>
            )}
            {section === 'policies' && (
                <>
                    <ListCaption
                        title="Effective data policies"
                        caption="Configured values · source-owned changes"
                    />
                    <EntityTable
                        rows={policies.filter((row) =>
                            (row.title + row.owner)
                                .toLowerCase()
                                .includes(query.toLowerCase()),
                        )}
                        rowKey={(row) => row.key}
                        identity={(row) => ({
                            icon: Database,
                            name: row.title,
                            subline: row.owner,
                        })}
                        minWidth={720}
                        actionsFor={policyActions}
                        onOpen={setPolicy}
                        onRowContextMenu={context.open}
                        columns={[
                            {
                                key: 'value',
                                label: 'Current value',
                                width: '1fr',
                                cell: (row) => row.value,
                            },
                            {
                                key: 'meaning',
                                label: 'Interpretation',
                                width: '2fr',
                                cell: (row) => (
                                    <span className="text-subtle">
                                        {row.detail}
                                    </span>
                                ),
                            },
                        ]}
                    />
                    <Notice>
                        Raw device messages, personal tracking and audit
                        evidence use separate retention controls. These
                        configured values do not confirm a cleanup job ran, and
                        changing a tracker profile does not change retention.
                    </Notice>
                </>
            )}
            {section === 'profiles' && (
                <>
                    <ListCaption
                        title="Device configuration ownership"
                        caption="Use the existing governed controls"
                    />
                    <ReviewCard
                        icon={Settings2}
                        title="Review compatible profiles in the device record"
                    >
                        <p className="text-subtle">
                            The device owner checks the exact model and
                            firmware, available actions and current
                            configuration. A batch contains a separate governed
                            request for each compatible tracker.
                        </p>
                        <ReviewRow
                            label="Requested"
                            value="A command request exists; it is not yet applied."
                        />
                        <ReviewRow
                            label="Acknowledged"
                            value="The device responded; configuration still needs verification."
                        />
                        <ReviewRow
                            label="Confirmed"
                            value="Source evidence verifies the intended configuration."
                        />
                        <ReviewRow
                            label="Failed or disconnected"
                            value="Review that device's result before retrying or waiting for reconnect."
                        />
                        <Button
                            variant="outline"
                            className="mt-4"
                            disabled={!canViewDevices}
                            onClick={() =>
                                router.visit('/fleet-assets/devices')
                            }
                        >
                            Open tracker directory{' '}
                            <ArrowUpRight className="size-4" />
                        </Button>
                    </ReviewCard>
                    <Notice>
                        Settings does not dispatch commands or create another
                        profile store. Existing device permissions, step-up
                        checks, approvals and verification remain in force.
                    </Notice>
                </>
            )}
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={context.ctx.record.title}
                    items={policyActions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {deviceContext.ctx && (
                <EntityContextMenu
                    x={deviceContext.ctx.x}
                    y={deviceContext.ctx.y}
                    title={deviceContext.ctx.record.name}
                    items={deviceActions(deviceContext.ctx.record)}
                    onClose={deviceContext.close}
                />
            )}
            {policy && (
                <Modal
                    title={policy.title}
                    description={policy.owner}
                    onClose={() => setPolicy(null)}
                >
                    <ReviewRow label="Current value" value={policy.value} />
                    <p className="text-subtle">{policy.detail}</p>
                </Modal>
            )}
            {device && (
                <Modal
                    title={device.name}
                    description="Canonical device metadata"
                    onClose={() => setDevice(null)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDevice(null)}
                            >
                                Close
                            </Button>
                            <Button onClick={() => router.visit(device.href)}>
                                Open device record
                            </Button>
                        </>
                    }
                >
                    <ReviewRow label="Model" value={device.model} />
                    <ReviewRow label="Firmware" value={device.firmware} />
                    <ReviewRow
                        label="Last contact"
                        value={
                            device.lastContact
                                ? formatDateTimeLong(device.lastContact)
                                : 'Not recorded'
                        }
                    />
                    <Notice>
                        Last contact does not establish a usable fix. The source
                        record owns permitted position evidence and supported
                        controls.
                    </Notice>
                </Modal>
            )}
        </div>
    );
}

const setupRows = [
    {
        key: 'vehicles',
        title: 'Vehicles',
        detail: 'Create a canonical vehicle, review compliance and operational responsibility, then link tracking if required.',
        href: '/fleet-assets/vehicles',
        owner: 'Fleet',
        icon: Car,
    },
    {
        key: 'assets',
        title: 'Assets & categories',
        detail: 'Create manual assets first. Keep identity, categories, ownership and custody in the asset register.',
        href: '/fleet-assets/assets',
        owner: 'Assets',
        icon: ClipboardList,
    },
    {
        key: 'sites',
        title: 'Sites & rooms',
        detail: 'Use the approved Site and room directories. An asset keeps its canonical location and access rules.',
        href: '/sites',
        owner: 'Sites',
        icon: Building2,
    },
    {
        key: 'maintenance',
        title: 'Responsibilities & reminders',
        detail: 'Review service schedules, assigned work and due dates with the maintenance owner. Unknown compliance stays unknown.',
        href: '/fleet-assets/maintenance/work-orders',
        owner: 'Maintenance',
        icon: Settings2,
    },
    {
        key: 'import',
        title: 'Import inventory',
        detail: 'Use the existing importer to upload, map, validate and review duplicates. Accepted rows are retained when rejected rows are corrected or retried.',
        href: '/fleet-assets/assets?view=imports',
        owner: 'Assets import',
        icon: FileUp,
    },
    {
        key: 'boundaries',
        title: 'Maps & boundaries',
        detail: 'Manage shared geometry and source-specific uses. Map-provider configuration does not change these records.',
        href: '/fleet-assets/geofences',
        owner: 'Maps & boundaries',
        icon: MapPin,
    },
];
export function Setup({ query }: { query: string }) {
    const [detail, setDetail] = useState<(typeof setupRows)[number] | null>(
            null,
        ),
        context = useEntityContextMenu<(typeof setupRows)[number]>();
    const actions = (row: (typeof setupRows)[number]) => [
        {
            label: 'Review setup responsibility',
            icon: Eye,
            onClick: () => setDetail(row),
        },
        {
            label: `Open ${row.owner}`,
            icon: ArrowUpRight,
            onClick: () => router.visit(row.href),
        },
    ];
    const rows = setupRows.filter((row) =>
        (row.title + row.detail).toLowerCase().includes(query.toLowerCase()),
    );
    return (
        <div className="space-y-5">
            <ListCaption
                title="Setup & ongoing changes"
                caption="Manual-first records · optional tracking"
            />
            <EntityTable
                rows={rows}
                rowKey={(row) => row.key}
                identity={(row) => ({
                    icon: row.icon,
                    name: row.title,
                    subline: row.owner,
                })}
                minWidth={700}
                columns={[
                    {
                        key: 'detail',
                        label: 'Where to configure',
                        width: '2.5fr',
                        cell: (row) => (
                            <span className="text-subtle">{row.detail}</span>
                        ),
                    },
                ]}
                actionsFor={actions}
                onOpen={setDetail}
                onRowContextMenu={context.open}
            />
            <Notice>
                Each source workspace enforces your permissions and approved
                Sites. Setup changes must preserve existing booking, schedule
                and evidence history. Tracking is optional and does not require
                another vehicle or asset record.
            </Notice>
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={context.ctx.record.title}
                    items={actions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {detail && (
                <Modal
                    title={detail.title}
                    description={`Managed in ${detail.owner}`}
                    onClose={() => setDetail(null)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDetail(null)}
                            >
                                Close
                            </Button>
                            <Button onClick={() => router.visit(detail.href)}>
                                Open {detail.owner}
                            </Button>
                        </>
                    }
                >
                    <p className="text-subtle">{detail.detail}</p>
                    {detail.key === 'import' && (
                        <Notice>
                            The existing importer owns mapping, row errors,
                            duplicate checks, saved progress and reconciliation.
                            Opening it does not upload or import anything.
                        </Notice>
                    )}
                </Modal>
            )}
        </div>
    );
}
type HistoryRow = { id: number; title: string; time: string };
export function ChangeHistory({ query }: { query: string }) {
    const [page, setPage] = useState(1),
        [detail, setDetail] = useState<HistoryRow | null>(null),
        context = useEntityContextMenu<HistoryRow>(),
        remote = useRemote<Page<HistoryRow>>(`history?page=${page}`);
    const actions = (row: HistoryRow) => [
        {
            label: 'Review saved change',
            icon: Eye,
            onClick: () => setDetail(row),
        },
    ];
    return (
        <div className="space-y-5">
            <ListCaption
                title="Settings change history"
                caption="Your preferences and permitted provider changes"
            />
            {remote.error ? (
                <Notice>
                    {remote.error}{' '}
                    <Button variant="outline" onClick={remote.reload}>
                        Retry
                    </Button>
                </Notice>
            ) : !remote.data ? (
                <SkeletonTable />
            ) : remote.data.data.length ? (
                <>
                    <EntityTable
                        rows={remote.data.data.filter((row) =>
                            row.title
                                .toLowerCase()
                                .includes(query.toLowerCase()),
                        )}
                        rowKey={(row) => row.id}
                        identity={(row) => ({ name: row.title, icon: History })}
                        columns={[
                            {
                                key: 'time',
                                label: 'Recorded',
                                width: '1fr',
                                cell: (row) => formatDateTimeLong(row.time),
                            },
                        ]}
                        minWidth={600}
                        actionsFor={actions}
                        onOpen={setDetail}
                        onRowContextMenu={context.open}
                    />
                    <div className="flex justify-end gap-3">
                        <Button
                            variant="outline"
                            disabled={page <= 1}
                            onClick={() => setPage(page - 1)}
                        >
                            Previous
                        </Button>
                        <Button
                            variant="outline"
                            disabled={page >= remote.data.last_page}
                            onClick={() => setPage(page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                </>
            ) : (
                <EmptyState
                    icon={History}
                    title="No saved changes yet"
                    description="Settings saves will appear here. Operational changes remain in their source histories."
                />
            )}
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={context.ctx.record.title}
                    items={actions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {detail && (
                <Modal
                    title={detail.title}
                    description="Recorded application change"
                    onClose={() => setDetail(null)}
                >
                    <ReviewRow
                        label="Recorded"
                        value={formatDateTimeLong(detail.time)}
                    />
                    <p className="text-subtle">
                        A saved configuration does not confirm provider
                        delivery, device application or acknowledgement.
                    </p>
                </Modal>
            )}
        </div>
    );
}
