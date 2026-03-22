import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, useForm, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus, Target, Users } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';
import { useState } from 'react';

interface Competency {
    id: number;
    name: string;
    description: string | null;
    category: string;
    proficiency_levels: string[];
    is_active: boolean;
    sort_order: number;
}

interface StaffMember {
    id: number;
    name: string;
    email: string;
}

interface Props {
    competencies: Competency[];
    grouped: Record<string, Competency[]>;
    staff: StaffMember[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance', href: '/hr/performance' },
    { title: 'Competencies', href: '/hr/performance/competencies' },
];

export default function CompetencyIndex({ competencies, grouped, staff, can }: Props) {
    const [showForm, setShowForm] = useState(false);
    const form = useForm({
        name: '',
        description: '',
        category: '',
        proficiency_levels: ['Beginner', 'Developing', 'Competent', 'Advanced', 'Expert'],
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/hr/performance/competencies', {
            preserveScroll: true,
            onSuccess: () => {
                setShowForm(false);
                form.reset();
            },
        });
    };

    const categories = Object.keys(grouped);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Competency Framework" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Competency Framework</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Define and manage organisational competencies
                        </div>
                    </div>

                    <div className="flex gap-2">
                        {can.manage && (
                            <>
                                <Button size="sm" asChild variant="outline">
                                    <Link href="/hr/performance/competencies?assess=1">
                                        <Users className="mr-1.5 h-4 w-4" />
                                        Assess
                                    </Link>
                                </Button>
                                <Button size="sm" onClick={() => setShowForm(!showForm)}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add Competency
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {showForm && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">New Competency</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-3">
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Name</Label>
                                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                        {form.errors.name && <p className="mt-1 text-xs text-red-500">{form.errors.name}</p>}
                                    </div>
                                    <div>
                                        <Label>Category</Label>
                                        <Input value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} placeholder="e.g. Technical, Leadership" />
                                        {form.errors.category && <p className="mt-1 text-xs text-red-500">{form.errors.category}</p>}
                                    </div>
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={2} />
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={form.processing}>Save</Button>
                                    <Button type="button" variant="outline" onClick={() => setShowForm(false)}>Cancel</Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {categories.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-slate-400">
                            No competencies defined yet. {can.manage ? 'Click "Add Competency" to begin.' : ''}
                        </CardContent>
                    </Card>
                ) : (
                    categories.map((category) => (
                        <Card key={category}>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Target className="h-5 w-5 text-blue-500" />
                                    {category}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Competency</TableHead>
                                            <TableHead>Description</TableHead>
                                            <TableHead>Levels</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {grouped[category].map((comp) => (
                                            <TableRow key={comp.id}>
                                                <TableCell className="font-medium">{comp.name}</TableCell>
                                                <TableCell className="text-sm text-slate-500">{comp.description || '-'}</TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-1">
                                                        {comp.proficiency_levels?.map((level, i) => (
                                                            <Badge key={i} variant="outline" className="text-xs">{level}</Badge>
                                                        ))}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    ))
                )}

                {/* Staff profiles */}
                {staff.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Employee Profiles</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {staff.map((s) => (
                                    <Link
                                        key={s.id}
                                        href={`/hr/performance/competencies/profile/${s.id}`}
                                        className="rounded-lg border p-3 text-sm hover:bg-slate-50 transition-colors"
                                    >
                                        <div className="font-medium">{s.name}</div>
                                        <div className="text-xs text-slate-400">{s.email}</div>
                                    </Link>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
