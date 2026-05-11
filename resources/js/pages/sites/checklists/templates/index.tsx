import FleetHero from '@/components/fleet-hero';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { ClipboardCheck, Plus, Search, Filter, Edit, Trash2, FileQuestion } from 'lucide-react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';

type Template = {
    id: number;
    key: string;
    name: string;
    description?: string;
    applicable_to_type: 'house' | 'head_office' | 'facility' | 'all';
    frequency: string;
    is_active: boolean;
    items_count: number;
};

type Props = {
    templates: {
        data: Template[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { type?: string; status?: string };
};

const typeLabels: Record<string, string> = {
    house: 'House',
    head_office: 'Head Office',
    facility: 'Facility',
    all: 'All Types',
};

const frequencyLabels: Record<string, string> = {
    once: 'One-time',
    daily: 'Daily',
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
};

export default function ChecklistTemplates({ templates, filters }: Props) {
    const [search, setSearch] = useState('');

    const filteredTemplates = templates.data.filter(t =>
        t.name.toLowerCase().includes(search.toLowerCase()) ||
        t.key.toLowerCase().includes(search.toLowerCase())
    );

    const activeCount = templates.data.filter(t => t.is_active).length;
    const inactiveCount = templates.data.length - activeCount;
    const totalItems = templates.data.reduce((sum, t) => sum + (t.items_count || 0), 0);

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: 'Checklist Templates', href: '/sites/checklists/templates' }]}>
            <Head title="Checklist Templates" />

            <div className="flex flex-col gap-4 p-4 md:p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Checklist Templates"
                    description="Manage reusable checklists for site inspections and walkthroughs"
                    icon={<ClipboardCheck className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Total', value: templates.data.length },
                        { label: 'Active', value: activeCount },
                        { label: 'Inactive', value: inactiveCount },
                        { label: 'Items', value: totalItems },
                    ]}
                    actions={
                        <Button asChild>
                            <Link href="/sites/checklists/templates/create">
                                <Plus className="w-4 h-4 mr-1" />
                                New Template
                            </Link>
                        </Button>
                    }
                />

                {/* Filters */}
                <div className="flex gap-3">
                    <div className="relative flex-1 max-w-sm">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search templates..."
                            className="pl-9"
                        />
                    </div>
                    <Button variant="outline" size="icon">
                        <Filter className="w-4 h-4" />
                    </Button>
                </div>

                {/* Templates Grid */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {filteredTemplates.map((template) => (
                        <Card key={template.id} className={!template.is_active ? 'opacity-60' : ''}>
                            <CardHeader className="pb-3">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <CardTitle className="text-base">{template.name}</CardTitle>
                                        <p className="text-xs text-muted-foreground font-mono mt-0.5">{template.key}</p>
                                    </div>
                                    {!template.is_active && (
                                        <Badge variant="outline" className="text-muted-foreground">Inactive</Badge>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent>
                                {template.description && (
                                    <p className="text-sm text-muted-foreground mb-3 line-clamp-2">{template.description}</p>
                                )}
                                <div className="flex flex-wrap gap-2 mb-4">
                                    <Badge variant="outline">{typeLabels[template.applicable_to_type]}</Badge>
                                    <Badge variant="outline" className="text-muted-foreground">
                                        {frequencyLabels[template.frequency]}
                                    </Badge>
                                    <Badge variant="outline" className="text-muted-foreground">
                                        <FileQuestion className="w-3 h-3 mr-1" />
                                        {template.items_count} items
                                    </Badge>
                                </div>
                                <div className="flex gap-2">
                                    <Button asChild variant="outline" size="sm" className="flex-1">
                                        <Link href={`/sites/checklists/templates/${template.id}/edit`}>
                                            <Edit className="w-3.5 h-3.5 mr-1" />
                                            Edit
                                        </Link>
                                    </Button>
                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-status-critical hover:text-status-critical"
                                            >
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </Button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent>
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>Delete Template</AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    Are you sure you want to delete "{template.name}"? This action cannot be undone.
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                <AlertDialogAction
                                                    className="bg-status-critical hover:bg-status-critical"
                                                    onClick={() => router.delete(`/sites/checklists/templates/${template.id}`)}
                                                >
                                                    Delete
                                                </AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {filteredTemplates.length === 0 && (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <ClipboardCheck className="w-12 h-12 mx-auto mb-3 opacity-50" />
                            <p>No checklist templates found</p>
                            <p className="text-sm mt-1">Create your first template to get started</p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
