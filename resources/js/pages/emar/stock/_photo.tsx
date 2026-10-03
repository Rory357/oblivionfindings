import { ConfirmDialog } from '@/components/confirm-dialog';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Camera } from 'lucide-react';
import { useRef, useState } from 'react';
import { csrfHeaders } from './_requests';
import type { ItemDetail } from './_types';

export function PackPhotoDialog({ item, onClose, onSaved }: { item: ItemDetail; onClose: () => void; onSaved: () => void }) {
    const [packId, setPackId] = useState(item.packs.length === 1 ? String(item.packs[0].id) : '');
    const [photo, setPhoto] = useState<File | null>(null);
    const [description, setDescription] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [discard, setDiscard] = useState(false);
    const camera = useRef<HTMLInputElement>(null);
    const requestUuid = useRef(crypto.randomUUID());
    const busy = useRef(false);
    const close = () => { if (!busy.current) { if (photo || description) setDiscard(true); else onClose(); } };
    const choosePhoto = (files: File[]) => {
        const selected = files[0];
        if (!selected) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(selected.type) || selected.size > 8 * 1024 * 1024) {
            setError('Choose a JPEG, PNG or WebP photo up to 8 MB.');
            return;
        }
        requestUuid.current = crypto.randomUUID();
        setPhoto(selected);
        setError('');
    };
    const save = async () => {
        if (busy.current || !photo || !packId) return;
        busy.current = true;
        setSaving(true);
        setError('');
        const body = new FormData();
        body.set('request_uuid', requestUuid.current);
        body.set('photo', photo);
        body.set('description', description);
        try {
            const response = await fetch(`/emar/stock/packs/${packId}/photos`, { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                setError(result.errors?.photo?.[0] ?? result.errors?.description?.[0] ?? result.message ?? 'Could not save this photo. Your entries are kept.');
                return;
            }
            onSaved();
            onClose();
        } catch {
            setError('The photo save could not be confirmed. Your photo and description are kept. Retry uses the same request.');
        } finally {
            busy.current = false;
            setSaving(false);
        }
    };
    return <>
        <SettingsModal title="Add a current pack photo" description={`${item.client_name} · ${item.name}`} width={720} onClose={close}
            footer={<><Button variant="outline" disabled={saving} onClick={close}>Cancel</Button><Button disabled={saving || !packId || !photo} onClick={() => void save()}>{saving ? 'Saving…' : 'Save pack photo'}</Button></>}>
            <SettingsNotice role="note">Photos help identify a pack. Check the current medicine order and its label. A new photo becomes the latest for this pack; earlier photos remain in its history.</SettingsNotice>
            <RecordPicker label="Pack" value={packId} options={item.packs.map((pack) => ({ value: String(pack.id), label: `${pack.batch_number ?? (pack.batch_not_printed ? 'Batch not printed' : 'Batch unknown')} · ${pack.expiry_date?.slice(0, 10) ?? 'Expiry unknown'}` }))}
                onChange={(value) => { setPackId(value); requestUuid.current = crypto.randomUUID(); }} />
            {error && <SettingsNotice>{error}</SettingsNotice>}
            <FileDropzone accept="image/jpeg,image/png,image/webp" multiple={false} onFiles={choosePhoto} disabled={saving} title="Choose a pack photo" hint="JPEG, PNG or WebP · up to 8 MB" />
            <input ref={camera} type="file" className="sr-only" accept="image/*" capture="environment" disabled={saving} onChange={(event) => { choosePhoto(Array.from(event.target.files ?? [])); event.target.value = ''; }} />
            <Button variant="outline" disabled={saving} onClick={() => camera.current?.click()}><Camera className="size-4" />Use camera</Button>
            {photo && <StagedFileCard file={photo} onRemove={() => setPhoto(null)} />}
            <Label htmlFor="photo-description">What changed or what does this photo show?</Label>
            <Textarea id="photo-description" maxLength={1000} disabled={saving} value={description} onChange={(event) => { setDescription(event.target.value); requestUuid.current = crypto.randomUUID(); }} />
        </SettingsModal>
        <ConfirmDialog open={discard} onClose={() => setDiscard(false)} onConfirm={onClose} title="Discard this pack photo?" description="Your unsaved photo and description will be discarded." confirmText="Discard draft" />
    </>;
}
