import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { Plus } from 'lucide-react';

interface Template {
    id: number;
    name: string;
    description: string | null;
    template_type: string;
    placeholders: string[] | null;
    is_active: boolean;
    created_at: string;
}

interface Props {
    templates: Template[];
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
    other: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
};

export default function DocumentTemplates({ templates, can }: Props) {
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

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Name</th>
                                    <th className="px-4 py-3 text-left font-medium">Description</th>
                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Created</th>
                                    {can.manage && (
                                        <th className="px-4 py-3 text-right font-medium">Actions</th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {templates.map((template) => {
                                    const typeClass = typeColors[template.template_type] || typeColors.other;
                                    return (
                                        <tr key={template.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">{template.name}</td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {template.description || '\u2014'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={typeClass}>
                                                    {template.template_type}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant={template.is_active ? 'default' : 'secondary'}>
                                                    {template.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{template.created_at}</td>
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
                                {templates.length === 0 && (
                                    <tr>
                                        <td colSpan={can.manage ? 6 : 5} className="px-4 py-8 text-center text-muted-foreground">
                                            No document templates found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
