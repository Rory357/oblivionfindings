/* eslint-disable no-restricted-syntax -- detail rows are a custom definition list
   inside a Dialog, not standalone Cards. All colours are semantic tokens. */
import { ClientAvatar } from '@/components/meds/board-bits';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DoseStatusBadge } from './round-bits';
import { doseStatusMeta, type ActivityItem } from './types';

function fmt(iso: string | null): string | null {
    if (!iso) return null;
    const d = new Date(iso);
    return Number.isNaN(d.getTime())
        ? null
        : d.toLocaleString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    if (value === null || value === undefined || value === '') return null;
    return (
        <div className="flex items-start justify-between gap-4 border-b border-border py-2 last:border-b-0">
            <span className="text-[12.5px] text-muted-foreground">{label}</span>
            <span className="text-right text-[12.5px] font-medium">{value}</span>
        </div>
    );
}

export default function RoundActivityDialog({ item, onClose }: { item: ActivityItem; onClose: () => void }) {
    const chips = [
        item.witnessed_by ? `Witness: ${item.witnessed_by}` : null,
        item.blood_glucose_level != null ? `BG ${item.blood_glucose_level} mmol/L` : null,
        item.pulse_bpm != null ? `Pulse ${item.pulse_bpm} bpm` : null,
    ].filter(Boolean) as string[];

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-[520px] gap-0 p-0">
                <DialogHeader className="space-y-0 border-b p-5 pr-12">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <ClientAvatar name={item.resident_name ?? 'Resident'} clientId={item.resident_id ?? 0} className="h-11 w-11 text-sm" />
                            <div className="min-w-0">
                                <DialogTitle className="text-base font-bold">{item.resident_name ?? 'Resident'}</DialogTitle>
                                <p className="text-xs text-muted-foreground">{item.site_name ?? '—'}</p>
                            </div>
                        </div>
                        <DoseStatusBadge status={item.status} />
                    </div>
                </DialogHeader>

                <div className="px-5 py-4">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <span className="text-[17px] font-bold">{item.medication_name ?? 'Medication'}</span>
                        {item.dose ? <span className="text-sm text-muted-foreground">{item.dose}</span> : null}
                    </div>

                    <div className="mt-3">
                        <Row label="Outcome" value={doseStatusMeta(item.status).label} />
                        <Row label="Round" value={item.round_name} />
                        <Row label="Recorded by" value={item.staff} />
                        <Row label="Recorded at" value={fmt(item.administered_at) ?? item.time} />
                        <Row label="Scheduled for" value={fmt(item.scheduled_for)} />
                        <Row label="Witness" value={item.witnessed_by} />
                        <Row label="Blood glucose" value={item.blood_glucose_level != null ? `${item.blood_glucose_level} mmol/L` : null} />
                        <Row label="Apical pulse" value={item.pulse_bpm != null ? `${item.pulse_bpm} bpm` : null} />
                        <Row label="Coded reason" value={item.reason_code} />
                    </div>

                    {chips.length > 0 ? (
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {chips.map((c) => (
                                <span key={c} className="rounded-md border bg-muted px-2 py-0.5 text-[11.5px]">
                                    {c}
                                </span>
                            ))}
                        </div>
                    ) : null}

                    {item.reason ? (
                        <div className="mt-3 rounded-lg bg-muted px-3 py-2 text-[12.5px] text-foreground italic">“{item.reason}”</div>
                    ) : null}
                </div>
            </DialogContent>
        </Dialog>
    );
}
