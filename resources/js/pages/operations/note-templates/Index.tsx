import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Eye, FileText, Hash, Pencil, Plus, Search, StickyNote } from 'lucide-react';

const ANY = '__ANY__';

type NoteTemplate = {
    id: number;
    name: string;
    template_type: string;
    is_active: boolean;
    fields_count: number;
    created_at: string;
};

type Props = {
    templates: {
        data: NoteTemplate[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
    };
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function NoteTemplatesIndex({ templates = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/note-templates', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Note Templates" />
            <PageHeader
                title="Note Templates"
                description="Manage care note templates and custom fields."
                backHref="/operations"
            />
            <PageShell>
                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search templates..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[130px] text-xs">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button asChild size="sm">
                        <Link href="/operations/note-templates/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Template
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(templates?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <StickyNote className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Note Templates</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create your first note template to standardise care notes.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/note-templates/create">Create Template</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {(templates?.data ?? []).map((tpl) => (
                        <Card key={tpl.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                    <FileText className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/operations/note-templates/${tpl.id}`} className="text-sm font-semibold hover:underline">
                                            {tpl.name}
                                        </Link>
                                        <Badge variant={tpl.is_active ? 'default' : 'secondary'} className="h-4 px-1.5 text-[9px]">
                                            {tpl.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">
                                            {tpl.template_type}
                                        </Badge>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <Hash className="h-3 w-3" /> {tpl.fields_count} fields
                                        </span>
                                        <span>Created: {formatDate(tpl.created_at)}</span>
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/note-templates/${tpl.id}`}>
                                            <Eye className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/note-templates/${tpl.id}/edit`}>
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(templates?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(templates?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
