import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Plus, BookOpen, GraduationCap, Calendar } from 'lucide-react';
import { useState, FormEvent } from 'react';
import { type BreadcrumbItem } from '@/types';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface SelectOption {
    value: string;
    label: string;
}

interface Course {
    id: number;
    title: string;
    code: string;
    description: string | null;
    category: string | null;
    delivery_method: string;
    duration_hours: string;
    provider: string | null;
    cost: string | null;
    is_mandatory: boolean;
    is_active: boolean;
    enrollments_count: number;
    sessions_count: number;
}

interface Summary {
    total_courses: number;
    mandatory_courses: number;
    total_enrollments: number;
    completed_enrollments: number;
    upcoming_sessions: number;
    completion_rate: number;
}

interface Props {
    courses: { data: Course[]; links: any[] };
    categories: string[];
    summary: Summary;
    filters: { category: string | null; delivery_method: string | null; mandatory_only: boolean; search: string | null };
    deliveryMethods: SelectOption[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Training', href: '/hr/training/catalog' },
    { title: 'Course Catalog', href: '/hr/training/catalog' },
];

const deliveryLabels: Record<string, string> = {
    online: 'Online',
    in_person: 'In Person',
    blended: 'Blended',
    self_paced: 'Self-Paced',
};

const formatCurrency = (value: string | null) => {
    if (!value) return '-';
    const num = parseFloat(value);
    if (Number.isNaN(num)) return value;
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(num);
};

const emptyForm = {
    title: '',
    code: '',
    description: '',
    category: '',
    delivery_method: 'online',
    duration_hours: '',
    provider: '',
    cost: '',
    is_mandatory: false,
    max_participants: '',
};

export default function TrainingCatalog({ courses, categories, summary, filters, deliveryMethods, can }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(emptyForm);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/training/catalog', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/training/courses', form, {
            onSuccess: () => {
                setOpen(false);
                setForm(emptyForm);
            },
        });
    };

    const set = (key: string, value: string | boolean) => setForm((prev) => ({ ...prev, [key]: value }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Training Catalog" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Course Catalog</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Browse and manage training courses
                        </div>
                    </div>

                    {can.manage && (
                        <Button size="sm" onClick={() => { setForm(emptyForm); setOpen(true); }}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            New Course
                        </Button>
                    )}
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <Card>
                        <CardContent className="pt-4">
                            <div className="flex items-center gap-2">
                                <BookOpen className="h-4 w-4 text-slate-400" />
                                <span className="text-xs text-slate-500">Courses</span>
                            </div>
                            <div className="mt-1 text-2xl font-bold">{summary.total_courses}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <div className="flex items-center gap-2">
                                <GraduationCap className="h-4 w-4 text-slate-400" />
                                <span className="text-xs text-slate-500">Mandatory</span>
                            </div>
                            <div className="mt-1 text-2xl font-bold">{summary.mandatory_courses}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <span className="text-xs text-slate-500">Total Enrollments</span>
                            <div className="mt-1 text-2xl font-bold">{summary.total_enrollments}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <span className="text-xs text-slate-500">Completion Rate</span>
                            <div className="mt-1 text-2xl font-bold">{summary.completion_rate}%</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <div className="flex items-center gap-2">
                                <Calendar className="h-4 w-4 text-slate-400" />
                                <span className="text-xs text-slate-500">Upcoming Sessions</span>
                            </div>
                            <div className="mt-1 text-2xl font-bold">{summary.upcoming_sessions}</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Search courses..."
                                value={filters.search || ''}
                                onChange={(e) => onFilter({ search: e.target.value || null })}
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Category</Label>
                            <Select
                                value={filters.category || 'all'}
                                onValueChange={(val) => onFilter({ category: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Categories</SelectItem>
                                    {categories.map((cat) => (
                                        <SelectItem key={cat} value={cat}>{cat}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs text-slate-500">Delivery Method</Label>
                            <Select
                                value={filters.delivery_method || 'all'}
                                onValueChange={(val) => onFilter({ delivery_method: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Methods</SelectItem>
                                    {deliveryMethods.map((dm) => (
                                        <SelectItem key={dm.value} value={dm.value}>{dm.label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-end">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={filters.mandatory_only}
                                    onChange={(e) => onFilter({ mandatory_only: e.target.checked })}
                                    className="rounded border-slate-300"
                                />
                                Mandatory only
                            </label>
                        </div>
                    </CardContent>
                </Card>

                {/* Courses Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Course</TableHead>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Delivery</TableHead>
                                    <TableHead>Duration</TableHead>
                                    <TableHead>Cost</TableHead>
                                    <TableHead>Enrollments</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {courses.data.map((course) => (
                                    <TableRow
                                        key={course.id}
                                        className="cursor-pointer hover:bg-muted/50"
                                        onClick={() => router.get(`/hr/training/courses/${course.id}`)}
                                    >
                                        <TableCell>
                                            <div className="font-medium">{course.title}</div>
                                            {course.is_mandatory && (
                                                <Badge variant="destructive" className="mt-0.5 text-[10px]">Mandatory</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{course.code}</TableCell>
                                        <TableCell>{course.category || '-'}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">{deliveryLabels[course.delivery_method] || course.delivery_method}</Badge>
                                        </TableCell>
                                        <TableCell>{course.duration_hours}h</TableCell>
                                        <TableCell>{formatCurrency(course.cost)}</TableCell>
                                        <TableCell>{course.enrollments_count}</TableCell>
                                        <TableCell>
                                            <Badge variant={course.is_active ? 'default' : 'secondary'}>
                                                {course.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!courses.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="py-8 text-center text-sm text-slate-500">
                                            No courses found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {courses?.links?.length ? (
                    <LaravelPagination links={courses.links} />
                ) : null}
            </div>

            {/* Create Course Dialog */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>New Course</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Title</Label>
                                <Input value={form.title} onChange={(e) => set('title', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Code</Label>
                                <Input value={form.code} onChange={(e) => set('code', e.target.value)} required />
                            </div>
                        </div>
                        <div>
                            <Label>Description</Label>
                            <Textarea value={form.description} onChange={(e) => set('description', e.target.value)} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Category</Label>
                                <Input value={form.category} onChange={(e) => set('category', e.target.value)} />
                            </div>
                            <div>
                                <Label>Delivery Method</Label>
                                <Select value={form.delivery_method} onValueChange={(val) => set('delivery_method', val)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {deliveryMethods.map((dm) => (
                                            <SelectItem key={dm.value} value={dm.value}>{dm.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div>
                                <Label>Duration (hours)</Label>
                                <Input type="number" step="0.5" value={form.duration_hours} onChange={(e) => set('duration_hours', e.target.value)} required />
                            </div>
                            <div>
                                <Label>Provider</Label>
                                <Input value={form.provider} onChange={(e) => set('provider', e.target.value)} />
                            </div>
                            <div>
                                <Label>Cost (NZD)</Label>
                                <Input type="number" step="0.01" value={form.cost} onChange={(e) => set('cost', e.target.value)} />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label>Max Participants</Label>
                                <Input type="number" value={form.max_participants} onChange={(e) => set('max_participants', e.target.value)} />
                            </div>
                            <div className="flex items-end">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.is_mandatory as any}
                                        onChange={(e) => set('is_mandatory', e.target.checked as any)}
                                        className="rounded border-slate-300"
                                    />
                                    Mandatory course
                                </label>
                            </div>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit">Create Course</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
