import MarketingLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    BarChart3,
    Bell,
    Building2,
    Calendar,
    CheckCircle2,
    Clock,
    Eye,
    FileText,
    GraduationCap,
    Heart,
    LayoutDashboard,
    MapPin,
    Pill,
    Radar,
    ScanEye,
    Shield,
    Smartphone,
    Users,
    Zap,
} from 'lucide-react';
import React from 'react';

const Features: React.FC = () => {
    const mainFeatures = [
        {
            icon: Users,
            title: 'Resident Management',
            description:
                'Complete digital care records for every person you support.',
            items: [
                'Comprehensive resident profiles with support history',
                'Digital care plans and risk assessments',
                'Outcome tracking and goal management',
                'Document storage and version control',
                'Family and advocate contact management',
                'Move-in/move-out workflows',
            ],
        },
        {
            icon: Activity,
            title: 'Visit & Task Management',
            description:
                'Schedule, track and evidence support visits seamlessly.',
            items: [
                'Intelligent visit scheduling and routing',
                'Real-time visit status tracking',
                'GPS-verified check-in/check-out',
                'Digital notes and outcome recording',
                'Task lists and action tracking',
                'Handover notes and shift summaries',
            ],
        },
        {
            icon: Pill,
            title: 'Medication Management',
            description:
                'Full eMAR functionality with controlled drug tracking.',
            items: [
                'Electronic Medication Administration Records (eMAR)',
                'Controlled drug registers and audits',
                'PRN medication tracking',
                'Medication stock management',
                'Missed medication alerts',
                'Pharmacy integration ready',
            ],
        },
        {
            icon: Shield,
            title: 'Compliance & Safeguarding',
            description:
                'Stay inspection-ready with comprehensive incident and audit tools.',
            items: [
                'Incident and accident reporting',
                'Safeguarding concern management',
                'Investigation workflows',
                'Audit trails for all actions',
                'Certification evidence packs',
                'Regulatory requirement tracking',
            ],
        },
        {
            icon: Calendar,
            title: 'Staff Rostering',
            description:
                'Efficient shift planning with availability and skills matching.',
            items: [
                'Drag-and-drop shift scheduling',
                'Staff availability management',
                'Skills and competency matching',
                'Time-off requests and approvals',
                'Shift swaps and cover finding',
                'Payroll export integration',
            ],
        },
        {
            icon: GraduationCap,
            title: 'Training & Competency',
            description:
                'Track staff credentials, training and professional development.',
            items: [
                'Training record management',
                'Competency assessments',
                'Credential expiry alerts',
                'Induction tracking',
                'E-learning integration',
                'Mandatory training dashboards',
            ],
        },
    ];

    const additionalFeatures = [
        {
            icon: Building2,
            title: 'Property & Asset Management',
            description: 'Track equipment, maintenance and site compliance.',
        },
        {
            icon: MapPin,
            title: 'Fleet Tracking',
            description: 'Vehicle tracking for services with transport needs.',
        },
        {
            icon: BarChart3,
            title: 'Analytics & Reporting',
            description: 'Insights into service performance and trends.',
        },
        {
            icon: FileText,
            title: 'Document Management',
            description: 'Centralised policies, procedures and templates.',
        },
        {
            icon: Clock,
            title: 'Timesheets',
            description: 'Digital timesheet submission and approval workflows.',
        },
        {
            icon: Heart,
            title: 'Respite Management',
            description:
                'Dedicated tools for respite and short break services.',
        },
    ];

    const benefits = [
        {
            icon: Smartphone,
            title: 'Mobile-First Design',
            description:
                'Support workers can access everything they need from any device, anywhere.',
        },
        {
            icon: Shield,
            title: 'Bank-Grade Security',
            description:
                'Privacy Act compliant with end-to-end encryption and role-based access control.',
        },
        {
            icon: AlertTriangle,
            title: 'Real-Time Alerts',
            description:
                'Automatic notifications for missed visits, overdue tasks and incidents.',
        },
        {
            icon: LayoutDashboard,
            title: 'Customisable Dashboards',
            description:
                'Each role sees what matters most to them—from managers to support workers.',
        },
    ];

    const controlRoomFeatures = [
        {
            icon: ScanEye,
            title: 'Smart Motion Detection',
            description:
                'Advanced sensors detect movement patterns and distinguish between normal and unusual activity.',
        },
        {
            icon: Bell,
            title: 'Instant Alerts',
            description:
                'Real-time notifications when activity is detected in specific zones or during unusual hours.',
        },
        {
            icon: Radar,
            title: 'Presence Sensing',
            description:
                'Know when someone enters or leaves a monitored area with accurate presence detection.',
        },
        {
            icon: Zap,
            title: 'Timeline Reconstruction',
            description:
                'Automatically piece together movement events across multiple zones to understand what happened.',
        },
    ];

    return (
        <MarketingLayout
            title="Features"
            description="Explore the complete feature set of Oblivion Findings - the modern operations platform for supported living."
        >
            {/* Hero */}
            <section className="text-center">
                <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
                    Everything you need to run{' '}
                    <span className="text-primary">exceptional care</span>
                </h1>
                <p className="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground">
                    From resident records to staff scheduling, smart monitoring
                    to compliance reporting—all the tools you need in one
                    integrated platform.
                </p>
            </section>

            {/* Control Room - Featured Section */}
            <section className="mt-16">
                <div className="rounded-3xl border border-border bg-gradient-to-br from-primary/5 via-primary/5 to-background p-1">
                    <div className="rounded-[20px] bg-card p-8 sm:p-12">
                        <div className="grid items-center gap-10 lg:grid-cols-2">
                            <div>
                                <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-1 text-sm text-primary">
                                    <Eye size={16} />
                                    <span>Smart Monitoring</span>
                                </div>

                                <h2 className="text-3xl font-bold text-foreground">
                                    Smart Monitoring
                                </h2>
                                <p className="mt-4 text-lg text-muted-foreground">
                                    Intelligent monitoring that provides
                                    situational awareness without compromising
                                    privacy. Automated detection, timeline
                                    reconstruction, and real-time alerts give
                                    you the full picture of what's happening
                                    across all your locations.
                                </p>

                                <div className="mt-8 grid gap-6 sm:grid-cols-2">
                                    {controlRoomFeatures.map(
                                        (feature, index) => {
                                            const Icon = feature.icon;
                                            return (
                                                <div
                                                    key={index}
                                                    className="group relative flex gap-4 overflow-hidden rounded-xl p-3 transition-all hover:bg-muted/30"
                                                >
                                                    {/* Gloss overlay */}
                                                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 text-primary shadow-inner shadow-primary/10">
                                                        <Icon size={20} />
                                                    </div>
                                                    <div>
                                                        <h3 className="font-semibold text-foreground">
                                                            {feature.title}
                                                        </h3>
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            {
                                                                feature.description
                                                            }
                                                        </p>
                                                    </div>
                                                </div>
                                            );
                                        },
                                    )}
                                </div>

                                <div className="mt-8">
                                    <Link
                                        href="/smart-monitoring"
                                        className="inline-flex items-center gap-2 rounded-full bg-primary px-6 py-3 text-sm font-medium text-primary-foreground shadow-lg shadow-primary/25 transition-all hover:bg-primary/90"
                                    >
                                        Explore Smart Monitoring
                                        <ArrowRight size={16} />
                                    </Link>
                                </div>
                            </div>

                            {/* Control Room Dashboard Preview - No camera feeds, only alerts/detections */}
                            <div className="relative">
                                <div className="absolute -inset-4 rounded-3xl bg-gradient-to-r from-primary/20 to-primary/20 blur-2xl" />
                                <div className="relative rounded-2xl border border-border bg-background p-4 shadow-2xl">
                                    <div className="mb-4 flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <LayoutDashboard
                                                size={20}
                                                className="text-primary"
                                            />
                                            <span className="font-medium text-foreground">
                                                Control Room
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="h-2 w-2 animate-pulse rounded-full bg-status-success" />
                                            <span className="text-xs text-muted-foreground">
                                                Monitoring Active
                                            </span>
                                        </div>
                                    </div>

                                    {/* Location Status Overview */}
                                    <div className="mb-3 rounded-lg border border-border bg-muted/50 p-3">
                                        <div className="mb-2 text-xs font-medium text-foreground">
                                            Location Status
                                        </div>
                                        <div className="grid grid-cols-2 gap-2 text-xs">
                                            <div className="flex items-center justify-between rounded bg-background p-2">
                                                <span className="text-muted-foreground">
                                                    Main Entrance
                                                </span>
                                                <span className="flex items-center gap-1 text-status-success">
                                                    <span className="h-1.5 w-1.5 rounded-full bg-status-success" />
                                                    Clear
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between rounded bg-background p-2">
                                                <span className="text-muted-foreground">
                                                    Lounge
                                                </span>
                                                <span className="flex items-center gap-1 text-status-success">
                                                    <span className="h-1.5 w-1.5 rounded-full bg-status-success" />
                                                    Clear
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between rounded bg-background p-2">
                                                <span className="text-muted-foreground">
                                                    Kitchen
                                                </span>
                                                <span className="flex items-center gap-1 text-status-warning">
                                                    <span className="h-1.5 w-1.5 rounded-full bg-status-warning" />
                                                    Activity
                                                </span>
                                            </div>
                                            <div className="flex items-center justify-between rounded bg-background p-2">
                                                <span className="text-muted-foreground">
                                                    Garden
                                                </span>
                                                <span className="flex items-center gap-1 text-status-success">
                                                    <span className="h-1.5 w-1.5 rounded-full bg-status-success" />
                                                    Clear
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Alert Panel */}
                                    <div className="mb-3 rounded-lg border border-status-warning/20 bg-status-warning-bg p-3">
                                        <div className="flex items-start gap-3">
                                            <AlertTriangle
                                                size={16}
                                                className="mt-0.5 text-status-warning"
                                            />
                                            <div>
                                                <p className="text-xs font-medium text-status-warning dark:text-status-warning">
                                                    Activity Detected
                                                </p>
                                                <p className="text-[10px] text-muted-foreground">
                                                    Kitchen zone • 14:32:18 •
                                                    Unusual motion pattern
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Detection Timeline */}
                                    <div className="rounded-lg border border-border bg-muted/50 p-3">
                                        <div className="mb-2 text-xs font-medium text-foreground">
                                            Recent Detections
                                        </div>
                                        <div className="space-y-2 text-[11px]">
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Motion detected
                                                </span>
                                                <span className="text-foreground">
                                                    Kitchen • 2 min ago
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Presence ended
                                                </span>
                                                <span className="text-foreground">
                                                    Lounge • 15 min ago
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Motion detected
                                                </span>
                                                <span className="text-foreground">
                                                    Entrance • 32 min ago
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Main Features Grid */}
            <section className="mt-24 space-y-16">
                {mainFeatures.map((feature, index) => {
                    const Icon = feature.icon;
                    const isEven = index % 2 === 0;

                    return (
                        <div
                            key={index}
                            className={`grid items-center gap-10 lg:grid-cols-2 ${
                                isEven ? '' : 'lg:grid-flow-dense'
                            }`}
                        >
                            <div className={isEven ? '' : 'lg:col-start-2'}>
                                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                    <Icon size={28} />
                                </div>
                                <h2 className="mt-6 text-2xl font-bold text-foreground">
                                    {feature.title}
                                </h2>
                                <p className="mt-3 text-lg text-muted-foreground">
                                    {feature.description}
                                </p>
                                <ul className="mt-6 grid gap-3 sm:grid-cols-2">
                                    {feature.items.map((item, i) => (
                                        <li
                                            key={i}
                                            className="flex items-start gap-3"
                                        >
                                            <CheckCircle2
                                                size={18}
                                                className="mt-0.5 shrink-0 text-status-success"
                                            />
                                            <span className="text-sm text-muted-foreground">
                                                {item}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            {/* Feature illustration placeholder */}
                            <div
                                className={
                                    isEven
                                        ? ''
                                        : 'lg:col-start-1 lg:row-start-1'
                                }
                            >
                                <div className="group relative overflow-hidden rounded-2xl border border-border bg-card p-2 transition-all hover:shadow-lg hover:shadow-primary/5">
                                    {/* Gloss overlay */}
                                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                    <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                    <div className="relative rounded-xl bg-muted/50 p-8">
                                        <div className="flex items-center justify-center">
                                            <div className="text-center">
                                                <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl bg-gradient-to-br from-primary/20 to-primary/5 shadow-inner shadow-primary/10">
                                                    <Icon
                                                        size={40}
                                                        className="text-primary"
                                                    />
                                                </div>
                                                <p className="mt-4 text-sm text-muted-foreground">
                                                    {feature.title}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </section>

            {/* Additional Features */}
            <section className="mt-24">
                <h2 className="text-center text-2xl font-bold text-foreground">
                    And there's more...
                </h2>
                <div className="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {additionalFeatures.map((feature, index) => {
                        const Icon = feature.icon;
                        return (
                            <div
                                key={index}
                                className="group relative overflow-hidden rounded-2xl border border-border bg-card p-6 transition-all hover:border-primary/20 hover:shadow-lg hover:shadow-primary/5"
                            >
                                {/* Gloss overlay */}
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 text-primary shadow-inner shadow-primary/10">
                                    <Icon size={20} />
                                </div>
                                <h3 className="mt-4 text-lg font-semibold text-foreground">
                                    {feature.title}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {feature.description}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </section>

            {/* Benefits */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-card p-8 sm:p-12">
                    <div className="text-center">
                        <h2 className="text-2xl font-bold text-foreground">
                            Built for modern care providers
                        </h2>
                        <p className="mx-auto mt-4 max-w-xl text-muted-foreground">
                            Technology should make care delivery easier, not
                            harder. Here's how we help:
                        </p>
                    </div>

                    <div className="mt-10 grid gap-8 md:grid-cols-2">
                        {benefits.map((benefit, index) => {
                            const Icon = benefit.icon;
                            return (
                                <div
                                    key={index}
                                    className="group relative flex gap-4 overflow-hidden rounded-xl p-4 transition-all hover:bg-muted/30"
                                >
                                    {/* Gloss overlay */}
                                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 text-primary shadow-inner shadow-primary/10">
                                        <Icon size={24} />
                                    </div>
                                    <div>
                                        <h3 className="font-semibold text-foreground">
                                            {benefit.title}
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {benefit.description}
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* Integrations */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-gradient-to-br from-primary/5 to-transparent p-8 sm:p-12">
                    <div className="grid items-center gap-10 lg:grid-cols-2">
                        <div>
                            <h2 className="text-2xl font-bold text-foreground">
                                Integrates with your existing tools
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                Oblivion Findings plays nicely with the software
                                you already use. Our API and webhooks make it
                                easy to connect with:
                            </p>
                            <ul className="mt-6 space-y-3">
                                {[
                                    'Accounting and payroll systems',
                                    'Te Whatu Ora and NASC portals',
                                    'Pharmacy systems',
                                    'Communication tools (Teams, Slack)',
                                    'Business intelligence platforms',
                                    'Smart sensor and detection systems',
                                ].map((item, i) => (
                                    <li
                                        key={i}
                                        className="flex items-center gap-3"
                                    >
                                        <CheckCircle2
                                            size={18}
                                            className="text-status-success"
                                        />
                                        <span className="text-sm text-muted-foreground">
                                            {item}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="flex items-center justify-center">
                            <div className="grid grid-cols-3 gap-4">
                                {[...Array(6)].map((_, i) => (
                                    <div
                                        key={i}
                                        className="group relative flex h-20 w-20 items-center justify-center overflow-hidden rounded-2xl border border-border bg-background text-muted-foreground transition-all hover:border-primary/20"
                                    >
                                        {/* Gloss overlay */}
                                        <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/50 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                        <div className="h-8 w-8 rounded-lg bg-gradient-to-br from-muted/80 to-muted/40" />
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* CTA */}
            <section className="mt-24">
                <div className="rounded-3xl bg-gradient-to-r from-primary to-primary/90 px-6 py-12 sm:px-12 sm:py-16">
                    <div className="mx-auto max-w-3xl text-center">
                        <h2 className="text-2xl font-bold text-primary-foreground sm:text-3xl">
                            See these features in action
                        </h2>
                        <p className="mt-4 text-primary-foreground/80">
                            Get a personalised demo tailored to your service's
                            specific needs.
                        </p>
                        <div className="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                            <Link
                                href="/contact"
                                className="inline-flex items-center justify-center gap-2 rounded-full bg-background px-8 py-4 text-base font-medium text-foreground shadow-lg transition-all hover:bg-background/90"
                            >
                                Book a demo
                                <ArrowRight size={18} />
                            </Link>
                            <Link
                                href="/pricing"
                                className="inline-flex items-center justify-center gap-2 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 px-8 py-4 text-base font-medium text-primary-foreground transition-all hover:bg-primary-foreground/20"
                            >
                                View pricing
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </MarketingLayout>
    );
};

export default Features;
