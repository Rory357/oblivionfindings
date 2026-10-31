import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
} from '@/components/page';
import type { StatusVariant } from '@/components/ui/status-badge';
import {
    formatDateTime,
    formatDateTimeLong,
    formatRelative,
} from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    BatteryCharging,
    BedDouble,
    BellRing,
    Car,
    Cctv,
    Cog,
    Cpu,
    DoorOpen,
    Droplets,
    Fence,
    Flame,
    HardDrive,
    HeartPulse,
    KeyRound,
    Laptop,
    MapPin,
    PersonStanding,
    Phone,
    Pill,
    Plug,
    Printer,
    Router,
    Search,
    Server,
    ShieldAlert,
    Siren,
    Snowflake,
    Tag,
    Thermometer,
    Video,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import type { DeviceProfile, DeviceProfileSectionKey } from './device-profile';

/** One fitting icon per stored taxonomy category; Cpu covers anything else. */
const CATEGORY_ICONS: Record<string, LucideIcon> = {
    // Security
    alarm: Siren,
    cctv: Cctv,
    access_control: KeyRound,
    perimeter: Fence,
    // Tracking
    vehicle_tracker: Car,
    personal_tracker: MapPin,
    asset_tracker: Tag,
    telematics: Car,
    // Healthcare
    fall_detection: PersonStanding,
    occupancy: Activity,
    wandering: DoorOpen,
    bed_sensor: BedDouble,
    medication: Pill,
    nurse_call: BellRing,
    wellness: HeartPulse,
    environmental: Thermometer,
    appliance: Plug,
    // Network & IT
    server: Server,
    storage: HardDrive,
    network: Router,
    power: BatteryCharging,
    endpoint: Laptop,
    voice: Phone,
    recording: Video,
    rack: Server,
    it_environmental: Thermometer,
    printer: Printer,
    // Facilities & IoT
    leak_detection: Droplets,
    gas_detection: Flame,
    cold_chain: Snowflake,
    mechanical: Cog,
    facility_access: DoorOpen,
    building_safety: ShieldAlert,
};

export function deviceCategoryIcon(
    category: string | null | undefined,
): LucideIcon {
    return (category && CATEGORY_ICONS[category]) || Cpu;
}

/** Tone for the recorded device state (DeviceStatus) shown in the title chip. */
function deviceStateVariant(state: string | null): StatusVariant {
    switch (state) {
        case 'active':
            return 'success';
        case 'degraded':
        case 'quarantined':
            return 'warning';
        case 'offline':
        case 'lost':
            return 'critical';
        case 'maintenance':
            return 'info';
        default:
            return 'neutral';
    }
}

/** A clock for relative wording that keeps "5m ago" honest while the page stays open. */
function useNow(intervalMs = 30_000): number {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), intervalMs);
        return () => window.clearInterval(timer);
    }, [intervalMs]);
    return now;
}

export function DeviceProfileHeader({
    profile,
    asOf,
    onSection,
    onSearch,
    actions,
    rail,
    filters,
}: {
    profile: DeviceProfile;
    asOf: string;
    onSection: (section: DeviceProfileSectionKey) => void;
    onSearch: () => void;
    actions: ReactNode;
    rail: ReactNode;
    filters?: ReactNode;
}) {
    const header = profile.header;
    const monitoring = profile.health.monitoring;
    const now = useNow();
    const observedAt = header.freshness.observedAt;
    const meters: Array<{
        label: string;
        value: ReactNode;
        caption: ReactNode;
        section: DeviceProfileSectionKey;
    }> = [
        {
            label: 'Recorded health',
            value: header.health.label,
            caption:
                header.health.deviceStateLabel ?? 'Device state not recorded',
            section: 'health',
        },
        {
            label: 'Checks',
            value: monitoring
                ? `${monitoring.healthy}/${monitoring.enabled}`
                : 'Unavailable',
            caption: 'Healthy / enabled checks; device health is separate',
            section: 'monitors',
        },
        {
            label: 'Last observation',
            value: (
                <time
                    dateTime={observedAt ?? undefined}
                    title={
                        observedAt ? formatDateTimeLong(observedAt) : undefined
                    }
                >
                    {formatRelative(observedAt, now, 'Not recorded')}
                </time>
            ),
            caption: observedAt
                ? `${formatDateTime(observedAt)} · ${header.providerObservation.label}`
                : header.providerObservation.label,
            section: 'health',
        },
        {
            label: 'Assignment',
            value: header.assignment
                ? (header.assignment.name ?? 'Name not recorded')
                : 'Unassigned',
            caption:
                header.assignment?.type.replaceAll('_', ' ') ??
                'No current owner',
            section: 'assignments',
        },
        {
            label: 'Required action',
            value: header.requiredAction.label,
            caption: header.requiredAction.description,
            section: header.requiredAction.section,
        },
    ];
    const target = (requested: DeviceProfileSectionKey) =>
        profile.sections.find((section) => section.key === requested) ??
        profile.sections[0];
    return (
        <PageHeader
            variant="profile"
            icon={deviceCategoryIcon(header.identity.category)}
            title={header.identity.name}
            wrapTitle
            backHref="/security-devices/devices"
            titleChip={
                <PageHeaderStatusChip
                    variant={deviceStateVariant(header.health.deviceState)}
                    icon={Activity}
                >
                    {header.health.deviceStateLabel ??
                        'Device state not recorded'}
                </PageHeaderStatusChip>
            }
            subline={[
                header.identity.manufacturer,
                header.identity.model,
                header.identity.type,
                header.location?.name ?? 'Unassigned',
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    <PageHeaderGlassButton onClick={onSearch}>
                        <Search className="size-4" />
                        Find a section
                    </PageHeaderGlassButton>
                    {actions}
                </>
            }
            meters={meters
                .filter((meter) => target(meter.section))
                .map((meter) => {
                    const section = target(meter.section)!;
                    return (
                        <PageHeaderMeterBlock
                            key={meter.label}
                            label={meter.label}
                            onClick={() => onSection(section.key)}
                            ariaLabel={`Open ${section.label}: ${meter.label}`}
                        >
                            <PageHeaderMeterBig>
                                {meter.value}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {meter.caption}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    );
                })}
            mobileSummary={`${header.health.label} · ${header.requiredAction.label}`}
            expandedContent={
                <div className="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs leading-snug text-band-foreground/75">
                    <span>Device UID: {header.identity.uid}</span>
                    <span aria-hidden="true">·</span>
                    <span>
                        Observation freshness:{' '}
                        {header.freshness.state.replaceAll('_', ' ')}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>
                        Location:{' '}
                        {header.location?.href ? (
                            <Link
                                href={header.location.href}
                                className="font-medium text-band-foreground underline underline-offset-2 hover:text-band-foreground/90"
                            >
                                {header.location.name}
                            </Link>
                        ) : (
                            (header.location?.name ?? 'Unassigned')
                        )}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>
                        Provider observation: {header.providerObservation.label}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>
                        Read started{' '}
                        {formatDateTimeLong(asOf, 'at a time not recorded')}.
                        This is a dated read, not an atomic or live health
                        check.
                    </span>
                </div>
            }
            rail={rail}
            filters={filters}
        />
    );
}
