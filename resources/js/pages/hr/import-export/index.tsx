import {
    Field,
    ReviewCard,
    ReviewRow,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    Download,
    FileText,
    Table2,
    Upload,
    UploadCloud,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { fireConfetti } from '@/lib/confetti';

type ImportResult = { created: number; updated: number; errors: string[] };
type SiteOption = { id: number; name: string };

type Props = {
    stats: {
        exportable: number;
        profiles: number;
    };
    sites: SiteOption[];
};

const MAX_CSV_BYTES = 5 * 1024 * 1024; // matches the 5MB server rule

/** Minimal CSV row split honouring double quotes (preview only). */
function splitCsvLine(line: string): string[] {
    const result: string[] = [];
    let current = '';
    let inQuotes = false;
    for (const ch of line) {
        if (ch === '"') {
            inQuotes = !inQuotes;
        } else if (ch === ',' && !inQuotes) {
            result.push(current.trim());
            current = '';
        } else {
            current += ch;
        }
    }
    result.push(current.trim());
    return result;
}

/* ================================================================== */
/*  Import wizard                                                     */
/* ================================================================== */

const IMPORT_STEPS: readonly WizardStep[] = [
    {
        key: 'file',
        label: 'CSV file',
        blurb: 'Choose the upload',
        icon: Upload,
    },
    {
        key: 'confirm',
        label: 'Preview & confirm',
        blurb: 'Check, then run',
        icon: ClipboardCheck,
    },
];

function ImportWizard({
    onClose,
    sites,
}: {
    onClose: () => void;
    sites: SiteOption[];
}) {
    const wizard = useWizard(IMPORT_STEPS.length);
    const [result, setResult] = useState<ImportResult | null>(null);
    const [preview, setPreview] = useState<string[][]>([]);
    const [rowCount, setRowCount] = useState(0);

    const form = useForm({ file: null as File | null });

    const stageFile = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        const ok =
            file.type === 'text/csv' ||
            file.type === 'text/plain' ||
            /\.(csv|txt)$/i.test(file.name);
        if (!ok) {
            toast.error('Please choose a CSV file.');
            return;
        }
        if (file.size > MAX_CSV_BYTES) {
            toast.error('The CSV must be 5MB or smaller.');
            return;
        }
        form.setData('file', file);
        const reader = new FileReader();
        reader.onload = (ev) => {
            const text = (ev.target?.result as string) ?? '';
            const lines = text.split('\n').filter((l) => l.trim());
            setRowCount(Math.max(lines.length - 1, 0));
            setPreview(lines.slice(0, 6).map(splitCsvLine));
        };
        reader.readAsText(file);
    };

    const clearFile = () => {
        form.setData('file', null);
        setPreview([]);
        setRowCount(0);
    };

    const submit = () => {
        form.post('/hr/import-export/import', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as
                    | { importResult?: ImportResult; error?: string }
                    | undefined;
                if (flash?.error) {
                    toast.error(flash.error);
                    return;
                }
                const res = flash?.importResult ?? {
                    created: 0,
                    updated: 0,
                    errors: [],
                };
                setResult(res);
                if (res.errors.length === 0) fireConfetti();
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Import employees"
            description="Create or update employee records from a CSV file."
            railIcon={UploadCloud}
            railTitle="Import employees"
            railSub="Bulk CSV import"
            steps={IMPORT_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                result ? (
                    <WizardSuccessPane
                        title={
                            result.errors.length > 0
                                ? 'Import finished with errors'
                                : 'Import complete'
                        }
                        blurb={
                            <>
                                {result.created} employee
                                {result.created === 1 ? '' : 's'} created and{' '}
                                {result.updated} updated.
                                {result.errors.length > 0 ? (
                                    <>
                                        {' '}
                                        {result.errors.length} row
                                        {result.errors.length === 1
                                            ? ''
                                            : 's'}{' '}
                                        failed — the details are listed on the
                                        page behind this dialog.
                                    </>
                                ) : null}
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={
                                form.processing ||
                                !form.data.file ||
                                sites.length === 0
                            }
                        >
                            {form.processing
                                ? form.progress
                                    ? `Uploading… ${form.progress.percentage ?? 0}%`
                                    : 'Importing…'
                                : `Import ${rowCount > 0 ? `${rowCount} row${rowCount === 1 ? '' : 's'}` : 'CSV'}`}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={!form.data.file || sites.length === 0}
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Upload}
                        title="Choose the CSV"
                        blurb="Rows are matched by email — existing people are updated, new ones created."
                    />
                    <Field label="CSV file" required error={form.errors.file}>
                        {form.data.file ? (
                            <StagedFileCard
                                file={form.data.file}
                                onRemove={clearFile}
                            />
                        ) : (
                            <FileDropzone
                                onFiles={stageFile}
                                accept=".csv,.txt,text/csv"
                                multiple={false}
                                title="Drag & drop the CSV here"
                                hint="CSV up to 5MB — name, email, position_role and primary_site_id are required"
                            />
                        )}
                    </Field>
                    <div className="mt-4 flex items-start gap-3 rounded-xl border border-border bg-muted/40 p-4">
                        <FileText className="mt-0.5 h-5 w-5 flex-none text-primary" />
                        <div className="text-[12.5px] text-muted-foreground">
                            Not sure about the columns? Download the{' '}
                            <a
                                href="/hr/import-export/template"
                                className="font-semibold text-primary hover:underline"
                            >
                                blank template
                            </a>{' '}
                            — it has every supported header pre-filled.
                        </div>
                    </div>
                    <div className="mt-4 rounded-xl border border-border p-4">
                        <div className="text-sm font-medium">
                            Available Site IDs
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Every row needs a primary_site_id from this list.
                            Hidden, inactive, or archived Sites are rejected.
                        </p>
                        <div className="mt-3 max-h-32 space-y-1 overflow-y-auto text-xs">
                            {sites.length > 0 ? (
                                sites.map((site) => (
                                    <div
                                        key={site.id}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span>{site.name}</span>
                                        <code className="rounded bg-muted px-1.5 py-0.5">
                                            {site.id}
                                        </code>
                                    </div>
                                ))
                            ) : (
                                <span className="text-status-critical">
                                    No active Sites are available for import.
                                </span>
                            )}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Preview & confirm"
                        blurb="Check the first rows look right, then run the import."
                    />
                    {preview.length > 0 ? (
                        <div className="overflow-x-auto rounded-xl border border-border">
                            <table className="w-full text-xs">
                                <thead className="bg-muted/50">
                                    <tr>
                                        {preview[0].map((h, i) => (
                                            <th
                                                key={i}
                                                className="px-3 py-2 text-left font-medium"
                                            >
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.slice(1, 6).map((row, ri) => (
                                        <tr
                                            key={ri}
                                            className="border-t border-border"
                                        >
                                            {row.map((c, ci) => (
                                                <td
                                                    key={ci}
                                                    className="px-3 py-1.5 text-muted-foreground"
                                                >
                                                    {c || '-'}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="border-t border-border px-3 py-1.5 text-xs text-muted-foreground">
                                Showing first {Math.min(preview.length - 1, 5)}{' '}
                                of {rowCount} data row
                                {rowCount === 1 ? '' : 's'}
                            </div>
                        </div>
                    ) : null}
                    <div className="mt-4">
                        <ReviewCard
                            icon={Table2}
                            title="Import"
                            onEdit={() => wizard.goTo(0)}
                            span
                        >
                            <ReviewRow
                                label="File"
                                value={form.data.file?.name}
                            />
                            <ReviewRow
                                label="Data rows"
                                value={String(rowCount)}
                            />
                            <ReviewRow
                                label="Behaviour"
                                value="Match by email — update existing, create new"
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Page                                                              */
/* ================================================================== */

export default function ImportExportIndex({ stats, sites }: Props) {
    const { props } = usePage<{ flash?: { importResult?: ImportResult } }>();
    const importResult = props.flash?.importResult ?? null;
    const [importing, setImporting] = useState(false);

    function handleExport() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/hr/import-export/export';
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_token';
            input.value = csrfMeta.getAttribute('content') ?? '';
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr/people' },
                { title: 'Import / Export', href: '/hr/import-export' },
            ]}
        >
            <Head title="Employee Import / Export" />
            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={UploadCloud}
                        title="Employee Import / Export"
                        description="Bulk import or export employee records via CSV."
                        stats={[
                            {
                                label: 'Active employees',
                                value: stats.exportable,
                                tone: 'success',
                            },
                            {
                                label: 'Profiles on record',
                                value: stats.profiles,
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    onClick={handleExport}
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Download className="mr-1.5 h-4 w-4" />{' '}
                                    Export CSV
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => setImporting(true)}
                                    disabled={sites.length === 0}
                                >
                                    <Upload className="mr-1.5 h-4 w-4" /> Import
                                    CSV
                                </Button>
                            </div>
                        }
                    />
                }
            >
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Download className="h-5 w-5" /> Export
                                Employees
                            </CardTitle>
                            <CardDescription>
                                Download a CSV of all {stats.exportable} active
                                employee records.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <Button onClick={handleExport}>
                                <Download className="mr-2 h-4 w-4" /> Export to
                                CSV
                            </Button>
                            <a
                                href="/hr/import-export/template"
                                className="inline-flex items-center text-sm text-muted-foreground hover:underline"
                            >
                                <FileText className="mr-1 h-4 w-4" /> Download
                                blank template
                            </a>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Upload className="h-5 w-5" /> Import Employees
                            </CardTitle>
                            <CardDescription>
                                Upload a CSV to create or update employee
                                records — rows are matched by email.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <Button
                                onClick={() => setImporting(true)}
                                disabled={sites.length === 0}
                            >
                                <Upload className="mr-2 h-4 w-4" /> Start import
                            </Button>
                            <div className="text-sm text-muted-foreground">
                                A guided upload with a preview of your file
                                before anything is written.
                            </div>
                        </CardContent>
                    </Card>
                </div>
                {importResult && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Import Results</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex flex-wrap gap-3">
                                <Badge
                                    variant="outline"
                                    className="border-status-success/30 bg-status-success-bg text-status-success"
                                >
                                    <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                                    {importResult.created} created
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="border-status-info/30 bg-status-info-bg text-status-info"
                                >
                                    <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                                    {importResult.updated} updated
                                </Badge>
                                {importResult.errors.length > 0 && (
                                    <Badge
                                        variant="outline"
                                        className="border-status-critical/30 bg-status-critical-bg text-status-critical"
                                    >
                                        <XCircle className="mr-1 h-3 w-3" />{' '}
                                        {importResult.errors.length} errors
                                    </Badge>
                                )}
                            </div>
                            {importResult.errors.length > 0 && (
                                <div className="rounded-lg border border-status-critical/20 bg-status-critical-bg p-4">
                                    <div className="mb-2 flex items-center gap-2 text-sm font-medium text-status-critical">
                                        <AlertTriangle className="h-4 w-4" />{' '}
                                        Errors
                                    </div>
                                    <ul className="space-y-1 text-sm text-status-critical">
                                        {importResult.errors.map((err, i) => (
                                            <li key={i}>{err}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </PageLayout>

            {importing ? (
                <ImportWizard
                    onClose={() => setImporting(false)}
                    sites={sites}
                />
            ) : null}
        </AppLayout>
    );
}
