import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useState } from 'react';
import { Plus, X, ArrowLeft, Star, ClipboardList, CheckCircle2, AlertCircle, GripVertical } from 'lucide-react';

interface Criterion { label: string; weight?: number; }

interface Kit {
    id: number;
    name: string;
    role: string | null;
    criteria: Criterion[];
    guidance: string | null;
    is_active: boolean;
    created_at: string;
    usage_count?: number;
}

interface Props {
    kits: {
        data: Kit[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    roles: string[];
    can: { manage: boolean };
}

export default function InterviewKits({ kits, roles, can }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingKitId, setEditingKitId] = useState<number | null>(null);
    const [criteria, setCriteria] = useState<Array<{ label: string; weight: string }>>([
        { label: '', weight: '25' },
    ]);
    const form = useForm({ name: '', role: '', guidance: '' });

    function resetForm() {
        form.reset();
        setEditingKitId(null);
        setCriteria([{ label: '', weight: '25' }]);
        setFormOpen(false);
    }

    function addCriterion() {
        setCriteria([...criteria, { label: '', weight: '25' }]);
    }

    function removeCriterion(index: number) {
        setCriteria(criteria.filter((_, i) => i !== index));
    }

    function updateCriterion(index: number, field: 'label' | 'weight', value: string) {
        setCriteria(criteria.map((c, i) => i === index ? { ...c, [field]: value } : c));
    }

    const totalWeight = criteria.reduce((sum, c) => sum + (Number(c.weight) || 0), 0);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        const parsedCriteria = criteria
            .filter(c => c.label.trim())
            .map(c => ({ label: c.label.trim(), weight: Number(c.weight) || undefined }));

        form.transform((data) => ({
            name: data.name,
            role: data.role || null,
            guidance: data.guidance || null,
            criteria: parsedCriteria,
        }));

        if (editingKitId) {
            form.put(`/hr/recruitment/kits/${editingKitId}`, { preserveScroll: true, onSuccess: resetForm });
        } else {
            form.post('/hr/recruitment/kits', { preserveScroll: true, onSuccess: resetForm });
        }
    }

    function toggleActive(kitId: number) {
        router.post(`/hr/recruitment/kits/${kitId}/toggle-active`, {}, { preserveScroll: true });
    }

    function startEdit(kit: Kit) {
        setEditingKitId(kit.id);
        form.setData({ name: kit.name, role: kit.role || '', guidance: kit.guidance || '' });
        setCriteria(kit.criteria.map(c => ({ label: c.label, weight: String(c.weight ?? 25) })));
        setFormOpen(true);
    }

    return (
        <AppLayout breadcrumbs={[
            { title: 'HR', href: '/hr' },
            { title: 'Recruitment', href: '/hr/recruitment' },
            { title: 'Interview Kits', href: '/hr/recruitment/kits' },
        ]}>
            <Head title="Interview Kits" />
            <PageShell>
                <PageHeader
                    title="Interview Kits"
                    description="Structured scorecards and interview criteria for consistent hiring decisions."
                    actions={
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/hr/recruitment"><ArrowLeft className="mr-2 h-4 w-4" />Pipeline</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/hr/recruitment/jobs">Jobs</Link>
                            </Button>
                            {can.manage && (
                                <Dialog open={formOpen} onOpenChange={setFormOpen}>
                                    <DialogTrigger asChild>
                                        <Button onClick={() => { setEditingKitId(null); form.reset(); setCriteria([{ label: '', weight: '25' }]); }}>
                                            <Plus className="mr-2 h-4 w-4" />New Kit
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                                        <DialogHeader>
                                            <DialogTitle>{editingKitId ? 'Edit Interview Kit' : 'Create Interview Kit'}</DialogTitle>
                                        </DialogHeader>
                                        <form onSubmit={submit} className="space-y-5">
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label>Name *</Label>
                                                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Support Worker Standard Kit" />
                                                    {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Role</Label>
                                                    <Select value={form.data.role || '__none__'} onValueChange={(v) => form.setData('role', v === '__none__' ? '' : v)}>
                                                        <SelectTrigger><SelectValue placeholder="Any role" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="__none__">Any role</SelectItem>
                                                            {roles.map((r) => <SelectItem key={r} value={r}>{r.replace('_', ' ')}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                            </div>

                                            {/* Criteria Builder */}
                                            <div className="space-y-3">
                                                <div className="flex items-center justify-between">
                                                    <Label>Evaluation Criteria</Label>
                                                    <div className={`flex items-center gap-1.5 text-xs font-medium ${
                                                        totalWeight === 100 ? 'text-green-500' : 'text-amber-500'
                                                    }`}>
                                                        {totalWeight === 100 ? <CheckCircle2 className="h-3.5 w-3.5" /> : <AlertCircle className="h-3.5 w-3.5" />}
                                                        Total: {totalWeight}%
                                                    </div>
                                                </div>
                                                <div className="space-y-2">
                                                    {criteria.map((criterion, idx) => (
                                                        <div key={idx} className="flex items-center gap-2 group">
                                                            <GripVertical className="h-4 w-4 text-muted-foreground/30 shrink-0" />
                                                            <Input
                                                                className="flex-1"
                                                                placeholder="Criterion name..."
                                                                value={criterion.label}
                                                                onChange={(e) => updateCriterion(idx, 'label', e.target.value)}
                                                            />
                                                            <div className="flex items-center gap-1 shrink-0">
                                                                <Input
                                                                    type="number"
                                                                    min={0}
                                                                    max={100}
                                                                    className="w-16 text-center"
                                                                    value={criterion.weight}
                                                                    onChange={(e) => updateCriterion(idx, 'weight', e.target.value)}
                                                                />
                                                                <span className="text-xs text-muted-foreground">%</span>
                                                            </div>
                                                            {criteria.length > 1 && (
                                                                <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0 opacity-0 group-hover:opacity-100 transition-opacity" onClick={() => removeCriterion(idx)}>
                                                                    <X className="h-3.5 w-3.5" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                                <Button type="button" variant="outline" size="sm" onClick={addCriterion}>
                                                    <Plus className="mr-1 h-3.5 w-3.5" />Add Criterion
                                                </Button>

                                                {/* Weight Bar Preview */}
                                                {criteria.some(c => c.label.trim()) && (
                                                    <div className="rounded-lg border p-3 bg-muted/30">
                                                        <p className="text-xs font-medium mb-2">Weight Distribution</p>
                                                        <div className="flex h-4 rounded-full overflow-hidden bg-muted/50">
                                                            {criteria.filter(c => c.label.trim()).map((c, i) => {
                                                                const w = Number(c.weight) || 0;
                                                                const colors = ['bg-blue-500', 'bg-indigo-500', 'bg-amber-500', 'bg-emerald-500', 'bg-purple-500', 'bg-cyan-500', 'bg-pink-500', 'bg-orange-500'];
                                                                return (
                                                                    <div key={i} className={`${colors[i % colors.length]} transition-all`} style={{ width: `${(w / Math.max(totalWeight, 1)) * 100}%` }} title={`${c.label}: ${w}%`} />
                                                                );
                                                            })}
                                                        </div>
                                                        <div className="flex flex-wrap gap-2 mt-2">
                                                            {criteria.filter(c => c.label.trim()).map((c, i) => {
                                                                const colors = ['bg-blue-500', 'bg-indigo-500', 'bg-amber-500', 'bg-emerald-500', 'bg-purple-500', 'bg-cyan-500', 'bg-pink-500', 'bg-orange-500'];
                                                                return (
                                                                    <span key={i} className="flex items-center gap-1 text-[10px] text-muted-foreground">
                                                                        <span className={`h-2 w-2 rounded-full ${colors[i % colors.length]}`} />
                                                                        {c.label} ({c.weight}%)
                                                                    </span>
                                                                );
                                                            })}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>Interviewer Guidance</Label>
                                                <Textarea rows={4} value={form.data.guidance} onChange={(e) => form.setData('guidance', e.target.value)} placeholder="Instructions for interviewers using this kit..." />
                                            </div>

                                            <div className="flex justify-end gap-2">
                                                <Button type="button" variant="outline" onClick={resetForm}>Cancel</Button>
                                                <Button type="submit" disabled={form.processing}>
                                                    {form.processing ? 'Saving...' : editingKitId ? 'Update Kit' : 'Create Kit'}
                                                </Button>
                                            </div>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </div>
                    }
                />

                {/* Kit Cards */}
                {kits.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <ClipboardList className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p className="text-lg font-medium">No interview kits yet</p>
                            <p className="text-sm">Create a kit to standardize your interview process.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {kits.data.map((kit) => {
                            const colors = ['bg-blue-500', 'bg-indigo-500', 'bg-amber-500', 'bg-emerald-500', 'bg-purple-500', 'bg-cyan-500'];
                            const kitTotalWeight = kit.criteria.reduce((sum, c) => sum + (c.weight ?? 0), 0);
                            return (
                                <Card key={kit.id} className={`hover:shadow-md transition-shadow ${!kit.is_active ? 'opacity-60' : ''}`}>
                                    <CardContent className="p-5">
                                        <div className="flex items-start justify-between gap-2 mb-3">
                                            <div>
                                                <h3 className="font-semibold text-sm">{kit.name}</h3>
                                                <div className="flex items-center gap-2 mt-1">
                                                    <Badge variant="outline" className="text-xs">{kit.role || 'Any role'}</Badge>
                                                    <Badge variant={kit.is_active ? 'default' : 'secondary'} className="text-xs">
                                                        {kit.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </div>
                                            </div>
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                                <Star className="h-4 w-4 text-primary" />
                                            </div>
                                        </div>

                                        {/* Criteria Preview */}
                                        <div className="flex flex-wrap gap-1 mb-3">
                                            {kit.criteria.slice(0, 4).map((c, i) => (
                                                <Badge key={i} variant="secondary" className="text-[10px]">{c.label}</Badge>
                                            ))}
                                            {kit.criteria.length > 4 && (
                                                <Badge variant="secondary" className="text-[10px]">+{kit.criteria.length - 4} more</Badge>
                                            )}
                                        </div>

                                        {/* Weight Bar */}
                                        {kitTotalWeight > 0 && (
                                            <div className="flex h-2 rounded-full overflow-hidden bg-muted/50 mb-3">
                                                {kit.criteria.map((c, i) => (
                                                    <div key={i} className={`${colors[i % colors.length]} transition-all`}
                                                        style={{ width: `${((c.weight ?? 0) / kitTotalWeight) * 100}%` }} />
                                                ))}
                                            </div>
                                        )}

                                        <div className="flex items-center justify-between text-xs text-muted-foreground mb-3">
                                            <span>{kit.criteria.length} criteria</span>
                                            {kit.usage_count !== undefined && <span>Used {kit.usage_count} times</span>}
                                        </div>

                                        {can.manage && (
                                            <div className="flex gap-1.5 pt-3 border-t">
                                                <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => startEdit(kit)}>Edit</Button>
                                                <Button size="sm" variant={kit.is_active ? 'secondary' : 'default'} className="h-7 text-xs" onClick={() => toggleActive(kit.id)}>
                                                    {kit.is_active ? 'Deactivate' : 'Activate'}
                                                </Button>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
