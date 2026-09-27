import {
    BookingWizard,
    defaultBookingStart,
    type BookingWizardMode,
} from '@/components/fleet-assets/vehicle-workspace/booking-wizard';
import type {
    BookingRow,
    VehicleCalendarSummary,
} from '@/components/fleet-assets/vehicle-workspace/calendar-types';
import type { VehicleProfile } from '@/components/fleet-assets/vehicle-workspace/types';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import { EntityCard } from '@/components/lists/entity-card';
import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import PageShell from '@/components/page-shell';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    type PageHeaderRailItem,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { type CalView } from '@/pages/sites/calendar/_parts';
import { Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    Car,
    Clock,
    Columns3,
    Download,
    LayoutGrid,
    List,
    MapPin,
    Plus,
    Rows3,
    Truck,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    FleetCalendar,
    type FleetCalendarVehicle,
    type FleetEvent,
} from './fleet-calendar';
import { FleetEvidenceMeters } from './fleet-header';
import { FleetMap } from './fleet-map';
import { registerEvidence, useRegisterEvidence } from './register-evidence';

type Vehicle = {
    id: number;
    name: string;
    asset_tag: string;
    registration_number?: string | null;
    body_type?: string | null;
    seating_capacity?: number | null;
    has_wheelchair_ramp?: boolean;
    has_hoist?: boolean;
    status: string;
    /** False for a vehicle seen through central fleet oversight, outside the person's Sites. */
    at_your_sites?: boolean;
    state: {
        status: string;
        /** Withheld (null) where the trip Site rule doesn't allow positions. */
        lat: number | null;
        lng: number | null;
        speed_kph: number | null;
        battery_pct: number | null;
        last_seen_at: string | null;
    } | null;
    home_site: { id: number; name: string } | null;
};

type PaginatedOrArray<T> =
    | T[]
    | {
          data: T[];
          links?: Array<{ url: string | null; label: string; active: boolean }>;
          meta?: { current_page?: number; last_page?: number; total?: number };
      };

function toArray<T>(input: PaginatedOrArray<T> | null | undefined): T[] {
    if (!input) return [];
    if (Array.isArray(input)) return input;
    return input.data ?? [];
}

type Props = {
    vehicles: PaginatedOrArray<Vehicle>;
    all_vehicles?: FleetCalendarVehicle[];
    sites?: Array<{ id: number; name: string }>;
    assignment_sites?: Array<{ id: number; name: string }>;
    filters?: Record<string, string | null>;
    hero: {
        total: number;
        available: number | null;
        availability_label?: string;
        in_use: number;
        maintenance: number;
    };
    compliance: {
        wof_due: number;
        wof_expired: number;
        rego_due: number;
        rego_expired: number;
        cof_due: number;
        cof_expired: number;
        insurance_expiring: number | null;
        insurance_expired: number | null;
        open_alerts: number;
        critical_alerts: number;
    };
    can: {
        manage: boolean;
    };
};

export default function VehiclesIndex({
    vehicles: rawVehicles,
    all_vehicles: allVehicles = [],
    sites,
    assignment_sites: assignmentSites = [],
    filters = {},
    hero,
    compliance,
    can,
}: Props) {
    const vehicles = toArray(rawVehicles);
    const paginationLinks = !Array.isArray(rawVehicles)
        ? (rawVehicles?.links ?? [])
        : [];
    const paginationMeta = !Array.isArray(rawVehicles)
        ? (rawVehicles?.meta ?? {})
        : {};
    const canManage = can.manage;
    const [searchTerm, setSearchTerm] = useState(filters.search ?? '');
    const [statusFilter, setStatusFilter] = useState('all');
    const [isRefreshing, setIsRefreshing] = useState(false);
    const contextMenu = useEntityContextMenu<Vehicle>();
    const [requestDraft, setRequestDraft] = useState<{
        vehicleId?: number;
        startLocal?: string;
        endLocal?: string;
    } | null>(null);
    const [workflow, setWorkflow] = useState<{
        vehicle: VehicleProfile;
        summary: VehicleCalendarSummary;
        mode: BookingWizardMode;
    } | null>(null);
    const [workflowError, setWorkflowError] = useState('');
    const [lastTimeChange, setLastTimeChange] = useState<{
        vehicleId: number;
        row: BookingRow;
        version: number;
    } | null>(null);
    const workflowRequest = useRef<AbortController | null>(null);
    useEffect(() => () => workflowRequest.current?.abort(), []);
    const [calendarRevision, setCalendarRevision] = useState(0);
    const params =
        typeof window === 'undefined'
            ? new URLSearchParams()
            : new URLSearchParams(window.location.search);
    const requestedView = params.get('view');
    const view: 'register' | 'map' | CalView = [
        'map',
        'month',
        'week',
        'day',
        'agenda',
        'timeline',
    ].includes(requestedView ?? '')
        ? (requestedView as 'map' | CalView)
        : 'register';
    const date = /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '')
        ? params.get('date')!
        : new Intl.DateTimeFormat('en-CA', {
              timeZone: 'Pacific/Auckland',
              year: 'numeric',
              month: '2-digit',
              day: '2-digit',
          }).format(new Date());
    const vehicleId = Number(params.get('vehicle')) || null;
    const isCalendar = view !== 'register' && view !== 'map';
    const [meterTarget, setMeterTarget] = useState<HTMLDivElement | null>(null);
    const [actionTarget, setActionTarget] = useState<HTMLDivElement | null>(
        null,
    );
    const [filterTarget, setFilterTarget] = useState<HTMLDivElement | null>(
        null,
    );
    const headerTargets = {
        meters: meterTarget,
        actions: actionTarget,
        filters: filterTarget,
    };
    const layout = params.get('layout') === 'cards' ? 'cards' : 'table';
    const patchParams = (patch: Record<string, string | number | null>) => {
        const next = new URLSearchParams(window.location.search);
        Object.entries(patch).forEach(([key, value]) =>
            value === null || value === '' || value === 'all'
                ? next.delete(key)
                : next.set(key, String(value)),
        );
        router.get(
            `/fleet-assets/vehicles?${next}`,
            {},
            { preserveState: false, preserveScroll: true },
        );
    };
    useEffect(() => {
        if (searchTerm === (filters.search ?? '')) return;
        const timer = window.setTimeout(
            () => patchParams({ search: searchTerm.trim(), page: null }),
            350,
        );
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- only submitted search and current field are relevant
    }, [searchTerm, filters.search]);
    const scope = allVehicles.filter(
        (vehicle) =>
            (!filters.site_id ||
                filters.site_id === 'all' ||
                vehicle.home_site?.id === Number(filters.site_id)) &&
            (!filters.type ||
                filters.type === 'all' ||
                vehicle.body_type === filters.type) &&
            (!filters.capacity ||
                filters.capacity === 'all' ||
                (vehicle.seating_capacity ?? 0) >= Number(filters.capacity)) &&
            (!filters.access ||
                filters.access !== 'wheelchair' ||
                vehicle.has_wheelchair_ramp ||
                vehicle.has_hoist) &&
            (!filters.status ||
                filters.status === 'all' ||
                vehicle.status === filters.status) &&
            (!filters.search ||
                `${vehicle.name} ${vehicle.asset_tag ?? ''} ${vehicle.registration_number ?? ''}`
                    .toLowerCase()
                    .includes(filters.search.toLowerCase())),
    );
    const profile = (id: number, tab?: string) => {
        const back = encodeURIComponent(
            window.location.pathname + window.location.search,
        );
        window.location.assign(
            `/fleet-assets/vehicles/${id}?${tab ? `tab=${tab}&` : ''}${tab === 'calendar' ? `date=${date}&` : ''}return_to=${back}`,
        );
    };
    const openBooking = async (
        id: number | null,
        start?: string,
        end?: string,
        event?: Pick<FleetEvent, 'kind' | 'recordId' | 'version'>,
        proposal?: {
            start: string;
            end: string;
            startOffset?: string;
            endOffset?: string;
        },
    ) => {
        workflowRequest.current?.abort();
        const controller = new AbortController();
        workflowRequest.current = controller;
        if (!event) {
            setWorkflowError('');
            setWorkflow(null);
            setRequestDraft({
                vehicleId: id ?? undefined,
                startLocal:
                    start ?? defaultBookingStart(isCalendar ? date : undefined),
                endLocal: end,
            });
            return;
        }
        setWorkflowError('');
        const vehicle = allVehicles.find((row) => row.id === id);
        if (!vehicle) {
            setWorkflowError(
                'This vehicle is no longer in the permitted fleet.',
            );
            return;
        }
        try {
            const summaryResponse = await fetch(
                `/fleet-assets/vehicles/${id}/calendar/summary`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            );
            if (!summaryResponse.ok)
                throw new Error(
                    'Current booking permissions could not be loaded.',
                );
            const summary =
                (await summaryResponse.json()) as VehicleCalendarSummary;
            let mode: BookingWizardMode;
            if (event) {
                if (event.kind !== 'booking' || !event.recordId)
                    throw new Error('This entry cannot be edited here.');
                const recordResponse = await fetch(
                    `/fleet-assets/vehicles/${id}/calendar/records/booking/${event.recordId}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (!recordResponse.ok)
                    throw new Error(
                        'This booking changed or is no longer available. Recheck the calendar.',
                    );
                const result = (await recordResponse.json()) as {
                    row: BookingRow;
                };
                if (!result.row.can.edit)
                    throw new Error(
                        'You cannot change this booking. Open its source record for the current state.',
                    );
                if (proposal && event.version !== result.row.lock_version)
                    throw new Error(
                        'This booking changed since the calendar loaded. Recheck before moving it.',
                    );
                mode = {
                    kind: 'change',
                    row: result.row,
                    proposedStartLocal: proposal?.start,
                    proposedEndLocal: proposal?.end,
                    proposedStartOffset: proposal?.startOffset,
                    proposedEndOffset: proposal?.endOffset,
                };
            } else {
                if (!summary.can.request)
                    throw new Error(
                        'Booking requests are not permitted for this vehicle at your sites.',
                    );
                mode = { kind: 'request', startLocal: start, endLocal: end };
            }
            if (controller.signal.aborted) return;
            setWorkflow({
                vehicle: {
                    id: vehicle.id,
                    name: vehicle.name,
                    asset_tag: vehicle.asset_tag,
                    registration_number: vehicle.registration_number,
                    site: vehicle.home_site,
                } as VehicleProfile,
                summary,
                mode,
            });
        } catch (reason) {
            if (controller.signal.aborted) return;
            setWorkflowError(
                reason instanceof Error
                    ? reason.message
                    : 'The booking source could not be loaded.',
            );
        }
    };
    const railItems: PageHeaderRailItem<typeof view>[] = [
        { key: 'register', label: 'Vehicles', icon: Car },
        { key: 'map', label: 'Map', icon: MapPin },
        { key: 'month', label: 'Month', icon: LayoutGrid },
        { key: 'week', label: 'Week', icon: Columns3 },
        { key: 'day', label: 'Day', icon: Clock },
        { key: 'agenda', label: 'Agenda', icon: List },
        { key: 'timeline', label: 'Timeline', icon: Rows3 },
    ];
    const registerSource = useRegisterEvidence(view === 'register');
    const registerStale =
        registerSource.state === 'ready' &&
        (!Number.isFinite(Date.parse(registerSource.asOf)) ||
            Date.now() - Date.parse(registerSource.asOf) > 5 * 60000);
    const evidenceFor = (id: number) =>
        registerEvidence(
            registerStale ? [] : registerSource.events,
            id,
            Date.now(),
        );
    const currentEvidence = (id: number) =>
        registerSource.state === 'loading'
            ? 'Loading calendar evidence…'
            : registerSource.state === 'failed'
              ? 'Calendar evidence unavailable'
              : registerStale
                ? 'Calendar evidence needs a recheck'
                : (evidenceFor(id).current?.statusLabel ??
                  'Assess the selected trip and time');

    // Bulk selection
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [bulkSiteId, setBulkSiteId] = useState('');

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            setIsRefreshing(true);
            router.reload({
                only: ['vehicles', 'all_vehicles', 'hero', 'compliance'],
                onFinish: () => setIsRefreshing(false),
            });
        }, 30000);
        return () => window.clearInterval(interval);
    }, []);

    // The register is filtered before pagination by the server. A second
    // client-side filter would make its totals and page links dishonest.
    const filteredVehicles = vehicles;

    const toggleSelect = useCallback((id: number) => {
        setSelectedIds((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    }, []);

    // Bulk Site and tracker actions need the vehicle's own Site.
    const selectableVehicles = filteredVehicles.filter(
        (v) => v.at_your_sites !== false,
    );

    const toggleSelectAll = useCallback(() => {
        if (selectedIds.length === selectableVehicles.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(selectableVehicles.map((v) => v.id));
        }
    }, [selectableVehicles, selectedIds.length]);

    const handleBulkAction = useCallback(
        (action: string) => {
            if (selectedIds.length === 0) return;
            const payload: Record<string, unknown> = {
                action,
                ids: selectedIds,
            };
            if (action === 'assign_site' && bulkSiteId) {
                payload.site_id = Number(bulkSiteId);
            }
            router.post('/fleet-assets/vehicles/bulk-action', payload as any, {
                preserveState: true,
                onSuccess: () => setSelectedIds([]),
            });
        },
        [selectedIds, bulkSiteId],
    );

    const vehicleActions = (vehicle: Vehicle) => [
        {
            label: 'Open vehicle profile',
            icon: Car,
            onClick: () => profile(vehicle.id),
        },
        {
            label: 'View fleet calendar',
            icon: CalendarDays,
            onClick: () => patchParams({ view: 'week', vehicle: vehicle.id }),
        },
        {
            label: 'View on map',
            icon: MapPin,
            onClick: () => patchParams({ view: 'map', mapFocus: vehicle.id }),
        },
        ...(vehicle.at_your_sites === false
            ? []
            : [
                  {
                      label: 'Request this vehicle',
                      icon: Plus,
                      onClick: () => void openBooking(vehicle.id),
                  },
              ]),
        {
            label: 'Open source evidence',
            icon: ArrowRight,
            onClick: () => profile(vehicle.id, 'maintenance'),
        },
    ];
    const statusTone = (vehicle: Vehicle) =>
        ['maintenance', 'out_of_service'].includes(vehicle.status)
            ? ('warning' as const)
            : ('neutral' as const);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Fleet', href: '/fleet-assets/vehicles' },
            ]}
        >
            <Head title="Fleet" />
            <PageHeader
                icon={Truck}
                title="Fleet"
                titleChip={
                    <PageHeaderStatusChip variant="neutral">
                        {sites?.find(
                            (site) => String(site.id) === filters.site_id,
                        )?.name ?? 'All permitted sites'}
                    </PageHeaderStatusChip>
                }
                subline="Vehicles, use and bookings · Pacific/Auckland"
                actions={
                    <>
                        {isCalendar ? (
                            <div className="contents" ref={setActionTarget} />
                        ) : (
                            <>
                                {view === 'register' && (
                                    <>
                                        <PageHeaderSearch
                                            value={searchTerm}
                                            onChange={setSearchTerm}
                                            placeholder="Search name, registration or tag"
                                        />
                                        <PageHeaderGlassButton
                                            aria-label="Export permitted register CSV"
                                            onClick={() =>
                                                window.location.assign(
                                                    `/fleet-assets/vehicles?${new URLSearchParams({ ...Object.fromEntries(params), export: 'csv' })}`,
                                                )
                                            }
                                        >
                                            <Download className="size-4" />
                                            Export
                                        </PageHeaderGlassButton>
                                    </>
                                )}
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={() => void openBooking(null)}
                                >
                                    Request vehicle
                                </PageHeaderPrimaryButton>
                            </>
                        )}
                    </>
                }
                meters={
                    view === 'map' ? (
                        <div className="contents" ref={setMeterTarget} />
                    ) : (
                        <>
                            {isCalendar ? (
                                <div
                                    className="contents"
                                    ref={setMeterTarget}
                                />
                            ) : (
                                <PageHeaderMeterBlock
                                    label="Permitted vehicles"
                                    onClick={() =>
                                        patchParams({
                                            view: 'register',
                                            site_id: null,
                                            status: null,
                                            type: null,
                                            capacity: null,
                                            access: null,
                                            search: null,
                                        })
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {hero.total}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Complete fleet scope
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            )}
                            <FleetEvidenceMeters
                                total={hero.total}
                                inUse={hero.in_use}
                                maintenance={hero.maintenance}
                                onAssess={() => void openBooking(vehicleId)}
                            />
                        </>
                    )
                }
                filters={
                    <>
                        <PageHeaderFilterSelect
                            label="Site"
                            value={filters.site_id ?? 'all'}
                            options={[
                                { value: 'all', label: 'All sites' },
                                ...(sites ?? []).map((site) => ({
                                    value: String(site.id),
                                    label: site.name,
                                })),
                            ]}
                            onChange={(value) =>
                                patchParams({ site_id: value, page: null })
                            }
                        />
                        {isCalendar ? (
                            <div className="contents" ref={setFilterTarget} />
                        ) : (
                            <>
                                <PageHeaderFilterSelect
                                    label="Type"
                                    value={filters.type ?? 'all'}
                                    options={[
                                        { value: 'all', label: 'All types' },
                                        ...[
                                            ...new Set(
                                                allVehicles
                                                    .map(
                                                        (vehicle) =>
                                                            vehicle.body_type,
                                                    )
                                                    .filter(
                                                        (
                                                            type,
                                                        ): type is string =>
                                                            !!type,
                                                    ),
                                            ),
                                        ].map((type) => ({
                                            value: type,
                                            label: type,
                                        })),
                                    ]}
                                    onChange={(value) =>
                                        patchParams({ type: value, page: null })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Seats"
                                    value={filters.capacity ?? 'all'}
                                    options={[
                                        { value: 'all', label: 'Any capacity' },
                                        ...[4, 5, 7, 8, 10].map((count) => ({
                                            value: String(count),
                                            label: `${count}+ seats`,
                                        })),
                                    ]}
                                    onChange={(value) =>
                                        patchParams({
                                            capacity: value,
                                            page: null,
                                        })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Access"
                                    value={filters.access ?? 'all'}
                                    options={[
                                        { value: 'all', label: 'Any access' },
                                        {
                                            value: 'wheelchair',
                                            label: 'Wheelchair access',
                                        },
                                    ]}
                                    onChange={(value) =>
                                        patchParams({
                                            access: value,
                                            page: null,
                                        })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Status"
                                    value={filters.status ?? 'all'}
                                    options={[
                                        { value: 'all', label: 'All statuses' },
                                        ...[
                                            ...new Set(
                                                allVehicles.map(
                                                    (vehicle) => vehicle.status,
                                                ),
                                            ),
                                        ].map((status) => ({
                                            value: status,
                                            label: status,
                                        })),
                                    ]}
                                    onChange={(value) =>
                                        patchParams({
                                            status: value,
                                            page: null,
                                        })
                                    }
                                />
                                {view === 'register' && (
                                    <PageHeaderViewToggle
                                        value={layout}
                                        onChange={(value) =>
                                            patchParams({ layout: value })
                                        }
                                        options={[
                                            {
                                                value: 'table',
                                                label: 'List',
                                                icon: List,
                                            },
                                            {
                                                value: 'cards',
                                                label: 'Cards',
                                                icon: LayoutGrid,
                                            },
                                        ]}
                                    />
                                )}
                            </>
                        )}
                    </>
                }
                rail={
                    <PageHeaderRail
                        items={railItems}
                        value={view}
                        onSelect={(next) =>
                            patchParams({ view: next, page: null })
                        }
                        ariaLabel="Fleet views"
                    />
                }
            />
            <PageShell>
                {view === 'register' && (
                    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                        <div
                            className="flex flex-wrap gap-2"
                            aria-label="Recorded expiry and alert counts"
                        >
                            {[
                                [
                                    'WoF',
                                    compliance.wof_due,
                                    compliance.wof_expired,
                                ],
                                [
                                    'Rego',
                                    compliance.rego_due,
                                    compliance.rego_expired,
                                ],
                                [
                                    'CoF',
                                    compliance.cof_due,
                                    compliance.cof_expired,
                                ],
                                [
                                    'Insurance',
                                    compliance.insurance_expiring,
                                    compliance.insurance_expired,
                                ],
                            ].map(([label, due, expired]) => (
                                <a
                                    key={String(label)}
                                    href="/fleet-assets/compliance"
                                >
                                    <StatusBadge
                                        variant={
                                            Number(expired) > 0
                                                ? 'critical'
                                                : Number(due) > 0
                                                  ? 'warning'
                                                  : 'neutral'
                                        }
                                    >
                                        {label}:{' '}
                                        {due === null || expired === null
                                            ? 'Not assessed'
                                            : Number(expired) > 0
                                              ? `${expired} expired`
                                              : Number(due) > 0
                                                ? `${due} due soon`
                                                : 'No expiry alerts'}
                                    </StatusBadge>
                                </a>
                            ))}
                            <a href="/fleet-assets/alerts">
                                <StatusBadge
                                    variant={
                                        compliance.critical_alerts
                                            ? 'critical'
                                            : compliance.open_alerts
                                              ? 'warning'
                                              : 'neutral'
                                    }
                                >
                                    Alerts: {compliance.open_alerts} open
                                </StatusBadge>
                            </a>
                        </div>
                    </div>
                )}

                {view === 'map' ? (
                    <FleetMap
                        headerTarget={meterTarget}
                        permittedCount={hero.total}
                        scopeIds={scope.map((vehicle) => vehicle.id)}
                        requestableIds={allVehicles
                            .filter((vehicle) => vehicle.at_your_sites)
                            .map((vehicle) => vehicle.id)}
                        onProfile={(vehicle, tab) => profile(vehicle.id, tab)}
                        onCalendar={(vehicle) =>
                            profile(vehicle.id, 'calendar')
                        }
                        onRequest={(vehicle) => void openBooking(vehicle.id)}
                    />
                ) : view !== 'register' ? (
                    <FleetCalendar
                        headerTargets={headerTargets}
                        vehicleCriteria={Object.entries({
                            Search: filters.search,
                            Type: filters.type,
                            Seats: filters.capacity,
                            Access: filters.access,
                            Status: filters.status,
                        })
                            .filter(([, value]) => value && value !== 'all')
                            .map(([label, value]) => `${label}: ${value}`)}
                        onClearCriteria={() =>
                            patchParams({
                                search: null,
                                type: null,
                                capacity: null,
                                access: null,
                                status: null,
                                page: null,
                            })
                        }
                        onView={(next) => patchParams({ view: next })}
                        key={calendarRevision}
                        view={view}
                        date={date}
                        vehicles={scope}
                        vehicleId={vehicleId}
                        onDate={(next) => patchParams({ date: next })}
                        onDay={(next) =>
                            patchParams({ view: 'day', date: next })
                        }
                        onVehicle={(id) => patchParams({ vehicle: id })}
                        onRequest={(id, start, end) =>
                            void openBooking(id, start, end)
                        }
                        onEdit={(event, proposal) =>
                            void openBooking(
                                event.vehicleId,
                                undefined,
                                undefined,
                                event,
                                proposal,
                            )
                        }
                        onProfile={(id) => profile(id)}
                    />
                ) : vehicles.length === 0 ? (
                    <FleetEmptyState
                        icon={Car}
                        title={
                            hero.total === 0
                                ? 'No vehicles in your permitted fleet'
                                : 'No vehicles match these filters'
                        }
                        description={
                            hero.total === 0
                                ? 'Vehicle records will appear here when they are assigned to permitted sites.'
                                : 'Try clearing the search, site, type, capacity, access or status criteria.'
                        }
                        actionLabel={
                            hero.total === 0 ? undefined : 'Clear filters'
                        }
                        actionHref={
                            hero.total === 0
                                ? undefined
                                : '/fleet-assets/vehicles'
                        }
                    />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 className="text-section-title">
                                    Vehicle register
                                </h2>
                                <p className="text-caption">
                                    {vehicles.length} on this page ·{' '}
                                    {paginationMeta.total ?? vehicles.length}{' '}
                                    matching · {hero.total} permitted overall
                                </p>
                                <p className="text-caption">
                                    Calendar context: next 14 days ·{' '}
                                    {registerSource.state === 'ready' &&
                                    !registerStale
                                        ? `Source checked ${formatDateTime(registerSource.asOf)}`
                                        : registerSource.state === 'loading'
                                          ? 'Checking source records…'
                                          : 'Open the calendar to recheck source records'}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    patchParams({
                                        search: null,
                                        site_id: null,
                                        type: null,
                                        capacity: null,
                                        access: null,
                                        status: null,
                                        page: null,
                                    })
                                }
                            >
                                Clear filters
                            </Button>
                        </div>
                        {layout === 'table' ? (
                            <EntityTable
                                rows={filteredVehicles}
                                rowKey={(vehicle) => vehicle.id}
                                identityLabel="Vehicle"
                                identityWidth="1.5fr"
                                minWidth={930}
                                identity={(vehicle) => ({
                                    icon: Car,
                                    name: vehicle.name,
                                    subline:
                                        [
                                            vehicle.registration_number,
                                            vehicle.asset_tag,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') ||
                                        'No registration or asset tag',
                                })}
                                columns={[
                                    {
                                        key: 'site',
                                        label: 'Home site / type',
                                        width: '1.1fr',
                                        cell: (vehicle) => (
                                            <div>
                                                {vehicle.home_site?.name ??
                                                    'No home site'}
                                                <span className="text-caption block">
                                                    {vehicle.body_type ??
                                                        'Type not recorded'}
                                                </span>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'capacity',
                                        label: 'Capacity / access',
                                        width: '1fr',
                                        cell: (vehicle) => (
                                            <div>
                                                {vehicle.seating_capacity ==
                                                null
                                                    ? 'Seats unknown'
                                                    : `${vehicle.seating_capacity} seats`}
                                                <span className="text-caption block">
                                                    {vehicle.has_wheelchair_ramp ||
                                                    vehicle.has_hoist
                                                        ? 'Wheelchair equipment recorded'
                                                        : 'Access not recorded'}
                                                </span>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'status',
                                        label: 'Use / source evidence',
                                        width: '1fr',
                                        cell: (vehicle) => (
                                            <div>
                                                <EntityStatusChip
                                                    variant={statusTone(
                                                        vehicle,
                                                    )}
                                                >
                                                    {vehicle.status}
                                                </EntityStatusChip>
                                                <span className="text-caption block">
                                                    {currentEvidence(
                                                        vehicle.id,
                                                    )}
                                                </span>
                                                <span className="text-caption block">
                                                    {vehicle.state?.last_seen_at
                                                        ? `Tracker report ${formatDateTime(vehicle.state.last_seen_at)}`
                                                        : 'No recent tracker report'}
                                                </span>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'next',
                                        label: 'Next record / action',
                                        width: '0.85fr',
                                        cell: (vehicle) => (
                                            <div>
                                                <p className="text-caption">
                                                    {evidenceFor(vehicle.id)
                                                        .next
                                                        ? `${evidenceFor(vehicle.id).next!.statusLabel} · ${formatDateTime(evidenceFor(vehicle.id).next!.start)}`
                                                        : 'Open calendar to assess availability'}
                                                </p>
                                                <Button
                                                    variant="link"
                                                    size="sm"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        patchParams({
                                                            view: 'week',
                                                            vehicle: vehicle.id,
                                                        });
                                                    }}
                                                >
                                                    View calendar{' '}
                                                    <ArrowRight className="size-3" />
                                                </Button>
                                            </div>
                                        ),
                                    },
                                ]}
                                actionsFor={vehicleActions}
                                onOpen={(vehicle) => profile(vehicle.id)}
                                onRowContextMenu={contextMenu.open}
                                selection={
                                    canManage
                                        ? {
                                              keys: new Set(selectedIds),
                                              onToggle: (vehicle) =>
                                                  toggleSelect(vehicle.id),
                                              labelFor: (vehicle) =>
                                                  `Select ${vehicle.name}`,
                                              canSelect: (vehicle) =>
                                                  vehicle.at_your_sites !==
                                                  false,
                                          }
                                        : undefined
                                }
                            />
                        ) : (
                            <div className="grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
                                {filteredVehicles.map((vehicle) => (
                                    <EntityCard
                                        key={vehicle.id}
                                        meridian="neutral"
                                        icon={Car}
                                        name={vehicle.name}
                                        subline={
                                            [
                                                vehicle.registration_number,
                                                vehicle.asset_tag,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') ||
                                            'No registration or tag'
                                        }
                                        actions={vehicleActions(vehicle)}
                                        onOpen={() => profile(vehicle.id)}
                                        onContextMenu={(event) =>
                                            contextMenu.open(event, vehicle)
                                        }
                                        selection={
                                            canManage
                                                ? {
                                                      checked:
                                                          selectedIds.includes(
                                                              vehicle.id,
                                                          ),
                                                      label: `Select ${vehicle.name}`,
                                                      onToggle: () =>
                                                          toggleSelect(
                                                              vehicle.id,
                                                          ),
                                                      disabled:
                                                          vehicle.at_your_sites ===
                                                          false,
                                                  }
                                                : undefined
                                        }
                                        chips={
                                            <>
                                                <EntityChip>
                                                    {vehicle.body_type ??
                                                        'Type unknown'}
                                                </EntityChip>
                                                <EntityChip>
                                                    {vehicle.seating_capacity ==
                                                    null
                                                        ? 'Seats unknown'
                                                        : `${vehicle.seating_capacity} seats`}
                                                </EntityChip>
                                            </>
                                        }
                                        alerts={
                                            <>
                                                <EntityStatusChip
                                                    variant={statusTone(
                                                        vehicle,
                                                    )}
                                                >
                                                    {vehicle.status}
                                                </EntityStatusChip>
                                                <span className="text-caption block">
                                                    {currentEvidence(
                                                        vehicle.id,
                                                    )}
                                                </span>
                                            </>
                                        }
                                        footer={{
                                            personIcon: MapPin,
                                            primary:
                                                vehicle.home_site?.name ??
                                                'No home site',
                                            secondary: vehicle.state
                                                ?.last_seen_at
                                                ? `Tracker report ${formatDateTime(vehicle.state.last_seen_at)}`
                                                : 'Tracking evidence unavailable',
                                        }}
                                        openLabel="Open profile"
                                    />
                                ))}
                            </div>
                        )}
                        {contextMenu.ctx && (
                            <EntityContextMenu
                                x={contextMenu.ctx.x}
                                y={contextMenu.ctx.y}
                                icon={Car}
                                title={contextMenu.ctx.record.name}
                                items={vehicleActions(contextMenu.ctx.record)}
                                onClose={contextMenu.close}
                            />
                        )}

                        {/* Bulk Action Bar */}
                        {canManage && selectedIds.length > 0 && (
                            <article className="fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-background px-4 py-3 shadow-lg">
                                <span className="text-sm font-medium">
                                    {selectedIds.length} vehicle
                                    {selectedIds.length !== 1 ? 's' : ''}{' '}
                                    selected
                                </span>
                                <div className="flex items-center gap-2">
                                    <Select
                                        value={bulkSiteId}
                                        onValueChange={setBulkSiteId}
                                    >
                                        <SelectTrigger className="h-8 w-40 text-xs">
                                            <SelectValue placeholder="Assign to site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {assignmentSites.map((s) => (
                                                <SelectItem
                                                    key={s.id}
                                                    value={String(s.id)}
                                                >
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            handleBulkAction('assign_site')
                                        }
                                        disabled={!bulkSiteId}
                                    >
                                        Assign
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            handleBulkAction('mark_offline')
                                        }
                                    >
                                        Mark Offline
                                    </Button>
                                </div>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setSelectedIds([])}
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            </article>
                        )}

                        {/* Pagination */}
                        {(paginationMeta.last_page ?? 1) > 1 &&
                            paginationLinks.length > 0 && (
                                <div className="flex items-center justify-center gap-1 pt-4">
                                    {paginationLinks.map((link, i) => (
                                        <Button
                                            key={i}
                                            variant={
                                                link.active
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            size="sm"
                                            disabled={!link.url}
                                            onClick={() =>
                                                link.url && router.get(link.url)
                                            }
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
                                    ))}
                                </div>
                            )}
                    </>
                )}
                {workflowError && (
                    <div
                        role="alert"
                        className="mt-3 rounded-lg border border-status-critical bg-status-critical-bg p-3 text-status-critical-foreground"
                    >
                        {workflowError}
                    </div>
                )}
                {lastTimeChange && !workflow && (
                    <Card
                        role="status"
                        className="mt-3 flex flex-wrap items-center gap-3 p-3"
                    >
                        <span className="text-sm">
                            Booking time changed. Returning to the previous time
                            requires review and current source checks.
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                void openBooking(
                                    lastTimeChange.vehicleId,
                                    undefined,
                                    undefined,
                                    {
                                        kind: 'booking',
                                        recordId: lastTimeChange.row.id,
                                        version: lastTimeChange.version,
                                    },
                                    {
                                        start: toDatetimeLocal(
                                            lastTimeChange.row.starts_at,
                                        ),
                                        end: toDatetimeLocal(
                                            lastTimeChange.row.ends_at,
                                        ),
                                    },
                                )
                            }
                        >
                            Undo time change
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setLastTimeChange(null)}
                        >
                            Dismiss
                        </Button>
                    </Card>
                )}
                {requestDraft && (
                    <BookingWizard
                        requestVehicles={scope
                            .filter((vehicle) => vehicle.at_your_sites)
                            .map((vehicle) => ({
                                id: vehicle.id,
                                name: vehicle.name,
                                asset_tag: vehicle.asset_tag,
                                registration_number:
                                    vehicle.registration_number ?? null,
                                site: vehicle.home_site,
                            }))}
                        initialVehicleId={requestDraft.vehicleId}
                        mode={{
                            kind: 'request',
                            startLocal: requestDraft.startLocal,
                            endLocal: requestDraft.endLocal,
                        }}
                        onClose={() => setRequestDraft(null)}
                        onSaved={() => {
                            setCalendarRevision((value) => value + 1);
                            router.reload({
                                only: ['vehicles', 'all_vehicles', 'hero'],
                            });
                        }}
                    />
                )}
                {workflow && (
                    <BookingWizard
                        vehicle={workflow.vehicle}
                        summary={workflow.summary}
                        mode={workflow.mode}
                        onTimeChanged={(row, version) =>
                            setLastTimeChange({
                                vehicleId: workflow.vehicle.id,
                                row,
                                version,
                            })
                        }
                        onClose={() => setWorkflow(null)}
                        onSaved={() => {
                            setCalendarRevision((value) => value + 1);
                            router.reload({
                                only: ['vehicles', 'all_vehicles', 'hero'],
                            });
                        }}
                    />
                )}
            </PageShell>
        </AppLayout>
    );
}
