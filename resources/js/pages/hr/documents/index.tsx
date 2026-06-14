import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Download, FileText, Folder, PenSquare, Plus } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

interface HrDocument {
    id: number;
    title: string;
    document_type: string;
    related_user: { id: number; name: string } | null;
    storage_path: string;
    created_at: string;
    created_by_user: { name: string };
}

interface Employee {
    id: number;
    user_id: number;
    name: string | null;
    employee_number: string | null;
}

interface DocumentTemplate {
    id: number;
    name: string;
    category: string;
}

interface Props {
    documents: {
        data: HrDocument[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    employees: Employee[];
    templates: DocumentTemplate[];
    filters: { type: string | null; q: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Documents', href: '/hr/documents' },
];

const typeColors: Record<string, string> = {
    contract: 'border-status-info/30 text-status-info bg-status-info-bg',
    policy: 'border-primary/30 text-primary bg-primary/10',
    certificate:
        'border-status-success/30 text-status-success bg-status-success-bg',
    letter: 'border-status-warning/30 text-status-warning bg-status-warning-bg',
    offer: 'border-primary/30 text-primary bg-primary/10',
    other: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
};

export default function DocumentsIndex({
    documents,
    employees,
    templates,
    filters,
    can,
}: Props) {
    const [signDoc, setSignDoc] = useState<HrDocument | null>(null);
    const [signerIds, setSignerIds] = useState<number[]>([]);
    const [genOpen, setGenOpen] = useState(false);
    const [genForm, setGenForm] = useState({
        template_id: '',
        employee_profile_id: '',
        title: '',
    });

    const setGen = (key: string, value: string) =>
        setGenForm((prev) => ({ ...prev, [key]: value }));

    const submitGenerate = (e: FormEvent) => {
        e.preventDefault();
        if (!genForm.template_id || !genForm.employee_profile_id) return;
        router.post('/hr/documents/generate', genForm, {
            preserveScroll: true,
            onSuccess: () => {
                setGenOpen(false);
                setGenForm({
                    template_id: '',
                    employee_profile_id: '',
                    title: '',
                });
            },
        });
    };

    const signableEmployees = useMemo(
        () => employees.filter((e) => e.user_id),
        [employees],
    );

    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/documents',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    const toggleSigner = (userId: number) =>
        setSignerIds((prev) =>
            prev.includes(userId)
                ? prev.filter((id) => id !== userId)
                : [...prev, userId],
        );

    const openSignDialog = (doc: HrDocument) => {
        setSignerIds([]);
        setSignDoc(doc);
    };

    const submitSignatureRequest = (e: FormEvent) => {
        e.preventDefault();
        if (!signDoc || signerIds.length === 0) return;
        router.post(
            '/hr/signatures/request',
            { document_id: signDoc.id, user_ids: signerIds },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSignDoc(null);
                    setSignerIds([]);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Documents" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Folder}
                        title="HR Documents"
                        description="Manage employee contracts, policies, certificates, and offer letters."
                        stats={[
                            { label: 'Total', value: documents.total },
                        ]}
                        actions={
                            can.manage ? (
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() => setGenOpen(true)}
                                        className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    >
                                        <FileText className="mr-2 h-4 w-4" />
                                        Generate from Template
                                    </Button>
                                    <Button asChild>
                                        <Link href="/hr/documents/upload">
                                            <Plus className="mr-2 h-4 w-4" />
                                            Upload Document
                                        </Link>
                                    </Button>
                                </div>
                            ) : undefined
                        }
                    />
                }
            >
                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search documents..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter')
                                applyFilter(
                                    'q',
                                    (e.target as HTMLInputElement).value,
                                );
                        }}
                    />
                    <Select
                        value={filters.type || '__none__'}
                        onValueChange={(v) =>
                            applyFilter('type', v === '__none__' ? null : v)
                        }
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All Types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Types</SelectItem>
                            <SelectItem value="contract">Contract</SelectItem>
                            <SelectItem value="policy">Policy</SelectItem>
                            <SelectItem value="certificate">
                                Certificate
                            </SelectItem>
                            <SelectItem value="letter">Letter</SelectItem>
                            <SelectItem value="offer">Offer</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Title
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Type
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Related Employee
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Created By
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Created
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {documents.data.map((doc) => {
                                    const typeClass =
                                        typeColors[doc.document_type] ||
                                        typeColors.other;
                                    return (
                                        <tr
                                            key={doc.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                {doc.title}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={typeClass}
                                                >
                                                    {doc.document_type}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {doc.related_user
                                                    ? doc.related_user.name
                                                    : '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {doc.created_by_user.name}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {doc.created_at}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex justify-end gap-1">
                                                    {can.manage && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                openSignDialog(
                                                                    doc,
                                                                )
                                                            }
                                                        >
                                                            <PenSquare className="mr-1 h-3 w-3" />
                                                            Send
                                                        </Button>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <a
                                                            href={`/hr/documents/${doc.id}/download`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            <Download className="mr-1 h-3 w-3" />
                                                            Download
                                                        </a>
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {documents.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No documents found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {documents.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(documents.current_page - 1) * documents.per_page +
                                1}{' '}
                            to{' '}
                            {Math.min(
                                documents.current_page * documents.per_page,
                                documents.total,
                            )}{' '}
                            of {documents.total} results
                        </p>
                        <LaravelPagination links={documents.links} />
                    </div>
                )}
            </PageLayout>

            {/* Generate from template dialog */}
            <Dialog open={genOpen} onOpenChange={setGenOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Generate from Template</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitGenerate} className="space-y-4">
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                Template
                            </label>
                            <Select
                                value={genForm.template_id}
                                onValueChange={(v) => setGen('template_id', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a template" />
                                </SelectTrigger>
                                <SelectContent>
                                    {templates.map((t) => (
                                        <SelectItem
                                            key={t.id}
                                            value={String(t.id)}
                                        >
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                Employee
                            </label>
                            <Select
                                value={genForm.employee_profile_id}
                                onValueChange={(v) =>
                                    setGen('employee_profile_id', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select an employee" />
                                </SelectTrigger>
                                <SelectContent>
                                    {employees.map((emp) => (
                                        <SelectItem
                                            key={emp.id}
                                            value={String(emp.id)}
                                        >
                                            {emp.name ?? `Employee #${emp.id}`}
                                            {emp.employee_number
                                                ? ` (${emp.employee_number})`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                Title (optional)
                            </label>
                            <Input
                                value={genForm.title}
                                onChange={(e) => setGen('title', e.target.value)}
                                placeholder="Defaults to the template name"
                            />
                        </div>
                        {templates.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No active templates yet.{' '}
                                <Link
                                    href="/hr/documents/templates"
                                    className="underline"
                                >
                                    Manage templates
                                </Link>
                            </p>
                        )}
                        <div className="flex items-center justify-between">
                            <Link
                                href="/hr/documents/templates"
                                className="text-xs text-muted-foreground underline"
                            >
                                Manage templates
                            </Link>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setGenOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        !genForm.template_id ||
                                        !genForm.employee_profile_id
                                    }
                                >
                                    Generate
                                </Button>
                            </div>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Send for signature dialog */}
            <Dialog
                open={signDoc !== null}
                onOpenChange={(o) => !o && setSignDoc(null)}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Send for Signature</DialogTitle>
                    </DialogHeader>
                    <form
                        onSubmit={submitSignatureRequest}
                        className="space-y-4"
                    >
                        <p className="text-sm text-muted-foreground">
                            Request a signature on{' '}
                            <span className="font-medium text-foreground">
                                {signDoc?.title}
                            </span>{' '}
                            from the selected staff members.
                        </p>
                        <div className="max-h-72 space-y-1 overflow-y-auto rounded-md border p-2">
                            {signableEmployees.length === 0 ? (
                                <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                                    No active staff available.
                                </p>
                            ) : (
                                signableEmployees.map((emp) => (
                                    <label
                                        key={emp.user_id}
                                        className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted/50"
                                    >
                                        <Checkbox
                                            checked={signerIds.includes(
                                                emp.user_id,
                                            )}
                                            onCheckedChange={() =>
                                                toggleSigner(emp.user_id)
                                            }
                                        />
                                        <span>
                                            {emp.name ??
                                                `Employee #${emp.id}`}
                                            {emp.employee_number
                                                ? ` (${emp.employee_number})`
                                                : ''}
                                        </span>
                                    </label>
                                ))
                            )}
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-muted-foreground">
                                {signerIds.length} selected
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setSignDoc(null)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={signerIds.length === 0}
                                >
                                    Send Requests
                                </Button>
                            </div>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
