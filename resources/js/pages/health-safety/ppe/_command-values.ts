import type { PpeCommand } from '@/lib/health-safety/ppe-navigation';
import type { PpeRecord } from './_workspace-types';
import { ppeReceiptCommand } from './_command-state';

export type PpeFormValues = {
    brand: string; model: string; serial_number: string; quantity: string; location: string; condition: string;
    purchase_date: string; expiry_date: string; next_inspection_due: string; user_id: string;
    fit_test_completed: boolean; fit_test_date: string; fit_test_result: string; training_completed: boolean; training_date: string;
    acknowledged: boolean; notes: string; reason: string; disposal_method: string; result: string;
    condition_after: string; findings: string; action_taken: string; file: File | null; kind: string; alt_text: string;
    documents: { file: File; kind: string; note: string }[];
};
export function ppeFormValues(record: PpeRecord): PpeFormValues {
    const d = record.kind === 'inventory' ? record.detail : null;
    return { brand: d?.brand ?? '', model: d?.model ?? '', serial_number: d?.serial_number ?? '', quantity: String(d?.quantity ?? 1),
        location: d?.location ?? '', condition: d?.condition ?? 'good', purchase_date: d?.purchase_date ?? '', expiry_date: d?.expiry_date ?? '',
        next_inspection_due: d?.next_inspection_due ?? '', user_id: '', fit_test_completed: false, fit_test_date: '', fit_test_result: '',
        training_completed: false, training_date: '', acknowledged: false, notes: '', reason: '', disposal_method: '', result: '',
        condition_after: '', findings: '', action_taken: '', file: null, kind: 'document', alt_text: '', documents: [] };
}
const nullable = (value: string) => value.trim() === '' ? null : value;
export function ppeCommandRequest(record: PpeRecord, command: PpeCommand, f: PpeFormValues, attachment?: number | null) {
    const base = `/health-safety/ppe/${record.kind}/${record.detail.id}`;
    let method: 'post' | 'put' | 'delete' = 'post'; let href = base; let values: Record<string, unknown> = {};
    switch (command) {
        case 'edit': method = 'put'; values = { brand: nullable(f.brand), model: nullable(f.model), serial_number: nullable(f.serial_number),
            quantity: Number(f.quantity), location: nullable(f.location), condition: f.condition, purchase_date: nullable(f.purchase_date),
            expiry_date: nullable(f.expiry_date), next_inspection_due: nullable(f.next_inspection_due) }; break;
        case 'hold': case 'release': method = 'put'; values = { status: command === 'hold' ? 'maintenance' : 'available', reason: f.reason }; break;
        case 'allocate': href += '/allocate'; values = { user_id: Number(f.user_id), fit_test_completed: f.fit_test_completed,
            fit_test_date: f.fit_test_completed ? nullable(f.fit_test_date) : null, fit_test_result: f.fit_test_completed ? nullable(f.fit_test_result) : null,
            training_completed: f.training_completed, training_date: f.training_completed ? nullable(f.training_date) : null, acknowledged: f.acknowledged, notes: nullable(f.notes) }; break;
        case 'acknowledge': href += ppeReceiptCommand(record, command) === 'acknowledge_own' ? '/acknowledge-own' : '/acknowledge'; break;
        case 'return': href += '/return'; values = { condition: f.condition, notes: nullable(f.notes) }; break;
        case 'inspect': href += '/inspections'; values = { result: f.result, condition_after: nullable(f.condition_after), findings: nullable(f.findings),
            action_taken: nullable(f.action_taken), next_inspection_due: nullable(f.next_inspection_due), documents: f.documents }; break;
        case 'condemn': href += '/condemn'; values = { reason: f.reason }; break;
        case 'dispose': href += '/dispose'; values = { disposal_method: f.disposal_method, reason: nullable(f.reason) }; break;
        case 'upload_evidence': href += '/attachments'; values = { file: f.file, kind: f.kind, notes: nullable(f.notes), alt_text: nullable(f.alt_text) }; break;
        case 'remove_evidence': method = 'delete'; href += `/attachments/${attachment}`; values = { reason: f.reason }; break;
        case 'relocate_evidence': href += `/attachments/${attachment}/relocate`; values = { reason: f.reason }; break;
    }
    return { method, href, values };
}
