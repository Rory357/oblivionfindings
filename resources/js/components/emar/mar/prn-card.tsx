import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { PrnMedication } from '@/pages/meds/today/types';
import { Plus, Syringe } from 'lucide-react';

type Props = {
    prn: PrnMedication[];
    canRecord: boolean;
    onGive: (med: PrnMedication) => void;
};

export default function PrnCard({ prn, canRecord, onGive }: Props) {
    if (prn.length === 0) {
        return null;
    }

    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className="flex items-center gap-2.5 border-b px-5 py-4">
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                    <Syringe className="h-4 w-4" />
                </span>
                <div>
                    <div className="text-[15px] leading-tight font-bold">
                        PRN &amp; as-required
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {prn.length} medication{prn.length === 1 ? '' : 's'} ·
                        limits enforced
                    </div>
                </div>
            </div>
            <ul className="divide-y">
                {prn.map((med) => {
                    const atLimit = med.over_limit || med.remaining_today === 0;
                    const blocked = atLimit || med.interval_blocked;
                    const givenLabel =
                        med.max_per_day !== null
                            ? `${med.given_last_24h} of ${med.max_per_day} given`
                            : `${med.given_last_24h} given (24h)`;
                    return (
                        <li
                            key={med.id}
                            className="flex flex-wrap items-center gap-3 px-5 py-3.5"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <span className="text-[13.5px] font-bold">
                                        {med.name}
                                    </span>
                                    {med.is_controlled && (
                                        <span className="rounded bg-status-critical-bg px-1.5 py-0.5 text-[9.5px] font-bold tracking-wide text-status-critical uppercase">
                                            CD
                                        </span>
                                    )}
                                </div>
                                <div className="mt-0.5 text-xs text-muted-foreground">
                                    {[med.dose, med.prn_reason]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                            </div>
                            <div className="text-right">
                                <div
                                    className={cn(
                                        'text-xs font-bold',
                                        atLimit
                                            ? 'text-status-critical'
                                            : 'text-status-success',
                                    )}
                                >
                                    {givenLabel}
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    {med.interval_blocked &&
                                    med.next_allowed_label
                                        ? `next ${med.next_allowed_label}`
                                        : med.last_given_label
                                          ? `last ${med.last_given_label}`
                                          : 'none today'}
                                </div>
                            </div>
                            {canRecord && (
                                <Button
                                    size="sm"
                                    disabled={blocked}
                                    onClick={() => onGive(med)}
                                >
                                    <Plus className="h-4 w-4" />
                                    Give
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
