import { EntityChip } from '@/components/lists';
import { EvidenceRecords } from '@/components/lists/evidence-records';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptySearch } from '@/components/ui/empty-state';
import { ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Download,
    Laptop,
    LayoutGrid,
    List,
    Network,
    RefreshCw,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type CurrentPermissions = {
    securityDevices?: {
        viewAny?: boolean;
        commandsAdmin?: boolean;
        integrationsManage?: boolean;
        devicesView?: boolean;
        integrationsView?: boolean;
    };
};

export type AgentDownload = {
    id: string;
    platform: 'windows' | 'linux';
    architecture: 'amd64' | 'arm64';
    roles: ('computer' | 'site')[];
    version: string;
    release_revision: number;
    filename: string;
    size: number;
    sha256: string;
    download_url: string;
};
export type AgentDownloadsProps = {
    artifacts: AgentDownload[];
    catalogue_available: boolean;
    catalogue_expires_at: string | null;
    can_manage_computers: boolean;
    can_manage_collectors: boolean;
};
type ReleaseView = 'all' | 'windows' | 'linux';
const platformLabel = (a: AgentDownload) =>
    a.platform === 'windows' ? 'Windows' : 'Linux';
const architectureLabel = (a: AgentDownload) =>
    a.architecture === 'amd64' ? 'x64' : 'ARM64';
const roleLabel = (a: AgentDownload) =>
    a.roles.length === 2
        ? 'Computer support + site monitoring'
        : a.roles[0] === 'computer'
          ? 'Computer support'
          : 'Site monitoring';
const formatLabel = (a: AgentDownload) =>
    a.filename.endsWith('.exe')
        ? 'Installer'
        : a.filename.endsWith('.zip')
          ? 'Offline package'
          : 'Collector archive';
const sizeLabel = (a: AgentDownload) =>
    (a.size / (1024 * 1024)).toLocaleString('en-NZ', {
        maximumFractionDigits: 1,
    }) + ' MB';
const downloadHref = (a: AgentDownload) =>
    /^[a-z0-9][a-z0-9-]{1,63}$/.test(a.id) &&
    /^[a-f0-9]{64}$/.test(a.sha256) &&
    a.download_url === `/security-devices/agent-downloads/${a.id}/${a.sha256}`
        ? a.download_url
        : null;
function DownloadLink({ artifact }: { artifact: AgentDownload }) {
    const href = downloadHref(artifact);
    return href ? (
        <Button asChild variant="outline" className="frontline-hit">
            <a
                href={href}
                onClick={(event) => event.stopPropagation()}
                aria-label={`Download ${formatLabel(artifact)} · ${roleLabel(artifact)} for ${platformLabel(artifact)} ${architectureLabel(artifact)} · ${artifact.id}`}
            >
                <Download aria-hidden="true" />
                Download
            </a>
        </Button>
    ) : (
        <span className="text-caption">
            Refresh to load the current download link.
        </span>
    );
}
export default function AgentDownloads(props: AgentDownloadsProps) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: CurrentPermissions };
    }>().props;
    const [sourceActor] = useState(auth?.user?.id);
    const sameActor = Boolean(sourceActor && sourceActor === auth?.user?.id);
    const computer = Boolean(
        sameActor &&
        auth?.user?.id &&
        auth.can?.securityDevices?.viewAny === true &&
        auth.can?.securityDevices?.commandsAdmin === true,
    );
    const site = Boolean(
        sameActor &&
        auth?.user?.id &&
        auth.can?.securityDevices?.viewAny === true &&
        auth.can?.securityDevices?.integrationsManage === true,
    );
    return (
        <AgentReleaseList
            key={JSON.stringify([auth?.user?.id, auth?.can, props])}
            {...props}
            sameActor={sameActor}
            computer={computer}
            site={site}
            deviceView={
                sameActor && auth?.can?.securityDevices?.devicesView === true
            }
            integrationView={
                sameActor &&
                auth?.can?.securityDevices?.integrationsView === true
            }
        />
    );
}
function AgentReleaseList({
    artifacts,
    catalogue_available,
    catalogue_expires_at,
    can_manage_computers,
    can_manage_collectors,
    computer,
    site,
    deviceView,
    integrationView,
    sameActor,
}: AgentDownloadsProps & {
    computer: boolean;
    site: boolean;
    deviceView: boolean;
    integrationView: boolean;
    sameActor: boolean;
}) {
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const [view, setView] = useState<ReleaseView>('all');
    const [search, setSearch] = useState('');
    const [clock, setClock] = useState(Date.now);
    const expires = catalogue_expires_at
        ? Date.parse(catalogue_expires_at)
        : NaN;
    const current =
        catalogue_available &&
        Number.isFinite(expires) &&
        expires > Math.max(clock, Date.now());
    useEffect(() => {
        if (!Number.isFinite(expires) || expires <= Date.now()) return;
        const timer = window.setTimeout(
            () => setClock(Date.now()),
            Math.min(expires - Date.now() + 1, 60000),
        );
        return () => window.clearTimeout(timer);
    }, [expires, clock]);
    const permitted = current
        ? artifacts.filter(
              (a) =>
                  a.roles.length > 0 &&
                  a.roles.length <= 2 &&
                  a.roles.every((role) =>
                      role === 'computer' ? computer : role === 'site' && site,
                  ) &&
                  (a.platform !== 'linux' ||
                      a.roles.every((role) => role === 'site')),
          )
        : [];
    const windows = permitted.filter((a) => a.platform === 'windows').length;
    const linux = permitted.filter((a) => a.platform === 'linux').length;
    const rows = permitted.filter(
        (a) =>
            (view === 'all' || a.platform === view) &&
            [
                a.id,
                a.filename,
                a.version,
                roleLabel(a),
                platformLabel(a),
                architectureLabel(a),
                formatLabel(a),
            ]
                .join(' ')
                .toLowerCase()
                .includes(search.trim().toLowerCase()),
    );
    const restricted = !computer && !site;
    const unavailable = !current;
    const clearFilters = () => {
        setSearch('');
        setView('all');
    };
    const setup = () =>
        document
            .getElementById('installation-profile')
            ?.scrollIntoView({ block: 'start' });
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Agent downloads',
                    href: '/security-devices/agent-downloads',
                },
            ]}
        >
            <Head title="Agent downloads" />
            <PageHeader
                icon={Download}
                title="Agent downloads"
                subline="Published computer support and Site monitoring packages in your permitted scope"
                actions={
                    <>
                        <PageHeaderSearch
                            value={search}
                            onChange={setSearch}
                            placeholder="Search releases, files, versions…"
                        />
                        <PageHeaderGlassButton
                            onClick={() =>
                                !sameActor
                                    ? router.visit(
                                          '/security-devices/agent-downloads',
                                          {
                                              preserveState: false,
                                              preserveScroll: true,
                                          },
                                      )
                                    : router.reload({
                                          only: [
                                              'artifacts',
                                              'catalogue_available',
                                              'catalogue_expires_at',
                                              'can_manage_computers',
                                              'can_manage_collectors',
                                              'auth',
                                          ],
                                      })
                            }
                        >
                            <RefreshCw />
                            Refresh
                        </PageHeaderGlassButton>
                    </>
                }
                meters={
                    <>
                        <PageHeaderMeterBlock
                            label="Published releases"
                            onClick={() => {
                                clearFilters();
                            }}
                        >
                            <PageHeaderMeterBig>
                                {restricted
                                    ? 'Restricted'
                                    : unavailable
                                      ? 'Unavailable'
                                      : permitted.length}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Current permitted catalogue
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Windows releases"
                            onClick={() => {
                                setView('windows');
                                setSearch('');
                            }}
                        >
                            <PageHeaderMeterBig>
                                {restricted
                                    ? 'Restricted'
                                    : unavailable
                                      ? 'Unavailable'
                                      : windows}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Computer or Site packages
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Linux collectors"
                            onClick={() => {
                                setView('linux');
                                setSearch('');
                            }}
                        >
                            <PageHeaderMeterBig>
                                {restricted
                                    ? 'Restricted'
                                    : unavailable
                                      ? 'Unavailable'
                                      : linux}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Site monitoring only
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Installation profile"
                            onClick={setup}
                        >
                            <span>Review destination</span>
                            <PageHeaderMeterCaption>
                                Computer or approved Site
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                }
                filters={
                    <PageHeaderViewToggle
                        value={layout}
                        onChange={setLayout}
                        options={[
                            {
                                value: 'cards',
                                label: 'Cards',
                                icon: LayoutGrid,
                            },
                            { value: 'table', label: 'Table', icon: List },
                        ]}
                    />
                }
                rail={
                    <PageHeaderRail<ReleaseView>
                        items={[
                            {
                                key: 'all',
                                label: 'All releases',
                                count:
                                    restricted || unavailable
                                        ? undefined
                                        : permitted.length,
                            },
                            {
                                key: 'windows',
                                label: 'Windows',
                                count:
                                    restricted || unavailable
                                        ? undefined
                                        : windows,
                            },
                            {
                                key: 'linux',
                                label: 'Linux',
                                count:
                                    restricted || unavailable
                                        ? undefined
                                        : linux,
                            },
                        ]}
                        value={view}
                        onSelect={setView}
                        ariaLabel="Release platforms"
                    />
                }
            />
            <div className="mt-5 space-y-5">
                <p className="text-caption">
                    {restricted
                        ? 'Download permission required.'
                        : current
                          ? `Catalogue valid until ${formatDateTimeLong(catalogue_expires_at!)}. The server verifies the current file and permission when you download.`
                          : catalogue_available
                            ? 'This catalogue has expired or its validity is unavailable. Refresh to load current published releases.'
                            : 'No verified agent release is available from this deployment. Downloads will appear after a release has been published.'}
                </p>
                {!restricted &&
                current &&
                permitted.length > 0 &&
                rows.length === 0 ? (
                    <EmptySearch
                        searchTerm={
                            search ||
                            (view === 'all'
                                ? 'All releases'
                                : view === 'windows'
                                  ? 'Windows'
                                  : 'Linux')
                        }
                        onClear={clearFilters}
                    />
                ) : (
                    <EvidenceRecords<AgentDownload>
                        key={JSON.stringify([
                            search,
                            view,
                            current,
                            computer,
                            site,
                        ])}
                        title="Published releases"
                        description="File details describe a published package. Downloading does not enrol a device, issue credentials or grant remote access."
                        rows={rows}
                        view={layout}
                        rowKey={(a) => a.id}
                        identity={(a) => ({
                            name: roleLabel(a),
                            subline: `${platformLabel(a)} ${architectureLabel(a)} · ${formatLabel(a)} · ${a.id}`,
                            icon: a.platform === 'windows' ? Laptop : Network,
                        })}
                        articleLabel={(a) => `Release ${a.id}: ${roleLabel(a)}`}
                        icon={Download}
                        actionsFor={() => []}
                        cardFor={(a) => ({
                            meridian: 'neutral',
                            icon: a.platform === 'windows' ? Laptop : Network,
                            name: roleLabel(a),
                            subline: `${platformLabel(a)} ${architectureLabel(a)} · ${a.id}`,
                            chips: (
                                <>
                                    <EntityChip>Version {a.version}</EntityChip>
                                    <EntityChip>{formatLabel(a)}</EntityChip>
                                    <EntityChip>{sizeLabel(a)}</EntityChip>
                                </>
                            ),
                            footer: {
                                primary: a.filename,
                                secondary: `Release revision ${a.release_revision}`,
                            },
                        })}
                        extraFor={(a) => <DownloadLink artifact={a} />}
                        compactColumns={[
                            {
                                key: 'version',
                                label: 'Version',
                                width: '110px',
                                cell: (a) => a.version,
                            },
                            {
                                key: 'size',
                                label: 'Size',
                                width: '100px',
                                cell: sizeLabel,
                            },
                            {
                                key: 'download',
                                label: 'Download',
                                width: '150px',
                                cell: (a) => <DownloadLink artifact={a} />,
                            },
                        ]}
                        columns={[
                            {
                                key: 'file',
                                label: 'File verification',
                                width: '1fr',
                                cell: (a) => (
                                    <>
                                        <ReviewRow
                                            label="Release ID"
                                            value={a.id}
                                        />
                                        <ReviewRow
                                            label="Purpose"
                                            value={roleLabel(a)}
                                        />
                                        <ReviewRow
                                            label="Platform"
                                            value={platformLabel(a)}
                                        />
                                        <ReviewRow
                                            label="Architecture"
                                            value={architectureLabel(a)}
                                        />
                                        <ReviewRow
                                            label="Package"
                                            value={formatLabel(a)}
                                        />
                                        <ReviewRow
                                            label="Version"
                                            value={a.version}
                                        />
                                        <ReviewRow
                                            label="Release revision"
                                            value={a.release_revision}
                                        />
                                        <ReviewRow
                                            label="Size"
                                            value={`${sizeLabel(a)} · ${a.size.toLocaleString('en-NZ')} bytes`}
                                        />
                                        <ReviewRow
                                            label="File"
                                            value={
                                                <span className="break-all">
                                                    {a.filename}
                                                </span>
                                            }
                                        />
                                        <ReviewRow
                                            label="SHA-256"
                                            value={
                                                <span className="font-mono break-all">
                                                    {a.sha256}
                                                </span>
                                            }
                                        />
                                        <ReviewRow
                                            label="Catalogue valid until"
                                            value={
                                                current
                                                    ? formatDateTimeLong(
                                                          catalogue_expires_at!,
                                                      )
                                                    : 'Unavailable'
                                            }
                                        />
                                        <ReviewRow
                                            label="Download"
                                            value={
                                                <DownloadLink artifact={a} />
                                            }
                                        />
                                    </>
                                ),
                            },
                        ]}
                        empty={
                            restricted
                                ? 'Downloads restricted'
                                : unavailable
                                  ? 'Verified catalogue unavailable'
                                  : 'No download available yet'
                        }
                        minWidth={1050}
                    />
                )}
                <Card
                    id="installation-profile"
                    className="min-w-0 gap-4 p-5 shadow-none"
                >
                    <h2 className="text-section-title">
                        Connect to this organisation
                    </h2>
                    <p className="text-subtle">
                        The installation profile identifies this server and the
                        approved computer or Site. Check that destination before
                        enrolment. AI provider keys stay on this server.
                    </p>
                    <p className="text-subtle">
                        Windows packages provide computer support, Site
                        monitoring or both in one installation. Choose x64 or
                        ARM64 for the computer. Linux packages run Site
                        monitoring; the Obli computer helper is available for
                        Windows.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        {computer && deviceView && can_manage_computers ? (
                            <Button asChild variant="outline">
                                <Link href="/security-devices/devices">
                                    Open device register
                                </Link>
                            </Button>
                        ) : null}
                        {site && integrationView && can_manage_collectors ? (
                            <Button asChild variant="outline">
                                <Link href="/security-devices/discovery?tab=collectors">
                                    Open site collectors
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                    <p className="text-caption">
                        Installing a package does not grant remote access.
                        Enrolment and consent use the existing computer and Site
                        settings.
                    </p>
                </Card>
            </div>
        </AppLayout>
    );
}
