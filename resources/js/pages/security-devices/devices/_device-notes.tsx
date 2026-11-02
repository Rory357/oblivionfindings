/**
 * The Notes & documents tab every new device page shares (drawing pg-sdr-5d): upkeep dates, its
 * documents and their expiry, notes and upkeep records, and the asset record it's linked to.
 * Upload document uses today's private upload route (20 MB, checked and kept privately).
 */
import { EntityContextMenu, EntityKebab, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { PopupField, PopupForm } from '@/components/popup';
import { FactRows, Log, QuietLine, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDateOnly } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Download, FileText, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { DevicePageProps, DocumentRow, NotesTab } from './_device-page-types';
import { logItems, MainSide } from './_device-page-parts';

function addedDay(iso: string | null): string {
    return iso ? formatDateOnly(iso.slice(0, 10)) : '—';
}

function Expiry({ expiry }: { expiry: DocumentRow['expiry'] }) {
    if (!expiry) return <span className="text-muted-foreground">—</span>;
    const day = formatDateOnly(expiry.date);
    if (expiry.ended) return <StatusBadge variant="neutral">Ended {day}</StatusBadge>;
    return <StatusBadge variant={expiry.soon ? 'warning' : 'success'}>Ends {day}</StatusBadge>;
}

export function UploadDocument({
    open,
    deviceId,
    deviceName,
    kinds,
    onClose,
}: {
    open: boolean;
    deviceId: number;
    deviceName: string;
    kinds: { value: string; label: string }[];
    onClose: () => void;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [title, setTitle] = useState('');
    const [kind, setKind] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open) return;
        setFile(null);
        setTitle('');
        setKind('');
        setErrors({});
    }, [open]);

    const submit = () => {
        const missing: Record<string, string> = {};
        if (!file) missing.file = 'Choose the file to upload.';
        if (!title.trim()) missing.title = 'Give it a title people will recognise.';
        if (!kind) missing.category = 'Choose what kind of document it is.';
        if (Object.keys(missing).length) {
            setErrors(missing);
            return;
        }
        setProcessing(true);
        router.post(
            `/security-devices/devices/${deviceId}/documents`,
            { file: file as File, title: title.trim(), category: kind },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (failed) => setErrors(Object.fromEntries(Object.entries(failed).map(([key, value]) => [key, String(value)]))),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={Upload}
            title="Upload document"
            description="A manual, plan, contract or service report for this device"
            context={{ title: deviceName, icon: FileText }}
            error={errors.file && file ? errors.file : undefined}
            submitLabel="Upload document"
            processingLabel="Uploading…"
            processing={processing}
            dirty={!!file || !!title}
            onSubmit={submit}
            testId="sd-upload-document"
        >
            <PopupField label="File" required error={!file ? errors.file : undefined}>
                {file ? (
                    <StagedFileCard file={file} onRemove={() => setFile(null)} />
                ) : (
                    <FileDropzone
                        id="sd-document-file"
                        onFiles={(files) => setFile(files[0] ?? null)}
                        title="Drop a file here, or browse"
                        hint="Up to 20 MB · kept privately in Oblivion"
                    />
                )}
            </PopupField>
            <PopupField label="Title" htmlFor="sd-document-title" required error={errors.title}>
                <Input id="sd-document-title" value={title} placeholder="e.g. Service contract 2025–27" onChange={(event) => setTitle(event.target.value)} />
            </PopupField>
            <PopupField label="Kind" required error={errors.category}>
                <Select value={kind || undefined} onValueChange={setKind}>
                    <SelectTrigger aria-label="Kind">
                        <SelectValue placeholder="Choose a kind" />
                    </SelectTrigger>
                    <SelectContent>
                        {kinds.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </PopupField>
        </PopupForm>
    );
}

export function NotesTabView({ props, notes, now }: { props: DevicePageProps; notes: NotesTab; now: Date }) {
    const [uploading, setUploading] = useState(false);
    const menu = useEntityContextMenu<DocumentRow>();
    const download = (row: DocumentRow) => window.location.assign(row.href);
    const menuFor = (row: DocumentRow): MenuItem[] => [{ label: 'Download', icon: Download, onClick: () => download(row) }];

    return (
        <MainSide
            main={
                <>
                    {notes.upkeep.length > 0 ? (
                        <FactRows title="Upkeep" rows={notes.upkeep.map(([label, value, date]) => [label, date ? formatDateOnly(date) : value])} />
                    ) : (
                        <QuietLine title="Upkeep" text="No install, service or warranty dates are recorded." />
                    )}
                    <Surface title="Documents" sub="Manuals, plans, contracts and service reports">
                        {props.can.upload ? (
                            <div className="flex justify-end px-[18px] pt-2">
                                <Button type="button" variant="outline" size="sm" onClick={() => setUploading(true)}>
                                    <Upload className="size-4" />
                                    Upload document
                                </Button>
                            </div>
                        ) : null}
                        {notes.documents.length === 0 ? (
                            <p className="text-subtle px-[18px] py-4">No documents yet.</p>
                        ) : (
                            <div className="mt-3 overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Document</TableHead>
                                            <TableHead>Kind</TableHead>
                                            <TableHead className="whitespace-nowrap">Added</TableHead>
                                            <TableHead className="whitespace-nowrap">Review or expiry</TableHead>
                                            <TableHead className="w-10"><span className="sr-only">Actions</span></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {notes.documents.map((row) => (
                                            <TableRow key={row.id} className="cursor-pointer" onClick={() => download(row)} onContextMenu={(event) => menu.open(event, row)}>
                                                <TableCell>
                                                    <span className="flex items-center gap-2.5">
                                                        <FileText aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                                                        <span className="grid min-w-0">
                                                            <b className="truncate text-sm font-semibold">{row.title}</b>
                                                            <small className="text-caption truncate">{row.file}</small>
                                                        </span>
                                                    </span>
                                                </TableCell>
                                                <TableCell>{row.kind}</TableCell>
                                                <TableCell className="whitespace-nowrap">{addedDay(row.added_at)}</TableCell>
                                                <TableCell className="whitespace-nowrap"><Expiry expiry={row.expiry} /></TableCell>
                                                <TableCell onClick={(event) => event.stopPropagation()}>
                                                    <EntityKebab actions={menuFor(row)} label={`Actions for ${row.title}`} />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </Surface>
                    {notes.notes.length > 0 ? (
                        <Log title="Notes" sub="Anything the next person should know" items={logItems(notes.notes, now)} limit={12} />
                    ) : (
                        <QuietLine title="Notes" sub="Anything the next person should know" text="No notes yet." />
                    )}
                </>
            }
            side={
                <>
                    {notes.purchase.price || notes.purchase.asset ? (
                        <FactRows
                            title="Purchase"
                            action={notes.purchase.asset?.href ? { label: 'Open asset', href: notes.purchase.asset.href } : null}
                            rows={[
                                ['Price', notes.purchase.price ?? '—'],
                                ['Asset record', notes.purchase.asset ? [notes.purchase.asset.name, notes.purchase.asset.tag].filter(Boolean).join(' · ') : 'Not linked'],
                            ]}
                        />
                    ) : (
                        <QuietLine title="Purchase" text="No price or asset record is linked." />
                    )}
                    {menu.ctx ? (
                        <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={FileText} title={menu.ctx.record.title} items={menuFor(menu.ctx.record)} onClose={menu.close} />
                    ) : null}
                    <UploadDocument open={uploading} deviceId={props.device.id} deviceName={props.device.name} kinds={notes.kinds} onClose={() => setUploading(false)} />
                </>
            }
        />
    );
}
