import { CheckFlowDialog } from '@/components/fleet-assets/vehicle-workspace/check-flow';
import { ChecksStudio } from '@/components/fleet-assets/vehicle-workspace/checks-studio';
import { CatalogueProvider } from '@/components/fleet-assets/vehicle-workspace/choice-picker';
import { FinanceStudio } from '@/components/fleet-assets/vehicle-workspace/finance-studio';
import type { VehicleFinanceWorkspace } from '@/components/fleet-assets/vehicle-workspace/finance-types';
import { OpenWorkPanel } from '@/components/fleet-assets/vehicle-workspace/maintenance-studio';
import { VehicleAlertsPanel } from '@/components/fleet-assets/vehicle-workspace/map-alerts';
import { VehicleDrivingPanel } from '@/components/fleet-assets/vehicle-workspace/map-driving';
import { VehicleLocationPanel } from '@/components/fleet-assets/vehicle-workspace/map-location';
import { VehicleTelemetryPanel } from '@/components/fleet-assets/vehicle-workspace/map-telemetry';
import {
    DetailsPanel,
    PhotoDialog,
    type EligibleDriver,
} from '@/components/fleet-assets/vehicle-workspace/overview-details';
import { DocumentsPanel } from '@/components/fleet-assets/vehicle-workspace/overview-documents';
import { ReadinessPanel } from '@/components/fleet-assets/vehicle-workspace/overview-readiness';
import { ReportProblemDialog } from '@/components/fleet-assets/vehicle-workspace/report-problem';
import { EvidencePanel } from '@/components/fleet-assets/vehicle-workspace/service-evidence';
import { HistoryPanel } from '@/components/fleet-assets/vehicle-workspace/service-history';
import { MileagePanel } from '@/components/fleet-assets/vehicle-workspace/service-mileage';
import { RemindersPanel } from '@/components/fleet-assets/vehicle-workspace/service-reminders';
import { SchedulesPanel } from '@/components/fleet-assets/vehicle-workspace/service-schedules';
import { TripHistory } from '@/components/fleet-assets/vehicle-workspace/trip-history';
import type { VehicleWorkspace } from '@/components/fleet-assets/vehicle-workspace/types';
import { VehicleCalendar } from '@/components/fleet-assets/vehicle-workspace/vehicle-calendar';
import {
    MAIN_RAIL,
    VehicleHeader,
} from '@/components/fleet-assets/vehicle-workspace/vehicle-header';
import {
    locationUrl,
    readLocation,
    type MainTab,
    type WorkspaceLocation,
    type WorkspaceView,
} from '@/components/fleet-assets/vehicle-workspace/workspace-model';
import { PageLayout } from '@/components/page';
import {
    TabSearchPalette,
    TierTwoTabs,
    type GroupedProfileNavGroup,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Car,
    ClipboardCheck,
    FileText,
    Gauge,
    History,
    MapPin,
    Route,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

// Each workspace view loads its own records; the page carries the summary.
type Props = {
    workspace: VehicleWorkspace;
    sites: Array<{ id: number; name: string }>;
    eligible_drivers: EligibleDriver[];
    finance_workspace?: VehicleFinanceWorkspace;
};

type TierTab = GroupedProfileNavTab & { key: string };

/** The approved design's second-tier sections for each main tab. */
function tierTabs(
    workspace: VehicleWorkspace,
): Partial<Record<MainTab, TierTab[]>> {
    return {
        overview: [
            { key: 'readiness', label: 'Readiness', icon: ShieldCheck },
            { key: 'details', label: 'Vehicle details', icon: Car },
            ...(workspace.can.view_documents
                ? [{ key: 'documents', label: 'Documents', icon: FileText }]
                : []),
            // Always listed: people without Finance access see why it's empty.
            { key: 'finance', label: 'Finance', icon: FileText },
        ],
        service: [
            { key: 'evidence', label: 'Evidence & due dates', icon: FileText },
            { key: 'schedules', label: 'Service schedules', icon: Wrench },
            { key: 'reminders', label: 'Reminders', icon: Bell },
            { key: 'history', label: 'Service history', icon: History },
            { key: 'mileage', label: 'Mileage', icon: Gauge },
        ],
        checks: [
            { key: 'recent', label: 'Recent checks', icon: ClipboardCheck },
            { key: 'templates', label: 'Templates', icon: FileText },
        ],
        maintenance: [
            { key: 'open', label: 'Open work', icon: Wrench },
            { key: 'history', label: 'Historical work', icon: History },
        ],
        map: [
            { key: 'location', label: 'Location & geofences', icon: MapPin },
            ...(workspace.can.view_vehicle_technology
                ? [
                      {
                          key: 'telemetry',
                          label: 'Vehicle telemetry',
                          icon: Activity,
                      },
                  ]
                : []),
            // Each panel explains when its records aren't available to the viewer.
            { key: 'driving', label: 'Driving insights', icon: Gauge },
            { key: 'alerts', label: 'Alerts & Control Room', icon: Bell },
        ],
        trips: [{ key: 'trips', label: 'Vehicle trips', icon: Route }],
    };
}

export default function VehicleShow({
    workspace,
    sites,
    eligible_drivers,
    finance_workspace,
}: Props) {
    const { vehicle, can } = workspace;
    const tiers = tierTabs(workspace);
    // A link to a section this person can't open falls back to its tab's first section.
    const permitted = useCallback(
        (next: WorkspaceLocation): WorkspaceLocation => {
            const tier = tiers[next.tab];
            if (!tier || next.tab === 'trips' || !next.view) return next;
            return tier.some((tab) => tab.key === next.view)
                ? next
                : { ...next, view: tier[0].key as WorkspaceView };
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps -- tiers follow the workspace's permissions
        [workspace.can],
    );
    const [location, setLocation] = useState<WorkspaceLocation>(() =>
        permitted(
            typeof window === 'undefined'
                ? { tab: 'overview', view: 'readiness' }
                : readLocation(window.location.search),
        ),
    );
    const [findOpen, setFindOpen] = useState(false);
    const [photoOpen, setPhotoOpen] = useState(false);
    const [createSchedule, setCreateSchedule] = useState(false);
    const [checkOpen, setCheckOpen] = useState(false);
    const [reportOpen, setReportOpen] = useState(false);
    const startCheck = can.inspect ? () => setCheckOpen(true) : undefined;

    const navigate = useCallback(
        (next: WorkspaceLocation) => {
            const resolved = permitted(
                next.view
                    ? next
                    : readLocation(
                          locationUrl(vehicle.id, next).split('?')[1] ?? '',
                      ),
            );
            setLocation(resolved);
            window.history.replaceState(
                window.history.state,
                '',
                locationUrl(vehicle.id, resolved),
            );
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        [vehicle.id, permitted],
    );
    const refresh = useCallback(
        () => router.reload({ only: ['workspace'] }),
        [],
    );
    // Finance links and requests load when the Finance section opens.
    const financeOpen =
        location.tab === 'overview' && location.view === 'finance';
    useEffect(() => {
        if (financeOpen && finance_workspace === undefined)
            router.reload({ only: ['finance_workspace'] });
    }, [financeOpen, finance_workspace]);
    const searchGroups: GroupedProfileNavGroup[] = MAIN_RAIL.map((item) => {
        const icon = item.icon ?? Car;
        return {
            key: item.key,
            label: item.label,
            icon,
            tabs: (
                tiers[item.key] ?? [{ key: item.key, label: item.label, icon }]
            ).map((tab) => ({ ...tab, key: `${item.key}.${tab.key}` })),
        };
    });
    const openFound = (key: string) => {
        const [tab, view] = key.split('.');
        navigate({
            tab: tab as MainTab,
            view:
                tab === view || tab === 'trips'
                    ? undefined
                    : (view as WorkspaceView),
        });
        setFindOpen(false);
    };
    const tier = tiers[location.tab] ?? null;
    const activeView =
        location.tab === 'trips' ? 'trips' : (location.view ?? tier?.[0].key);
    const sectionLabel =
        tier?.find((tab) => tab.key === activeView)?.label ??
        MAIN_RAIL.find((item) => item.key === location.tab)?.label;
    const at = (tab: MainTab, view?: WorkspaceView) =>
        location.tab === tab && (view === undefined || location.view === view);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Vehicles', href: '/fleet-assets/vehicles' },
                {
                    title: vehicle.name,
                    href: `/fleet-assets/vehicles/${vehicle.id}`,
                },
            ]}
        >
            <Head
                title={`${vehicle.name} · ${location.tab === 'calendar' ? 'Vehicle calendar' : 'Vehicle'}`}
            />
            <CatalogueProvider
                entries={workspace.catalogues}
                canAdd={can.add_catalogue}
            >
                <div className="vehicle-studio min-w-0">
                    {location.tab === 'calendar' ? (
                        <VehicleCalendar
                            workspace={workspace}
                            focusDate={location.date}
                            onBack={() =>
                                navigate(
                                    location.date
                                        ? { tab: 'service', view: 'reminders' }
                                        : { tab: 'overview' },
                                )
                            }
                            onNavigate={navigate}
                            onChanged={refresh}
                        />
                    ) : (
                        <PageLayout
                            hero={
                                <VehicleHeader
                                    workspace={workspace}
                                    tab={location.tab}
                                    onNavigate={navigate}
                                    onFind={() => setFindOpen(true)}
                                    onPhoto={() => setPhotoOpen(true)}
                                    onStartCheck={startCheck}
                                    onReport={
                                        can.report_maintenance
                                            ? () => setReportOpen(true)
                                            : undefined
                                    }
                                />
                            }
                            tabs={
                                tier ? (
                                    <TierTwoTabs
                                        tabs={tier}
                                        activeTab={activeView ?? tier[0].key}
                                        onTab={(key) =>
                                            navigate({
                                                tab: location.tab,
                                                view:
                                                    location.tab === 'trips'
                                                        ? undefined
                                                        : (key as WorkspaceView),
                                            })
                                        }
                                        testIdPrefix="vehicle-workspace"
                                        ariaLabel="Vehicle sections"
                                        panelId="vehicle-workspace-panel"
                                        renderLink={(
                                            entry,
                                            className,
                                            inner,
                                            accessibility,
                                        ) => {
                                            const target: WorkspaceLocation = {
                                                tab: location.tab,
                                                view:
                                                    location.tab === 'trips'
                                                        ? undefined
                                                        : (entry.key as WorkspaceView),
                                            };
                                            return (
                                                <a
                                                    key={entry.key}
                                                    href={locationUrl(
                                                        vehicle.id,
                                                        target,
                                                    )}
                                                    className={className}
                                                    {...accessibility}
                                                    onClick={(event) => {
                                                        if (
                                                            event.metaKey ||
                                                            event.ctrlKey ||
                                                            event.shiftKey ||
                                                            event.button !== 0
                                                        )
                                                            return;
                                                        event.preventDefault();
                                                        navigate(target);
                                                    }}
                                                >
                                                    {inner}
                                                </a>
                                            );
                                        }}
                                    />
                                ) : null
                            }
                        >
                            <section
                                id="vehicle-workspace-panel"
                                role="tabpanel"
                                className="grid min-w-0 gap-5"
                                aria-label={sectionLabel}
                            >
                                {at('overview', 'readiness') && (
                                    <ReadinessPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                        onStartCheck={startCheck}
                                        onSetUpSchedule={() => {
                                            setCreateSchedule(true);
                                            navigate({
                                                tab: 'service',
                                                view: 'schedules',
                                            });
                                        }}
                                    />
                                )}
                                {at('overview', 'details') && (
                                    <DetailsPanel
                                        workspace={workspace}
                                        sites={sites}
                                        drivers={eligible_drivers}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('overview', 'documents') && (
                                    <DocumentsPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('overview', 'finance') && (
                                    <FinanceStudio
                                        workspace={workspace}
                                        finance={finance_workspace}
                                        onChanged={() =>
                                            router.reload({
                                                only: [
                                                    'finance_workspace',
                                                    'workspace',
                                                ],
                                            })
                                        }
                                    />
                                )}
                                {at('service', 'evidence') && (
                                    <EvidencePanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('service', 'schedules') && (
                                    <SchedulesPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                        createRequested={createSchedule}
                                        onCreateHandled={() =>
                                            setCreateSchedule(false)
                                        }
                                    />
                                )}
                                {at('service', 'reminders') && (
                                    <RemindersPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('service', 'history') && (
                                    <HistoryPanel
                                        workspace={workspace}
                                        back={{
                                            tab: 'service',
                                            view: 'history',
                                        }}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('service', 'mileage') && (
                                    <MileagePanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('checks') && (
                                    <ChecksStudio
                                        workspace={workspace}
                                        view={
                                            location.view === 'templates'
                                                ? 'templates'
                                                : 'recent'
                                        }
                                        onChanged={refresh}
                                    />
                                )}
                                {at('maintenance', 'open') && (
                                    <OpenWorkPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('maintenance', 'history') && (
                                    <HistoryPanel
                                        workspace={workspace}
                                        back={{
                                            tab: 'maintenance',
                                            view: 'history',
                                        }}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('map', 'location') && (
                                    <VehicleLocationPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('map', 'telemetry') && (
                                    <VehicleTelemetryPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('map', 'driving') && (
                                    <VehicleDrivingPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('map', 'alerts') && (
                                    <VehicleAlertsPanel
                                        workspace={workspace}
                                        onNavigate={navigate}
                                        onChanged={refresh}
                                    />
                                )}
                                {at('trips') && (
                                    <TripHistory
                                        workspace={workspace}
                                        focusDate={location.date}
                                        onNavigate={navigate}
                                    />
                                )}
                            </section>
                        </PageLayout>
                    )}
                    <TabSearchPalette
                        open={findOpen}
                        onClose={() => setFindOpen(false)}
                        groups={searchGroups}
                        onTab={openFound}
                        testIdPrefix="vehicle-workspace"
                        searchLabel="Find a section in this vehicle"
                    />
                    {checkOpen && (
                        <CheckFlowDialog
                            workspace={workspace}
                            onClose={() => setCheckOpen(false)}
                            onChanged={refresh}
                        />
                    )}
                    {reportOpen && (
                        <ReportProblemDialog
                            workspace={workspace}
                            back={location}
                            onClose={() => setReportOpen(false)}
                            onChanged={refresh}
                        />
                    )}
                    {photoOpen && (
                        <PhotoDialog
                            vehicle={vehicle}
                            onClose={() => setPhotoOpen(false)}
                            onSaved={refresh}
                        />
                    )}
                </div>
            </CatalogueProvider>
        </AppLayout>
    );
}
