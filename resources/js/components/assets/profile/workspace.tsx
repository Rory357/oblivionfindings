import {
    TabSearchPalette,
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime } from '@/lib/fleet-utils';
import { AssetWizardDialog } from '@/pages/fleet-assets/assets/components/asset-wizard-dialog';
import type { Props } from '@/pages/fleet-assets/assets/show';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    ArrowUpRight,
    CalendarDays,
    Camera,
    ClipboardCheck,
    FileText,
    History,
    Layers,
    MapPin,
    Package,
    Plus,
    Shield,
    UserRound,
    Wallet,
    Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { AssetActionDialog } from './action-dialog';
import { AssetCheckDialog } from './check-dialog';
import { AssetCustodyView } from './custody-view';
import { AssetDocumentLibrary } from './document-library';
import { AssetFinanceReviewDialog } from './finance-review-dialog';
import { AssetFinanceView } from './finance-view';
import { AssetIdentityView } from './identity-view';
import { latestAssetCheck } from './latest-check';
import { Empty, Fact, Panel, SectionHeading, human } from './presentation';
import './profile.css';
import { AssetQrDialog } from './qr-dialog';
import { OriginalChecks, ScanObservations } from './source-panels';
import { AssetSummaryView } from './summary-view';
import type { Movement, ProfileAction, ProfileWorkspace } from './types';

const views = [
    { key: 'overview', label: 'Overview', icon: Package },
    { key: 'custody', label: 'Custody', icon: UserRound },
    { key: 'checks', label: 'Checks & service', icon: ClipboardCheck },
    { key: 'maintenance', label: 'Maintenance', icon: Wrench },
    { key: 'location', label: 'Location', icon: MapPin },
    { key: 'kit', label: 'Components & kit', icon: Layers },
    { key: 'history', label: 'History', icon: History },
];
const sections = [
    { key: 'summary', label: 'Summary', icon: Shield },
    { key: 'identity', label: 'Asset details', icon: Package },
    { key: 'library', label: 'Documents', icon: FileText },
    { key: 'finance', label: 'Finance', icon: Wallet },
];
const sectionGroups: Record<string, GroupedProfileNavTab[]> = {
    overview: sections,
    custody: [
        { key: 'current', label: 'Current custody', icon: UserRound },
        { key: 'movements', label: 'Movement history', icon: History },
    ],
    checks: [
        { key: 'checks', label: 'Original checks', icon: ClipboardCheck },
        { key: 'service', label: 'Service & calibration', icon: CalendarDays },
    ],
    maintenance: [{ key: 'work', label: 'Issues & work', icon: Wrench }],
    location: [
        { key: 'location', label: 'Location & observations', icon: MapPin },
    ],
    kit: [
        { key: 'kit', label: 'Kit contents', icon: Package },
        {
            key: 'componentHistory',
            label: 'Replacement history',
            icon: History,
        },
    ],
    history: [
        { key: 'timeline', label: 'Asset history', icon: History },
        { key: 'retirement', label: 'Retirement review', icon: Archive },
    ],
};
function readSection(view: string, section?: string | null) {
    return sectionGroups[view].some((item) => item.key === section)
        ? section!
        : sectionGroups[view][0].key;
}

export function AssetProfileWorkspace({
    asset,
    workspace: data,
    active_maintenance_restrictions: holds,
    sites = [],
    clients = [],
    asset_finance_technology: finance,
    hr_asset,
    can_view_hr_assets,
    timeline = [],
}: Props & { workspace: ProfileWorkspace }) {
    const [view, setView] = useState('overview'),
        [section, setSection] = useState('summary'),
        [query, setQuery] = useState(''),
        [find, setFind] = useState(false),
        [edit, setEdit] = useState(false),
        [qr, setQr] = useState(false),
        [check, setCheck] = useState(false),
        [financeReview, setFinanceReview] = useState<string | null>(null),
        [action, setAction] = useState<{
            action: ProfileAction;
            movement?: Movement;
            itemId?: number;
        } | null>(null);
    useEffect(() => {
        const read = () => {
            const hash = new URLSearchParams(window.location.hash.slice(1));
            const requested = hash.get('view');
            const next =
                requested === 'components'
                    ? 'kit'
                    : requested === 'lifecycle'
                      ? 'history'
                      : views.some((item) => item.key === requested)
                        ? requested!
                        : 'overview';
            setView(next);
            setSection(readSection(next, hash.get('section')));
        };
        read();
        window.addEventListener('hashchange', read);
        return () => window.removeEventListener('hashchange', read);
    }, []);
    const navigate = (next: string, requested?: string) => {
        const sub = readSection(
            next,
            requested ?? (next === view ? section : undefined),
        );
        setView(next);
        setSection(sub);
        setQuery('');
        window.location.hash = new URLSearchParams({
            view: next,
            section: sub,
        }).toString();
    };
    const refresh = () =>
        new Promise<void>((resolve) =>
            router.reload({
                only: [
                    'asset',
                    'workspace',
                    'timeline',
                    'active_maintenance_restrictions',
                    'asset_finance_technology',
                ],
                onSuccess: () => resolve(),
            }),
        );
    const allowed = data.permissions,
        mutable = data.ready && asset.status !== 'retired',
        pending = data.movements.find((item) =>
            ['pending_receipt', 'incomplete', 'disputed'].includes(item.state),
        ),
        loan = data.movements.find(
            (item) =>
                item.kind === 'loan' &&
                item.state === 'acknowledged' &&
                !item.returned_at,
        );
    const allDocuments = [
        ...data.documents,
        ...(data.sources?.source_files ?? []),
    ];
    const assignment = asset.assignments?.find((item) => !item.returned_at),
        currentDocs = allDocuments.filter(
            (file) => file.current && !file.archived,
        ),
        lastCheck = latestAssetCheck(data);
    const openAction = (
        value: ProfileAction,
        movement?: Movement,
        itemId?: number,
    ) => setAction({ action: value, movement, itemId });
    const reportUrl = `/fleet-assets/maintenance/work-orders?asset_id=${asset.id}&new=1`;
    const matching = (text: string) =>
        text.toLowerCase().includes(query.toLowerCase());
    const workList = (
        <>
            {data.work.length ? (
                data.work
                    .filter((work) =>
                        matching(
                            `${work.reference} ${work.title} ${work.status}`,
                        ),
                    )
                    .map((work) => (
                        <article
                            key={work.id}
                            className="rounded-lg border p-4"
                        >
                            <div className="flex flex-wrap justify-between gap-3">
                                <Link
                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                    href={work.url}
                                >
                                    {work.reference} · {work.title}{' '}
                                    <ArrowUpRight className="inline size-4" />
                                </Link>
                                <span className="text-caption">
                                    {human(work.status)} ·{' '}
                                    {human(work.priority)}
                                </span>
                            </div>
                            <p className="text-subtle mt-2">
                                Owner: {work.owner || 'Awaiting allocation'}
                                {work.due_at
                                    ? ` · Due ${formatDate(work.due_at)}`
                                    : ''}
                            </p>
                            {work.next_action && (
                                <p className="text-subtle mt-1">
                                    Next: {work.next_action}
                                </p>
                            )}
                        </article>
                    ))
            ) : (
                <Empty>
                    {allowed.maintenance
                        ? 'No Maintenance work is linked to this asset.'
                        : 'Maintenance records require separate access.'}
                </Empty>
            )}
        </>
    );
    if (asset.vehicle_documents)
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Assets', href: '/fleet-assets/assets' },
                    {
                        title: asset.name,
                        href: `/fleet-assets/assets/${asset.id}`,
                    },
                ]}
            >
                <Head title={asset.name} />
                <div className="p-6">
                    <Panel title={asset.name}>
                        <p>
                            This registered vehicle has a canonical Vehicle
                            Profile for its custody, checks, documents and
                            Finance links.
                        </p>
                        {asset.vehicle_documents.url ? (
                            <Button asChild>
                                <Link
                                    href={asset.vehicle_documents.url.replace(
                                        'view=documents',
                                        'view=overview',
                                    )}
                                >
                                    Open Vehicle Profile <ArrowUpRight />
                                </Link>
                            </Button>
                        ) : (
                            <p>
                                Your current access does not include the Vehicle
                                Profile.
                            </p>
                        )}
                        <Button variant="outline" asChild>
                            <Link href="/fleet-assets/assets">
                                Return to Assets
                            </Link>
                        </Button>
                    </Panel>
                </div>
            </AppLayout>
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Assets', href: '/fleet-assets/assets' },
                { title: asset.name, href: `/fleet-assets/assets/${asset.id}` },
            ]}
        >
            <Head title={`${asset.name} · Asset Profile`} />
            <div className="asset-profile min-w-0 space-y-5">
                <PageHeader
                    mark={
                        // eslint-disable-next-line no-restricted-syntax -- Interactive profile-photo ring follows the shared PageHeader mark geometry.
                        <button
                            type="button"
                            className="eh-mark-ring relative shrink-0 overflow-hidden"
                            aria-label="Change asset profile photo"
                            disabled={
                                !allowed.update ||
                                !allowed.manageDocuments ||
                                !mutable
                            }
                            onClick={() => openAction('set_photo')}
                        >
                            {data.photo_url ? (
                                <img
                                    src={data.photo_url}
                                    alt=""
                                    className="size-full object-cover"
                                />
                            ) : (
                                <Package className="size-5" />
                            )}
                            <Camera className="absolute right-0 bottom-0 size-3 rounded-full bg-primary" />
                        </button>
                    }
                    variant="profile"
                    icon={Package}
                    backHref="/fleet-assets/assets"
                    title={asset.name}
                    wrapTitle
                    titleChip={
                        <PageHeaderStatusChip
                            variant={holds ? 'warning' : 'neutral'}
                        >
                            {holds ? 'On hold' : human(asset.status)}
                        </PageHeaderStatusChip>
                    }
                    subline={`${asset.asset_tag || `AS-${asset.id}`} · ${asset.site?.name || 'Site not recorded'}${data.room ? ` / ${data.room}` : ''} · ${human(asset.category)}`}
                    actions={
                        <>
                            <PageHeaderSearchTrigger
                                placeholder="Find in this asset…"
                                onOpen={() => setFind(true)}
                            />
                            <PageHeaderGlassButton
                                icon={UserRound}
                                onClick={() => navigate('custody')}
                            >
                                Manage custody
                            </PageHeaderGlassButton>
                            {allowed.report && mutable && (
                                <PageHeaderPrimaryButton
                                    icon={AlertTriangle}
                                    onClick={() => router.get(reportUrl)}
                                >
                                    Report a problem
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Maintenance"
                                tone={holds ? 'warning' : 'brand'}
                                onClick={() => navigate('maintenance')}
                            >
                                <PageHeaderMeterBig>
                                    {holds
                                        ? 'Hold active'
                                        : `${data.work.filter((w) => !['completed', 'cancelled'].includes(w.status)).length} open`}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {holds
                                        ? 'Review source work before use'
                                        : 'Work and restrictions'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Custody"
                                onClick={() => navigate('custody')}
                            >
                                <PageHeaderMeterBig>
                                    {pending
                                        ? human(pending.state)
                                        : loan
                                          ? 'On loan'
                                          : assignment
                                            ? 'Assigned'
                                            : 'Unassigned'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {pending
                                        ? `${pending.recipient || 'Recipient not recorded'} · ${pending.received_kit.length}/${pending.kit.length} items`
                                        : assignment?.assignee?.name ||
                                          'Responsibility and actual receipt'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Last check"
                                onClick={() => navigate('checks')}
                            >
                                <PageHeaderMeterBig>
                                    {lastCheck
                                        ? human(lastCheck.result)
                                        : 'No recorded check'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {lastCheck
                                        ? formatDate(lastCheck.at)
                                        : 'Check history and service schedules'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Documents"
                                onClick={() => navigate('overview', 'library')}
                            >
                                <PageHeaderMeterBig>
                                    {currentDocs.length} current
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Manuals, warranty & source evidence
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={views}
                            onFind={() => setFind(true)}
                            value={view}
                            onSelect={(value) => navigate(value)}
                        />
                    }
                />
                <TierTwoTabs
                    tabs={sectionGroups[view]}
                    activeTab={section}
                    onTab={(next) => navigate(view, next)}
                    testIdPrefix="asset-profile"
                    ariaLabel="Asset Profile sections"
                    renderLink={(tab, className, inner, accessibility) => (
                        <Link
                            href={tab.href!}
                            className={className}
                            {...accessibility}
                        >
                            {inner}
                        </Link>
                    )}
                />
                <TabSearchPalette
                    open={find}
                    onClose={() => setFind(false)}
                    groups={views.map((item) => ({
                        ...item,
                        tabs: sectionGroups[item.key],
                    }))}
                    onTab={(key) => {
                        const group = views.find((item) =>
                            sectionGroups[item.key].some(
                                (tab) => tab.key === key,
                            ),
                        );
                        if (group) navigate(group.key, key);
                    }}
                    testIdPrefix="asset-profile"
                    searchLabel="Find in this asset"
                />
                {!data.ready && (
                    <p role="status" className="rounded-lg border bg-muted p-4">
                        This profile is available to read. Its database update
                        must be applied before recording custody, kit or
                        lifecycle changes.
                    </p>
                )}
                {holds > 0 && (
                    <div
                        role="status"
                        className="flex flex-wrap items-center gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg p-4"
                    >
                        <AlertTriangle className="size-5 text-status-warning" />
                        <strong>Use on hold</strong>
                        <span className="text-subtle flex-1">
                            Moving, receiving or checking this asset does not
                            release the Maintenance hold.
                        </span>
                        <Button
                            variant="outline"
                            onClick={() => navigate('maintenance')}
                        >
                            Review Maintenance
                        </Button>
                    </div>
                )}
                {view === 'overview' && (
                    <>
                        {section === 'summary' && (
                            <AssetSummaryView
                                asset={asset}
                                data={data}
                                holds={holds}
                                timeline={timeline}
                                navigate={navigate}
                            />
                        )}
                        {section === 'identity' && (
                            <AssetIdentityView
                                asset={asset}
                                data={data}
                                onEdit={() => setEdit(true)}
                                onAction={openAction}
                                onQr={() => setQr(true)}
                                onDocuments={() =>
                                    navigate('overview', 'library')
                                }
                            />
                        )}
                        {section === 'library' && (
                            <AssetDocumentLibrary
                                assetId={asset.id}
                                assetName={asset.name}
                                documents={allDocuments}
                                canManage={allowed.manageDocuments && mutable}
                                onSaved={refresh}
                            />
                        )}
                        {section === 'finance' && (
                            <AssetFinanceView
                                projection={finance}
                                data={data}
                                assetTag={asset.asset_tag}
                                work={workList}
                                onRequest={
                                    allowed.finance_review && mutable
                                        ? (type = '') => setFinanceReview(type)
                                        : undefined
                                }
                            />
                        )}
                    </>
                )}
                {view === 'custody' && (
                    <AssetCustodyView
                        asset={asset}
                        data={data}
                        section={section}
                        onAction={openAction}
                        onKit={() => navigate('kit')}
                    />
                )}
                {view === 'checks' && (
                    <div className="space-y-5">
                        <SectionHeading
                            title={
                                section === 'service'
                                    ? 'Service & calibration'
                                    : 'Original checks'
                            }
                            description="Original answers, equipment observations and approved service requirements stay with their source."
                        />
                        <div hidden={section !== 'checks'}>
                            <div className="mb-5">
                                <OriginalChecks data={data} />
                            </div>
                            <Panel
                                title="Equipment observations"
                                icon={ClipboardCheck}
                                actions={
                                    allowed.recordInspection &&
                                    mutable && (
                                        <Button onClick={() => setCheck(true)}>
                                            <ClipboardCheck />
                                            Record check
                                        </Button>
                                    )
                                }
                            >
                                {data.checks.length ? (
                                    data.checks.map((item) => (
                                        <article
                                            key={item.id}
                                            className="rounded-lg border p-4"
                                        >
                                            <strong>
                                                {human(item.result)} ·{' '}
                                                {formatDateTime(item.at)}
                                            </strong>
                                            <p className="text-subtle">
                                                {item.by ||
                                                    'Author not recorded'}
                                            </p>
                                            <p className="text-subtle mt-2">
                                                {item.notes ||
                                                    'No notes recorded.'}
                                            </p>
                                            {item.due && (
                                                <p className="text-subtle">
                                                    Next due{' '}
                                                    {formatDate(item.due)}
                                                </p>
                                            )}
                                            {item.result !== 'pass' &&
                                                allowed.report && (
                                                    <Button
                                                        className="mt-3"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link href={reportUrl}>
                                                            Create or link
                                                            Maintenance{' '}
                                                            <ArrowUpRight />
                                                        </Link>
                                                    </Button>
                                                )}
                                        </article>
                                    ))
                                ) : (
                                    <Empty>
                                        No checks recorded. Use the approved
                                        equipment instructions; no check
                                        interval is inferred here.
                                    </Empty>
                                )}
                            </Panel>
                        </div>
                        <div hidden={section !== 'service'}>
                            <Panel
                                title="Service requirements"
                                icon={CalendarDays}
                            >
                                {allowed.assess && mutable && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={`/fleet-assets/maintenance/checklists/run?asset_id=${asset.id}`}
                                        >
                                            Run approved assessment{' '}
                                            <ArrowUpRight />
                                        </Link>
                                    </Button>
                                )}
                                {(
                                    asset.checklist_runs as
                                        | {
                                              id: number;
                                              passed: boolean | null;
                                              template: { name: string } | null;
                                              completed_at: string | null;
                                          }[]
                                        | undefined
                                )?.map((run) => (
                                    <p key={run.id} className="text-subtle">
                                        <Link
                                            className="text-primary"
                                            href={`/fleet-assets/inspections/${run.id}`}
                                        >
                                            {run.template?.name ||
                                                `Assessment ${run.id}`}{' '}
                                            <ArrowUpRight className="inline size-4" />
                                        </Link>{' '}
                                        · {formatDateTime(run.completed_at)}
                                    </p>
                                ))}
                                <dl className="space-y-4">
                                    <Fact label="Inspection due">
                                        {asset.inspection_due_at
                                            ? formatDate(
                                                  asset.inspection_due_at,
                                              )
                                            : 'No due date recorded'}
                                    </Fact>
                                    <Fact label="Maintenance due">
                                        {asset.maintenance_due_at
                                            ? formatDate(
                                                  asset.maintenance_due_at,
                                              )
                                            : 'No due date recorded'}
                                    </Fact>
                                </dl>
                                {asset.service_schedules?.length ? (
                                    asset.service_schedules.map((schedule) => (
                                        <p
                                            key={schedule.id}
                                            className="text-subtle border-t pt-3"
                                        >
                                            {schedule.name} · Next due{' '}
                                            {formatDate(schedule.next_due_at)}
                                        </p>
                                    ))
                                ) : (
                                    <p className="text-subtle text-muted-foreground">
                                        No approved service schedule is linked.
                                    </p>
                                )}
                                <Button
                                    variant="outline"
                                    onClick={() => navigate('maintenance')}
                                >
                                    Open linked Maintenance
                                </Button>
                            </Panel>
                        </div>
                    </div>
                )}
                {view === 'maintenance' && (
                    <Panel
                        title="Issues & work"
                        icon={Wrench}
                        actions={
                            allowed.report &&
                            mutable && (
                                <Button asChild>
                                    <Link href={reportUrl}>
                                        Report or link a problem{' '}
                                        <ArrowUpRight />
                                    </Link>
                                </Button>
                            )
                        }
                    >
                        <Input
                            aria-label="Search Maintenance"
                            placeholder="Search reference, work or status…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        {workList}
                    </Panel>
                )}
                {view === 'location' && (
                    <div className="grid gap-5 lg:grid-cols-2">
                        <Panel
                            title="Assigned location"
                            icon={MapPin}
                            actions={
                                allowed.recordScan &&
                                mutable && (
                                    <Button
                                        onClick={() =>
                                            openAction('verify_location')
                                        }
                                    >
                                        <MapPin />
                                        Verify location
                                    </Button>
                                )
                            }
                        >
                            <dl className="space-y-4">
                                <Fact label="Site">{asset.site?.name}</Fact>
                                <Fact label="Room / location">
                                    {data.room || asset.location}
                                </Fact>
                                <Fact label="Source">
                                    Canonical asset placement
                                    {pending
                                        ? ' · A dispatch is awaiting receipt'
                                        : ''}
                                </Fact>
                            </dl>
                        </Panel>
                        <Panel title="Last manual observation" icon={MapPin}>
                            {data.manual_location ? (
                                <>
                                    <p>{data.manual_location.location}</p>
                                    <p className="text-subtle">
                                        Observed{' '}
                                        {formatDateTime(
                                            data.manual_location.observed_at,
                                        )}
                                    </p>
                                    <p className="text-caption text-muted-foreground">
                                        A manual observation does not overwrite
                                        assigned location or tracker reports.
                                    </p>
                                </>
                            ) : (
                                <Empty>No manual verification recorded.</Empty>
                            )}
                        </Panel>
                        <Panel title="Device provenance" icon={MapPin}>
                            {asset.trackers?.length ? (
                                asset.trackers.map((device) => (
                                    <p key={device.id} className="text-subtle">
                                        {device.name || device.device_uid} ·{' '}
                                        {human(device.status)} · Last report{' '}
                                        {formatDateTime(device.last_seen_at)}{' '}
                                        {device.detail_url && (
                                            <Link
                                                className="text-primary"
                                                href={device.detail_url}
                                            >
                                                Open source{' '}
                                                <ArrowUpRight className="inline size-4" />
                                            </Link>
                                        )}
                                    </p>
                                ))
                            ) : (
                                <Empty>
                                    No tracker linked. Static equipment can be
                                    located and verified without GPS.
                                </Empty>
                            )}
                        </Panel>
                        <ScanObservations data={data} />
                    </div>
                )}
                {view === 'kit' && (
                    <Panel
                        title={
                            section === 'componentHistory'
                                ? 'Replacement history'
                                : 'Kit contents'
                        }
                        icon={Layers}
                        actions={
                            allowed.update &&
                            section === 'kit' &&
                            mutable && (
                                <Button onClick={() => openAction('kit_add')}>
                                    <Plus />
                                    Add kit item
                                </Button>
                            )
                        }
                    >
                        <p className="text-subtle text-muted-foreground">
                            Each movement snapshots the kit and requires
                            explicit receipt confirmation. Registered components
                            retain their own assignments, checks and history.
                        </p>
                        {data.kit.filter((item) =>
                            section === 'componentHistory'
                                ? !!item.removed_at
                                : !item.removed_at,
                        ).length ? (
                            data.kit
                                .filter((item) =>
                                    section === 'componentHistory'
                                        ? !!item.removed_at
                                        : !item.removed_at,
                                )
                                .map((item) => (
                                    <article
                                        key={item.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"
                                    >
                                        <div>
                                            <strong>{item.name}</strong>
                                            <p className="text-subtle">
                                                {item.removed_at
                                                    ? `Removed ${formatDate(item.removed_at)} · ${item.reason}`
                                                    : `Added ${formatDate(item.added_at)}`}
                                            </p>
                                        </div>
                                        <div className="flex gap-2">
                                            {item.component_id && (
                                                <Button
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/fleet-assets/assets/${item.component_id}`}
                                                    >
                                                        Open component{' '}
                                                        <ArrowUpRight />
                                                    </Link>
                                                </Button>
                                            )}
                                            {allowed.update &&
                                                mutable &&
                                                !item.removed_at && (
                                                    <Button
                                                        variant="ghost"
                                                        onClick={() =>
                                                            openAction(
                                                                'kit_remove',
                                                                undefined,
                                                                item.id,
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                        </div>
                                    </article>
                                ))
                        ) : (
                            <Empty>
                                {section === 'componentHistory'
                                    ? 'No component replacements or removals recorded.'
                                    : 'No current kit items recorded.'}
                            </Empty>
                        )}
                    </Panel>
                )}
                {view === 'history' && section === 'timeline' && (
                    <Panel title="Asset history" icon={History}>
                        <Input
                            aria-label="Search history"
                            placeholder="Search action, person or reason…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        {data.events
                            .filter((event) =>
                                matching(
                                    `${event.action} ${event.actor} ${event.reason} ${event.message}`,
                                ),
                            )
                            .map((event) => (
                                <article
                                    key={event.id}
                                    className="border-l-2 border-primary/30 py-3 pl-5"
                                >
                                    <h3 className="font-medium">
                                        {human(event.action)}
                                    </h3>
                                    <p className="text-caption text-muted-foreground">
                                        {formatDateTime(event.at)} ·{' '}
                                        {event.actor || 'Recorded actor'}
                                    </p>
                                    <p className="text-subtle mt-2">
                                        {event.message}
                                    </p>
                                    {event.reason && (
                                        <p className="text-subtle">
                                            {event.reason}
                                        </p>
                                    )}
                                </article>
                            ))}
                        {timeline
                            .filter((event) => matching(event.summary))
                            .map((event) => (
                                <p
                                    key={`${event.type}-${event.id}`}
                                    className="text-subtle border-t py-3"
                                >
                                    {formatDateTime(event.date)} ·{' '}
                                    {event.summary}
                                </p>
                            ))}
                        {!data.events.length && !timeline.length && (
                            <Empty>No recorded events.</Empty>
                        )}
                        <div className="flex gap-3">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.get(
                                        `/fleet-assets/assets/${asset.id}#view=history`,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                Latest history
                            </Button>
                            {data.history_next && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.get(
                                            `/fleet-assets/assets/${asset.id}?history_before=${data.history_next}#view=history`,
                                            {},
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Older events
                                </Button>
                            )}
                        </div>
                        <p className="text-caption text-muted-foreground">
                            Up to 100 profile events per page. Original document
                            versions remain in the document library.
                        </p>
                    </Panel>
                )}
                {view === 'history' && section === 'retirement' && (
                    <Panel title="Retirement review" icon={Archive}>
                        <p className="text-subtle">
                            Operational retirement retains this asset and its
                            history. Financial disposal remains with Finance.
                        </p>
                        {data.retirement_blockers.length ? (
                            <ul className="text-subtle list-disc space-y-3 pl-5">
                                {data.retirement_blockers.map((reason) => (
                                    <li key={reason}>{reason}</li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-subtle">
                                No current retirement dependencies were found.
                                Review the record and evidence before
                                continuing.
                            </p>
                        )}
                        {allowed.delete && mutable && (
                            <Button
                                variant="outline"
                                onClick={() => openAction('retire')}
                            >
                                Review retirement
                            </Button>
                        )}
                    </Panel>
                )}
                {action && (
                    <AssetActionDialog
                        {...action}
                        assetId={asset.id}
                        assetName={asset.name}
                        workspace={data}
                        sites={
                            action.action === 'ownership'
                                ? sites.filter(
                                      (site) =>
                                          site.id ===
                                          (asset.site?.id ??
                                              asset.home_site?.id),
                                  )
                                : sites
                        }
                        onClose={() => setAction(null)}
                        onSaved={refresh}
                    />
                )}
                {check && (
                    <AssetCheckDialog
                        assetId={asset.id}
                        assetName={asset.name}
                        version={data.version}
                        onClose={() => setCheck(false)}
                        onSaved={refresh}
                    />
                )}
                {financeReview !== null && (
                    <AssetFinanceReviewDialog
                        assetId={asset.id}
                        assetName={asset.name}
                        workspace={data}
                        initialType={financeReview}
                        onClose={() => setFinanceReview(null)}
                        onSaved={refresh}
                    />
                )}
                {qr && (
                    <AssetQrDialog
                        assetId={asset.id}
                        assetName={asset.name}
                        qr={data.qr}
                        onClose={() => setQr(false)}
                    />
                )}
                {edit && (
                    <AssetWizardDialog
                        open
                        asset={
                            {
                                ...asset,
                                asset_profile_version: data.version,
                                site_id: asset.site?.id ?? null,
                                home_site_id: asset.home_site?.id ?? null,
                            } as Parameters<
                                typeof AssetWizardDialog
                            >[0]['asset']
                        }
                        sites={sites}
                        clients={clients}
                        onClose={() => {
                            setEdit(false);
                            refresh();
                        }}
                    />
                )}
                <p className="text-caption flex flex-wrap gap-5 text-muted-foreground">
                    <Link href="/fleet-assets/assets" className="text-primary">
                        Return to Assets register
                    </Link>
                    {hr_asset && can_view_hr_assets && (
                        <Link
                            className="text-primary"
                            href={`/hr/assets/${hr_asset.id}`}
                        >
                            Linked HR register record{' '}
                            <ArrowUpRight className="inline size-3" />
                        </Link>
                    )}
                </p>
            </div>
        </AppLayout>
    );
}
