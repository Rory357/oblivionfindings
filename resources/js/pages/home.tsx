import MarketingLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Camera,
    CheckCircle2,
    FileText,
    Heart,
    LayoutDashboard,
    Pill,
    ScanEye,
    Shield,
    Users,
} from 'lucide-react';
import React from 'react';

const Home: React.FC = () => {
    const features = [
        {
            icon: Users,
            title: 'Resident Management',
            description:
                'Complete profiles, care plans, risk assessments and support histories in one place.',
        },
        {
            icon: Activity,
            title: 'Visit & Task Tracking',
            description:
                'Schedule, assign and track support visits with real-time status updates.',
        },
        {
            icon: Pill,
            title: 'Medication Management',
            description:
                'eMAR integration, controlled drug tracking and administration records.',
        },
        {
            icon: Shield,
            title: 'Compliance & Safeguarding',
            description:
                'Incident reporting, investigations, audits and regulatory readiness.',
        },
        {
            icon: LayoutDashboard,
            title: 'Staff & Rostering',
            description:
                'Shift scheduling, availability, credentials and competency tracking.',
        },
        {
            icon: FileText,
            title: 'Documentation',
            description:
                'Digital notes, assessments, handovers and evidence packs.',
        },
    ];

    const benefits = [
        'Reduce admin time by up to 40%',
        'Audit-ready for certification',
        'Real-time visibility across all services',
        'Unlimited staff accounts',
    ];

    return (
        <MarketingLayout>
            {/* Hero Section */}
            <section className="relative overflow-hidden rounded-3xl border border-border bg-gradient-to-br from-muted/50 to-background px-6 py-16 sm:px-12 sm:py-24 lg:py-32">
                {/* Background effects */}
                <div className="pointer-events-none absolute inset-0">
                    <div className="absolute -top-20 -right-20 h-[400px] w-[400px] rounded-full bg-primary/10 blur-3xl dark:bg-primary/10" />
                    <div className="absolute -bottom-20 -left-20 h-[300px] w-[300px] rounded-full bg-status-success blur-3xl" />
                </div>

                <div className="relative mx-auto max-w-4xl text-center">
                    <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-4 py-1.5 text-sm text-primary">
                        <span className="h-2 w-2 animate-pulse rounded-full bg-status-success" />
                        <span>
                            Built for New Zealand supported living providers
                        </span>
                    </div>

                    <p className="mb-4 text-lg font-medium text-primary italic">
                        Preserving stories. Powered by code. Fueled by care.
                    </p>

                    <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl lg:text-6xl">
                        Everything your supported living{' '}
                        <span className="bg-gradient-to-r from-primary to-primary/70 bg-clip-text text-transparent">
                            service needs to thrive
                        </span>
                    </h1>

                    <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground">
                        Oblivion Findings is a modern operations platform built
                        specifically for New Zealand supported living providers.
                        Manage residents, staff, compliance, smart monitoring
                        and care delivery—all in one intuitive system.
                    </p>

                    <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <Link
                            href="/contact"
                            className="inline-flex items-center justify-center gap-2 rounded-full bg-primary px-8 py-4 text-base font-medium text-primary-foreground shadow-lg shadow-primary/25 transition-all hover:bg-primary/90 hover:shadow-primary/40"
                        >
                            Book a live demo
                            <ArrowRight size={18} />
                        </Link>

                        <Link
                            href="/features"
                            className="inline-flex items-center justify-center gap-2 rounded-full border border-border bg-background px-8 py-4 text-base font-medium text-foreground transition-all hover:bg-muted"
                        >
                            Explore features
                        </Link>
                    </div>

                    <div className="mt-12 flex flex-wrap items-center justify-center gap-6 text-sm text-muted-foreground">
                        {benefits.map((benefit, index) => (
                            <div
                                key={index}
                                className="flex items-center gap-2"
                            >
                                <CheckCircle2
                                    size={16}
                                    className="text-status-success"
                                />
                                <span>{benefit}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Control Room Highlight */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-gradient-to-br from-primary/5 to-background p-8 sm:p-12">
                    <div className="grid items-center gap-10 lg:grid-cols-2">
                        <div>
                            <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-1 text-sm text-primary">
                                <Camera size={16} />
                                <span>Smart Detection</span>
                            </div>
                            <h2 className="text-3xl font-bold text-foreground">
                                Know what's happening.{' '}
                                <span className="text-primary">
                                    Even when you're not there.
                                </span>
                            </h2>
                            <p className="mt-4 text-lg text-muted-foreground">
                                Our Control Room system provides intelligent
                                monitoring with automatic incident detection,
                                timeline reconstruction, and real-time alerts—
                                giving you complete situational awareness across
                                all your locations.
                            </p>
                            <ul className="mt-6 space-y-3">
                                {[
                                    'Automatic detection of unusual activity patterns',
                                    'Timeline reconstruction across multiple sensors',
                                    'Smart alerts for motion and presence detection',
                                    'Integrated with your Control Room dashboard',
                                    'Privacy-compliant with configurable zones',
                                ].map((item, index) => (
                                    <li
                                        key={index}
                                        className="flex items-start gap-3"
                                    >
                                        <CheckCircle2
                                            size={18}
                                            className="mt-0.5 shrink-0 text-status-success"
                                        />
                                        <span className="text-muted-foreground">
                                            {item}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <div className="mt-8">
                                <Link
                                    href="/smart-monitoring"
                                    className="inline-flex items-center gap-2 rounded-full border border-border bg-background px-6 py-3 text-sm font-medium text-foreground transition-all hover:bg-muted"
                                >
                                    Learn more about Smart Monitoring
                                    <ArrowRight size={16} />
                                </Link>
                            </div>
                        </div>

                        {/* Control Room Preview - Shows alerts/detections only, no camera feeds */}
                        <div className="relative">
                            <div className="absolute -inset-4 rounded-3xl bg-gradient-to-r from-primary/20 to-primary/20 blur-2xl" />
                            <div className="relative rounded-2xl border border-border bg-background p-4 shadow-2xl">
                                <div className="mb-4 flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <ScanEye
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
                                            Active
                                        </span>
                                    </div>
                                </div>

                                {/* Detection Timeline */}
                                <div className="space-y-3">
                                    <div className="rounded-lg border border-border bg-muted/50 p-3">
                                        <div className="mb-2 flex items-center gap-2">
                                            <div className="h-2 w-2 rounded-full bg-status-success" />
                                            <span className="text-xs font-medium text-foreground">
                                                Location Status
                                            </span>
                                            <span className="ml-auto text-[10px] text-muted-foreground">
                                                Now
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-2 gap-2 text-[11px]">
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Main Entrance
                                                </span>
                                                <span className="text-status-success">
                                                    Normal
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Lounge
                                                </span>
                                                <span className="text-status-success">
                                                    Normal
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Kitchen
                                                </span>
                                                <span className="text-status-warning">
                                                    Activity
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Garden
                                                </span>
                                                <span className="text-status-success">
                                                    Normal
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Alert Panel */}
                                    <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3">
                                        <div className="flex items-start gap-3">
                                            <div className="mt-0.5 flex h-2 w-2 shrink-0 rounded-full bg-status-warning" />
                                            <div className="flex-1">
                                                <div className="flex items-center justify-between">
                                                    <p className="text-xs font-medium text-status-warning dark:text-status-warning">
                                                        Activity Detected
                                                    </p>
                                                    <span className="text-[10px] text-muted-foreground">
                                                        14:32:18
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-[10px] text-muted-foreground">
                                                    Unusual motion pattern in
                                                    Kitchen area during
                                                    typically quiet period
                                                </p>
                                                <div className="mt-2 flex gap-2">
                                                    <span className="rounded bg-status-warning-bg px-1.5 py-0.5 text-[9px] text-status-warning">
                                                        Kitchen
                                                    </span>
                                                    <span className="rounded bg-status-warning-bg px-1.5 py-0.5 text-[9px] text-status-warning">
                                                        Motion
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Timeline Reconstruction */}
                                    <div className="rounded-lg border border-border bg-muted/50 p-3">
                                        <div className="mb-2 flex items-center gap-2">
                                            <div className="h-2 w-2 rounded-full bg-primary" />
                                            <span className="text-xs font-medium text-foreground">
                                                Timeline Reconstruction
                                            </span>
                                        </div>
                                        <div className="space-y-2">
                                            <div className="flex items-center gap-2 text-[11px]">
                                                <span className="w-12 text-muted-foreground">
                                                    14:32
                                                </span>
                                                <span className="text-status-success">
                                                    ●
                                                </span>
                                                <span className="text-foreground">
                                                    Entry detected
                                                </span>
                                                <span className="ml-auto text-muted-foreground">
                                                    Main Entrance
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-2 text-[11px]">
                                                <span className="w-12 text-muted-foreground">
                                                    14:33
                                                </span>
                                                <span className="text-status-success">
                                                    ●
                                                </span>
                                                <span className="text-foreground">
                                                    Movement to lounge
                                                </span>
                                                <span className="ml-auto text-muted-foreground">
                                                    Hallway
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-2 text-[11px]">
                                                <span className="w-12 text-muted-foreground">
                                                    14:35
                                                </span>
                                                <span className="text-status-warning">
                                                    ●
                                                </span>
                                                <span className="text-foreground">
                                                    Activity in kitchen
                                                </span>
                                                <span className="ml-auto text-muted-foreground">
                                                    Kitchen
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

            {/* Features Grid */}
            <section className="mt-24">
                <div className="text-center">
                    <h2 className="text-3xl font-bold tracking-tight text-foreground">
                        Built for the realities of supported living
                    </h2>
                    <p className="mx-auto mt-4 max-w-2xl text-muted-foreground">
                        Every feature designed with input from service managers,
                        support workers and compliance officers who understand
                        what it takes to deliver great care.
                    </p>
                </div>

                <div className="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {features.map((feature, index) => {
                        const Icon = feature.icon;
                        return (
                            <div
                                key={index}
                                className="group relative overflow-hidden rounded-2xl border border-border bg-card p-6 transition-all hover:border-primary/20 hover:shadow-lg hover:shadow-primary/5"
                            >
                                {/* Gloss overlay */}
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 text-primary shadow-inner shadow-primary/10 transition-colors">
                                    <Icon size={24} />
                                </div>
                                <h3 className="mt-4 text-lg font-semibold text-foreground">
                                    {feature.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {feature.description}
                                </p>
                            </div>
                        );
                    })}
                </div>

                <div className="mt-10 text-center">
                    <Link
                        href="/features"
                        className="inline-flex items-center gap-2 rounded-full border border-border bg-background px-6 py-3 text-sm font-medium text-foreground transition-all hover:bg-muted"
                    >
                        See all features
                        <ArrowRight size={16} />
                    </Link>
                </div>
            </section>

            {/* Emotional Quote Section */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-gradient-to-br from-primary/10 via-primary/5 to-background p-8 sm:p-12">
                    <div className="mx-auto max-w-3xl text-center">
                        <div className="mb-6 inline-flex items-center justify-center">
                            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/20">
                                <Heart className="h-6 w-6 text-primary" />
                            </div>
                        </div>
                        <blockquote className="text-2xl leading-relaxed font-medium text-foreground sm:text-3xl">
                            "Every person has a story worth telling. We're here
                            to help you capture those moments, preserve precious
                            memories, and create a legacy that lasts forever."
                        </blockquote>
                        <p className="mt-6 text-muted-foreground">
                            More than just records and compliance—Oblivion
                            Findings helps you honour the lives and journeys of
                            the people you support.
                        </p>
                    </div>
                </div>
            </section>

            {/* Dashboard Preview Section */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-card p-1">
                    <div className="rounded-[20px] bg-gradient-to-br from-muted/50 to-background p-6 sm:p-10">
                        <div className="grid items-center gap-10 lg:grid-cols-2">
                            <div>
                                <h2 className="text-2xl font-bold text-foreground sm:text-3xl">
                                    Clarity for managers.
                                    <br />
                                    <span className="text-primary">
                                        Simplicity for staff.
                                    </span>
                                </h2>
                                <p className="mt-4 text-muted-foreground">
                                    Give your team the tools they need to focus
                                    on what matters— delivering excellent
                                    person-centred support. No more paper
                                    diaries, missed handovers or hunting for
                                    information.
                                </p>

                                <div className="mt-8 space-y-4">
                                    {[
                                        'Real-time view of visits, incidents and tasks',
                                        'Automated alerts for overdue actions',
                                        'Secure web access for staff and managers',
                                        'Instant reports for inspections and audits',
                                    ].map((item, index) => (
                                        <div
                                            key={index}
                                            className="flex items-start gap-3"
                                        >
                                            <div className="mt-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-status-success">
                                                <CheckCircle2
                                                    size={14}
                                                    className="text-status-success"
                                                />
                                            </div>
                                            <span className="text-sm text-muted-foreground">
                                                {item}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Mock Dashboard */}
                            <div className="relative">
                                <div className="absolute -inset-4 rounded-3xl bg-gradient-to-r from-primary/10 to-status-success/10 blur-2xl" />
                                <div className="relative rounded-2xl border border-border bg-background p-4 shadow-2xl">
                                    <div className="mb-4 flex items-center gap-2">
                                        <div className="flex gap-1.5">
                                            <div className="h-3 w-3 rounded-full bg-status-critical" />
                                            <div className="h-3 w-3 rounded-full bg-status-warning" />
                                            <div className="h-3 w-3 rounded-full bg-status-success" />
                                        </div>
                                        <div className="flex-1 text-center text-xs text-muted-foreground">
                                            Dashboard Preview
                                        </div>
                                    </div>

                                    <div className="space-y-3">
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="group relative overflow-hidden rounded-xl bg-gradient-to-br from-muted/80 to-muted/40 p-3 transition-all">
                                                {/* Gloss overlay */}
                                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/5" />
                                                <div className="relative">
                                                    <div className="text-xs text-muted-foreground">
                                                        Active Residents
                                                    </div>
                                                    <div className="text-xl font-semibold text-foreground">
                                                        42
                                                    </div>
                                                    <div className="text-[10px] text-status-success">
                                                        +4 this month
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="group relative overflow-hidden rounded-xl bg-gradient-to-br from-muted/80 to-muted/40 p-3 transition-all">
                                                {/* Gloss overlay */}
                                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/5" />
                                                <div className="relative">
                                                    <div className="text-xs text-muted-foreground">
                                                        Visits Today
                                                    </div>
                                                    <div className="text-xl font-semibold text-foreground">
                                                        28
                                                    </div>
                                                    <div className="text-[10px] text-muted-foreground">
                                                        Across 6 locations
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="group relative overflow-hidden rounded-xl bg-gradient-to-br from-muted/80 to-muted/40 p-3 transition-all">
                                            {/* Gloss overlay */}
                                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/5" />
                                            <div className="relative">
                                                <div className="mb-2 text-xs text-muted-foreground">
                                                    Recent Activity
                                                </div>
                                                {[1, 2, 3].map((_, i) => (
                                                    <div
                                                        key={i}
                                                        className="flex items-center justify-between py-1.5 text-xs"
                                                    >
                                                        <span className="text-foreground">
                                                            Visit completed •
                                                            Alex M
                                                        </span>
                                                        <span className="text-muted-foreground">
                                                            {10 + i * 5}m ago
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* CTA Section */}
            <section className="mt-24">
                <div className="rounded-3xl bg-gradient-to-r from-primary to-primary/90 px-6 py-12 sm:px-12 sm:py-16">
                    <div className="mx-auto max-w-3xl text-center">
                        <h2 className="text-2xl font-bold text-primary-foreground sm:text-3xl">
                            Ready to modernise your supported living operations?
                        </h2>
                        <p className="mt-4 text-primary-foreground/80">
                            Get a personalised demo tailored to your New Zealand
                            service.
                        </p>
                        <div className="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                            <Link
                                href="/contact"
                                className="inline-flex items-center justify-center gap-2 rounded-full bg-background px-8 py-4 text-base font-medium text-foreground shadow-lg transition-all hover:bg-background/90"
                            >
                                Schedule your demo
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

export default Home;
