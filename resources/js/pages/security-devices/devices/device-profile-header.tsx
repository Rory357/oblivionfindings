import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
} from '@/components/page';
import { formatDateTimeLong, formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { Activity, Cpu, Search } from 'lucide-react';
import type { ReactNode } from 'react';
import type { DeviceProfile, DeviceProfileSectionKey } from './device-profile';

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
            label: 'Monitoring',
            value: monitoring
                ? `${monitoring.healthy}/${monitoring.enabled}`
                : 'Unavailable',
            caption: 'Healthy / enabled monitors; device health is separate',
            section: 'monitors',
        },
        {
            label: 'Last observation',
            value: formatRelative(
                header.freshness.observedAt,
                Date.now(),
                'Not recorded',
            ),
            caption: header.providerObservation.label,
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
            icon={Cpu}
            title={header.identity.name}
            wrapTitle
            backHref="/security-devices/devices"
            titleChip={
                <PageHeaderStatusChip variant="neutral" icon={Activity}>
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
                <div className="text-caption space-y-1">
                    <p>Device UID: {header.identity.uid}</p>
                    <p>
                        Observation freshness:{' '}
                        {header.freshness.state.replaceAll('_', ' ')}
                    </p>
                    <p>
                        Location:{' '}
                        {header.location?.href ? (
                            <Link
                                href={header.location.href}
                                className="underline underline-offset-2"
                            >
                                {header.location.name}
                            </Link>
                        ) : (
                            (header.location?.name ?? 'Unassigned')
                        )}
                    </p>
                    <p>
                        Provider observation: {header.providerObservation.label}
                    </p>
                    <p>
                        Read started{' '}
                        {formatDateTimeLong(asOf, 'at a time not recorded')}.
                        This is a dated read, not an atomic or live health
                        check.
                    </p>
                </div>
            }
            rail={rail}
            filters={filters}
        />
    );
}
