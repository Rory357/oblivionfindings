import MarketingLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Brain,
    Camera,
    CheckCircle2,
    Clock,
    Eye,
    FileText,
    History,
    LayoutDashboard,
    Moon,
    ScanEye,
    Search,
    Shield,
    Users,
    Video,
} from 'lucide-react';
import React from 'react';

const SmartMonitoring: React.FC = () => {
    const aiCapabilities = [
        {
            icon: ScanEye,
            title: 'Object & Person Detection',
            description:
                'AI automatically identifies people, objects, and potential hazards in real-time. Know instantly when someone enters a restricted area or when unusual objects appear.',
        },
        {
            icon: AlertTriangle,
            title: 'Fall & Distress Detection',
            description:
                'Advanced algorithms analyse body posture and movement patterns to detect falls, trips, or signs of distress—alerting staff within seconds.',
        },
        {
            icon: History,
            title: 'Timeline Reconstruction',
            description:
                'When an incident occurs, the AI automatically pieces together footage from multiple cameras to create a complete timeline of events.',
        },
        {
            icon: Brain,
            title: 'Behavioural Analysis',
            description:
                'Learn normal activity patterns and receive alerts when behaviour deviates from the baseline—early warning for potential issues.',
        },
        {
            icon: Eye,
            title: 'Privacy Masking',
            description:
                'GDPR-compliant privacy zones automatically blur sensitive areas like bathrooms and bedrooms while maintaining security in common spaces.',
        },
        {
            icon: LayoutDashboard,
            title: 'Unified Dashboard',
            description:
                'All cameras, alerts, and AI insights in one place—integrated seamlessly with your resident management and incident reporting.',
        },
    ];

    const scenarios = [
        {
            title: 'The Midnight Wanderer',
            description:
                'A resident with dementia gets up at 3 AM and walks toward an exit. The AI detects unusual movement during night hours, triggers an alert to the night staff, and reconstructs the path taken—preventing a potential safeguarding incident.',
            icon: Moon,
        },
        {
            title: 'The Slip and Fall',
            description:
                'A resident slips in the kitchen when no staff are present. The AI immediately detects the fall posture, sends an urgent alert with camera location, and saves the preceding 30 seconds of footage for review.',
            icon: AlertTriangle,
        },
        {
            title: 'The Missing Item',
            description:
                'A valuable item goes missing. Instead of reviewing hours of footage, staff use AI search: "Show me all people who entered room 3 between 2 PM and 4 PM." The system returns relevant clips in seconds.',
            icon: Search,
        },
        {
            title: 'The Investigation',
            description:
                'Following an incident report, management uses the timeline reconstruction feature. The AI stitches together footage from 4 cameras to show the complete sequence of events—providing clear evidence for the safeguarding review.',
            icon: FileText,
        },
    ];

    const specs = [
        { label: 'Camera Support', value: 'Unlimited IP cameras' },
        { label: 'Recording Quality', value: 'Up to 4K resolution' },
        { label: 'Retention', value: 'Configurable 30-365 days' },
        { label: 'AI Processing', value: 'Edge or cloud-based' },
        { label: 'Alert Speed', value: '< 3 seconds' },
        { label: 'Integration', value: 'ONVIF, RTSP, Hikvision, Axis' },
    ];

    return (
        <MarketingLayout
            title="Smart Monitoring"
            description="Intelligent monitoring with automatic incident detection, timeline reconstruction, and real-time alerts for supported living providers."
        >
            {/* Hero */}
            <section className="relative overflow-hidden rounded-3xl border border-border bg-gradient-to-br from-primary/10 via-primary/5 to-background px-6 py-16 sm:px-12 sm:py-24">
                <div className="pointer-events-none absolute inset-0">
                    <div className="absolute -top-20 -right-20 h-[400px] w-[400px] rounded-full bg-primary/20 blur-3xl" />
                    <div className="absolute -bottom-20 -left-20 h-[300px] w-[300px] rounded-full bg-primary/10 blur-3xl" />
                </div>

                <div className="relative mx-auto max-w-4xl text-center">
                    <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-primary/30 bg-primary/10 px-4 py-1.5 text-sm text-primary">
                        <Camera size={16} />
                        <span>Next-Generation Security</span>
                    </div>

                    <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl lg:text-6xl">
                        AI-Powered Control Room
                    </h1>
                    <p className="mx-auto mt-6 max-w-3xl text-xl text-muted-foreground">
                        Modern CCTV systems that don't just record—they
                        understand. Detect incidents, reconstruct timelines, and
                        protect your residents with intelligent monitoring.
                    </p>

                    <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <Link
                            href="/contact"
                            className="inline-flex items-center justify-center gap-2 rounded-full bg-primary px-8 py-4 text-base font-medium text-primary-foreground shadow-lg shadow-primary/25 transition-all hover:bg-primary/90"
                        >
                            Book a Smart Monitoring demo
                            <ArrowRight size={18} />
                        </Link>
                        <Link
                            href="/features"
                            className="inline-flex items-center justify-center gap-2 rounded-full border border-border bg-background px-8 py-4 text-base font-medium text-foreground transition-all hover:bg-muted"
                        >
                            View all features
                        </Link>
                    </div>
                </div>
            </section>

            {/* AI Capabilities Grid */}
            <section className="mt-24">
                <div className="text-center">
                    <h2 className="text-3xl font-bold text-foreground">
                        Intelligence that watches when you can't
                    </h2>
                    <p className="mx-auto mt-4 max-w-2xl text-muted-foreground">
                        Our AI doesn't replace your staff—it empowers them with
                        superhuman awareness and perfect memory.
                    </p>
                </div>

                <div className="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {aiCapabilities.map((capability, index) => {
                        const Icon = capability.icon;
                        return (
                            <div
                                key={index}
                                className="group relative overflow-hidden rounded-2xl border border-border bg-card p-6 transition-all hover:border-primary/20 hover:shadow-lg hover:shadow-primary/5"
                            >
                                {/* Gloss overlay */}
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 text-primary shadow-inner shadow-primary/10">
                                    <Icon size={24} />
                                </div>
                                <h3 className="mt-4 text-lg font-semibold text-foreground">
                                    {capability.title}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {capability.description}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </section>

            {/* Interactive Demo Section */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-card p-1">
                    <div className="rounded-[20px] bg-gradient-to-br from-muted/50 to-background p-6 sm:p-10">
                        <div className="grid items-center gap-10 lg:grid-cols-2">
                            <div>
                                <h2 className="text-2xl font-bold text-foreground sm:text-3xl">
                                    See the story, not just the footage
                                </h2>
                                <p className="mt-4 text-muted-foreground">
                                    When an incident occurs across multiple
                                    camera zones, our AI automatically
                                    reconstructs the complete timeline— saving
                                    hours of manual footage review.
                                </p>

                                <div className="mt-8 space-y-4">
                                    <div className="flex items-start gap-4">
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                            1
                                        </div>
                                        <div>
                                            <h4 className="font-medium text-foreground">
                                                Incident Detected
                                            </h4>
                                            <p className="text-sm text-muted-foreground">
                                                AI identifies unusual activity
                                                and triggers an alert
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-4">
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                            2
                                        </div>
                                        <div>
                                            <h4 className="font-medium text-foreground">
                                                Multi-Camera Search
                                            </h4>
                                            <p className="text-sm text-muted-foreground">
                                                System scans all cameras to
                                                track movement
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-4">
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                            3
                                        </div>
                                        <div>
                                            <h4 className="font-medium text-foreground">
                                                Timeline Built
                                            </h4>
                                            <p className="text-sm text-muted-foreground">
                                                Complete sequence stitched
                                                together automatically
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-4">
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                            4
                                        </div>
                                        <div>
                                            <h4 className="font-medium text-foreground">
                                                Instant Review
                                            </h4>
                                            <p className="text-sm text-muted-foreground">
                                                Staff view the complete story in
                                                minutes, not hours
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Control Room Interface Mockup */}
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
                                                System Online
                                            </span>
                                        </div>
                                    </div>

                                    {/* Main Grid */}
                                    <div className="mb-3 grid grid-cols-2 gap-2">
                                        <div className="relative aspect-video overflow-hidden rounded-lg bg-muted">
                                            <div className="absolute inset-0 bg-gradient-to-br from-muted to-muted" />
                                            <div className="absolute top-2 left-2 rounded bg-black/50 px-2 py-1 text-[10px] text-white">
                                                Main Entrance - Cam 01
                                            </div>
                                            <div className="absolute right-2 bottom-2 flex items-center gap-1 rounded bg-status-success-bg px-2 py-1 text-[10px] text-status-success">
                                                <span className="h-1.5 w-1.5 rounded-full bg-status-success" />
                                                Live
                                            </div>
                                            {/* AI Detection Box */}
                                            <div className="absolute top-1/2 left-1/2 h-20 w-14 -translate-x-1/2 -translate-y-1/2 rounded-lg border-2 border-primary/60">
                                                <div className="absolute -top-6 left-0 rounded bg-primary px-2 py-1 text-[10px] text-white">
                                                    Person 94% confidence
                                                </div>
                                            </div>
                                        </div>
                                        <div className="relative aspect-video overflow-hidden rounded-lg bg-muted">
                                            <div className="absolute inset-0 bg-gradient-to-br from-muted to-muted" />
                                            <div className="absolute top-2 left-2 rounded bg-black/50 px-2 py-1 text-[10px] text-white">
                                                Lounge - Cam 02
                                            </div>
                                            <div className="absolute right-2 bottom-2 flex items-center gap-1 rounded bg-status-success-bg px-2 py-1 text-[10px] text-status-success">
                                                <span className="h-1.5 w-1.5 rounded-full bg-status-success" />
                                                Live
                                            </div>
                                        </div>
                                        <div className="relative aspect-video overflow-hidden rounded-lg bg-muted">
                                            <div className="absolute inset-0 bg-gradient-to-br from-muted to-muted" />
                                            <div className="absolute top-2 left-2 rounded bg-black/50 px-2 py-1 text-[10px] text-white">
                                                Kitchen - Cam 03
                                            </div>
                                            <div className="absolute right-2 bottom-2 flex items-center gap-1 rounded bg-status-warning-bg px-2 py-1 text-[10px] text-status-warning">
                                                <span className="h-1.5 w-1.5 rounded-full bg-status-warning" />
                                                Motion Detected
                                            </div>
                                        </div>
                                        <div className="relative aspect-video overflow-hidden rounded-lg bg-muted">
                                            <div className="absolute inset-0 bg-gradient-to-br from-muted to-muted" />
                                            <div className="absolute top-2 left-2 rounded bg-black/50 px-2 py-1 text-[10px] text-white">
                                                Garden - Cam 04
                                            </div>
                                            <div className="absolute right-2 bottom-2 flex items-center gap-1 rounded bg-status-success-bg px-2 py-1 text-[10px] text-status-success">
                                                <span className="h-1.5 w-1.5 rounded-full bg-status-success" />
                                                Live
                                            </div>
                                        </div>
                                    </div>

                                    {/* Alert Panel */}
                                    <div className="rounded-lg border border-status-warning/30 bg-status-warning p-3">
                                        <div className="flex items-start gap-3">
                                            <AlertTriangle
                                                size={18}
                                                className="mt-0.5 text-status-warning"
                                            />
                                            <div className="flex-1">
                                                <div className="flex items-center justify-between">
                                                    <p className="text-sm font-medium text-status-warning dark:text-status-warning">
                                                        AI Alert: Unusual
                                                        Activity
                                                    </p>
                                                    <span className="text-[10px] text-muted-foreground">
                                                        14:32:18
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    Motion pattern in Kitchen
                                                    (Cam 03) inconsistent with
                                                    normal activity for this
                                                    time period.
                                                </p>
                                                <div className="mt-2 flex gap-2">
                                                    <button className="rounded bg-status-warning px-2 py-1 text-[10px] text-white">
                                                        View Timeline
                                                    </button>
                                                    <button className="rounded border border-status-warning/30 px-2 py-1 text-[10px] text-status-warning">
                                                        Dismiss
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Real-World Scenarios */}
            <section className="mt-24">
                <div className="text-center">
                    <h2 className="text-3xl font-bold text-foreground">
                        Real-world scenarios
                    </h2>
                    <p className="mx-auto mt-4 max-w-2xl text-muted-foreground">
                        See how AI-powered CCTV makes a difference in everyday
                        situations
                    </p>
                </div>

                <div className="mt-12 grid gap-6 md:grid-cols-2">
                    {scenarios.map((scenario, index) => {
                        const Icon = scenario.icon;
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
                                    {scenario.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {scenario.description}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </section>

            {/* Technical Specs */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-card p-8 sm:p-12">
                    <div className="text-center">
                        <h2 className="text-2xl font-bold text-foreground">
                            Technical Specifications
                        </h2>
                        <p className="mt-4 text-muted-foreground">
                            Enterprise-grade infrastructure built for 24/7 care
                            environments
                        </p>
                    </div>

                    <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {specs.map((spec, index) => (
                            <div
                                key={index}
                                className="group relative flex items-center gap-4 overflow-hidden rounded-xl bg-gradient-to-br from-muted/80 to-muted/40 p-4 transition-all hover:from-muted/90 hover:to-muted/50"
                            >
                                {/* Gloss overlay */}
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/5" />
                                <CheckCircle2
                                    size={20}
                                    className="shrink-0 text-status-success"
                                />
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        {spec.label}
                                    </p>
                                    <p className="font-medium text-foreground">
                                        {spec.value}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Compliance & Privacy */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-gradient-to-br from-primary/5 to-transparent p-8 sm:p-12">
                    <div className="grid items-center gap-10 lg:grid-cols-2">
                        <div>
                            <h2 className="text-2xl font-bold text-foreground">
                                Privacy by design
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                We understand that CCTV in care settings
                                requires a careful balance between safety and
                                privacy. Our system is built with GDPR
                                compliance at its core.
                            </p>
                            <ul className="mt-6 space-y-3">
                                {[
                                    'Automatic privacy masking for sensitive areas',
                                    'Configurable retention periods with auto-deletion',
                                    'Audit logs of all footage access',
                                    'Role-based permissions for viewing',
                                    'Data Processing Agreements included',
                                    'ICO-registered and GDPR compliant',
                                ].map((item, i) => (
                                    <li
                                        key={i}
                                        className="flex items-center gap-3"
                                    >
                                        <Shield
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
                            <div className="group relative overflow-hidden rounded-2xl border border-border bg-background p-8 text-center transition-all hover:shadow-lg hover:shadow-primary/5">
                                {/* Gloss overlay */}
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/50 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                                <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/30 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                                <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl bg-gradient-to-br from-primary/20 to-primary/5 shadow-inner shadow-primary/10">
                                    <Shield
                                        size={40}
                                        className="text-primary"
                                    />
                                </div>
                                <h3 className="mt-4 text-lg font-semibold text-foreground">
                                    GDPR Compliant
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Full compliance with UK data protection
                                    regulations for CCTV in care settings
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Integration with Platform */}
            <section className="mt-24">
                <div className="rounded-3xl border border-border bg-card p-8 sm:p-12">
                    <div className="grid items-center gap-10 lg:grid-cols-2">
                        <div className="order-2 lg:order-1">
                            <div className="space-y-4">
                                <div className="group relative overflow-hidden rounded-xl bg-gradient-to-br from-muted/80 to-muted/40 p-4 transition-all hover:from-muted/90 hover:to-muted/50">
                                    {/* Gloss overlay */}
                                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/5" />
                                    <div className="relative flex items-center gap-3">
                                        <Video
                                            size={20}
                                            className="text-primary"
                                        />
                                        <span className="font-medium text-foreground">
                                            CCTV Alert Generated
                                        </span>
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            14:32:18
                                        </span>
                                    </div>
                                </div>
                                <div className="group relative overflow-hidden rounded-xl bg-gradient-to-br from-muted/80 to-muted/40 p-4 transition-all hover:from-muted/90 hover:to-muted/50">
                                    {/* Gloss overlay */}
                                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/5" />
                                    <div className="relative flex items-center gap-3">
                                        <FileText
                                            size={20}
                                            className="text-primary"
                                        />
                                        <span className="font-medium text-foreground">
                                            Auto-linked to Incident Report
                                        </span>
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            14:33:05
                                        </span>
                                    </div>
                                </div>
                                <div className="group relative overflow-hidden rounded-xl bg-gradient-to-br from-muted/80 to-muted/40 p-4 transition-all hover:from-muted/90 hover:to-muted/50">
                                    {/* Gloss overlay */}
                                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/5" />
                                    <div className="relative flex items-center gap-3">
                                        <Users
                                            size={20}
                                            className="text-primary"
                                        />
                                        <span className="font-medium text-foreground">
                                            Manager Notified
                                        </span>
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            14:33:12
                                        </span>
                                    </div>
                                </div>
                                <div className="group relative overflow-hidden rounded-xl bg-gradient-to-br from-muted/80 to-muted/40 p-4 transition-all hover:from-muted/90 hover:to-muted/50">
                                    {/* Gloss overlay */}
                                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/30 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/5" />
                                    <div className="relative flex items-center gap-3">
                                        <Clock
                                            size={20}
                                            className="text-status-success"
                                        />
                                        <span className="font-medium text-foreground">
                                            Timeline Reconstructed
                                        </span>
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            14:35:00
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="order-1 lg:order-2">
                            <h2 className="text-2xl font-bold text-foreground">
                                Integrated with everything
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                The Control Room doesn't work in isolation—it's
                                seamlessly connected to your entire Oblivion
                                Findings platform.
                            </p>
                            <ul className="mt-6 space-y-3">
                                {[
                                    'CCTV alerts auto-create incident reports',
                                    'Footage linked to resident records',
                                    'Access controlled through existing permissions',
                                    'Part of the same dashboard your staff already use',
                                    'No separate logins or systems to learn',
                                ].map((item, i) => (
                                    <li
                                        key={i}
                                        className="flex items-start gap-3"
                                    >
                                        <CheckCircle2
                                            size={18}
                                            className="mt-0.5 text-status-success"
                                        />
                                        <span className="text-sm text-muted-foreground">
                                            {item}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            {/* CTA */}
            <section className="mt-24">
                <div className="rounded-3xl bg-gradient-to-r from-primary to-primary/90 px-6 py-12 sm:px-12 sm:py-16">
                    <div className="mx-auto max-w-3xl text-center">
                        <h2 className="text-2xl font-bold text-primary-foreground sm:text-3xl">
                            See the future of care monitoring
                        </h2>
                        <p className="mt-4 text-primary-foreground/80">
                            Get a personalised demonstration of our AI Control
                            Room and see how it can transform your safeguarding
                            and incident response.
                        </p>
                        <div className="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                            <Link
                                href="/contact"
                                className="inline-flex items-center justify-center gap-2 rounded-full bg-background px-8 py-4 text-base font-medium text-foreground shadow-lg transition-all hover:bg-background/90"
                            >
                                Schedule a demo
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

export default SmartMonitoring;
