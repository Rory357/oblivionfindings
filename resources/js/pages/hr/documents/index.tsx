import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { Download, FileText, Folder, Plus } from 'lucide-react';

interface HrDocument {
    id: number;
    title: string;
    document_type: string;
    related_user: { id: number; name: string } | null;
    storage_path: string;
    created_at: string;
    created_by_user: { name: string };
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
    filters: { type: string | null; q: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Documents', href: '/hr/documents' },
];

const typeColors: Record<string, string> = {
    contract: 'border-status-info/30 text-status-info bg-status-info',
    policy: 'border-primary/30 text-primary bg-primary/10',
    certificate:
        'border-status-success/30 text-status-success bg-status-success',
    letter: 'border-status-warning/30 text-status-warning bg-status-warning',
    offer: 'border-primary/30 text-primary bg-primary/10',
    other: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
};

export default function DocumentsIndex({ documents, filters, can }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get(
            '/hr/documents',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Documents" />
            <PageLayout
                hero={
                    <PageHero
                        icon={Folder}
                        title="HR Documents"
                        description="Manage employee contracts, policies, certificates, and offer letters."
                        stats={[
                            { label: 'Total', value: documents.total },
                        ]}
                        actions={
                            can.manage ? (
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" asChild className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                        <Link href="/hr/documents/templates">
                                            <FileText className="mr-2 h-4 w-4" />
                                            Generate from Template
                                        </Link>
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
        </AppLayout>
    );
}
