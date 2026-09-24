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
    type PageHeaderRailItem,
} from '@/components/page';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Bell,
    CalendarDays,
    Camera,
    Car,
    ClipboardCheck,
    MapPin,
    Route,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { outcomeLabel } from './checks-model';
import './studio.css';
import type { VehicleWorkspace } from './types';
import {
    checkOverdue,
    formatKm,
    headerStatus,
    lastCheck,
    SOURCE_KIND_LABELS,
    type MainTab,
    type WorkspaceLocation,
} from './workspace-model';

const FUEL_LABELS: Record<string, string> = {
    petrol: 'Petrol',
    diesel: 'Diesel',
    electric: 'Electric',
    hybrid: 'Hybrid',
    lpg: 'LPG',
};

export const MAIN_RAIL: PageHeaderRailItem<MainTab>[] = [
    { key: 'overview', label: 'Overview', icon: Car },
    { key: 'service', label: 'Service & compliance', icon: ShieldCheck },
    { key: 'checks', label: 'Checks & inspections', icon: ClipboardCheck },
    { key: 'maintenance', label: 'Maintenance', icon: Wrench },
    { key: 'map', label: 'Map', icon: MapPin },
    { key: 'trips', label: 'Trip history', icon: Route },
    { key: 'calendar', label: 'Calendar', icon: CalendarDays },
];

export function VehicleHeader({
    workspace,
    tab,
    onNavigate,
    onFind,
    onPhoto,
    onStartCheck,
    onReport,
}: {
    workspace: VehicleWorkspace;
    tab: MainTab;
    onNavigate: (location: WorkspaceLocation) => void;
    onFind: () => void;
    onPhoto: () => void;
    /** Start a vehicle check; omitted when the person can't inspect. */
    onStartCheck?: () => void;
    /** Report a problem; omitted when the person can't report. */
    onReport?: () => void;
}) {
    const { vehicle, can, odometer, checks, work } = workspace;
    const status = headerStatus(workspace);
    const identity = [
        vehicle.registration_number,
        vehicle.asset_tag,
        vehicle.site?.name,
    ]
        .filter(Boolean)
        .join(' · ');
    const specification = [
        [vehicle.manufacturer, vehicle.model].filter(Boolean).join(' '),
        vehicle.fuel_type
            ? (FUEL_LABELS[vehicle.fuel_type] ?? vehicle.fuel_type)
            : null,
        vehicle.use_purpose,
    ]
        .filter(Boolean)
        .join(' · ');
    const currentReading = odometer.readings.find(
        (reading) => reading.is_current,
    );
    const nextService = workspace.schedules.find(
        (schedule) => schedule.is_active,
    );
    const hold = work.active_restrictions > 0;
    const overdueCheck = checkOverdue(workspace, workspace.as_of.slice(0, 10));
    const last = lastCheck(checks);
    const maintenanceValue = !work.can_view
        ? 'Not available'
        : hold
          ? 'Hold active'
          : work.total
            ? 'View work'
            : 'No history';

    return (
        <PageHeader
            variant="profile"
            wrapTitle
            mark={
                <div className="identity-mark">
                    <Link
                        href="/fleet-assets/vehicles"
                        className="hero-back"
                        aria-label="Back to vehicles"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>
                    {/* eslint-disable-next-line no-restricted-syntax -- The profile ring doubles as the photo control, as in the approved design. */}
                    <button
                        type="button"
                        className="eh-mark-ring photo-header-trigger"
                        disabled={!can.manage_documents}
                        aria-label={
                            vehicle.photo_url
                                ? 'Change vehicle profile photo'
                                : 'Upload vehicle profile photo'
                        }
                        onClick={onPhoto}
                    >
                        {vehicle.photo_url ? (
                            <img src={vehicle.photo_url} alt={vehicle.name} />
                        ) : (
                            <Car className="size-[25px]" />
                        )}
                        {can.manage_documents && (
                            <Camera
                                className="photo-corner size-[15px]"
                                aria-hidden
                            />
                        )}
                    </button>
                </div>
            }
            title={vehicle.name}
            titleChip={
                <PageHeaderStatusChip variant={status.variant}>
                    {status.label}
                </PageHeaderStatusChip>
            }
            subline={
                <>
                    {identity ? (
                        <span className="block">{identity}</span>
                    ) : null}
                    {specification ? (
                        <span className="block">{specification}</span>
                    ) : null}
                </>
            }
            actions={
                <>
                    <PageHeaderSearchTrigger
                        placeholder="Find in this vehicle…"
                        onOpen={onFind}
                    />
                    <PageHeaderGlassButton
                        icon={CalendarDays}
                        onClick={() => onNavigate({ tab: 'calendar' })}
                    >
                        Calendar
                    </PageHeaderGlassButton>
                    {onStartCheck ? (
                        <PageHeaderPrimaryButton
                            icon={ClipboardCheck}
                            onClick={onStartCheck}
                        >
                            Start check
                        </PageHeaderPrimaryButton>
                    ) : null}
                    {onReport ? (
                        <PageHeaderGlassButton onClick={onReport}>
                            Report a problem
                        </PageHeaderGlassButton>
                    ) : null}
                </>
            }
            meters={
                <div className="meter-grid">
                    <PageHeaderMeterBlock
                        label="Odometer"
                        ariaLabel="View mileage history"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'mileage' })
                        }
                    >
                        <PageHeaderMeterBig>
                            {odometer.current_km !== null
                                ? formatKm(odometer.current_km)
                                : 'Unknown'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {currentReading?.observed_at
                                ? `${formatDateTime(currentReading.observed_at)} · ${SOURCE_KIND_LABELS[currentReading.source_kind] ?? 'Reading'}`
                                : 'No observation available'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Next service"
                        ariaLabel="View evidence and due dates"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'evidence' })
                        }
                    >
                        <PageHeaderMeterBig>
                            {nextService
                                ? nextService.next_due_at
                                    ? formatDateOnly(nextService.next_due_at)
                                    : formatKm(nextService.next_due_km)
                                : 'Not scheduled'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {nextService
                                ? [
                                      nextService.next_due_at &&
                                      nextService.next_due_km !== null
                                          ? formatKm(nextService.next_due_km)
                                          : null,
                                      nextService.name,
                                  ]
                                      .filter(Boolean)
                                      .join(' · ')
                                : 'Set up a service requirement'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Last check"
                        ariaLabel="View checks and inspections"
                        onClick={() => onNavigate({ tab: 'checks' })}
                    >
                        <PageHeaderMeterBig>
                            {!last
                                ? 'No record'
                                : overdueCheck
                                  ? 'Overdue'
                                  : last.outcome
                                    ? outcomeLabel(last.outcome)
                                    : 'Submitted'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {last
                                ? [`CHK-${last.id}`, last.template]
                                      .filter(Boolean)
                                      .join(' · ')
                                : 'No submitted checks'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Maintenance"
                        tone={hold ? 'critical' : 'brand'}
                        ariaLabel="View maintenance"
                        onClick={() => onNavigate({ tab: 'maintenance' })}
                    >
                        <PageHeaderMeterBig>
                            {maintenanceValue}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {!work.can_view
                                ? 'Maintenance access required'
                                : !work.total
                                  ? 'No maintenance recorded'
                                  : work.awaiting_release
                                    ? 'Repair complete · release pending'
                                    : 'Original issues and work'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </div>
            }
            filters={
                <div className="header-filters">
                    <span>
                        As at {formatDateTime(workspace.as_of)} ·
                        Pacific/Auckland
                    </span>
                    {/* eslint-disable-next-line no-restricted-syntax -- The design's 23px header filter pill. */}
                    <button
                        type="button"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'reminders' })
                        }
                    >
                        <Bell className="size-[14px]" aria-hidden /> Reminders
                        &amp; follow-up
                    </button>
                </div>
            }
            rail={
                <PageHeaderRail
                    items={MAIN_RAIL}
                    value={tab}
                    onSelect={(key) => onNavigate({ tab: key })}
                    onFind={onFind}
                    ariaLabel="Vehicle sections"
                />
            }
        />
    );
}
