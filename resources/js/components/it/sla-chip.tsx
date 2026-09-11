import {
    SLA_LABELS,
    SLA_VARIANTS,
    type SlaVerdict,
} from '@/components/it/sla-evidence';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { Clock } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface SlaFields {
    sla?: SlaVerdict;
}

/** Other time displays share this tick; SLA itself uses the server's calendar verdict. */
export function useMinuteTick(enabled = true): number {
    const [tick, setTick] = useState(0);
    useEffect(() => {
        if (!enabled) return;
        const id = window.setInterval(() => setTick((t) => t + 1), 60_000);
        return () => window.clearInterval(id);
    }, [enabled]);
    return tick;
}

/** No local wall-clock countdown can substitute for the recorded business calendar. */
export function SlaChip({ ticket }: { ticket: SlaFields }) {
    const sla = ticket.sla;
    const state = sla?.state ?? 'unmeasured';
    return (
        <StatusBadge
            variant={SLA_VARIANTS[state]}
            size="sm"
            title={
                sla
                    ? `SLA calculated ${formatDateTime(sla.evaluated_at)}. ${sla.coverage === 'full' ? 'Both clocks measured.' : 'Measurement is incomplete.'}`
                    : 'Clock evidence is unavailable.'
            }
        >
            <Clock className="mr-1 h-3 w-3" aria-hidden="true" />
            SLA {SLA_LABELS[state].toLowerCase()}
            {sla?.coverage === 'partial' ? ' · partial measurement' : ''}
        </StatusBadge>
    );
}
