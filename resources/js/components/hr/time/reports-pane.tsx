/* eslint-disable no-restricted-syntax -- The report surface uses styled native
 * <button>/<a> for the export actions and token-tinted bar rows (custom layout,
 * not shadcn <Button> cases). Colours stay token-based. */
import { Download, FileText } from 'lucide-react';

import { avatarStyle, type TimeReport } from './types';

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0]?.toUpperCase() ?? '')
            .join('') || '—'
    );
}

export function ReportsPane({
    report,
    exportHref,
    pdfHref,
}: {
    report: TimeReport | null;
    exportHref: string;
    pdfHref: string;
}) {
    if (!report) {
        return (
            <div className="rounded-2xl border border-border bg-card px-5 py-14 text-center text-[13px] font-semibold text-muted-foreground">
                No report data yet.
            </div>
        );
    }

    const maxSite = Math.max(1, ...report.by_site.map((s) => s.hours));
    const kpis = [
        {
            label: 'Total hours',
            value: `${report.kpis.total_hours}h`,
            tone: '',
        },
        {
            label: 'Overtime · >40h',
            value: `${report.kpis.overtime_hours}h`,
            tone: 'text-status-warning',
        },
        {
            label: 'Break fails',
            value: `${report.kpis.break_fails}`,
            tone: report.kpis.break_fails > 0 ? 'text-status-warning' : '',
        },
        { label: 'Mileage', value: `${report.kpis.mileage_km} km`, tone: '' },
    ];

    return (
        <div className="flex flex-col gap-[18px]">
            <div className="flex flex-wrap items-center gap-2.5">
                <div>
                    <h2 className="text-[16px] font-bold">
                        Hours &amp; compliance · this week
                    </h2>
                    <p className="mt-0.5 text-[12.5px] text-muted-foreground">
                        {report.week_start} – {report.week_end}
                    </p>
                </div>
                <div className="flex-1" />
                <a
                    href={exportHref}
                    className="inline-flex h-9 items-center gap-1.5 rounded-[9px] border border-border bg-card px-3.5 text-[12.5px] font-semibold hover:bg-muted"
                >
                    <Download className="h-[15px] w-[15px]" /> CSV
                </a>
                <a
                    href={pdfHref}
                    className="inline-flex h-9 items-center gap-1.5 rounded-[9px] bg-primary px-3.5 text-[12.5px] font-semibold text-primary-foreground hover:brightness-95"
                >
                    <FileText className="h-[15px] w-[15px]" /> PDF report
                </a>
            </div>

            <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
                {kpis.map((k) => (
                    <div
                        key={k.label}
                        className="rounded-2xl border border-border bg-card px-4 py-3.5"
                    >
                        <div className="text-[11px] font-bold tracking-[0.05em] text-muted-foreground uppercase">
                            {k.label}
                        </div>
                        <div
                            className={`mt-1 text-[25px] font-bold tabular-nums ${k.tone}`}
                        >
                            {k.value}
                        </div>
                    </div>
                ))}
            </div>

            <div className="grid gap-[18px] lg:grid-cols-2">
                <section className="rounded-2xl border border-border bg-card px-[18px] py-4">
                    <h3 className="mb-3.5 text-[14px] font-bold">
                        Hours by site
                    </h3>
                    {report.by_site.length === 0 ? (
                        <p className="text-[12.5px] text-muted-foreground">
                            No hours recorded.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {report.by_site.map((s) => (
                                <div key={s.name}>
                                    <div className="mb-1 flex justify-between text-[12.5px]">
                                        <span className="font-semibold">
                                            {s.name}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {s.hours}h
                                        </span>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-primary"
                                            style={{
                                                width: `${(s.hours / maxSite) * 100}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="overflow-hidden rounded-2xl border border-border bg-card">
                    <div className="border-b border-border px-[18px] py-3.5">
                        <h3 className="text-[14px] font-bold">
                            Hours by staff
                        </h3>
                    </div>
                    {report.by_staff.length === 0 ? (
                        <p className="px-[18px] py-6 text-[12.5px] text-muted-foreground">
                            No hours recorded.
                        </p>
                    ) : (
                        <div className="flex flex-col">
                            {report.by_staff.map((r) => (
                                <div
                                    key={r.user_id}
                                    className="grid grid-cols-[1.6fr_0.8fr_0.8fr] items-center gap-2.5 border-t border-border px-[18px] py-2.5 first:border-t-0"
                                >
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <span
                                            className="grid h-7 w-7 flex-none place-items-center rounded-full text-[10.5px] font-bold"
                                            style={avatarStyle(r.user_id)}
                                        >
                                            {initials(r.name)}
                                        </span>
                                        <span className="truncate text-[12.5px] font-semibold">
                                            {r.name}
                                        </span>
                                    </div>
                                    <span className="text-[12.5px] font-bold tabular-nums">
                                        {r.hours}h
                                    </span>
                                    <span className="text-[12.5px] tabular-nums">
                                        {r.overtime > 0 ? (
                                            <span className="rounded-full border border-status-warning/30 bg-status-warning-bg px-2 py-0.5 text-[10.5px] font-semibold text-status-warning">
                                                +{r.overtime}h OT
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </div>
    );
}

export default ReportsPane;
