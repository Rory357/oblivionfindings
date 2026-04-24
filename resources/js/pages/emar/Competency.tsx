import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Award, CheckCircle, Clock, Pencil, Plus, Trash2, UserX, XCircle } from 'lucide-react';
import { useState } from 'react';

type Props = {
    assessments: { data: any[]; links: any };
    expiringSoon: any[];
    expired: any[];
    staffWithoutAssessment: { id: number; name: string; email: string }[];
    staff: { id: number; name: string }[];
    filters: { status?: string };
};

const statusConfig: Record<string, { icon: any; color: string }> = {
    passed: { icon: CheckCircle, color: 'text-green-600' },
    failed: { icon: XCircle, color: 'text-red-600' },
    pending: { icon: Clock, color: 'text-amber-600' },
    in_progress: { icon: Clock, color: 'text-blue-600' },
    expired: { icon: AlertTriangle, color: 'text-red-600' },
};

const competencyFields = [
    { key: 'medication_knowledge', label: 'Medication Knowledge' },
    { key: 'five_rights', label: 'Five Rights' },
    { key: 'safety_checks', label: 'Safety Checks' },
    { key: 'documentation', label: 'Documentation' },
    { key: 'controlled_drugs', label: 'Controlled Drugs' },
    { key: 'prn_assessment', label: 'PRN Assessment' },
    { key: 'insulin_competent', label: 'Insulin Competent' },
    { key: 'inhaler_competent', label: 'Inhaler Competent' },
    { key: 'topical_competent', label: 'Topical Competent' },
    { key: 'covert_admin_knowledge', label: 'Covert Admin Knowledge' },
    { key: 'error_reporting', label: 'Error Reporting' },
    { key: 'allergy_awareness', label: 'Allergy Awareness' },
] as const;

function getDefaultExpiryDate() {
    const d = new Date();
    d.setFullYear(d.getFullYear() + 1);
    return d.toISOString().split('T')[0];
}

function getTodayDate() {
    return new Date().toISOString().split('T')[0];
}

function NewAssessmentDialog({ staff, staffWithoutAssessment }: { staff: Props['staff']; staffWithoutAssessment: Props['staffWithoutAssessment'] }) {
    const [open, setOpen] = useState(false);

    // Combine staffWithoutAssessment + all staff, deduplicate
    const allStaffOptions = (() => {
        const map = new Map<number, { id: number; name: string }>();
        staffWithoutAssessment.forEach((s) => map.set(s.id, { id: s.id, name: s.name }));
        staff.forEach((s) => map.set(s.id, s));
        return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
    })();

    const form = useForm<Record<string, any>>({
        user_id: '',
        assessment_type: '',
        assessment_date: getTodayDate(),
        expiry_date: getDefaultExpiryDate(),
        medication_knowledge: false,
        five_rights: false,
        safety_checks: false,
        documentation: false,
        controlled_drugs: false,
        prn_assessment: false,
        insulin_competent: false,
        inhaler_competent: false,
        topical_competent: false,
        covert_admin_knowledge: false,
        error_reporting: false,
        allergy_awareness: false,
        can_administer_unsupervised: false,
        can_witness_controlled: false,
        strengths: '',
        areas_for_improvement: '',
        assessor_comments: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/competency', {
            onSuccess: () => { setOpen(false); form.reset(); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button><Plus className="mr-1 h-4 w-4" /> New Assessment</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>New Competency Assessment</DialogTitle>
                    <DialogDescription>
                        Record a staff member&apos;s medication competency,
                        permissions, and renewal dates.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Staff Member</Label>
                            <Select value={form.data.user_id} onValueChange={(v) => form.setData('user_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                <SelectContent>
                                    {allStaffOptions.map((s) => (
                                        <SelectItem key={s.id} value={s.id.toString()}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.user_id && <p className="text-sm text-red-600">{form.errors.user_id}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label>Assessment Type</Label>
                            <Select value={form.data.assessment_type} onValueChange={(v) => form.setData('assessment_type', v)}>
                                <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="initial">Initial</SelectItem>
                                    <SelectItem value="annual">Annual</SelectItem>
                                    <SelectItem value="refresher">Refresher</SelectItem>
                                    <SelectItem value="remedial">Remedial</SelectItem>
                                </SelectContent>
                            </Select>
                            {form.errors.assessment_type && <p className="text-sm text-red-600">{form.errors.assessment_type}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label>Assessment Date</Label>
                            <Input type="date" value={form.data.assessment_date} onChange={(e) => form.setData('assessment_date', e.target.value)} />
                            {form.errors.assessment_date && <p className="text-sm text-red-600">{form.errors.assessment_date}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label>Expiry Date</Label>
                            <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                            {form.errors.expiry_date && <p className="text-sm text-red-600">{form.errors.expiry_date}</p>}
                        </div>
                    </div>

                    {/* Competency Checkboxes */}
                    <div className="space-y-2">
                        <Label className="text-base font-semibold">Competencies</Label>
                        <div className="grid grid-cols-2 gap-3">
                            {competencyFields.map((field) => (
                                <div key={field.key} className="flex items-center gap-2">
                                    <Checkbox
                                        id={`comp-${field.key}`}
                                        checked={form.data[field.key] as boolean}
                                        onCheckedChange={(checked) => form.setData(field.key, checked === true)}
                                    />
                                    <Label htmlFor={`comp-${field.key}`} className="text-sm font-normal">{field.label}</Label>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Permission Checkboxes */}
                    <div className="space-y-3 rounded-lg border p-3">
                        <Label className="text-base font-semibold">Permissions</Label>
                        <div className="flex flex-col gap-3">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="comp-unsupervised"
                                    checked={form.data.can_administer_unsupervised as boolean}
                                    onCheckedChange={(checked) => form.setData('can_administer_unsupervised', checked === true)}
                                />
                                <Label htmlFor="comp-unsupervised" className="text-sm font-normal">Can administer unsupervised</Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="comp-witness"
                                    checked={form.data.can_witness_controlled as boolean}
                                    onCheckedChange={(checked) => form.setData('can_witness_controlled', checked === true)}
                                />
                                <Label htmlFor="comp-witness" className="text-sm font-normal">Can witness controlled drugs</Label>
                            </div>
                        </div>
                    </div>

                    {/* Text Areas */}
                    <div className="space-y-2">
                        <Label>Strengths</Label>
                        <Textarea value={form.data.strengths} onChange={(e) => form.setData('strengths', e.target.value)} placeholder="Staff strengths observed..." rows={3} />
                    </div>

                    <div className="space-y-2">
                        <Label>Areas for Improvement</Label>
                        <Textarea value={form.data.areas_for_improvement} onChange={(e) => form.setData('areas_for_improvement', e.target.value)} placeholder="Areas requiring further development..." rows={3} />
                    </div>

                    <div className="space-y-2">
                        <Label>Assessor Comments</Label>
                        <Textarea value={form.data.assessor_comments} onChange={(e) => form.setData('assessor_comments', e.target.value)} placeholder="Additional assessor notes..." rows={3} />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Save Assessment</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditAssessmentDialog({ assessment, staff, staffWithoutAssessment, open, onOpenChange }: { assessment: any; staff: Props['staff']; staffWithoutAssessment: Props['staffWithoutAssessment']; open: boolean; onOpenChange: (open: boolean) => void }) {
    const allStaffOptions = (() => {
        const map = new Map<number, { id: number; name: string }>();
        staffWithoutAssessment.forEach((s) => map.set(s.id, { id: s.id, name: s.name }));
        staff.forEach((s) => map.set(s.id, s));
        return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
    })();

    const form = useForm<Record<string, any>>({
        user_id: assessment.user_id?.toString() ?? '',
        assessment_type: assessment.assessment_type ?? '',
        assessment_date: assessment.assessment_date ? assessment.assessment_date.split('T')[0] : '',
        expiry_date: assessment.expiry_date ? assessment.expiry_date.split('T')[0] : '',
        medication_knowledge: assessment.medication_knowledge ?? false,
        five_rights: assessment.five_rights ?? false,
        safety_checks: assessment.safety_checks ?? false,
        documentation: assessment.documentation ?? false,
        controlled_drugs: assessment.controlled_drugs ?? false,
        prn_assessment: assessment.prn_assessment ?? false,
        insulin_competent: assessment.insulin_competent ?? false,
        inhaler_competent: assessment.inhaler_competent ?? false,
        topical_competent: assessment.topical_competent ?? false,
        covert_admin_knowledge: assessment.covert_admin_knowledge ?? false,
        error_reporting: assessment.error_reporting ?? false,
        allergy_awareness: assessment.allergy_awareness ?? false,
        can_administer_unsupervised: assessment.can_administer_unsupervised ?? false,
        can_witness_controlled: assessment.can_witness_controlled ?? false,
        strengths: assessment.strengths ?? '',
        areas_for_improvement: assessment.areas_for_improvement ?? '',
        assessor_comments: assessment.assessor_comments ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/emar/competency/${assessment.id}`, {
            onSuccess: () => { onOpenChange(false); form.reset(); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Edit Competency Assessment</DialogTitle>
                    <DialogDescription>
                        Update the competency outcome, permissions, or renewal
                        details for this assessment.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Staff Member</Label>
                            <Select value={form.data.user_id} onValueChange={(v) => form.setData('user_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                <SelectContent>
                                    {allStaffOptions.map((s) => (
                                        <SelectItem key={s.id} value={s.id.toString()}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.user_id && <p className="text-sm text-red-600">{form.errors.user_id}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label>Assessment Type</Label>
                            <Select value={form.data.assessment_type} onValueChange={(v) => form.setData('assessment_type', v)}>
                                <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="initial">Initial</SelectItem>
                                    <SelectItem value="annual">Annual</SelectItem>
                                    <SelectItem value="refresher">Refresher</SelectItem>
                                    <SelectItem value="remedial">Remedial</SelectItem>
                                </SelectContent>
                            </Select>
                            {form.errors.assessment_type && <p className="text-sm text-red-600">{form.errors.assessment_type}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label>Assessment Date</Label>
                            <Input type="date" value={form.data.assessment_date} onChange={(e) => form.setData('assessment_date', e.target.value)} />
                            {form.errors.assessment_date && <p className="text-sm text-red-600">{form.errors.assessment_date}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label>Expiry Date</Label>
                            <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                            {form.errors.expiry_date && <p className="text-sm text-red-600">{form.errors.expiry_date}</p>}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label className="text-base font-semibold">Competencies</Label>
                        <div className="grid grid-cols-2 gap-3">
                            {competencyFields.map((field) => (
                                <div key={field.key} className="flex items-center gap-2">
                                    <Checkbox
                                        id={`edit-comp-${field.key}`}
                                        checked={form.data[field.key] as boolean}
                                        onCheckedChange={(checked) => form.setData(field.key, checked === true)}
                                    />
                                    <Label htmlFor={`edit-comp-${field.key}`} className="text-sm font-normal">{field.label}</Label>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-3 rounded-lg border p-3">
                        <Label className="text-base font-semibold">Permissions</Label>
                        <div className="flex flex-col gap-3">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="edit-comp-unsupervised"
                                    checked={form.data.can_administer_unsupervised as boolean}
                                    onCheckedChange={(checked) => form.setData('can_administer_unsupervised', checked === true)}
                                />
                                <Label htmlFor="edit-comp-unsupervised" className="text-sm font-normal">Can administer unsupervised</Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="edit-comp-witness"
                                    checked={form.data.can_witness_controlled as boolean}
                                    onCheckedChange={(checked) => form.setData('can_witness_controlled', checked === true)}
                                />
                                <Label htmlFor="edit-comp-witness" className="text-sm font-normal">Can witness controlled drugs</Label>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>Strengths</Label>
                        <Textarea value={form.data.strengths} onChange={(e) => form.setData('strengths', e.target.value)} placeholder="Staff strengths observed..." rows={3} />
                    </div>

                    <div className="space-y-2">
                        <Label>Areas for Improvement</Label>
                        <Textarea value={form.data.areas_for_improvement} onChange={(e) => form.setData('areas_for_improvement', e.target.value)} placeholder="Areas requiring further development..." rows={3} />
                    </div>

                    <div className="space-y-2">
                        <Label>Assessor Comments</Label>
                        <Textarea value={form.data.assessor_comments} onChange={(e) => form.setData('assessor_comments', e.target.value)} placeholder="Additional assessor notes..." rows={3} />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Save Changes</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Competency({ assessments, expiringSoon, expired, staffWithoutAssessment, staff, filters }: Props) {
    const { auth } = usePage().props as any;
    const canManageCompetency = auth?.can?.medications?.ordersManage ?? false;
    const [editOpen, setEditOpen] = useState(false);
    const [editingAssessment, setEditingAssessment] = useState<any>(null);

    function openEditAssessment(assessment: any) {
        setEditingAssessment(assessment);
        setEditOpen(true);
    }
    function deleteAssessment(id: number) {
        if (!confirm('Are you sure you want to delete this assessment?')) return;
        router.delete(`/emar/competency/${id}`);
    }

    return (
        <AppLayout>
            <Head title="eMAR - Competency" />
            <PageHeader title="Medication Competency" description="Staff competency assessments for medication administration. Track certifications, renewals, and compliance." backHref="/emar" />
            <PageShell>
                {/* Stats */}
                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-700 dark:bg-red-900/40"><XCircle className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{expired.length}</p><p className="text-xs text-muted-foreground">Expired Assessments</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-900/40"><Clock className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{expiringSoon.length}</p><p className="text-xs text-muted-foreground">Expiring Within 30 Days</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-muted text-foreground dark:bg-muted/40"><UserX className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{staffWithoutAssessment.length}</p><p className="text-xs text-muted-foreground">Staff Without Assessment</p></div>
                        </CardContent>
                    </Card>
                </div>

                {/* New Assessment Button */}
                {canManageCompetency && (
                    <div className="mb-4 flex justify-end">
                        <NewAssessmentDialog staff={staff} staffWithoutAssessment={staffWithoutAssessment} />
                    </div>
                )}

                <Tabs defaultValue="assessments">
                    <TabsList className="mb-4">
                        <TabsTrigger value="assessments"><Award className="mr-1 h-3.5 w-3.5" /> All Assessments</TabsTrigger>
                        <TabsTrigger value="expiring"><Clock className="mr-1 h-3.5 w-3.5" /> Expiring Soon ({expiringSoon.length})</TabsTrigger>
                        <TabsTrigger value="unassessed"><UserX className="mr-1 h-3.5 w-3.5" /> Unassessed Staff ({staffWithoutAssessment.length})</TabsTrigger>
                    </TabsList>

                    <TabsContent value="assessments">
                        <div className="mb-4 flex gap-3">
                            <Select value={filters.status ?? ''} onValueChange={(v) => router.get('/emar/competency', { status: v || undefined }, { preserveState: true })}>
                                <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="passed">Passed</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="p-3 text-left font-medium">Staff Member</th>
                                            <th className="p-3 text-left font-medium">Type</th>
                                            <th className="p-3 text-left font-medium">Date</th>
                                            <th className="p-3 text-left font-medium">Status</th>
                                            <th className="p-3 text-left font-medium">Expiry</th>
                                            <th className="p-3 text-left font-medium">Assessor</th>
                                            <th className="p-3 text-left font-medium">Permissions</th>
                                            {canManageCompetency && <th className="p-3 text-left font-medium">Actions</th>}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {assessments.data.map((a: any) => {
                                            const cfg = statusConfig[a.status] ?? statusConfig.pending;
                                            const Icon = cfg.icon;
                                            return (
                                                <tr key={a.id} className="border-b last:border-0">
                                                    <td className="p-3 font-medium">{a.user?.name}</td>
                                                    <td className="p-3"><Badge variant="outline" className="text-xs">{a.assessment_type}</Badge></td>
                                                    <td className="p-3 text-xs">{a.assessment_date ? new Date(a.assessment_date).toLocaleDateString('en-NZ') : '—'}</td>
                                                    <td className="p-3"><div className="flex items-center gap-1"><Icon className={`h-4 w-4 ${cfg.color}`} /><span className="text-xs">{a.status}</span></div></td>
                                                    <td className="p-3 text-xs">
                                                        {a.expiry_date ? new Date(a.expiry_date).toLocaleDateString('en-NZ') : '—'}
                                                        {a.expiry_date && new Date(a.expiry_date) < new Date() && <Badge variant="destructive" className="ml-1 text-[10px]">Expired</Badge>}
                                                    </td>
                                                    <td className="p-3 text-xs">{a.assessor?.name ?? '—'}</td>
                                                    <td className="p-3">
                                                        <div className="flex gap-1">
                                                            {a.can_administer_unsupervised && <Badge className="bg-green-100 text-green-700 text-[10px]">Unsupervised</Badge>}
                                                            {a.can_witness_controlled && <Badge className="bg-blue-100 text-blue-700 text-[10px]">CD Witness</Badge>}
                                                        </div>
                                                    </td>
                                                    {canManageCompetency && (
                                                        <td className="p-3">
                                                            <div className="flex items-center gap-1">
                                                                <Button size="icon" variant="ghost" onClick={() => openEditAssessment(a)}>
                                                                    <Pencil className="h-3.5 w-3.5" />
                                                                </Button>
                                                                <Button size="icon" variant="ghost" className="text-red-600 hover:text-red-700" onClick={() => deleteAssessment(a.id)}>
                                                                    <Trash2 className="h-3.5 w-3.5" />
                                                                </Button>
                                                            </div>
                                                        </td>
                                                    )}
                                                </tr>
                                            );
                                        })}
                                        {assessments.data.length === 0 && <tr><td colSpan={canManageCompetency ? 8 : 7} className="p-6 text-center text-muted-foreground">No assessments found.</td></tr>}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="expiring">
                        <Card>
                            <CardContent className="p-0">
                                <div className="divide-y">
                                    {expiringSoon.map((a: any) => (
                                        <div key={a.id} className="flex items-center justify-between p-3">
                                            <span className="font-medium">{a.user?.name}</span>
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm">
                                                    Expires: <span className="font-medium text-amber-600">{a.expiry_date ? new Date(a.expiry_date).toLocaleDateString('en-NZ') : '—'}</span>
                                                </span>
                                                {canManageCompetency && (
                                                    <Button size="sm" variant="ghost" className="text-red-600 hover:text-red-700" onClick={() => deleteAssessment(a.id)}>
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                    {expiringSoon.length === 0 && <div className="p-6 text-center text-muted-foreground">No assessments expiring soon.</div>}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="unassessed">
                        <Card>
                            <CardContent className="p-0">
                                <div className="divide-y">
                                    {staffWithoutAssessment.map((s) => (
                                        <div key={s.id} className="flex items-center justify-between p-3">
                                            <div>
                                                <span className="font-medium">{s.name}</span>
                                                <span className="ml-2 text-xs text-muted-foreground">{s.email}</span>
                                            </div>
                                            <Badge variant="destructive" className="text-xs">No Active Assessment</Badge>
                                        </div>
                                    ))}
                                    {staffWithoutAssessment.length === 0 && <div className="p-6 text-center text-muted-foreground">All active staff have assessments.</div>}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </PageShell>

            {canManageCompetency && editingAssessment && (
                <EditAssessmentDialog
                    assessment={editingAssessment}
                    staff={staff}
                    staffWithoutAssessment={staffWithoutAssessment}
                    open={editOpen}
                    onOpenChange={(open) => { setEditOpen(open); if (!open) setEditingAssessment(null); }}
                />
            )}
        </AppLayout>
    );
}
