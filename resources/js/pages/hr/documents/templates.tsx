import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Plus } from 'lucide-react';

interface Template {
    id: number;
    name: string;
    category: string;
    merge_fields: string[];
    is_active: boolean;
    approval_required: boolean;
    version: number;
    created_at: string;
    updated_at: string;
}

interface Props {
    templates: {
        data: Template[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    categories: string[];
    filters: { category: string | null; q: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Documents', href: '/hr/documents' },
    { title: 'Templates', href: '/hr/documents/templates' },
];

const typeColors: Record<string, string> = {
    contract: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
    letter: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
    policy: 'border-purple-500/30 text-purple-400 bg-purple-500/10',
    certificate: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
    offer: 'border-indigo-500/30 text-indigo-400 bg-indigo-500/10',
    other: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
};

export default function DocumentTemplates({ templates, categories, filters, can }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get('/hr/documents/templates', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    function toggleActive(template: Template) {
        router.post(`/hr/documents/templates/${template.id}/toggle-active`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Document Templates" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Document Templates</h1>
                    {can.manage && (
                        <Button asChild>
                            <Link href="/hr/documents/templates/create">
                                <Plus className="mr-2 h-4 w-4" />
                                Create Template
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search templates..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                applyFilter('q', (e.target as HTMLInputElement).value);
                            }
                        }}
                    />
                    <Select
                        value={filters.category || '__none__'}
                        onValueChange={(v) => applyFilter('category', v === '__none__' ? null : v)}
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All categories</SelectItem>
                            {categories.map((category) => (
                                <SelectItem key={category} value={category}>
                                    {category.replace('_', ' ')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Name</th>
                                    <th className="px-4 py-3 text-left font-medium">Category</th>
                                    <th className="px-4 py-3 text-left font-medium">Merge Fields</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Version</th>
                                    <th className="px-4 py-3 text-left font-medium">Updated</th>
                                    {can.manage && <th className="px-4 py-3 text-right font-medium">Actions</th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {templates.data.map((template) => {
                                    const typeClass = typeColors[template.category] || typeColors.other;
                                    return (
                                        <tr key={template.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">{template.name}</td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={typeClass}>
                                                    {template.category}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {template.merge_fields.length > 0
                                                    ? template.merge_fields.slice(0, 3).join(', ')
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Badge variant={template.is_active ? 'default' : 'secondary'}>
                                                        {template.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                    {template.approval_required && (
                                                        <Badge variant="outline">Approval required</Badge>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">v{template.version}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{template.updated_at}</td>
                                            {can.manage && (
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button variant="ghost" size="sm" asChild>
                                                            <Link href={`/hr/documents/templates/${template.id}/edit`}>
                                                                Edit
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => toggleActive(template)}
                                                        >
                                                            {template.is_active ? 'Deactivate' : 'Activate'}
                                                        </Button>
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                                {templates.data.length === 0 && (
                                    <tr>
                                        <td colSpan={can.manage ? 7 : 6} className="px-4 py-8 text-center text-muted-foreground">
                                            No document templates found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {templates.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(templates.current_page - 1) * templates.per_page + 1} to{' '}
                            {Math.min(templates.current_page * templates.per_page, templates.total)} of{' '}
                            {templates.total} templates
                        </p>
                        <div className="flex items-center gap-1">
                            {templates.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                >
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
