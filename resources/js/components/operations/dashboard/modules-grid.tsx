import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Briefcase,
    FileSpreadsheet,
    FileText,
    Grid3x3,
    MessageSquare,
    Receipt,
    Settings2,
    UserCheck,
    type LucideIcon,
} from 'lucide-react';

type Module = {
    title: string;
    href: string;
    icon: LucideIcon;
    badge?: { text: string; tone?: 'muted' | 'critical' };
    description: string;
};

type Props = {
    openShifts: number;
    handoversToday?: number;
    progressNotesToday?: number;
    availabilityPct?: number;
    billingMtd?: string;
};

export function ModulesGrid({
    openShifts,
    handoversToday = 0,
    progressNotesToday = 0,
    availabilityPct = 94,
    billingMtd = '$0',
}: Props) {
    const modules: Module[] = [
        {
            title: 'Handovers',
            href: '/operations/handovers',
            icon: MessageSquare,
            badge: { text: `${handoversToday} today`, tone: 'muted' },
            description: 'Shift-to-shift notes · follow-ups · escalations',
        },
        {
            title: 'Progress notes',
            href: '/operations/clients',
            icon: FileText,
            badge: { text: `${progressNotesToday} today`, tone: 'muted' },
            description: 'Daily & progress notes live on each client profile',
        },
        {
            title: 'Job board',
            href: '/operations/job-board',
            icon: Briefcase,
            badge: {
                text: `${openShifts} open`,
                tone: openShifts > 0 ? 'critical' : 'muted',
            },
            description: 'Offer open shifts to bank staff · accept first',
        },
        {
            title: 'Availability',
            href: '/operations/availability',
            icon: UserCheck,
            badge: { text: `${availabilityPct}% set`, tone: 'muted' },
            description: 'Staff windows, time-off, scheduling constraints',
        },
        {
            title: 'Invoices & billing',
            href: '/finance/billing',
            icon: Receipt,
            badge: { text: `${billingMtd} MTD`, tone: 'muted' },
            description: 'Run charges · approve · export to Xero',
        },
        {
            title: 'Reports & exports',
            href: '/operations/reports',
            icon: FileSpreadsheet,
            badge: { text: 'Ad-hoc', tone: 'muted' },
            description: 'Payroll, finance, regulator · saved & scheduled',
        },
    ];

    return (
        <section>
            <div className="mb-2 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Grid3x3 className="h-4 w-4 text-muted-foreground" />
                    <h2 className="text-[13px] font-semibold tracking-wider text-muted-foreground uppercase">
                        More modules
                    </h2>
                </div>
                <button
                    type="button"
                    className="inline-flex items-center gap-1 text-[12px] font-medium text-primary hover:underline"
                >
                    Customise <Settings2 className="h-3 w-3" />
                </button>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-6">
                {modules.map((m) => {
                    const Icon = m.icon;
                    return (
                        <Link
                            key={m.title}
                            href={m.href}
                            className="group flex flex-col gap-1.5 rounded-xl border bg-card p-3.5 transition-all duration-200 hover:-translate-y-px hover:border-primary/40 hover:shadow-[0_6px_24px_-10px_rgba(76,29,149,.18)]"
                            style={{ borderColor: 'var(--border)' }}
                        >
                            <div className="flex items-center justify-between">
                                <div
                                    className="flex h-8 w-8 items-center justify-center rounded-lg"
                                    style={{
                                        background: 'var(--accent)',
                                        color: 'var(--primary)',
                                    }}
                                >
                                    <Icon className="h-4 w-4" />
                                </div>
                                {m.badge ? (
                                    <span
                                        className="rounded-full px-1.5 py-0.5 text-[10px] tabular-nums"
                                        style={
                                            m.badge.tone === 'critical'
                                                ? {
                                                      background:
                                                          'var(--status-critical-bg)',
                                                      color: 'var(--status-critical)',
                                                  }
                                                : {
                                                      color: 'var(--muted-foreground)',
                                                  }
                                        }
                                    >
                                        {m.badge.text}
                                    </span>
                                ) : null}
                            </div>
                            <div className="mt-1 text-[13px] font-semibold">
                                {m.title}
                            </div>
                            <div className="text-[10.5px] leading-snug text-muted-foreground">
                                {m.description}
                            </div>
                            <div className="mt-1 inline-flex items-center gap-0.5 text-[11px] font-semibold text-primary">
                                Open{' '}
                                <ArrowRight className="h-3 w-3 transition-transform duration-200 group-hover:translate-x-0.5" />
                            </div>
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}
