/* Reusable read-only "Safe Work Procedures" panel — surfaces the controlled
 * procedures applicable to a person's role(s) or a site, on host pages (HR self-
 * service /hr/my, Site profile, HR staff profile, client risk tab). Each row deep-
 * links to the procedures register with the detail modal open (?procedure=) so the
 * one ProcedureDetailDialog is reused rather than duplicated. Semantic tokens only. */
import { categoryMeta, reviewFlag, statusMeta } from '@/components/health-safety/procedure-detail-dialog';
import { FlagBadge, TONE_BG } from '@/pages/health-safety/components/register-row-kit';
import { formatDateLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { CalendarCheck, ChevronRight, FileText } from 'lucide-react';

export type ApplicableProcedure = {
    id: number;
    reference_number: string;
    title: string;
    category: string;
    status: string;
    review_date: string | null;
};

export function ApplicableProceduresPanel({
    procedures,
    title = 'Safe Work Procedures',
    subtitle,
    emptyLabel = 'No safe work procedures apply here yet.',
}: {
    procedures: ApplicableProcedure[];
    title?: string;
    subtitle?: string;
    emptyLabel?: string;
}) {
    return (
        <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3 md:px-5">
                <div className="flex items-center gap-2.5">
                    <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                        <FileText className="h-4 w-4" />
                    </span>
                    <div className="flex flex-col">
                        <h2 className="text-sm font-bold text-foreground">{title}</h2>
                        {subtitle ? <span className="text-xs text-muted-foreground">{subtitle}</span> : null}
                    </div>
                </div>
                <Link href="/health-safety/procedures" className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                    Library <ChevronRight className="h-3 w-3" />
                </Link>
            </div>

            {procedures.length ? (
                <ul className="divide-y divide-border">
                    {procedures.map((p) => {
                        const cat = categoryMeta(p.category);
                        const st = statusMeta(p.status);
                        const StatusIcon = st.icon;
                        const flag = reviewFlag(p.review_date);
                        return (
                            <li key={p.id}>
                                <Link
                                    href={`/health-safety/procedures?procedure=${p.id}`}
                                    className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-none md:px-5"
                                >
                                    <span className={`mt-0.5 h-2 w-2 shrink-0 rounded-full ${cat.dot}`} />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate text-sm font-semibold text-foreground">{p.title}</span>
                                            <span className="shrink-0 font-mono text-[11px] text-muted-foreground">{p.reference_number}</span>
                                        </span>
                                        <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-medium ${cat.chip}`}>{cat.label}</span>
                                            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-medium ${TONE_BG[st.tone]}`}>
                                                <StatusIcon className="h-2.5 w-2.5" />
                                                {st.label}
                                            </span>
                                            {flag ? (
                                                <FlagBadge icon={CalendarCheck} tone={flag.tone} title="Review window">
                                                    {flag.label}
                                                </FlagBadge>
                                            ) : p.review_date ? (
                                                <span className="text-[10.5px] text-muted-foreground">Review {formatDateLong(p.review_date)}</span>
                                            ) : null}
                                        </span>
                                    </span>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
                    <FileText className="h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">{emptyLabel}</p>
                </div>
            )}
        </section>
    );
}
