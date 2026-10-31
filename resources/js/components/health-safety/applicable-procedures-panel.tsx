import {
    ProcedureReviewDate,
    ProcedureStatus,
    procedureCategory,
} from '@/components/health-safety/procedures/procedure-kit';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    PROCEDURE_REGISTER,
    procedureHref,
    procedureReturn,
} from '@/lib/health-safety/procedure-navigation';
import { Link, usePage } from '@inertiajs/react';
import { Check, ChevronRight, FileText } from 'lucide-react';

export type ApplicableProcedure = {
    id: number;
    reference_number: string | null;
    title: string;
    category: string;
    status: string;
    review_date: string | null;
    version?: number;
    href?: string;
    acknowledged?: boolean;
    provenance?: string;
};

/** The list opens the authorized published version. Only its page records acknowledgement. */
export function ApplicableProceduresPanel({
    procedures,
    title = 'Safe work procedures',
    subtitle,
    emptyLabel = 'No safe work procedures are available here for your current access.',
    showAcknowledge = false,
    ackReadonly = false,
}: {
    procedures: ApplicableProcedure[];
    title?: string;
    subtitle?: string;
    emptyLabel?: string;
    showAcknowledge?: boolean;
    ackReadonly?: boolean;
}) {
    const returnTo = procedureReturn(usePage().url);
    return (
        <section className="overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-start justify-between gap-3 border-b border-border px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-section-title flex items-center gap-2">
                        <FileText className="size-4 shrink-0" />
                        {title}
                    </h2>
                    {subtitle && <p className="text-subtle mt-1">{subtitle}</p>}
                </div>
                <Link
                    href={PROCEDURE_REGISTER}
                    className="frontline-tap frontline-focus inline-flex shrink-0 items-center gap-1 text-sm text-primary underline"
                >
                    Library
                    <ChevronRight className="size-4" />
                </Link>
            </div>
            {procedures.length ? (
                <ul className="divide-y divide-border">
                    {procedures.map((p) => (
                        <li key={p.id} className="space-y-2 px-4 py-3">
                            <Link
                                href={
                                    p.href ?? procedureHref(p.id, { returnTo })
                                }
                                className="frontline-tap frontline-focus flex items-center justify-between gap-3 rounded-sm text-primary"
                            >
                                <span className="min-w-0">
                                    <span className="block font-semibold break-words underline">
                                        {p.title}
                                    </span>
                                    <span className="text-caption block text-muted-foreground">
                                        {p.reference_number}
                                        {p.version
                                            ? ` · Version ${p.version}`
                                            : ''}
                                    </span>
                                </span>
                                <ChevronRight className="size-4 shrink-0" />
                            </Link>
                            <div className="flex flex-wrap items-center gap-2">
                                <ProcedureStatus
                                    status={p.status}
                                    legacy={
                                        p.provenance ===
                                        'legacy_current_capture'
                                    }
                                />
                                <span className="text-caption">
                                    {procedureCategory(p.category)}
                                </span>
                                <ProcedureReviewDate date={p.review_date} />
                                {(showAcknowledge || ackReadonly) &&
                                    (p.acknowledged ? (
                                        <StatusBadge variant="success">
                                            <Check className="size-3" />
                                            Acknowledged this version
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge variant="warning">
                                            Acknowledgement pending
                                        </StatusBadge>
                                    ))}
                            </div>
                            {showAcknowledge && !p.acknowledged && (
                                <p className="text-subtle">
                                    Open the procedure to read and acknowledge
                                    {p.provenance === 'legacy_current_capture'
                                        ? ' the captured instructions.'
                                        : ' the published version.'}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle px-4 py-8 text-center">
                    {emptyLabel}
                </p>
            )}
        </section>
    );
}
