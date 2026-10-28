import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
} from '@/components/page';
import { SecurityDevicesSectionTabs } from '@/components/security-devices/security-devices-section-tabs';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    CheckCircle2,
    Cpu,
    ExternalLink,
    FileText,
    Info,
    Lock,
    SlidersHorizontal,
} from 'lucide-react';
import { useMemo, useState } from 'react';

export interface DriverCapabilityItem {
    key: string;
    label: string;
    requires_confirmation: boolean;
}

export interface OfficialDocLink {
    label: string;
    url: string;
}

export interface SupportedDriverRecord {
    key: string;
    brand: string;
    name: string;
    device_class: string;
    device_class_label: string;
    support_level: 'tested_on_hardware' | 'built_from_docs' | 'planned';
    support_level_label: string;
    support_badge_variant: StatusVariant;
    transport_summary: string;
    models: string[];
    capabilities: DriverCapabilityItem[];
    summary: string;
    limitations_note: string | null;
    hardware_evidence: string | null;
    official_docs: OfficialDocLink[];
    setup_href: string | null;
}

export interface SupportedDevicesProps {
    actor_id: number;
    generated_at: string;
    summary: {
        total_drivers: number;
        brands_count: number;
        tested_on_hardware: number;
        built_from_docs: number;
        planned: number;
    };
    classes: Array<{
        key: string;
        label: string;
        count: number;
    }>;
    support_levels: Array<{
        key: 'tested_on_hardware' | 'built_from_docs' | 'planned';
        label: string;
        badge_variant: StatusVariant;
        count: number;
    }>;
    drivers: SupportedDriverRecord[];
}

export default function SupportedDevicesPage(props: SupportedDevicesProps) {
    const [search, setSearch] = useState('');
    const [selectedClass, setSelectedClass] = useState<string>('all');
    const [selectedLevel, setSelectedLevel] = useState<string>('all');

    const filteredDrivers = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();

        return props.drivers.filter((driver) => {
            if (
                selectedClass !== 'all' &&
                driver.device_class !== selectedClass
            ) {
                return false;
            }

            if (
                selectedLevel !== 'all' &&
                driver.support_level !== selectedLevel
            ) {
                return false;
            }

            if (!needle) {
                return true;
            }

            const haystack = [
                driver.brand,
                driver.name,
                driver.device_class_label,
                driver.support_level_label,
                driver.transport_summary,
                driver.summary,
                driver.limitations_note ?? '',
                ...driver.models,
                ...driver.capabilities.map((cap) => cap.label),
            ]
                .join(' ')
                .toLocaleLowerCase();

            return haystack.includes(needle);
        });
    }, [props.drivers, search, selectedClass, selectedLevel]);

    const groupedByClass = useMemo(() => {
        return props.classes
            .map((deviceClass) => ({
                ...deviceClass,
                items: filteredDrivers.filter(
                    (driver) => driver.device_class === deviceClass.key,
                ),
            }))
            .filter((group) => group.items.length > 0);
    }, [filteredDrivers, props.classes]);

    const hasActiveFilters =
        search.trim() !== '' ||
        selectedClass !== 'all' ||
        selectedLevel !== 'all';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Supported devices',
                    href: '/security-devices/supported-devices',
                },
            ]}
        >
            <Head title="Supported devices - Security & Devices" />

            <div className="space-y-5" data-testid="supported-devices-page">
                <PageHeader
                    icon={Cpu}
                    title="Supported devices"
                    subline="Brands, open standards, and verification levels across cameras, alarms, sensors, trackers, and Site networks"
                    actions={
                        <PageHeaderSearch
                            value={search}
                            onChange={setSearch}
                            ariaLabel="Search supported brands, models, and protocols"
                            placeholder="Search brand, model, or protocol…"
                        />
                    }
                    meters={[
                        <PageHeaderMeterBlock
                            key="all"
                            label="Total drivers"
                            onClick={() => setSelectedLevel('all')}
                            ariaLabel="Show all drivers"
                        >
                            <PageHeaderMeterBig>
                                {props.summary.total_drivers}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Across {props.summary.brands_count} brands &
                                standards
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>,
                        <PageHeaderMeterBlock
                            key="tested"
                            label="Tested on hardware"
                            onClick={() =>
                                setSelectedLevel('tested_on_hardware')
                            }
                            ariaLabel="Filter to drivers tested on real hardware"
                        >
                            <PageHeaderMeterBig>
                                {props.summary.tested_on_hardware}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Verified on physical units
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>,
                        <PageHeaderMeterBlock
                            key="docs"
                            label="Built from vendor docs"
                            onClick={() => setSelectedLevel('built_from_docs')}
                            ariaLabel="Filter to drivers built from vendor docs"
                        >
                            <PageHeaderMeterBig>
                                {props.summary.built_from_docs}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Ready from published specs
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>,
                        <PageHeaderMeterBlock
                            key="planned"
                            label="Planned"
                            onClick={() => setSelectedLevel('planned')}
                            ariaLabel="Filter to planned drivers"
                        >
                            <PageHeaderMeterBig>
                                {props.summary.planned}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Partner or NDA API required
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>,
                    ]}
                    mobileSummary={`${props.summary.tested_on_hardware} tested · ${props.summary.built_from_docs} built from docs · ${props.summary.planned} planned`}
                    filters={
                        hasActiveFilters ? (
                            <PageHeaderFilterButton
                                onClick={() => {
                                    setSearch('');
                                    setSelectedClass('all');
                                    setSelectedLevel('all');
                                }}
                            >
                                Clear filters
                            </PageHeaderFilterButton>
                        ) : undefined
                    }
                />

                <SecurityDevicesSectionTabs
                    section="setup"
                    activeTab="supported-devices"
                />

                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <SlidersHorizontal className="size-4 text-muted-foreground" />
                                <CardTitle className="text-base">
                                    Filter by verification status or device type
                                </CardTitle>
                            </div>
                            <span className="text-caption text-muted-foreground">
                                Showing {filteredDrivers.length} of{' '}
                                {props.summary.total_drivers} entries
                            </span>
                        </div>
                        <CardDescription>
                            We distinguish hardware we have verified in person
                            from drivers built against published vendor
                            specifications and partner-gated APIs still on the
                            roadmap.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div
                            className="flex flex-wrap items-center gap-2"
                            role="group"
                            aria-label="Verification level filters"
                        >
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    selectedLevel === 'all'
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => setSelectedLevel('all')}
                            >
                                All levels ({props.summary.total_drivers})
                            </Button>
                            {props.support_levels.map((level) => (
                                <Button
                                    key={level.key}
                                    type="button"
                                    size="sm"
                                    variant={
                                        selectedLevel === level.key
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setSelectedLevel(level.key)}
                                >
                                    {level.label} ({level.count})
                                </Button>
                            ))}
                        </div>

                        <div
                            className="flex flex-wrap items-center gap-2"
                            role="group"
                            aria-label="Device class filters"
                        >
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    selectedClass === 'all'
                                        ? 'secondary'
                                        : 'ghost'
                                }
                                onClick={() => setSelectedClass('all')}
                            >
                                All device types
                            </Button>
                            {props.classes.map((deviceClass) => (
                                <Button
                                    key={deviceClass.key}
                                    type="button"
                                    size="sm"
                                    variant={
                                        selectedClass === deviceClass.key
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    onClick={() =>
                                        setSelectedClass(deviceClass.key)
                                    }
                                >
                                    {deviceClass.label} ({deviceClass.count})
                                </Button>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {groupedByClass.length === 0 ? (
                    <Card data-testid="supported-devices-empty">
                        <CardContent className="py-10 text-center">
                            <p className="text-sm font-medium">
                                No matching brands or drivers found
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Try clearing your search or switching back to
                                all verification levels.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-4"
                                onClick={() => {
                                    setSearch('');
                                    setSelectedClass('all');
                                    setSelectedLevel('all');
                                }}
                            >
                                Show all supported devices
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    groupedByClass.map((group) => (
                        <section
                            key={group.key}
                            className="space-y-3"
                            data-testid={`driver-group-${group.key}`}
                        >
                            <div className="flex items-baseline justify-between gap-2">
                                <h2 className="text-base font-semibold tracking-tight">
                                    {group.label}
                                </h2>
                                <span className="text-caption text-muted-foreground">
                                    {group.items.length}{' '}
                                    {group.items.length === 1
                                        ? 'driver'
                                        : 'drivers'}
                                </span>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                {group.items.map((driver) => (
                                    <Card
                                        key={driver.key}
                                        data-testid={`driver-card-${driver.key}`}
                                        className="flex flex-col justify-between"
                                    >
                                        <CardHeader className="space-y-2 pb-3">
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-caption font-medium text-muted-foreground">
                                                        {driver.brand}
                                                    </p>
                                                    <CardTitle className="text-base">
                                                        {driver.name}
                                                    </CardTitle>
                                                </div>
                                                <StatusBadge
                                                    variant={
                                                        driver.support_badge_variant
                                                    }
                                                >
                                                    {driver.support_level_label}
                                                </StatusBadge>
                                            </div>
                                            <p className="text-caption text-muted-foreground">
                                                Connection:{' '}
                                                {driver.transport_summary}
                                            </p>
                                        </CardHeader>

                                        <CardContent className="space-y-3 text-sm">
                                            <p className="text-foreground/90">
                                                {driver.summary}
                                            </p>

                                            {driver.models.length > 0 ? (
                                                <div>
                                                    <p className="text-caption font-medium text-muted-foreground">
                                                        Models & series
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {driver.models.join(
                                                            ' · ',
                                                        )}
                                                    </p>
                                                </div>
                                            ) : null}

                                            {driver.capabilities.length > 0 ? (
                                                <div className="space-y-1.5">
                                                    <p className="text-caption font-medium text-muted-foreground">
                                                        Supported features
                                                    </p>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {driver.capabilities.map(
                                                            (capability) => (
                                                                <span
                                                                    key={
                                                                        capability.key
                                                                    }
                                                                    className="inline-flex items-center gap-1 rounded-md border bg-muted/40 px-2 py-0.5 text-xs text-foreground"
                                                                    title={
                                                                        capability.requires_confirmation
                                                                            ? 'Requires role permission, operator confirmation, and audit log entry'
                                                                            : undefined
                                                                    }
                                                                >
                                                                    {capability.requires_confirmation ? (
                                                                        <Lock className="size-3 text-muted-foreground" />
                                                                    ) : null}
                                                                    {
                                                                        capability.label
                                                                    }
                                                                </span>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            ) : null}

                                            {driver.hardware_evidence ? (
                                                <div className="flex items-start gap-2 rounded-lg border border-status-success/30 bg-status-success-bg p-2.5 text-xs text-status-success-foreground">
                                                    <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-status-success" />
                                                    <span>
                                                        {
                                                            driver.hardware_evidence
                                                        }
                                                    </span>
                                                </div>
                                            ) : null}

                                            {driver.limitations_note ? (
                                                <div className="flex items-start gap-2 rounded-lg border bg-muted/30 p-2.5 text-xs text-muted-foreground">
                                                    <Info className="mt-0.5 size-3.5 shrink-0" />
                                                    <span>
                                                        {
                                                            driver.limitations_note
                                                        }
                                                    </span>
                                                </div>
                                            ) : null}

                                            <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                                                <div className="flex flex-wrap items-center gap-3">
                                                    {driver.official_docs.map(
                                                        (doc) => (
                                                            <a
                                                                key={doc.url}
                                                                href={doc.url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                                            >
                                                                <FileText className="size-3.5" />
                                                                {doc.label}
                                                                <ExternalLink className="size-3" />
                                                            </a>
                                                        ),
                                                    )}
                                                </div>

                                                {driver.setup_href ? (
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        <Link
                                                            href={
                                                                driver.setup_href
                                                            }
                                                        >
                                                            Open setup
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        </section>
                    ))
                )}
            </div>
        </AppLayout>
    );
}
