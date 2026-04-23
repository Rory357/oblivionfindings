import AppLayout from '@/layouts/app-layout';
import { Head, router, Link, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Plus, BookOpen, GraduationCap, Calendar, Search, Filter, Users,
    CheckCircle2, ShieldCheck, Monitor, MapPin, Layers, Zap, Clock, ArrowRight, BarChart3,
} from 'lucide-react';
import { useEffect, useState, FormEvent } from 'react';
import { type BreadcrumbItem } from '@/types';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface SelectOption { value: string; label: string }
interface Course {
    id: number; title: string; code: string; description: string | null;
    category: string | null; delivery_method: string; duration_hours: string;
    provider: string | null; cost: string | null; is_mandatory: boolean;
    is_active: boolean; enrollments_count: number; sessions_count: number;
}
interface Summary {
    total_courses: number; mandatory_courses: number; total_enrollments: number;
    completed_enrollments: number; upcoming_sessions: number; completion_rate: number;
}
interface Props {
    courses: { data: Course[]; links: any[] };
    categories: string[]; summary: Summary;
    filters: { category: string | null; delivery_method: string | null; mandatory_only: boolean; search: string | null };
    deliveryMethods: SelectOption[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Training', href: '/hr/compliance/training' },
    { title: 'Course Catalog', href: '/hr/training/catalog' },
];

const DELIVERY_COLORS: Record<string, string> = { online: 'bg-blue-100 text-blue-700', in_person: 'bg-emerald-100 text-emerald-700', blended: 'bg-violet-100 text-violet-700', self_paced: 'bg-amber-100 text-amber-700' };
const DELIVERY_ICONS: Record<string, typeof Monitor> = { online: Monitor, in_person: MapPin, blended: Layers, self_paced: Zap };
const deliveryLabels: Record<string, string> = { online: 'Online', in_person: 'In Person', blended: 'Blended', self_paced: 'Self-Paced' };

const formatCurrency = (value: string | null) => {
    if (!value) return '\u2014';
    const num = parseFloat(value);
    if (Number.isNaN(num)) return value;
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(num);
};

const emptyForm = {
    title: '', code: '', description: '', category: '', delivery_method: 'online',
    duration_hours: '', provider: '', cost: '', is_mandatory: false, max_participants: '',
};

export default function TrainingCatalog({ courses, categories, summary, filters, deliveryMethods, can }: Props) {
    const { url } = usePage();
    const openedFromLegacyCreateRoute = can.manage && new URL(url, 'https://oblivionfindings.test').searchParams.get('open') === 'create';
    const [open, setOpen] = useState(openedFromLegacyCreateRoute);
    const [form, setForm] = useState(emptyForm);

    useEffect(() => {
        if (open || !openedFromLegacyCreateRoute || typeof window === 'undefined') {
            return;
        }

        const nextUrl = new URL(window.location.href);

        if (nextUrl.searchParams.get('open') !== 'create') {
            return;
        }

        nextUrl.searchParams.delete('open');
        window.history.replaceState(window.history.state, '', `${nextUrl.pathname}${nextUrl.search}${nextUrl.hash}`);
    }, [open, openedFromLegacyCreateRoute]);

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/training/catalog', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/training/courses', form, { onSuccess: () => { setOpen(false); setForm(emptyForm); } });
    };
    const set = (key: string, value: string | boolean) => setForm((prev) => ({ ...prev, [key]: value }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Course Catalog" />

            <div className="space-y-6 p-4 lg:p-6">
                {/* Hero Banner */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-violet-600 via-purple-600 to-indigo-700 p-6 text-white shadow-lg">
                    <div className="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/5" />
                    <div className="absolute -bottom-8 right-20 h-24 w-24 rounded-full bg-white/5" />
                    <div className="absolute left-1/3 -top-4 h-28 w-28 rounded-full bg-white/5" />
                    <div className="relative flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold">Course Catalog</h1>
                            <p className="mt-1 text-white/70">Browse and manage training courses for your organisation</p>
                        </div>
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-6">
                                <div className="text-center">
                                    <div className="text-3xl font-bold">{summary.total_courses}</div>
                                    <div className="text-[10px] uppercase tracking-wider text-white/60">Courses</div>
                                </div>
                                <div className="h-10 w-px bg-white/20" />
                                <div className="text-center">
                                    <div className="text-3xl font-bold">{summary.completion_rate}%</div>
                                    <div className="text-[10px] uppercase tracking-wider text-white/60">Completion</div>
                                </div>
                            </div>
                            <div className="ml-4 flex gap-2">
                                <Button variant="secondary" size="sm" className="gap-1.5 bg-white/15 text-white border-white/20 hover:bg-white/25 backdrop-blur-sm" asChild>
                                    <Link href="/hr/compliance/training"><BarChart3 className="h-4 w-4" />Dashboard</Link>
                                </Button>
                                {can.manage && (
                                    <Button size="sm" className="gap-1.5 bg-white text-violet-700 hover:bg-white/90 shadow-md" onClick={() => { setForm(emptyForm); setOpen(true); }}>
                                        <Plus className="h-4 w-4" />New Course
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    {[
                        { label: 'Courses', value: summary.total_courses, icon: BookOpen, gradient: 'from-violet-500/10 to-purple-500/5', iconBg: 'bg-violet-100', iconColor: 'text-violet-600', borderHover: 'hover:border-violet-300' },
                        { label: 'Mandatory', value: summary.mandatory_courses, icon: ShieldCheck, gradient: 'from-red-500/10 to-rose-500/5', iconBg: 'bg-red-100', iconColor: 'text-red-600', borderHover: 'hover:border-red-300' },
                        { label: 'Enrollments', value: summary.total_enrollments, icon: Users, gradient: 'from-blue-500/10 to-indigo-500/5', iconBg: 'bg-blue-100', iconColor: 'text-blue-600', borderHover: 'hover:border-blue-300' },
                        { label: 'Completion', value: `${summary.completion_rate}%`, icon: CheckCircle2, gradient: 'from-emerald-500/10 to-green-500/5', iconBg: 'bg-emerald-100', iconColor: 'text-emerald-600', borderHover: 'hover:border-emerald-300' },
                        { label: 'Upcoming', value: summary.upcoming_sessions, icon: Calendar, gradient: 'from-amber-500/10 to-yellow-500/5', iconBg: 'bg-amber-100', iconColor: 'text-amber-600', borderHover: 'hover:border-amber-300' },
                    ].map((kpi) => {
                        const Icon = kpi.icon;
                        return (
                            <Card key={kpi.label} className={`group overflow-hidden bg-gradient-to-br ${kpi.gradient} transition-all ${kpi.borderHover} hover:shadow-md`}>
                                <CardContent className="pt-4 pb-4">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">{kpi.label}</p>
                                            <p className="mt-1 text-2xl font-bold tracking-tight">{kpi.value}</p>
                                        </div>
                                        <div className={`flex h-9 w-9 items-center justify-center rounded-xl ${kpi.iconBg} transition-transform group-hover:scale-110`}>
                                            <Icon className={`h-4 w-4 ${kpi.iconColor}`} />
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Filters Toolbar */}
                <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-white/50 p-3 shadow-sm">
                    <div className="relative flex-1 min-w-[180px]">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search courses..." className="h-9 pl-8 text-sm" value={filters.search || ''} onChange={(e) => onFilter({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.category || 'all'} onValueChange={(v) => onFilter({ category: v === 'all' ? null : v })}>
                        <SelectTrigger className="h-9 w-[150px] text-xs"><Filter className="mr-1 h-3 w-3" /><SelectValue placeholder="All Categories" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Categories</SelectItem>
                            {categories.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.delivery_method || 'all'} onValueChange={(v) => onFilter({ delivery_method: v === 'all' ? null : v })}>
                        <SelectTrigger className="h-9 w-[140px] text-xs"><SelectValue placeholder="All Methods" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Methods</SelectItem>
                            {deliveryMethods.map((dm) => <SelectItem key={dm.value} value={dm.value}>{dm.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <input type="checkbox" checked={filters.mandatory_only} onChange={(e) => onFilter({ mandatory_only: e.target.checked })} className="rounded border-gray-300" />
                        Mandatory only
                    </label>
                </div>

                {/* Courses */}
                {courses.data.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-50">
                                <GraduationCap className="h-8 w-8 text-violet-400" />
                            </div>
                            <p className="font-medium">No Courses Found</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {filters.search || filters.category || filters.delivery_method || filters.mandatory_only ? 'No courses match your filters.' : 'Create your first training course to get started.'}
                            </p>
                            {can.manage && !filters.search && !filters.category && (
                                <Button className="mt-4 gap-1.5 bg-violet-600 hover:bg-violet-700" size="sm" onClick={() => { setForm(emptyForm); setOpen(true); }}>
                                    <Plus className="h-3.5 w-3.5" /> New Course
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {courses.data.map((c) => {
                            const dmColor = DELIVERY_COLORS[c.delivery_method] || 'bg-slate-100 text-slate-600';
                            const DmIcon = DELIVERY_ICONS[c.delivery_method] || BookOpen;
                            return (
                                <Card key={c.id} className="group cursor-pointer overflow-hidden transition-all hover:shadow-md hover:-translate-y-0.5 hover:border-violet-300"
                                    onClick={() => router.get(`/hr/training/courses/${c.id}`)}>
                                    <div className={`h-1 ${c.is_mandatory ? 'bg-gradient-to-r from-red-400 to-rose-500' : 'bg-gradient-to-r from-violet-400 to-purple-500'}`} />
                                    <CardContent className="pt-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex-1 min-w-0">
                                                <h3 className="font-semibold text-sm leading-tight line-clamp-2">{c.title}</h3>
                                                <p className="mt-1 font-mono text-[10px] text-muted-foreground">{c.code}</p>
                                            </div>
                                            <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${c.is_mandatory ? 'bg-red-50' : 'bg-violet-50'} transition-transform group-hover:scale-110`}>
                                                <DmIcon className={`h-4 w-4 ${c.is_mandatory ? 'text-red-500' : 'text-violet-500'}`} />
                                            </div>
                                        </div>

                                        <div className="mt-3 flex flex-wrap gap-1.5">
                                            {c.is_mandatory && <Badge className="border-0 bg-red-100 text-[9px] text-red-600">Mandatory</Badge>}
                                            {c.category && <Badge variant="outline" className="text-[9px] capitalize">{c.category}</Badge>}
                                            <Badge className={`border-0 text-[9px] ${dmColor}`}>{deliveryLabels[c.delivery_method]}</Badge>
                                        </div>

                                        <div className="mt-3 flex items-center justify-between border-t pt-3">
                                            <div className="flex items-center gap-4 text-[11px] text-muted-foreground">
                                                <span className="flex items-center gap-1"><Clock className="h-3 w-3" />{c.duration_hours}h</span>
                                                <span className="flex items-center gap-1"><Users className="h-3 w-3" />{c.enrollments_count}</span>
                                                {c.sessions_count > 0 && <span className="flex items-center gap-1"><Calendar className="h-3 w-3" />{c.sessions_count}</span>}
                                            </div>
                                            <div className="flex items-center gap-1.5">
                                                {c.cost && <span className="text-[11px] font-medium text-muted-foreground">{formatCurrency(c.cost)}</span>}
                                                <Badge variant={c.is_active ? 'default' : 'secondary'} className={c.is_active ? 'border-0 bg-emerald-100 text-[9px] text-emerald-700' : 'text-[9px]'}>
                                                    {c.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                {courses?.links?.length ? <LaravelPagination links={courses.links} /> : null}
            </div>

            {/* Create Course Dialog */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader><DialogTitle>New Course</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5"><Label>Title *</Label><Input value={form.title} onChange={(e) => set('title', e.target.value)} required /></div>
                            <div className="space-y-1.5"><Label>Code *</Label><Input value={form.code} onChange={(e) => set('code', e.target.value)} required /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Description</Label><Textarea value={form.description} onChange={(e) => set('description', e.target.value)} className="min-h-[60px]" /></div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5"><Label>Category</Label><Input value={form.category} onChange={(e) => set('category', e.target.value)} placeholder="e.g. Health & Safety" /></div>
                            <div className="space-y-1.5">
                                <Label>Delivery Method *</Label>
                                <Select value={form.delivery_method} onValueChange={(v) => set('delivery_method', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{deliveryMethods.map((dm) => <SelectItem key={dm.value} value={dm.value}>{dm.label}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="space-y-1.5"><Label>Duration (hours) *</Label><Input type="number" step="0.5" value={form.duration_hours} onChange={(e) => set('duration_hours', e.target.value)} required /></div>
                            <div className="space-y-1.5"><Label>Provider</Label><Input value={form.provider} onChange={(e) => set('provider', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Cost (NZD)</Label><Input type="number" step="0.01" value={form.cost} onChange={(e) => set('cost', e.target.value)} /></div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5"><Label>Max Participants</Label><Input type="number" value={form.max_participants} onChange={(e) => set('max_participants', e.target.value)} /></div>
                            <div className="flex items-end gap-2 pb-1">
                                <Checkbox checked={form.is_mandatory as any} onCheckedChange={(v) => set('is_mandatory', !!v)} />
                                <Label className="text-sm">Mandatory course</Label>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" className="bg-violet-600 hover:bg-violet-700">Create Course</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
