/* eslint-disable no-restricted-syntax -- Yes/No toggles are intentional styled
 * native controls (Segmented); semantic design tokens only. */
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, Segmented, SelectInput } from '@/components/wizard/primitives';
import { QUANTITY_UNITS } from '@/pages/health-safety/substances/constants';

/**
 * The shared storage-location field set — used by the detail dialog's "Add
 * storage" pane (site-picker first) and the site profile's "Add storage here"
 * dialog (substance-picker first). The variable lead field (site vs substance)
 * is rendered by the caller; these are the fields both share.
 */
export type StorageFieldKey =
    | 'location_description'
    | 'current_quantity'
    | 'maximum_quantity'
    | 'quantity_unit'
    | 'container_type'
    | 'properly_labelled'
    | 'segregation_compliant'
    | 'last_audit_date'
    | 'storage_notes';

function Bool({ value, onChange }: { value: boolean; onChange: (v: boolean) => void }) {
    return (
        <Segmented<'yes' | 'no'>
            value={value ? 'yes' : 'no'}
            onChange={(v) => onChange(v === 'yes')}
            options={[
                { value: 'yes', label: 'Yes' },
                { value: 'no', label: 'No' },
            ]}
        />
    );
}

export function StorageLocationFields({
    values,
    set,
    errors,
}: {
    values: Record<StorageFieldKey, string | boolean>;
    set: (key: StorageFieldKey, value: string | boolean) => void;
    errors: Partial<Record<string, string>>;
}) {
    const s = (k: StorageFieldKey) => String(values[k] ?? '');
    return (
        <>
            <Field label="Location" required error={errors.location_description}>
                <Input value={s('location_description')} onChange={(e) => set('location_description', e.target.value)} placeholder="e.g. Chemical store, bay 1" />
            </Field>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Quantity held" error={errors.current_quantity}>
                    <Input type="number" min="0" step="0.01" value={s('current_quantity')} onChange={(e) => set('current_quantity', e.target.value)} />
                </Field>
                <Field label="Maximum" error={errors.maximum_quantity}>
                    <Input type="number" min="0" step="0.01" value={s('maximum_quantity')} onChange={(e) => set('maximum_quantity', e.target.value)} />
                </Field>
                <Field label="Unit">
                    <SelectInput value={s('quantity_unit')} onChange={(v) => set('quantity_unit', v)} placeholder="Unit" options={QUANTITY_UNITS} />
                </Field>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Container">
                    <Input value={s('container_type')} onChange={(e) => set('container_type', e.target.value)} placeholder="e.g. HDPE drum, 5 L bottles" />
                </Field>
                <Field label="Last audited">
                    <Input type="date" value={s('last_audit_date')} onChange={(e) => set('last_audit_date', e.target.value)} />
                </Field>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Properly labelled">
                    <Bool value={!!values.properly_labelled} onChange={(v) => set('properly_labelled', v)} />
                </Field>
                <Field label="Segregation compliant">
                    <Bool value={!!values.segregation_compliant} onChange={(v) => set('segregation_compliant', v)} />
                </Field>
            </div>
            <Field label="Notes" hint="Optional">
                <Textarea rows={2} value={s('storage_notes')} onChange={(e) => set('storage_notes', e.target.value)} />
            </Field>
        </>
    );
}
