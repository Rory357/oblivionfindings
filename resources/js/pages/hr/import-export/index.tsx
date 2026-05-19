import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    FileText,
    Upload,
    UploadCloud,
    XCircle,
} from 'lucide-react';
import { useRef, useState } from 'react';

type ImportResult = { created: number; updated: number; errors: string[] };

export default function ImportExportIndex() {
    const { props } = usePage<{ flash?: { importResult?: ImportResult } }>();
    const importResult = props.flash?.importResult ?? null;
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<string[][]>([]);
    const [importing, setImporting] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
        const selected = e.target.files?.[0] ?? null;
        setFile(selected);
        if (selected) {
            const reader = new FileReader();
            reader.onload = (ev) => {
                const text = ev.target?.result as string;
                const lines = text.split('\n').filter((l) => l.trim());
                const rows = lines.slice(0, 6).map((line) => {
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
                });
                setPreview(rows);
            };
            reader.readAsText(selected);
        } else {
            setPreview([]);
        }
    }

    function handleImport() {
        if (!file) return;
        setImporting(true);
        const formData = new FormData();
        formData.append('file', file);
        router.post('/hr/import-export/import', formData, {
            forceFormData: true,
            onFinish: () => {
                setImporting(false);
                setFile(null);
                setPreview([]);
                if (fileInputRef.current) fileInputRef.current.value = '';
            },
        });
    }

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
            <PageHero
                icon={UploadCloud}
                title="Employee Import / Export"
                description="Bulk import or export employee records via CSV."
                actions={
                    <Button onClick={handleExport} size="sm">
                        <Download className="mr-2 h-4 w-4" /> Export CSV
                    </Button>
                }
            />
            <PageShell>
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Download className="h-5 w-5" /> Export
                                Employees
                            </CardTitle>
                            <CardDescription>
                                Download a CSV of all active employee records.
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
                                records.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".csv,.txt"
                                onChange={handleFileChange}
                                className="block w-full text-sm text-muted-foreground file:mr-4 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary-foreground hover:file:bg-primary/90"
                            />
                            {preview.length > 0 && (
                                <div className="overflow-x-auto rounded-lg border">
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
                                            {preview
                                                .slice(1, 6)
                                                .map((row, ri) => (
                                                    <tr
                                                        key={ri}
                                                        className="border-t"
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
                                    <div className="border-t px-3 py-1.5 text-xs text-muted-foreground">
                                        Showing first{' '}
                                        {Math.min(preview.length - 1, 5)} rows
                                    </div>
                                </div>
                            )}
                            <Button
                                onClick={handleImport}
                                disabled={!file || importing}
                            >
                                <Upload className="mr-2 h-4 w-4" />{' '}
                                {importing ? 'Importing...' : 'Import CSV'}
                            </Button>
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
                                    className="border-status-success/30 bg-status-success text-status-success"
                                >
                                    <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                                    {importResult.created} created
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="border-status-info/30 bg-status-info text-status-info"
                                >
                                    <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                                    {importResult.updated} updated
                                </Badge>
                                {importResult.errors.length > 0 && (
                                    <Badge
                                        variant="outline"
                                        className="border-status-critical/30 bg-status-critical text-status-critical"
                                    >
                                        <XCircle className="mr-1 h-3 w-3" />{' '}
                                        {importResult.errors.length} errors
                                    </Badge>
                                )}
                            </div>
                            {importResult.errors.length > 0 && (
                                <div className="rounded-lg border border-status-critical/20 bg-status-critical p-4">
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
            </PageShell>
        </AppLayout>
    );
}
