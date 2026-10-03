import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Camera, Check, ClipboardCheck, Package, Truck } from 'lucide-react';
import { useRef, useState } from 'react';
import { csrfHeaders, useStockCommand } from './_requests';
import type { StockItem, SupplyOrder } from './_types';

const steps = [
    { key: 'source', label: 'What arrived', blurb: 'Source and label', icon: Truck },
    { key: 'pack', label: 'The pack', blurb: 'Count, batch and expiry', icon: Package },
    { key: 'photo', label: 'Pack photo', blurb: 'Optional identification', icon: Camera },
    { key: 'review', label: 'Review and receive', blurb: 'Check what will be saved', icon: ClipboardCheck },
];
export function ReceiveWizard({ item, order, onClose, onSaved }: { item: StockItem; order?: SupplyOrder; onClose: () => void; onSaved: () => void }) {
    const [step, setStep] = useState(0);
    const [source, setSource] = useState(order ? 'pharmacy' : '');
    const [reference, setReference] = useState(order ? `Pharmacy order #${order.id}` : '');
    const [checked, setChecked] = useState(false);
    const [quantity, setQuantity] = useState('');
    const [unit, setUnit] = useState(item.unit ?? '');
    const [batch, setBatch] = useState(order?.batch_number ?? '');
    const [expiry, setExpiry] = useState(order?.batch_expiry ? `${order.batch_expiry.slice(5, 7)}/${order.batch_expiry.slice(0, 4)}` : '');
    const [noBatch, setNoBatch] = useState(false);
    const [noExpiry, setNoExpiry] = useState(false);
    const [reason, setReason] = useState('');
    const [notes, setNotes] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const [photoError, setPhotoError] = useState('');
    const [uploading, setUploading] = useState(false);
    const [savedLot, setSavedLot] = useState<number | null>(null);
    const [photoSaved, setPhotoSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const camera = useRef<HTMLInputElement>(null);
    const photoUuid = useRef(crypto.randomUUID());
    const command = useStockCommand();
    const saving = command.saving || uploading;
    const dirty = checked || quantity !== '' || notes !== '' || photo !== null || reason !== '';
    const close = () => { if (!saving) { if (dirty && !savedLot) setDiscard(true); else onClose(); } };
    const choosePhoto = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) {
            setPhotoError('Choose a JPEG, PNG or WebP photo up to 8 MB.');
            return;
        }
        setPhoto(file);
        photoUuid.current = crypto.randomUUID();
        setPhotoError('');
    };
    const upload = async (lotId: number) => {
        if (!photo) return;
        setUploading(true);
        setPhotoError('');
        const body = new FormData();
        body.set('photo', photo);
        body.set('request_uuid', photoUuid.current);
        try {
            const response = await fetch(`/emar/stock/packs/${lotId}/photos`, { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                setPhotoError(result.errors?.photo?.[0] ?? result.message ?? 'Stock is received. The photo was not saved; keep it here and try again.');
                return;
            }
            setPhotoSaved(true);
        } catch {
            setPhotoError('Stock is received. The photo save could not be confirmed; keep it here and try again.');
        } finally { setUploading(false); }
    };
    const receive = async () => {
        const result = await command.run({
            action: 'receive', client_medication_id: item.id, quantity, unit, source, source_reference: reference,
            label_checked: checked, batch_number: noBatch ? null : batch, batch_not_printed: noBatch,
            expiry_month: noExpiry ? null : expiry, expiry_not_printed: noExpiry, short_expiry_reason: reason,
            notes, pharmacy_order_id: order?.id ?? null,
        });
        if (!result?.lot_id) return;
        setSavedLot(result.lot_id);
        onSaved();
        await upload(result.lot_id);
    };
    const errors = Object.entries(command.errors);
    return <>
        <WizardShell open onClose={close} title="Receive a delivery" description="Count the supplied pack and check its label." railIcon={Truck} railTitle="Receive a delivery" railSub={`${item.client_name} · ${item.name}`} steps={steps} stepIndex={step} onStepClick={setStep} pct={(step + 1) * 25}
            footerStart={<Button variant="outline" className="frontline-tap" disabled={saving} onClick={close}>Cancel</Button>}
            footerEnd={<><Button variant="outline" disabled={step === 0 || saving} onClick={() => setStep(step - 1)}>Back</Button><Button className="frontline-tap" disabled={saving || (step === 0 && (!source || !reference.trim() || !checked)) || (step === 1 && (!quantity || !unit.trim() || (!noBatch && !batch) || (!noExpiry && !expiry)))} onClick={() => step < 3 ? setStep(step + 1) : void receive()}>{saving ? 'Saving…' : step === 3 ? 'Receive stock' : step === 2 && !photo ? 'Continue without a photo' : 'Continue'}</Button></>}
            success={savedLot ? <WizardSuccessPane title="Stock received" blurb={<>{quantity} {unit} received for {item.client_name}. {photoSaved ? 'The pack photo is saved.' : photo ? 'The photo still needs to be saved.' : 'Received without a photo.'}{photoError && <SettingsNotice>{photoError}</SettingsNotice>}</>}
                actions={<>{photo && !photoSaved && <Button disabled={uploading} onClick={() => void upload(savedLot)}>{uploading ? 'Saving photo…' : 'Try photo again'}</Button>}<Button disabled={uploading} onClick={onClose}>Done</Button></>} /> : undefined}>
            <WizardStepPane>
                {errors.length > 0 && <SettingsNotice>{errors.map(([key, error]) => <InputError key={key} message={error} />)}</SettingsNotice>}
                <div className="space-y-4">
                    {step === 0 && <>
                        <p className="text-section-title">{item.name}</p><p>{item.client_name} · {item.site_name}</p>
                        {order && <SettingsNotice role="note">Receiving against order #{order.id}. {Number(order.quantity_received ?? 0)} of {order.quantity_ordered} received so far. Count what arrived; do not copy the pharmacy label.</SettingsNotice>}
                        <Label>Where did it come from?</Label><div className="grid grid-cols-2 gap-2 sm:grid-cols-3">{[['pharmacy', 'Pharmacy'], ['family', 'Family'], ['hospital', 'Hospital'], ['respite', 'Respite'], ['other', 'Other source']].map(([key, label]) => <Button key={key} type="button" variant="outline" aria-pressed={source === key} onClick={() => setSource(key)}><Truck className="size-4" />{label}{source === key && <Check className="size-4" />}</Button>)}</div>
                        <Label htmlFor="source_reference">Source or delivery reference</Label><Input id="source_reference" value={reference} onChange={(event) => setReference(event.target.value)} />
                        <label className="frontline-tap flex items-center gap-2"><Checkbox checked={checked} onCheckedChange={(value) => setChecked(value === true)} />I checked the person's name, medicine and instructions against the label.</label>
                    </>}
                    {step === 1 && <>{!item.stock_id && <><Label htmlFor="unit">Counted unit printed on the pack</Label><Input id="unit" value={unit} onChange={(event) => setUnit(event.target.value)} /><p className="text-caption">For example, tablets or mL. This records the supplied unit; it does not convert the prescribed dose.</p></>}
                        <Label htmlFor="quantity">Counted quantity ({unit})</Label><Input id="quantity" type="number" min="0.01" step="0.01" value={quantity} onChange={(event) => setQuantity(event.target.value)} /><p className="text-caption">Count them — do not copy the label. The unit stays {unit}.</p>
                        <Label htmlFor="batch_number">Batch printed on the pack</Label><Input id="batch_number" disabled={noBatch} value={batch} onChange={(event) => setBatch(event.target.value)} />
                        <label className="frontline-tap flex items-center gap-2"><Checkbox checked={noBatch} onCheckedChange={(value) => setNoBatch(value === true)} />Batch not printed on the pack</label>
                        <Label htmlFor="expiry_month">Expiry printed on the pack (MM/YYYY)</Label><Input id="expiry_month" placeholder="MM/YYYY" disabled={noExpiry} value={expiry} onChange={(event) => setExpiry(event.target.value)} />
                        <label className="frontline-tap flex items-center gap-2"><Checkbox checked={noExpiry} onCheckedChange={(value) => setNoExpiry(value === true)} />Expiry not printed on the pack</label>
                        <Label htmlFor="short_expiry_reason">If it expires within 7 days, why accept it?</Label><Textarea id="short_expiry_reason" value={reason} onChange={(event) => setReason(event.target.value)} />
                        <Label htmlFor="notes">Delivery notes (optional)</Label><Textarea id="notes" value={notes} onChange={(event) => setNotes(event.target.value)} />
                    </>}
                    {step === 2 && <>
                        <SettingsNotice role="note">A photo helps identify this supplied pack. Always check the label and the current order. Taking a photo is optional; earlier photos are kept.</SettingsNotice>
                        <FileDropzone accept="image/jpeg,image/png,image/webp" multiple={false} onFiles={choosePhoto} title="Add a pack photo" hint="JPEG, PNG or WebP · up to 8 MB" />
                        <input ref={camera} className="hidden" type="file" accept="image/*" capture="environment" onChange={(event) => choosePhoto(Array.from(event.target.files ?? []))} />
                        <Button variant="outline" className="frontline-tap" onClick={() => camera.current?.click()}><Camera className="size-4" />Take a photo</Button>
                        {photo && <StagedFileCard file={photo} onRemove={() => setPhoto(null)}><p className="text-caption">Staged — saved after stock is received.</p></StagedFileCard>}
                        <InputError message={photoError} />
                    </>}
                    {step === 3 && <>
                        <ReviewCard icon={Package} title="The supplied pack" onEdit={() => setStep(1)}><ReviewRow label="Person" value={item.client_name} /><ReviewRow label="Medicine" value={item.name} /><ReviewRow label="Counted" value={`${quantity} ${unit}`} /><ReviewRow label="Batch" value={noBatch ? 'Not printed on the pack' : batch} /><ReviewRow label="Expiry" value={noExpiry ? 'Not printed on the pack' : expiry} /><ReviewRow label="Source" value={reference} /><ReviewRow label="Photo" value={photo ? 'Staged — not yet saved' : 'Continue without a photo'} /></ReviewCard>
                        <SettingsNotice role="note">When you save, this becomes a separate pack and a permanent receipt record. {order ? 'The received quantity is added to this order only; a partial delivery stays open.' : 'No pharmacy order is transmitted by this action.'} This is not a stock count.</SettingsNotice>
                    </>}
                </div>
            </WizardStepPane>
        </WizardShell>
        <ConfirmDialog open={discard} onClose={() => setDiscard(false)} onConfirm={onClose} title="Discard this receipt?" description="Nothing has been received. Your entries and staged photo will be discarded." confirmText="Discard draft" />
    </>;
}

