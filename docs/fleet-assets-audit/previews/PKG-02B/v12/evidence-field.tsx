import { Button } from '@/components/ui/button';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Camera, FileText, Upload } from 'lucide-react';
import { useState } from 'react';
import { Modal, Notice } from './ui';
export type EvidenceFile = {
    documentGroup?: string;
    id: string;
    name: string;
    type?: string;
    size?: number;
    url?: string;
    owner?: string;
    category?: string;
    reference?: string;
    issued?: string;
    expiry?: string;
    status?: string;
    finance?: string;
    replaces?: string;
    note?: string;
};
export const readFiles = (value: string): EvidenceFile[] => {
    try {
        return JSON.parse(value || '[]');
    } catch {
        return [];
    }
};
export function EvidenceField({
    label = 'Supporting evidence',
    value,
    onChange,
    imagesOnly = false,
}: {
    label?: string;
    value: string;
    onChange: (v: string) => void;
    imagesOnly?: boolean;
}) {
    const [error, setError] = useState('');
    const files = readFiles(value);
    const add = (chosen: File[]) => {
        const accepted = chosen.filter(
            (f) =>
                (imagesOnly
                    ? ['image/jpeg', 'image/png']
                    : ['image/jpeg', 'image/png', 'application/pdf']
                ).includes(f.type) && f.size <= 10 * 1024 * 1024,
        );
        setError(
            accepted.length !== chosen.length
                ? 'Choose PNG, JPEG or PDF up to 10 MiB. Valid selections were kept.'
                : '',
        );
        onChange(
            JSON.stringify([
                ...files,
                ...accepted.map((f) => ({
                    id: 'EV-DEMO-' + crypto.randomUUID().slice(0, 8),
                    name: f.name,
                    type: f.type,
                    size: f.size,
                    url: URL.createObjectURL(f),
                })),
            ]),
        );
    };
    return (
        <div className="evidence-field">
            <FileDropzone
                title={label}
                accept={
                    imagesOnly
                        ? 'image/jpeg,image/png'
                        : 'image/jpeg,image/png,application/pdf'
                }
                multiple={!imagesOnly}
                onFiles={add}
                hint={
                    imagesOnly
                        ? 'PNG or JPEG · up to 10 MiB'
                        : 'PDF, PNG or JPEG · up to 10 MiB each'
                }
            />
            {!imagesOnly && (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        const canvas = document.createElement('canvas');
                        canvas.width = 720;
                        canvas.height = 360;
                        const context = canvas.getContext('2d');
                        if (!context) return;
                        context.fillStyle = '#ffffff';
                        context.fillRect(0, 0, 720, 360);
                        context.fillStyle = '#5036ff';
                        context.fillRect(0, 0, 720, 12);
                        context.font = 'bold 28px sans-serif';
                        context.fillText('SYNTHETIC DOCUMENT', 36, 90);
                        context.fillStyle = '#1c2440';
                        context.font = '20px sans-serif';
                        context.fillText('Kōwhai van · VH-014', 36, 140);
                        context.fillText(
                            'Preview attachment — no compliance authority',
                            36,
                            195,
                        );
                        context.fillText('22 September 2026', 36, 250);
                        canvas.toBlob((blob) => {
                            if (blob)
                                add([
                                    new File(
                                        [blob],
                                        'synthetic-vehicle-document.png',
                                        { type: 'image/png' },
                                    ),
                                ]);
                        }, 'image/png');
                    }}
                >
                    Use a synthetic sample file
                </Button>
            )}
            {error && (
                <Notice title="File not accepted" tone="warning">
                    {error}
                </Notice>
            )}
            {files.length > 0 && (
                <div className="attachment-grid">
                    {files.map((f) => (
                        <div className="attachment-item" key={f.id}>
                            {f.type?.startsWith('image/') ? (
                                <img src={f.url} alt={f.name} />
                            ) : (
                                <FileText size={24} />
                            )}
                            <div>
                                <strong>{f.name}</strong>
                                <small>
                                    {Math.ceil((f.size || 0) / 1024)} KB ·
                                    selected
                                </small>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label={`Remove ${f.name}`}
                                onClick={() =>
                                    onChange(
                                        JSON.stringify(
                                            files.filter((x) => x.id !== f.id),
                                        ),
                                    )
                                }
                            >
                                ×
                            </Button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
export function PhotoDialog({
    current,
    onSave,
    onClose,
}: {
    current?: EvidenceFile;
    onSave: (photo: EvidenceFile) => void;
    onClose: () => void;
}) {
    const [value, setValue] = useState('[]'),
        [error, setError] = useState(''),
        [discard, setDiscard] = useState(false);
    const photo = readFiles(value).at(-1) ?? current;
    const close = () =>
        readFiles(value).length ? setDiscard(true) : onClose();
    return (
        <>
            <Modal
                title="Vehicle profile photo"
                description="Kōwhai van · VH-014"
                icon={Camera}
                size="standard"
                onClose={close}
                footer={
                    <>
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            disabled={!readFiles(value).length}
                            onClick={() => {
                                if (!photo) {
                                    setError('Choose a photo first.');
                                    return;
                                }
                                onSave(photo);
                                onClose();
                            }}
                        >
                            Use profile photo
                        </Button>
                    </>
                }
            >
                <div className="photo-preview">
                    {photo ? (
                        <img
                            src={photo.url}
                            alt="Selected vehicle profile photo"
                        />
                    ) : (
                        <>
                            <Camera size={40} />
                            <span>Add a clear photo of this vehicle</span>
                        </>
                    )}
                </div>
                <EvidenceField
                    label="Upload vehicle photo"
                    imagesOnly
                    value={value}
                    onChange={(v) =>
                        setValue(JSON.stringify(readFiles(v).slice(-1)))
                    }
                />
                {error && <Notice title={error} />}
                <p className="text-caption text-muted-foreground">
                    Stored in this preview session. Nothing is sent to the
                    application.
                </p>
            </Modal>
            {discard && (
                <Modal
                    title="Discard selected photo?"
                    description="The profile photo has not been saved."
                    onClose={() => setDiscard(false)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep editing
                            </Button>
                            <Button variant="destructive" onClick={onClose}>
                                Discard photo
                            </Button>
                        </>
                    }
                >
                    <p>Your current profile photo will stay in place.</p>
                </Modal>
            )}
        </>
    );
}
export function EvidenceShelf({
    files,
    onAdd,
    title = 'Evidence',
    disabled = false,
}: {
    files: EvidenceFile[];
    onAdd: () => void;
    title?: string;
    disabled?: boolean;
}) {
    return (
        <div className="evidence-shelf">
            <div className="studio-section-heading">
                <h3 className="text-section-title">
                    {title} <small>{files.length}</small>
                </h3>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    onClick={onAdd}
                >
                    <Upload size={15} />
                    Upload
                </Button>
            </div>
            {files.length ? (
                <div className="attachment-grid">
                    {files.map((f) => (
                        <a
                            className="attachment-item"
                            key={f.id}
                            href={f.url}
                            target="_blank"
                            rel="noreferrer"
                            onClick={(e) => !f.url && e.preventDefault()}
                        >
                            {f.type?.startsWith('image/') ? (
                                <img src={f.url} alt="" />
                            ) : (
                                <FileText size={24} />
                            )}
                            <div>
                                <strong>{f.name}</strong>
                                <small>{f.id}</small>
                            </div>
                        </a>
                    ))}
                </div>
            ) : (
                <div className="evidence-empty">
                    <FileText size={20} />
                    <span>PDFs, photos and supporting records</span>
                </div>
            )}
        </div>
    );
}
