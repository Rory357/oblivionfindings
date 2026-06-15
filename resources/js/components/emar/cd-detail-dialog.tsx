/* Read-only "view" detail for a controlled-drug row — opened by a row click or
 * the right-click "View details" action on any of the 7 CD tabs. Built on the
 * shared WizardShell chrome (rail + sectioned panes + footer Options bar) so it
 * matches prn-detail-dialog and every other popup workflow; the primary actions
 * open the relevant CD wizard in place rather than navigating off-page. Colours
 * are semantic design tokens throughout. */
import { InfoCard } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell, type WizardStep } from '@/components/wizard/shell';
import { Button } from '@/components/ui/button';
import {
    statusTone,
    type CdDestruction,
    type CdDiscrepancy,
    type CdEntry,
    type CdLossReport,
    type CdMedication,
} from '@/components/emar/controlled/types';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardCheck,
    FileText,
    FileWarning,
    Lock,
    Package,
    Printer,
    ShieldCheck,
    Trash2,
    User,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

/** Which CD row the detail modal is showing. `med` on an entry lets the footer
 * offer "Check balance" pre-filled to the movement's drug. */
export type CdDetailSubject =
    | { kind: 'medication'; med: CdMedication }
    | { kind: 'entry'; entry: CdEntry; med?: CdMedication }
    | { kind: 'discrepancy'; disc: CdDiscrepancy }
    | { kind: 'destruction'; destruction: CdDestruction }
    | { kind: 'loss'; loss: CdLossReport };

const CD_REGISTER_PDF = '/emar/pdf/controlled-register';

function fmtTs(iso?: string | null): string | null {
    if (!iso) return null;
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? null : d.toLocaleString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function fmtDay(value?: string | null): string | null {
    if (!value) return null;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function humanize(value?: string | null): string | null {
    return value ? value.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) : null;
}

function CdBadge() {
    return <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">CD</span>;
}

function StatusPill({ label }: { label: string }) {
    return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${statusTone(label)}`}>{label.replace(/_/g, ' ')}</span>;
}

function subjectClientId(s: CdDetailSubject): number | null {
    switch (s.kind) {
        case 'medication':
            return s.med.client_id;
        case 'entry':
            return s.entry.client_id;
        case 'discrepancy':
            return s.disc.client?.id ?? null;
        case 'destruction':
            return s.destruction.client_id ?? null;
        case 'loss':
            return s.loss.client?.id ?? null;
    }
}

function rail(s: CdDetailSubject): { icon: typeof Lock; title: string; sub: string } {
    switch (s.kind) {
        case 'medication':
            return { icon: Lock, title: s.med.client_name, sub: s.med.name };
        case 'entry':
            return { icon: Lock, title: s.entry.client_name, sub: s.entry.medication_name ?? 'CD movement' };
        case 'discrepancy':
            return { icon: AlertTriangle, title: s.disc.client ? `${s.disc.client.first_name} ${s.disc.client.last_name}` : 'CD discrepancy', sub: s.disc.medication?.name ?? 'Discrepancy' };
        case 'destruction':
            return { icon: Trash2, title: s.destruction.client_name, sub: s.destruction.medication_name ?? 'Destruction' };
        case 'loss':
            return { icon: FileWarning, title: s.loss.client ? `${s.loss.client.first_name} ${s.loss.client.last_name}` : 'CD loss report', sub: s.loss.medication_name ?? 'Loss report' };
    }
}

function stepsFor(s: CdDetailSubject): WizardStep[] {
    if (s.kind === 'entry') {
        return [
            { key: 'movement', label: 'Movement', blurb: 'Drug, quantity & balance', icon: Package },
            { key: 'audit', label: 'Audit trail', blurb: 'Witness & timestamp', icon: FileText },
        ];
    }
    const one: Record<Exclude<CdDetailSubject['kind'], 'entry'>, WizardStep> = {
        medication: { key: 'stock', label: 'Stock & reconciliation', blurb: 'On-hand & last check', icon: ShieldCheck },
        discrepancy: { key: 'disc', label: 'Discrepancy', blurb: 'Counts & resolution', icon: AlertTriangle },
        destruction: { key: 'dest', label: 'Destruction', blurb: 'Item, method & witnesses', icon: Trash2 },
        loss: { key: 'loss', label: 'Loss report', blurb: 'Loss & investigation', icon: FileWarning },
    };
    return [one[s.kind]];
}

export function CdDetailDialog({
    subject,
    onClose,
    onCheckBalance,
    onRecordMovement,
    onResolveDiscrepancy,
    onLossAction,
}: {
    subject: CdDetailSubject;
    onClose: () => void;
    /** Open the balance-check wizard pre-filled to `medId` (in place). */
    onCheckBalance?: (medId: number) => void;
    /** Open the record-CD-entry wizard (in place). */
    onRecordMovement?: () => void;
    /** Open the resolve-discrepancy wizard (in place). */
    onResolveDiscrepancy?: (disc: CdDiscrepancy) => void;
    /** Open the investigate/resolve-loss wizard (in place). */
    onLossAction?: (loss: CdLossReport, action: 'investigate' | 'resolve') => void;
}) {
    const [section, setSection] = useState(0);
    const r = rail(subject);
    const steps = stepsFor(subject);
    const clientId = subjectClientId(subject);

    const footerEnd: ReactNode[] = [];
    if (subject.kind === 'medication') {
        if (onCheckBalance) footerEnd.push(<Button key="bal" type="button" onClick={() => onCheckBalance(subject.med.id)}><ClipboardCheck className="h-4 w-4" /> Check balance</Button>);
        if (onRecordMovement) footerEnd.push(<Button key="mv" type="button" variant="outline" onClick={onRecordMovement}><Package className="h-4 w-4" /> Record movement</Button>);
    } else if (subject.kind === 'entry') {
        if (onRecordMovement) footerEnd.push(<Button key="mv" type="button" onClick={onRecordMovement}><Package className="h-4 w-4" /> Record movement</Button>);
        if (onCheckBalance && subject.med) footerEnd.push(<Button key="bal" type="button" variant="outline" onClick={() => onCheckBalance(subject.med!.id)}><ClipboardCheck className="h-4 w-4" /> Check balance</Button>);
    } else if (subject.kind === 'discrepancy') {
        if (onResolveDiscrepancy && subject.disc.status !== 'closed' && subject.disc.status !== 'resolved') {
            footerEnd.push(<Button key="res" type="button" onClick={() => onResolveDiscrepancy(subject.disc)}><ShieldCheck className="h-4 w-4" /> Resolve</Button>);
        }
    } else if (subject.kind === 'loss') {
        if (onLossAction && subject.loss.investigation_status === 'reported') footerEnd.push(<Button key="inv" type="button" variant="outline" onClick={() => onLossAction(subject.loss, 'investigate')}><FileWarning className="h-4 w-4" /> Investigate</Button>);
        if (onLossAction && subject.loss.investigation_status !== 'resolved') footerEnd.push(<Button key="resl" type="button" onClick={() => onLossAction(subject.loss, 'resolve')}><ShieldCheck className="h-4 w-4" /> Resolve</Button>);
    }
    if (clientId) footerEnd.push(<Button key="client" type="button" variant="ghost" onClick={() => router.visit(`/operations/clients/${clientId}/care`)}><User className="h-4 w-4" /> Client</Button>);
    footerEnd.push(<Button key="export" type="button" variant="ghost" onClick={() => window.open(CD_REGISTER_PDF, '_blank')}><Printer className="h-4 w-4" /> Export register</Button>);

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Controlled drug detail"
            description="Read-only detail of a controlled-drug register record."
            railIcon={r.icon}
            railTitle={r.title}
            railSub={r.sub}
            steps={steps}
            stepIndex={section}
            onStepClick={setSection}
            pct={null}
            footerStart={<Button type="button" variant="outline" onClick={onClose}>Close</Button>}
            footerEnd={<>{footerEnd}</>}
        >
            {subject.kind === 'medication' && <MedicationBody med={subject.med} />}
            {subject.kind === 'entry' && <EntryBody entry={subject.entry} section={section} />}
            {subject.kind === 'discrepancy' && <DiscrepancyBody disc={subject.disc} />}
            {subject.kind === 'destruction' && <DestructionBody destruction={subject.destruction} />}
            {subject.kind === 'loss' && <LossBody loss={subject.loss} />}
        </WizardShell>
    );
}

function DrugName({ name, controlled }: { name: ReactNode; controlled?: boolean }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            {name ?? '—'}
            {controlled ? <CdBadge /> : null}
        </span>
    );
}

function MedicationBody({ med }: { med: CdMedication }) {
    const s = med.stock;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={Lock} title="Controlled drug">
                <ReviewRow label="Name" value={<DrugName name={med.name} controlled={med.controlled_drug} />} />
                <ReviewRow label="Form" value={med.form} />
                <ReviewRow label="Strength" value={med.strength} />
                <ReviewRow label="Resident" value={med.client_name} />
            </ReviewCard>
            <ReviewCard icon={Package} title="Stock on hand">
                <ReviewRow label="On hand" value={s ? `${s.on_hand ?? '—'} ${s.unit ?? ''}`.trim() : '—'} />
                <ReviewRow label="Batch" value={s?.batch_number} />
                <ReviewRow label="Expiry" value={fmtDay(s?.expiry_date)} />
                <ReviewRow label="Reorder level" value={s?.reorder_level != null ? String(s.reorder_level) : null} />
                <ReviewRow label="Last counted" value={fmtTs(s?.last_counted_at)} />
            </ReviewCard>
            <ReviewCard icon={ShieldCheck} title="Reconciliation" span>
                <ReviewRow
                    label="Status"
                    value={med.overdue_check
                        ? <span className="rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-semibold text-status-warning">Overdue</span>
                        : <span className="rounded-full bg-status-success-bg px-2 py-0.5 text-[11px] font-semibold text-status-success">Current</span>}
                />
                <ReviewRow label="Last balance check" value={fmtTs(med.last_balance_check_at) ?? 'Never'} />
                <ReviewRow label="Days since check" value={med.days_since_check != null ? `${med.days_since_check}d` : null} />
            </ReviewCard>
        </div>
    );
}

function EntryBody({ entry, section }: { entry: CdEntry; section: number }) {
    if (section === 1) {
        return (
            <div className="grid gap-4">
                <ReviewCard icon={FileText} title="Audit trail">
                    <ReviewRow label="Recorded by" value={entry.recorded_by_name} />
                    <ReviewRow label="Witnessed by" value={entry.witnessed_by_name} />
                    <ReviewRow label="Recorded at" value={fmtTs(entry.recorded_at)} />
                </ReviewCard>
                {entry.notes ? (
                    <ReviewCard icon={FileText} title="Notes">
                        <p className="text-[13px] leading-relaxed text-muted-foreground">{entry.notes}</p>
                    </ReviewCard>
                ) : null}
            </div>
        );
    }
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={Lock} title="Controlled drug">
                <ReviewRow label="Name" value={<DrugName name={entry.medication_name} controlled={entry.controlled_drug ?? true} />} />
                <ReviewRow label="Resident" value={entry.client_name} />
            </ReviewCard>
            <ReviewCard icon={Package} title="Movement">
                <ReviewRow label="Type" value={<span className="capitalize">{entry.entry_type.replace(/_/g, ' ')}</span>} />
                <ReviewRow label="Quantity" value={`${entry.quantity ?? '—'} ${entry.unit ?? ''}`.trim()} />
                <ReviewRow label="Balance" value={`${entry.on_hand_before ?? '—'} → ${entry.on_hand_after ?? '—'}`} />
                <ReviewRow label="Batch" value={entry.batch_number} />
                <ReviewRow label="Expiry" value={fmtDay(entry.expiry_date)} />
            </ReviewCard>
        </div>
    );
}

function DiscrepancyBody({ disc }: { disc: CdDiscrepancy }) {
    const resolved = disc.status === 'closed' || disc.status === 'resolved';
    return (
        <div className="grid gap-4">
            {!resolved ? <InfoCard icon={AlertTriangle} tone="crit">Open discrepancy — investigate the count and resolve against the linked incident.</InfoCard> : null}
            <div className="grid gap-4 sm:grid-cols-2">
                <ReviewCard icon={AlertTriangle} title="Discrepancy">
                    <ReviewRow label="Drug" value={<DrugName name={disc.medication?.name} controlled />} />
                    <ReviewRow label="Resident" value={disc.client ? `${disc.client.first_name} ${disc.client.last_name}` : null} />
                    <ReviewRow label="Status" value={<StatusPill label={disc.status} />} />
                    <ReviewRow label="Reason" value={disc.reason} />
                </ReviewCard>
                <ReviewCard icon={ShieldCheck} title="Counts">
                    <ReviewRow label="Expected" value={disc.on_hand_before != null ? String(disc.on_hand_before) : null} />
                    <ReviewRow label="Actual" value={disc.on_hand_after != null ? String(disc.on_hand_after) : null} />
                    <ReviewRow label="Difference" value={disc.difference != null ? String(disc.difference) : null} />
                </ReviewCard>
                <ReviewCard icon={FileText} title="Reporting & resolution" span>
                    <ReviewRow label="Reported by" value={disc.reported_by_name} />
                    <ReviewRow label="Witnessed by" value={disc.witnessed_by_name} />
                    <ReviewRow label="Reported at" value={fmtTs(disc.reported_at)} />
                    {disc.notes ? <ReviewRow label="Notes" value={disc.notes} /> : null}
                    {resolved ? <ReviewRow label="Resolved by" value={disc.resolved_by_name} /> : null}
                    {resolved ? <ReviewRow label="Resolved at" value={fmtTs(disc.resolved_at)} /> : null}
                    {disc.resolution_notes ? <ReviewRow label="Resolution" value={disc.resolution_notes} /> : null}
                </ReviewCard>
            </div>
        </div>
    );
}

function DestructionBody({ destruction: d }: { destruction: CdDestruction }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <ReviewCard icon={Trash2} title="Item destroyed">
                <ReviewRow label="Drug" value={<DrugName name={d.medication_name} controlled />} />
                <ReviewRow label="Resident" value={d.client_name} />
                <ReviewRow label="Quantity" value={`${d.quantity ?? '—'} ${d.unit ?? ''}`.trim()} />
                <ReviewRow label="Reason" value={humanize(d.reason)} />
            </ReviewCard>
            <ReviewCard icon={ShieldCheck} title="Method & authorisation">
                <ReviewRow label="Disposal method" value={humanize(d.disposal_method)} />
                <ReviewRow label="Destroyed by" value={d.destroyed_by_name} />
                <ReviewRow label="Witness 1" value={d.witness_name} />
                <ReviewRow label="Witness 2" value={d.witness_2_name} />
                <ReviewRow label="Authorised by" value={d.authorised_by_name} />
                <ReviewRow label="Destroyed at" value={fmtTs(d.destroyed_at)} />
            </ReviewCard>
            {d.notes ? (
                <ReviewCard icon={FileText} title="Notes" span>
                    <p className="text-[13px] leading-relaxed text-muted-foreground">{d.notes}</p>
                </ReviewCard>
            ) : null}
        </div>
    );
}

function LossBody({ loss: l }: { loss: CdLossReport }) {
    const open = l.investigation_status !== 'resolved';
    return (
        <div className="grid gap-4">
            {open ? <InfoCard icon={FileWarning} tone="crit">Open loss investigation — capture findings and escalate to the CD Accountable Officer / regulator as required.</InfoCard> : null}
            <div className="grid gap-4 sm:grid-cols-2">
                <ReviewCard icon={FileWarning} title="Loss">
                    <ReviewRow label="Drug" value={<DrugName name={l.medication_name} controlled />} />
                    <ReviewRow label="Resident" value={l.client ? `${l.client.first_name} ${l.client.last_name}` : null} />
                    <ReviewRow label="Quantity lost" value={`${l.quantity_lost ?? '—'} ${l.unit ?? ''}`.trim()} />
                    <ReviewRow label="Status" value={<StatusPill label={l.investigation_status} />} />
                    <ReviewRow label="Circumstances" value={l.circumstances} />
                </ReviewCard>
                <ReviewCard icon={ShieldCheck} title="Escalation & investigation">
                    <ReviewRow label="Discovered by" value={l.discovered_by_name} />
                    <ReviewRow label="Discovered at" value={fmtTs(l.discovered_at)} />
                    <ReviewRow label="Police" value={l.reported_to_police ? l.police_reference || 'Reported' : 'No'} />
                    <ReviewRow label="Pharmacy" value={l.reported_to_pharmacy ? l.pharmacy_name || 'Reported' : 'No'} />
                    {l.investigation_notes ? <ReviewRow label="Findings" value={l.investigation_notes} /> : null}
                    {l.resolution_outcome ? <ReviewRow label="Outcome" value={l.resolution_outcome} /> : null}
                </ReviewCard>
            </div>
        </div>
    );
}

export default CdDetailDialog;
