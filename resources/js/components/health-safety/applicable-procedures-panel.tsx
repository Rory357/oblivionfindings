/* Reusable read-only "Safe Work Procedures" panel — surfaces the controlled
 * procedures applicable to a person's role(s) or a site, on host pages (HR self-
 * service /hr/my, Site profile, HR staff profile, client risk tab). Each row deep-
 * links to the procedures register with the detail modal open (?procedure=) so the
 * one ProcedureDetailDialog is reused rather than duplicated. Semantic tokens only. */
import {
    categoryMeta,
    reviewFlag,
    statusMeta,
} from '@/components/health-safety/procedure-detail-dialog';
import { formatDateLong } from '@/lib/datetime';
import {
    FlagBadge,
    TONE_BG,
} from '@/pages/health-safety/components/register-row-kit';
import { Link, router } from '@inertiajs/react';
import { CalendarCheck, Check, ChevronRight, FileText } from 'lucide-react';

export type ApplicableProcedure = {
    id: number;
    reference_number: string;
    title: string;
    category: string;
    status: string;
    review_date: string | null;
    acknowledged?: boolean;
};

export function ApplicableProceduresPanel({
    procedures,
    title = 'Safe Work Procedures',
    subtitle,
    emptyLabel = 'No safe work procedures apply here yet.',
    showAcknowledge = false,
    ackReadonly = false,
}: {
    procedures: ApplicableProcedure[];
    title?: string;
    subtitle?: string;
    emptyLabel?: string;
    /** Show a per-row acknowledge button (self-service surfaces like /hr/my). */
    showAcknowledge?: boolean;
    /** Show the subject's acknowledgement status read-only (manager views, no button). */
    ackReadonly?: boolean;
}) {
    return (
        <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3 md:px-5">
                <div className="flex items-center gap-2.5">
                    <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                        <FileText className="h-4 w-4" />
                    </span>
                    <div className="flex flex-col">
                        <h2 className="text-sm font-bold text-foreground">
                            {title}
                        </h2>
                        {subtitle ? (
                            <span className="text-xs text-muted-foreground">
                                {subtitle}
                            </span>
                        ) : null}
                    </div>
                </div>
                <Link
                    href="/health-safety/procedures"
                    className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                >
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
                            <li
                                key={p.id}
                                className="flex items-center gap-2 px-4 py-3 transition-colors hover:bg-muted/45 md:px-5"
                            >
                                <Link
                                    href={`/health-safety/procedures?procedure=${p.id}`}
                                    className="flex min-w-0 flex-1 items-center gap-3 focus-visible:outline-none"
                                >
                                    <span
                                        className={`h-2 w-2 shrink-0 rounded-full ${cat.dot}`}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate text-sm font-semibold text-foreground">
                                                {p.title}
                                            </span>
                                            <span className="shrink-0 font-mono text-[11px] text-muted-foreground">
                                                {p.reference_number}
                                            </span>
                                        </span>
                                        <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                            <span
                                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-medium ${cat.chip}`}
                                            >
                                                {cat.label}
                                            </span>
                                            <span
                                                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-medium ${TONE_BG[st.tone]}`}
                                            >
                                                <StatusIcon className="h-2.5 w-2.5" />
                                                {st.label}
                                            </span>
                                            {flag ? (
                                                <FlagBadge
                                                    icon={CalendarCheck}
                                                    tone={flag.tone}
                                                    title="Review window"
                                                >
                                                    {flag.label}
                                                </FlagBadge>
                                            ) : p.review_date ? (
                                                <span className="text-[10.5px] text-muted-foreground">
                                                    Review{' '}
                                                    {formatDateLong(
                                                        p.review_date,
                                                    )}
                                                </span>
                                            ) : null}
                                        </span>
                                    </span>
                                </Link>
                                {(showAcknowledge || ackReadonly) &&
                                p.acknowledged ? (
                                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-status-success-bg px-2.5 py-1 text-[11px] font-semibold text-status-success">
                                        <Check className="h-3 w-3" />{' '}
                                        Acknowledged
                                    </span>
                                ) : ackReadonly && p.status === 'approved' ? (
                                    <span className="inline-flex shrink-0 items-center rounded-full bg-status-warning-bg px-2.5 py-1 text-[11px] font-semibold text-status-warning">
                                        Pending
                                    </span>
                                ) : showAcknowledge &&
                                  p.status === 'approved' ? (
                                    // eslint-disable-next-line no-restricted-syntax -- inline ack affordance on a list row, not a form control
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                `/health-safety/procedures/${p.id}/acknowledge`,
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    preserveState: true,
                                                },
                                            )
                                        }
                                        className="shrink-0 rounded-lg border border-primary/40 px-2.5 py-1 text-[11px] font-semibold text-primary transition-colors hover:bg-primary/10"
                                    >
                                        Acknowledge
                                    </button>
                                ) : (
                                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                )}
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
                    <FileText className="h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">
                        {emptyLabel}
                    </p>
                </div>
            )}
        </section>
    );
}
