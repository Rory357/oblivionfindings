/* Read-only destruction detail — opened from a register row (click or the
 * right-click "View details" action). Built on the same WizardShell chrome
 * (rail + sectioned panes + footer Options bar) as prn-detail-dialog so it
 * matches every other popup workflow. The register is append-only (MoD Regs
 * 1977): there is NO edit/delete here — "Void" is the only correction path, and
 * it is offered only while the record is live. Colours are semantic tokens. */
import { InfoCard } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell, type WizardStep } from '@/components/wizard/shell';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Ban, Download, FileText, Package, ShieldCheck, Trash2, User } from 'lucide-react';
import { useState } from 'react';

/** One destruction-register row (the `destructions()` payload). */
export type DestructionRow = {
    id: number;
    client_id: number | null;
    client_name: string;
    site_id: number | null;
    site_name: string | null;
    medication_name: string | null;
    form: string | null;
    strength: string | null;
    quantity: number | string | null;
    unit: string | null;
    batch_number: string | null;
    expiry_date: string | null;
    reason: string | null;
    reason_label: string | null;
    disposal_method: string | null;
    disposal_method_label: string | null;
    is_controlled_drug: boolean;
    controlled_drug_class: string | null;
    authorised_by_name: string | null;
    destroyed_at: string | null;
    destroyed_by_name: string | null;
    witness_1_name: string | null;
    witness_2_name: string | null;
    notes: string | null;
    voided_at: string | null;
    void_reason: string | null;
    voided_by_name: string | null;
    is_voided: boolean;
    mar_url: string | null;
};

const SECTIONS: WizardStep[] = [
    { key: 'disposal', label: 'Disposal', blurb: 'Item, quantity & method', icon: Package },
    { key: 'witness', label: 'Witness & audit', blurb: 'Signatories & trail', icon: ShieldCheck },
];

const fmtDateTime = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const fmtDate = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

export function DestructionDetailDialog({
    record,
    onClose,
    onVoid,
    onExport,
}: {
    record: DestructionRow;
    onClose: () => void;
    /** Open the void modal in place (offered only while the record is live). */
    onVoid: () => void;
    /** Export this single record (CSV). */
    onExport: () => void;
}) {
    const [section, setSection] = useState(0);
    const d = record;
    // The wizard collects a denaturing-kit confirmation but it is not persisted
    // (no column on MedicationDestruction). Derive the line from the method.
    // TODO(G-denaturing): persist explicit kit confirmation (needs migration).
    const denaturing = d.disposal_method === 'denaturing';
    const qty = `${d.quantity ?? '—'}${d.unit ? ` ${d.unit}` : ''}`;

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Destruction record"
            description="Read-only detail of a witnessed medication disposal. This register is append-only — records are voided, never edited or deleted."
            railIcon={Trash2}
            railTitle={d.medication_name ?? 'Destruction'}
            railSub={[d.client_name, d.site_name].filter(Boolean).join(' · ') || 'Disposal register'}
            steps={SECTIONS}
            stepIndex={section}
            onStepClick={setSection}
            pct={null}
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                <>
                    {d.client_id ? (
                        <Button type="button" variant="ghost" onClick={() => router.visit(`/operations/clients/${d.client_id}/care`)}>
                            <User className="h-4 w-4" /> View client
                        </Button>
                    ) : null}
                    {d.mar_url ? (
                        <Button type="button" variant="ghost" onClick={() => router.visit(d.mar_url!)}>
                            <FileText className="h-4 w-4" /> MAR chart
                        </Button>
                    ) : null}
                    <Button type="button" variant="ghost" onClick={onExport}>
                        <Download className="h-4 w-4" /> Export record
                    </Button>
                    {!d.is_voided ? (
                        <Button type="button" variant="destructive" onClick={onVoid}>
                            <Ban className="h-4 w-4" /> Void
                        </Button>
                    ) : null}
                </>
            }
        >
            {d.is_voided ? (
                <div className="mb-4">
                    <InfoCard icon={Ban} tone="warn">
                        Voided{d.voided_by_name ? ` by ${d.voided_by_name}` : ''}
                        {d.voided_at ? ` · ${fmtDateTime(d.voided_at)}` : ''}
                        {d.void_reason ? ` — ${d.void_reason}` : ''}. The original entry is retained (struck through) and excluded from live counts.
                    </InfoCard>
                </div>
            ) : null}

            {section === 0 ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <ReviewCard icon={User} title="Resident" span>
                        <ReviewRow label="Name" value={d.client_name} />
                        <ReviewRow label="Site" value={d.site_name} />
                    </ReviewCard>
                    <ReviewCard icon={Package} title="Medication">
                        <ReviewRow
                            label="Name"
                            value={
                                <span className="inline-flex items-center gap-1.5">
                                    {d.medication_name ?? '—'}
                                    {d.is_controlled_drug ? (
                                        <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">
                                            CD{d.controlled_drug_class ? ` ${d.controlled_drug_class}` : ''}
                                        </span>
                                    ) : null}
                                </span>
                            }
                        />
                        <ReviewRow label="Form" value={d.form} />
                        <ReviewRow label="Strength" value={d.strength} />
                        <ReviewRow label="Quantity" value={qty} />
                        <ReviewRow label="Batch" value={d.batch_number} />
                        <ReviewRow label="Expiry" value={fmtDate(d.expiry_date)} />
                    </ReviewCard>
                    <ReviewCard icon={Trash2} title="Disposal" span>
                        <ReviewRow label="Reason" value={d.reason_label ?? d.reason} />
                        <ReviewRow label="Method" value={d.disposal_method_label ?? d.disposal_method} />
                        <ReviewRow label="Denaturing kit" value={denaturing ? 'Used — rendered irretrievable' : 'Not applicable'} />
                        <ReviewRow label="Destroyed" value={fmtDateTime(d.destroyed_at)} />
                    </ReviewCard>
                    {d.notes ? (
                        <ReviewCard icon={FileText} title="Notes" span>
                            <p className="text-[13px] leading-relaxed text-muted-foreground">{d.notes}</p>
                        </ReviewCard>
                    ) : null}
                </div>
            ) : (
                <div className="grid gap-4">
                    <ReviewCard icon={ShieldCheck} title="Witnesses & authorisation">
                        <ReviewRow label="Destroyed by" value={d.destroyed_by_name} />
                        <ReviewRow label="Witness 1" value={d.witness_1_name} />
                        <ReviewRow label="Witness 2" value={d.witness_2_name} />
                        {d.is_controlled_drug ? <ReviewRow label="Authorised by" value={d.authorised_by_name} /> : null}
                    </ReviewCard>
                    <ReviewCard icon={FileText} title="Audit trail">
                        <ReviewRow label="Recorded" value={`${d.destroyed_by_name ?? '—'}${d.destroyed_at ? ` · ${fmtDateTime(d.destroyed_at)}` : ''}`} />
                        <ReviewRow
                            label="Voided"
                            value={d.is_voided ? `${d.voided_by_name ?? '—'}${d.voided_at ? ` · ${fmtDateTime(d.voided_at)}` : ''}` : null}
                        />
                    </ReviewCard>
                    {d.is_controlled_drug ? (
                        <InfoCard icon={ShieldCheck} tone="info">
                            Controlled-drug destruction — recorded with two distinct witnesses and authorisation, per MoD Regs 1977.
                        </InfoCard>
                    ) : null}
                </div>
            )}
        </WizardShell>
    );
}

export default DestructionDetailDialog;
