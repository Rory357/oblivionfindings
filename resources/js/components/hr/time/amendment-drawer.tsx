import { ArrowRight, History } from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

interface Amendment {
    id: number;
    field_name: string;
    old_value: string | null;
    new_value: string | null;
    reason: string | null;
    amended_by: string;
    created_at: string;
}

const FIELD_LABEL: Record<string, string> = {
    clock_in: 'clock-in',
    clock_out: 'clock-out',
    break_minutes: 'break minutes',
    pay_type: 'pay type',
    notes: 'notes',
    is_sleepover: 'sleepover',
    is_on_call: 'on-call',
    is_public_holiday: 'public holiday',
    mileage_km: 'mileage',
    cost_centre: 'cost centre',
    project_code: 'project code',
    created_on_behalf: 'created this entry on behalf',
    voided: 'voided the entry',
};

function fieldLabel(field: string): string {
    return FIELD_LABEL[field] ?? field.replace(/_/g, ' ');
}

export function AmendmentDrawer({
    entryId,
    staffName,
    subtitle,
    onClose,
}: {
    entryId: number | null;
    staffName: string;
    subtitle: string;
    onClose: () => void;
}) {
    const [amendments, setAmendments] = useState<Amendment[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (entryId == null) return;
        let cancelled = false;
        setLoading(true);
        setAmendments([]);
        fetch(`/hr/time/entries/${entryId}/amendments`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : []))
            .then((data) => {
                if (!cancelled) setAmendments(Array.isArray(data) ? data : []);
            })
            .catch(() => {
                if (!cancelled) setAmendments([]);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [entryId]);

    return (
        <Sheet open={entryId != null} onOpenChange={(o) => !o && onClose()}>
            <SheetContent className="flex w-[430px] max-w-[92vw] flex-col gap-0 p-0">
                <SheetHeader className="space-y-1 border-b border-border px-[22px] py-5 text-left">
                    <span className="text-[10.5px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
                        Amendment history
                    </span>
                    <SheetTitle className="text-[18px] font-bold">{staffName}</SheetTitle>
                    <SheetDescription className="text-[12.5px]">
                        {subtitle}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto px-[22px] py-[18px]">
                    {loading ? (
                        <div className="space-y-3">
                            {[0, 1, 2].map((i) => (
                                <div
                                    key={i}
                                    className="h-16 animate-pulse rounded-lg bg-muted"
                                />
                            ))}
                        </div>
                    ) : amendments.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 py-16 text-center">
                            <History className="h-7 w-7 text-muted-foreground/50" />
                            <div className="text-[13px] font-semibold text-muted-foreground">
                                No amendments recorded.
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col">
                            {amendments.map((a, i) => {
                                const hasDiff =
                                    a.old_value != null && a.new_value != null;
                                return (
                                    <div key={a.id} className="flex gap-3.5">
                                        <div className="flex flex-none flex-col items-center">
                                            <span className="mt-1 h-2.5 w-2.5 rounded-full bg-primary" />
                                            {i < amendments.length - 1 ? (
                                                <span className="min-h-[18px] w-0.5 flex-1 bg-border" />
                                            ) : null}
                                        </div>
                                        <div className="min-w-0 pb-5">
                                            <div className="text-[13px]">
                                                <span className="font-bold">
                                                    {a.amended_by}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    changed
                                                </span>{' '}
                                                <span className="font-semibold">
                                                    {fieldLabel(a.field_name)}
                                                </span>
                                            </div>
                                            {hasDiff ? (
                                                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                                    <span className="rounded-md bg-status-critical-bg px-2 py-0.5 text-[12px] font-semibold text-status-critical line-through">
                                                        {a.old_value}
                                                    </span>
                                                    <ArrowRight className="h-3.5 w-3.5 text-muted-foreground" />
                                                    <span className="rounded-md bg-status-success-bg px-2 py-0.5 text-[12px] font-semibold text-status-success">
                                                        {a.new_value}
                                                    </span>
                                                </div>
                                            ) : null}
                                            {a.reason ? (
                                                <div className="mt-2 rounded-lg bg-muted px-2.5 py-2 text-[12px] leading-relaxed">
                                                    <span className="text-muted-foreground">
                                                        Reason:
                                                    </span>{' '}
                                                    &ldquo;{a.reason}&rdquo;
                                                </div>
                                            ) : null}
                                            <div className="mt-1.5 text-[11.5px] text-muted-foreground">
                                                {a.created_at}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

export default AmendmentDrawer;
