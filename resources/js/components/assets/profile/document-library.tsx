import { FilePreviewDialog } from '@/components/files/file-preview-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    isJsonObject,
    useVehicleRecordCommand as useRecordCommand,
} from '@/components/fleet-assets/vehicle-workspace/record-command';
import { VehicleSearchSelect as SearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import { WorkspaceWizard } from '@/components/fleet-assets/vehicle-workspace/wizard-kit';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDate } from '@/lib/fleet-utils';
import { Link } from '@inertiajs/react';
import {
    Archive,
    CheckCircle,
    Download,
    Eye,
    FileText,
    History,
    LayoutGrid,
    List,
    MoreVertical,
    RefreshCw,
    Search,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import { SectionHeading, State, human } from './presentation';
import type { AssetFile } from './types';

const categories = [
    'Manual',
    'Warranty',
    'Purchase evidence',
    'Check evidence',
    'Photo',
    'Other',
].map((label) => ({ value: label, label }));
type FileAction = 'upload' | 'replace' | 'archive' | 'retry';

export function AssetDocumentLibrary({
    assetId,
    assetName,
    documents,
    canManage,
    onSaved,
}: {
    assetId: number;
    assetName: string;
    documents: AssetFile[];
    canManage: boolean;
    onSaved: () => void;
}) {
    const [query, setQuery] = useState(''),
        [history, setHistory] = useState(false),
        [layout, setLayout] = useState<'list' | 'cards'>('list'),
        [availability, setAvailability] = useState('all'),
        [source, setSource] = useState('all'),
        [preview, setPreview] = useState<AssetFile | null>(null),
        [dialog, setDialog] = useState<{
            action: FileAction;
            file?: AssetFile;
        } | null>(null);
    const visible = documents.filter(
        (file) =>
            (history ||
                (!file.archived && (file.current || !file.downloadUrl))) &&
            (availability === 'all' ||
                (availability === 'available'
                    ? !!file.downloadUrl
                    : !file.downloadUrl)) &&
            (source === 'all' ||
                (source === 'asset' ? !file.sourceOwned : file.sourceOwned)) &&
            `${file.name} ${file.filename} ${file.category} ${file.source}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const unavailableCount = documents.filter(
        (file) => !file.downloadUrl && !file.archived,
    ).length;
    const actions = (file: AssetFile) => (
        <div className="flex flex-wrap items-center gap-2">
            <Button variant="ghost" onClick={() => setPreview(file)}>
                <Eye />
                {file.downloadUrl ? 'View file' : 'File details'}
            </Button>
            {file.downloadUrl && (
                <Button variant="ghost" asChild>
                    <a href={file.downloadUrl} download>
                        <Download />
                        Download
                    </a>
                </Button>
            )}
            {file.sourceUrl && (
                <Button variant="ghost" asChild>
                    <Link href={file.sourceUrl}>Open source</Link>
                </Button>
            )}
            {canManage && !file.sourceOwned && !file.archived && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Actions for ${file.name}`}
                        >
                            <MoreVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {file.current && (
                            <DropdownMenuItem
                                onSelect={() =>
                                    setDialog({ action: 'replace', file })
                                }
                            >
                                <Upload />
                                Replace
                            </DropdownMenuItem>
                        )}
                        {[
                            'stored',
                            'scan_unavailable',
                            'publication_failed',
                            'legacy_unverified',
                        ].includes(file.state) && (
                            <DropdownMenuItem
                                onSelect={() =>
                                    setDialog({ action: 'retry', file })
                                }
                            >
                                <RefreshCw />
                                Retry file check
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            onSelect={() =>
                                setDialog({ action: 'archive', file })
                            }
                        >
                            <Archive />
                            Archive
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
    const fileState = (file: AssetFile) => (
        <State
            value={
                file.archived
                    ? 'archived'
                    : !file.current && file.downloadUrl
                      ? 'previous_version'
                      : file.state
            }
        />
    );
    const cards = (
        <div className="grid gap-4 lg:grid-cols-2">
            {visible.map((file) => (
                <article
                    key={file.id}
                    className="space-y-3 rounded-xl border bg-card p-5 shadow-sm"
                >
                    <div className="flex items-start gap-3">
                        <FileText className="size-5 shrink-0 text-primary" />
                        <div className="min-w-0 flex-1">
                            <h3 className="text-section-title break-words">
                                {file.name}
                            </h3>
                            <p className="text-caption text-muted-foreground">
                                {file.category} · Version {file.version} ·{' '}
                                {formatFileSize(file.bytes || 0)}
                            </p>
                        </div>
                        {fileState(file)}
                    </div>
                    <p className="text-caption text-muted-foreground">
                        {file.source} · Added {formatDate(file.added_at)}
                        {file.added_by ? ` · ${file.added_by}` : ''}
                    </p>
                    {!file.downloadUrl && (
                        <p className="text-subtle text-status-warning">
                            {file.unavailableReason}
                        </p>
                    )}
                    {file.reason && (
                        <p className="text-subtle">
                            Archive reason: {file.reason}
                        </p>
                    )}
                    {actions(file)}
                </article>
            ))}
        </div>
    );
    return (
        <section className="space-y-5" aria-label="Asset documents">
            <SectionHeading
                eyebrow="Asset record"
                title="Documents & evidence"
                description="Find the original, replace a version, or follow its source."
                actions={
                    <div className="flex flex-wrap gap-3">
                        {/* eslint-disable-next-line no-restricted-syntax -- Compact segmented layout control, not a content panel. */}
                        <div
                            className="flex gap-1 rounded-lg border bg-card p-1"
                            role="group"
                            aria-label="Document layout"
                        >
                            <Button
                                size="sm"
                                variant={
                                    layout === 'list' ? 'secondary' : 'ghost'
                                }
                                aria-pressed={layout === 'list'}
                                onClick={() => setLayout('list')}
                            >
                                <List />
                                List
                            </Button>
                            <Button
                                size="sm"
                                variant={
                                    layout === 'cards' ? 'secondary' : 'ghost'
                                }
                                aria-pressed={layout === 'cards'}
                                onClick={() => setLayout('cards')}
                            >
                                <LayoutGrid />
                                Cards
                            </Button>
                        </div>
                        {canManage && (
                            <Button
                                onClick={() => setDialog({ action: 'upload' })}
                            >
                                <Upload />
                                Add document
                            </Button>
                        )}
                    </div>
                }
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Card className="flex-row items-center gap-3 p-5">
                    <FileText className="size-5 text-primary" />
                    <div>
                        <strong className="text-subtle">
                            {
                                documents.filter(
                                    (file) => file.current && !file.archived,
                                ).length
                            }{' '}
                            current documents & evidence
                        </strong>
                        <p className="text-caption mt-1 text-muted-foreground">
                            Asset files and authorised source evidence
                        </p>
                    </div>
                </Card>
                <Card className="flex-row items-center gap-3 p-5">
                    <History className="size-5 text-primary" />
                    <div>
                        <strong className="text-subtle">
                            {unavailableCount} unavailable file
                            {unavailableCount === 1 ? '' : 's'}
                        </strong>
                        <p className="text-caption mt-1 text-muted-foreground">
                            Original checks and Maintenance evidence stay with
                            their source.
                        </p>
                    </div>
                </Card>
            </div>
            <div className="flex flex-wrap items-center gap-3">
                <div className="relative min-w-56 flex-1">
                    <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                    <Input
                        aria-label="Search documents"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search file, type or reference…"
                        className="bg-card pl-9"
                    />
                </div>
                <Button
                    variant={history ? 'secondary' : 'outline'}
                    aria-pressed={history}
                    onClick={() => setHistory(!history)}
                >
                    <History />
                    Version history
                </Button>
                <div className="w-full sm:w-56">
                    <SearchSelect
                        label="Document source"
                        value={source}
                        options={[
                            { value: 'all', label: 'All sources' },
                            { value: 'asset', label: 'Asset documents' },
                            { value: 'source', label: 'Checks & Maintenance' },
                        ]}
                        onChange={setSource}
                    />
                </div>
                <div className="w-full sm:w-56">
                    <SearchSelect
                        label="File availability"
                        value={availability}
                        options={[
                            { value: 'all', label: 'Any availability' },
                            { value: 'available', label: 'Available files' },
                            {
                                value: 'unavailable',
                                label: 'Unavailable files',
                            },
                        ]}
                        onChange={setAvailability}
                    />
                </div>
            </div>
            <div className="text-caption flex justify-between gap-3 text-muted-foreground">
                <span>Document library</span>
                <span>
                    {visible.length} of {documents.length} records shown
                </span>
            </div>
            {visible.length ? (
                layout === 'cards' ? (
                    cards
                ) : (
                    <>
                        <Card className="hidden gap-0 overflow-x-auto py-0 md:block">
                            <table className="w-full text-left">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        {[
                                            'Record',
                                            'Version / added',
                                            'Original source',
                                            'Availability / action',
                                        ].map((label) => (
                                            <th
                                                key={label}
                                                scope="col"
                                                className="text-caption px-5 py-3 font-medium text-muted-foreground uppercase"
                                            >
                                                {label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {visible.map((file) => (
                                        <tr
                                            key={file.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="max-w-72 px-5 py-4">
                                                <div className="flex items-start gap-3">
                                                    <span className="rounded-lg bg-primary/10 p-2 text-primary">
                                                        <FileText className="size-4" />
                                                    </span>
                                                    <div>
                                                        <strong className="text-subtle break-words">
                                                            {file.name}
                                                        </strong>
                                                        <p className="text-caption mt-1 break-all text-muted-foreground">
                                                            {file.filename} ·{' '}
                                                            {formatFileSize(
                                                                file.bytes || 0,
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <p className="text-caption">
                                                    {file.archived
                                                        ? 'Archived'
                                                        : file.current
                                                          ? 'Current'
                                                          : 'Previous'}{' '}
                                                    · v{file.version}
                                                </p>
                                                <p className="text-caption mt-2 text-muted-foreground">
                                                    {formatDate(file.added_at)}{' '}
                                                    ·{' '}
                                                    {file.added_by ||
                                                        'Author not recorded'}
                                                </p>
                                                {file.expiry_date && (
                                                    <p className="text-caption mt-1">
                                                        Expires{' '}
                                                        {formatDate(
                                                            file.expiry_date,
                                                        )}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-5 py-4">
                                                <p className="text-subtle">
                                                    {file.sourceOwned
                                                        ? file.source
                                                        : 'Asset document'}
                                                    {file.set_id
                                                        ? ` set · ${file.set_id}`
                                                        : ''}
                                                </p>
                                                <p className="text-caption mt-1 text-muted-foreground">
                                                    {file.category} ·{' '}
                                                    {file.sourceOwned
                                                        ? 'Managed at its original source'
                                                        : 'Managed with this asset'}
                                                </p>
                                                {file.reason && (
                                                    <p className="text-caption mt-1">
                                                        Archive reason:{' '}
                                                        {file.reason}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                {fileState(file)}
                                                {!file.downloadUrl && (
                                                    <p className="text-caption mt-2 max-w-64 text-muted-foreground">
                                                        {file.unavailableReason ||
                                                            human(file.state)}
                                                    </p>
                                                )}
                                                <div className="mt-2">
                                                    {actions(file)}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </Card>
                        <div className="md:hidden">{cards}</div>
                    </>
                )
            ) : (
                <div className="rounded-xl border border-dashed p-10 text-center">
                    <FileText className="mx-auto mb-3 size-8 text-muted-foreground" />
                    <h3 className="text-section-title">
                        {query
                            ? 'No matching documents'
                            : 'No documents in this view'}
                    </h3>
                    <p className="text-subtle mt-2 text-muted-foreground">
                        Try another filter or add an original file.
                    </p>
                </div>
            )}
            <FilePreviewDialog
                file={preview}
                onClose={() => setPreview(null)}
            />
            {dialog && (
                <AssetFileDialog
                    {...dialog}
                    file={
                        dialog.file
                            ? documents.find(
                                  (file) => file.id === dialog.file?.id,
                              ) || dialog.file
                            : undefined
                    }
                    assetId={assetId}
                    assetName={assetName}
                    onSaved={onSaved}
                    onClose={() => setDialog(null)}
                />
            )}
        </section>
    );
}

function AssetFileDialog({
    action,
    file: source,
    assetId,
    assetName,
    onClose,
    onSaved,
}: {
    action: FileAction;
    file?: AssetFile;
    assetId: number;
    assetName: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [step, setStep] = useState(0),
        [file, setFile] = useState<File | null>(null),
        [title, setTitle] = useState(source?.name || ''),
        [category, setCategory] = useState(source?.category || 'Manual'),
        [expires, setExpires] = useState(''),
        [reason, setReason] = useState(''),
        [error, setError] = useState(''),
        [saved, setSaved] = useState(''),
        [key, setKey] = useState(() => crypto.randomUUID());
    const command = useRecordCommand(isJsonObject),
        upload = action === 'upload' || action === 'replace';
    const heading = {
        upload: 'Add asset document',
        replace: 'Replace document',
        archive: 'Archive document',
        retry: 'Retry file check',
    }[action];
    const validate = () => {
        if (action === 'replace' && !source?.current) {
            setError(
                'This version is no longer current. Close this draft and select the current document before replacing it.',
            );
            return false;
        }
        const problem =
            upload && !file
                ? 'Choose the original file.'
                : upload && file && file.size === 0
                  ? 'This file is empty or could not be read. Choose the original file again.'
                  : upload && file && file.size > 20 * 1024 * 1024
                    ? 'Choose a file no larger than 20 MB.'
                    : upload && !title.trim()
                      ? 'Enter a document title.'
                      : ['archive', 'replace'].includes(action) &&
                          !reason.trim()
                        ? 'Record why this file is being changed.'
                        : '';
        setError(problem);
        return !problem;
    };
    const submit = async () => {
        if (!validate()) {
            setStep(0);
            return;
        }
        const body = new FormData();
        body.set('request_key', key);
        body.set('reason', reason);
        body.set('expected_version', String(source?.set_version || 0));
        if (upload && file) {
            body.set('file', file);
            body.set('title', title.trim());
            body.set('category', category);
            if (expires) body.set('expiry_date', expires);
        }
        const result = await command.submit(
            `/assets/${assetId}/documents${action === 'upload' ? '' : `/${source?.id}/${action}`}`,
            body,
        );
        if (result) {
            const document = result.document as { state?: string };
            setSaved(
                action === 'archive'
                    ? 'Archived. The original and its history are retained.'
                    : document?.state === 'available'
                      ? 'The file passed its check and is available to open.'
                      : 'The file record was saved. It remains unavailable until its file check succeeds.',
            );
            onSaved();
        }
    };
    return (
        <WorkspaceWizard
            title={heading}
            description={source?.name || assetName}
            railIcon={FileText}
            railSub="Asset documents"
            steps={[
                {
                    key: 'details',
                    label: 'Details',
                    blurb: 'File and source details',
                    icon: FileText,
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm the file change',
                    icon: CheckCircle,
                },
            ]}
            step={step}
            setStep={setStep}
            pct={file || reason ? 70 : 0}
            context={{
                name: assetName,
                detail: source
                    ? `Version ${source.version} · ${source.category}`
                    : 'Private, versioned files',
            }}
            command={command}
            dirty={
                !!(
                    file ||
                    reason ||
                    expires ||
                    category !== (source?.category || 'Manual') ||
                    title !== (source?.name || '')
                )
            }
            saved={!!saved}
            submitLabel={heading}
            onValidateStep={validate}
            onSubmit={submit}
            onClose={onClose}
            onReload={async () => {
                await onSaved();
                command.reset();
                setKey(crypto.randomUUID());
                setStep(0);
            }}
            errorKey={error + command.message}
            success={
                <WizardSuccessPane
                    title="Document updated"
                    blurb={saved}
                    actions={
                        <Button onClick={onClose}>Return to documents</Button>
                    }
                />
            }
        >
            {step === 0 ? (
                <div className="space-y-4">
                    {(error || Object.keys(command.errors).length > 0) && (
                        <p role="alert" className="text-status-critical">
                            {error || Object.values(command.errors).join(' ')}
                        </p>
                    )}
                    {upload && (
                        <>
                            <Label id="asset-file-label">Original file *</Label>
                            <FileDropzone
                                id="asset-file"
                                aria-labelledby="asset-file-label"
                                aria-invalid={!!error && !file}
                                disabled={command.locked}
                                multiple={false}
                                onFiles={(files) => {
                                    setFile(files[0]);
                                    if (!title) setTitle(files[0].name);
                                }}
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.txt,.rtf"
                                hint="PDF, images, Office documents, CSV, text or RTF · up to 20 MB"
                            />
                            {file && (
                                <div className="flex items-center justify-between rounded-lg border p-3">
                                    <span className="break-all">
                                        {file.name} ·{' '}
                                        {formatFileSize(file.size)}
                                    </span>
                                    <Button
                                        variant="ghost"
                                        onClick={() => setFile(null)}
                                    >
                                        Remove
                                    </Button>
                                </div>
                            )}
                            <Label htmlFor="asset-file-title">Title *</Label>
                            <Input
                                id="asset-file-title"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                maxLength={255}
                                aria-invalid={!!error && !title}
                            />
                            <Label>Category</Label>
                            <SearchSelect
                                label="Document category"
                                value={category}
                                options={categories}
                                onChange={setCategory}
                            />
                            <Label>Expiry date (optional)</Label>
                            <DatePicker
                                id="asset-file-expires"
                                allowClear
                                label="Expiry date"
                                value={expires}
                                onChange={setExpires}
                            />
                        </>
                    )}
                    {['archive', 'replace'].includes(action) && (
                        <>
                            <Label htmlFor="asset-file-reason">Reason *</Label>
                            <Textarea
                                id="asset-file-reason"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                maxLength={2000}
                                aria-invalid={!!error && !reason.trim()}
                            />
                        </>
                    )}
                    {action === 'retry' && (
                        <p>
                            The same stored bytes will be checked again. This
                            does not bypass scanning or create another version.
                        </p>
                    )}
                    {action === 'archive' && (
                        <p>
                            Archive removes this file from the current library.
                            Authorised readers can still view and download it in
                            version history.
                        </p>
                    )}
                </div>
            ) : (
                <ReviewCard title={heading} icon={FileText}>
                    <ReviewRow label="Asset" value={assetName} />
                    <ReviewRow label="Document" value={title} />
                    {file && (
                        <ReviewRow
                            label="File"
                            value={`${file.name} · ${formatFileSize(file.size)}`}
                        />
                    )}
                    <ReviewRow label="Category" value={category} />
                    {expires && (
                        <ReviewRow
                            label="Expires"
                            value={formatDate(expires)}
                        />
                    )}
                    {(reason || action === 'retry') && (
                        <ReviewRow
                            label="Reason"
                            value={reason || 'File check retry'}
                        />
                    )}
                    <p className="text-caption">
                        Uploading evidence does not approve expenditure or
                        release a Maintenance hold.
                    </p>
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}
