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
import { useState } from 'react';

interface Criterion {
    label: string;
    weight?: number;
}

interface Kit {
    id: number;
    name: string;
    role: string | null;
    criteria: Criterion[];
    guidance: string | null;
    is_active: boolean;
    created_at: string;
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
    const [editingKitId, setEditingKitId] = useState<number | null>(null);
    const form = useForm({
        name: '',
        role: '',
        criteria_text: '',
        guidance: '',
    });

    function parseCriteriaText() {
        return form.data.criteria_text
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .map((line) => {
                const parts = line.split('|').map((part) => part.trim());
                if (parts.length < 2) {
                    return { label: parts[0], weight: undefined };
                }

                const weight = Number(parts[1]);
                return {
                    label: parts[0],
                    weight: Number.isFinite(weight) ? weight : undefined,
                };
            });
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();

        const parsedCriteria = parseCriteriaText();

        form.transform((data) => ({
            name: data.name,
            role: data.role || null,
            guidance: data.guidance || null,
            criteria: parsedCriteria,
        }));

        if (editingKitId) {
            form.put(`/hr/recruitment/kits/${editingKitId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingKitId(null);
                    form.reset();
                },
            });
            return;
        }

        form.post('/hr/recruitment/kits', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    function toggleActive(kitId: number) {
        router.post(`/hr/recruitment/kits/${kitId}/toggle-active`, {}, { preserveScroll: true });
    }

    function startEdit(kit: Kit) {
        setEditingKitId(kit.id);
        form.setData({
            name: kit.name,
            role: kit.role || '',
            criteria_text: kit.criteria
                .map((criterion) => criterion.weight !== undefined ? `${criterion.label} | ${criterion.weight}` : criterion.label)
                .join('\n'),
            guidance: kit.guidance || '',
        });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'HR', href: '/hr' },
                { title: 'Recruitment', href: '/hr/recruitment' },
                { title: 'Interview Kits', href: '/hr/recruitment/kits' },
            ]}
        >
            <Head title="Interview Kits" />
            <PageShell>
                <PageHeader
                    title="Interview Kits"
                    description="Structured scorecards and interview criteria for consistent hiring decisions."
                    actions={<Button variant="outline" asChild><Link href="/hr/recruitment/jobs">Back To Jobs</Link></Button>}
                />

                {can.manage && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>{editingKitId ? 'Edit Interview Kit' : 'Create Interview Kit'}</CardTitle>
                                {editingKitId && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setEditingKitId(null);
                                            form.reset();
                                        }}
                                    >
                                        Cancel Edit
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Name</Label>
                                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Support Worker Standard Kit" />
                                        {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Role</Label>
                                        <Select value={form.data.role || '__none__'} onValueChange={(v) => form.setData('role', v === '__none__' ? '' : v)}>
                                            <SelectTrigger><SelectValue placeholder="Any role" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">Any role</SelectItem>
                                                {roles.map((role) => (
                                                    <SelectItem key={role} value={role}>{role.replace('_', ' ')}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label>Criteria (one per line)</Label>
                                    <Textarea
                                        rows={6}
                                        value={form.data.criteria_text}
                                        onChange={(e) => form.setData('criteria_text', e.target.value)}
                                        placeholder={`Communication | 25\nValues Alignment | 25\nClinical Capability | 25\nReliability | 25`}
                                    />
                                    <p className="text-xs text-muted-foreground">Use `Criterion | Weight` format. Weight is optional.</p>
                                </div>

                                <div className="space-y-2">
                                    <Label>Interviewer Guidance</Label>
                                    <Textarea rows={4} value={form.data.guidance} onChange={(e) => form.setData('guidance', e.target.value)} />
                                </div>

                                <Button type="submit" disabled={form.processing}>
                                    {form.processing ? 'Saving...' : editingKitId ? 'Update Kit' : 'Create Kit'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Kit</th>
                                    <th className="px-4 py-3 text-left font-medium">Role</th>
                                    <th className="px-4 py-3 text-left font-medium">Criteria</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {kits.data.map((kit) => (
                                    <tr key={kit.id} className="hover:bg-muted/40">
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{kit.name}</p>
                                            <p className="text-xs text-muted-foreground">Created {kit.created_at}</p>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{kit.role || 'Any role'}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{kit.criteria.length} criteria</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={kit.is_active ? 'default' : 'secondary'}>
                                                {kit.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {can.manage && (
                                                <div className="flex justify-end gap-2">
                                                    <Button size="sm" variant="outline" onClick={() => startEdit(kit)}>
                                                        Edit
                                                    </Button>
                                                    <Button size="sm" variant="outline" onClick={() => toggleActive(kit.id)}>
                                                        {kit.is_active ? 'Deactivate' : 'Activate'}
                                                    </Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {kits.data.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">No interview kits yet.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}

