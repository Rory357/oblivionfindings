import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, Check, CheckCircle2, FileText, MessageSquare, Plus, Search, Send, Trash2, User } from 'lucide-react';
import { useEffect, useState } from 'react';

type UserType = { id: number; name: string };
type TemplateQuestion = { key: string; question: string };
type Template = { id: number; name: string; description: string | null; questions: TemplateQuestion[]; is_default: boolean };
type Props = {
    employees: UserType[];
    reviewTypes: string[];
    templates: Template[];
    defaultQuestions: Record<string, string>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: '360 Feedback', href: '/hr/feedback' },
    { title: 'Request Feedback', href: '/hr/feedback/request' },
];

const reviewTypeLabels: Record<string, { label: string; desc: string }> = {
    peer: { label: 'Peer Review', desc: 'Feedback from colleagues at the same level' },
    manager: { label: 'Manager Review', desc: 'Feedback from a direct manager' },
    direct_report: { label: 'Direct Report', desc: 'Feedback from people they manage' },
    self: { label: 'Self Assessment', desc: 'Self-reflection on own performance' },
};

const AVATAR_COLORS = ['bg-status-info', 'bg-primary', 'bg-status-success', 'bg-status-warning', 'bg-status-critical', 'bg-status-info', 'bg-status-critical', 'bg-primary'];
function avatarColor(id: number) { return AVATAR_COLORS[id % AVATAR_COLORS.length]; }
function getInitials(name: string) { return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2); }
function slugify(text: string) { return text.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 100); }

export default function FeedbackRequest({ employees, reviewTypes, templates, defaultQuestions }: Props) {
    const [reviewerSearch, setReviewerSearch] = useState('');
    const [showCreateTemplate, setShowCreateTemplate] = useState(false);
    const [newTemplate, setNewTemplate] = useState({ name: '', description: '', questions: [{ key: '', question: '' }] as TemplateQuestion[] });

    const form = useForm({
        subject_user_id: '',
        reviewer_user_ids: [] as string[],
        review_type: '',
        performance_review_id: null as number | null,
        template_id: templates.find(t => t.is_default)?.id?.toString() || '',
    });

    const toggleReviewer = (id: string) => {
        const current = form.data.reviewer_user_ids;
        form.setData('reviewer_user_ids', current.includes(id) ? current.filter(r => r !== id) : [...current, id]);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            template_id: data.template_id ? parseInt(data.template_id) : null,
        }));
        form.post('/hr/feedback/request', {
            onFinish: () => form.transform((data) => data),
        });
    };

    const isSelfAssessment = form.data.review_type === 'self';

    // Auto-sync reviewer for self-assessment
    useEffect(() => {
        if (isSelfAssessment && form.data.subject_user_id) {
            form.setData('reviewer_user_ids', [form.data.subject_user_id]);
        }
    }, [form.data.review_type, form.data.subject_user_id]);

    const availableReviewers = employees
        .filter(emp => String(emp.id) !== form.data.subject_user_id)
        .filter(emp => !reviewerSearch || emp.name.toLowerCase().includes(reviewerSearch.toLowerCase()));

    const selectedSubject = employees.find(e => String(e.id) === form.data.subject_user_id);
    const selectedTemplate = templates.find(t => String(t.id) === form.data.template_id);
    const displayQuestions = selectedTemplate?.questions ?? Object.entries(defaultQuestions).map(([key, question]) => ({ key, question }));

    // Template creation helpers
    const addQuestion = () => setNewTemplate(prev => ({ ...prev, questions: [...prev.questions, { key: '', question: '' }] }));
    const removeQuestion = (i: number) => setNewTemplate(prev => ({ ...prev, questions: prev.questions.filter((_, idx) => idx !== i) }));
    const updateQuestion = (i: number, field: 'key' | 'question', value: string) => {
        setNewTemplate(prev => {
            const questions = [...prev.questions];
            questions[i] = { ...questions[i], [field]: value };
            if (field === 'question' && !questions[i].key) questions[i].key = slugify(value);
            return { ...prev, questions };
        });
    };
    const submitTemplate = () => {
        router.post('/hr/feedback/templates', newTemplate, {
            preserveScroll: true,
            onSuccess: () => { setShowCreateTemplate(false); setNewTemplate({ name: '', description: '', questions: [{ key: '', question: '' }] }); },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Request 360 Feedback" />
            <div className="space-y-6 p-4 lg:p-6">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <Button variant="outline" size="icon" className="h-9 w-9" onClick={() => router.get('/hr/feedback')}>
                        <ArrowLeft className="h-4 w-4" />
                    </Button>
                    <div>
                        <h1 className="text-xl font-bold">Request 360-Degree Feedback</h1>
                        <p className="text-sm text-muted-foreground">Select a template, employee, and reviewers</p>
                    </div>
                </div>

                <form onSubmit={submit} className="mx-auto max-w-3xl space-y-6">
                    {/* Step 1: Template Selection */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-gradient-to-r from-primary/10 to-transparent pb-3">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-white">1</div>
                                    Question Template
                                </CardTitle>
                                <Button type="button" variant="outline" size="sm" className="gap-1 text-xs" onClick={() => setShowCreateTemplate(true)}>
                                    <Plus className="h-3 w-3" />Create Template
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-4">
                            <div className="grid gap-2 sm:grid-cols-2">
                                {templates.map(t => (
                                    <button key={t.id} type="button" onClick={() => form.setData('template_id', String(t.id))}
                                        className={`flex items-start gap-3 rounded-xl border p-3 text-left transition-all ${String(t.id) === form.data.template_id ? 'border-primary bg-primary/10/50 shadow-sm ring-1 ring-ring' : 'hover:bg-muted/50 hover:border-muted-foreground/20'}`}>
                                        <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${String(t.id) === form.data.template_id ? 'bg-primary/10' : 'bg-muted'}`}>
                                            <FileText className={`h-4 w-4 ${String(t.id) === form.data.template_id ? 'text-primary' : 'text-muted-foreground'}`} />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-1.5">
                                                <p className="text-sm font-semibold truncate">{t.name}</p>
                                                {t.is_default && <Badge className="border-0 bg-primary/10 text-[8px] text-primary">Default</Badge>}
                                            </div>
                                            {t.description && <p className="mt-0.5 text-[11px] text-muted-foreground line-clamp-1">{t.description}</p>}
                                            <p className="mt-1 text-[10px] text-muted-foreground">{t.questions.length} question{t.questions.length !== 1 ? 's' : ''}</p>
                                        </div>
                                        {String(t.id) === form.data.template_id && <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-primary" />}
                                    </button>
                                ))}
                            </div>
                            {templates.length === 0 && (
                                <div className="py-6 text-center">
                                    <FileText className="mx-auto mb-2 h-8 w-8 text-foreground" />
                                    <p className="text-sm text-muted-foreground">No templates yet. The default questions will be used.</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Step 2: Employee & Type */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-gradient-to-r from-primary/10 to-transparent pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-white">2</div>
                                Select Employee & Review Type
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 pt-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Employee being reviewed *</Label>
                                    <Select value={form.data.subject_user_id} onValueChange={v => form.setData('subject_user_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select an employee" /></SelectTrigger>
                                        <SelectContent>{employees.map(emp => <SelectItem key={emp.id} value={String(emp.id)}>{emp.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                    {form.errors.subject_user_id && <p className="text-xs text-status-critical">{form.errors.subject_user_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Review Type *</Label>
                                    <Select value={form.data.review_type} onValueChange={v => form.setData('review_type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select review type" /></SelectTrigger>
                                        <SelectContent>{reviewTypes.map(type => <SelectItem key={type} value={type}>{reviewTypeLabels[type]?.label || type}</SelectItem>)}</SelectContent>
                                    </Select>
                                    {form.errors.review_type && <p className="text-xs text-status-critical">{form.errors.review_type}</p>}
                                    {form.data.review_type && reviewTypeLabels[form.data.review_type] && (
                                        <p className="text-[11px] text-muted-foreground">{reviewTypeLabels[form.data.review_type].desc}</p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Step 3: Select Reviewers */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-gradient-to-r from-status-info-bg to-transparent pb-3">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-status-info text-[11px] font-bold text-white">3</div>
                                    {isSelfAssessment ? 'Reviewer' : 'Select Reviewers'}
                                </CardTitle>
                                {!isSelfAssessment && form.data.reviewer_user_ids.length > 0 && <Badge className="bg-status-info-bg text-status-info border-0">{form.data.reviewer_user_ids.length} selected</Badge>}
                            </div>
                        </CardHeader>
                        <CardContent className="pt-4">
                            {form.errors.reviewer_user_ids && <p className="mb-3 text-xs text-status-critical">{form.errors.reviewer_user_ids}</p>}
                            {isSelfAssessment ? (
                                /* Self-assessment: auto-assigned to subject */
                                <div className="flex items-center gap-3 rounded-xl border border-status-info/30 bg-status-info-bg p-4">
                                    {selectedSubject ? (
                                        <>
                                            <div className={`flex h-10 w-10 items-center justify-center rounded-full text-xs font-bold text-white ${avatarColor(parseInt(form.data.subject_user_id) || 0)}`}>
                                                {getInitials(selectedSubject.name)}
                                            </div>
                                            <div>
                                                <p className="text-sm font-semibold">{selectedSubject.name}</p>
                                                <p className="text-[11px] text-muted-foreground">Self-assessment — the employee will review their own performance</p>
                                            </div>
                                            <CheckCircle2 className="ml-auto h-5 w-5 text-status-info" />
                                        </>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">Select an employee above — they will be assigned as their own reviewer</p>
                                    )}
                                </div>
                            ) : !form.data.subject_user_id ? (
                                <div className="flex flex-col items-center gap-2 py-8">
                                    <User className="h-8 w-8 text-foreground" />
                                    <p className="text-sm text-muted-foreground">Select an employee first to see available reviewers</p>
                                </div>
                            ) : (
                                <>
                                    <div className="relative mb-3">
                                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                        <Input placeholder="Search reviewers..." className="h-9 pl-8 text-sm" value={reviewerSearch} onChange={e => setReviewerSearch(e.target.value)} />
                                    </div>
                                    <div className="max-h-64 space-y-1.5 overflow-y-auto">
                                        {availableReviewers.map(emp => {
                                            const selected = form.data.reviewer_user_ids.includes(String(emp.id));
                                            return (
                                                <label
                                                    key={emp.id}
                                                    className={`flex cursor-pointer items-center gap-3 rounded-lg border p-2.5 transition-all ${selected ? 'border-status-info/30 bg-status-info-bg shadow-sm' : 'hover:bg-muted/50'}`}
                                                    onClick={() => toggleReviewer(String(emp.id))}
                                                >
                                                    <Checkbox
                                                        checked={selected}
                                                        onCheckedChange={() => toggleReviewer(String(emp.id))}
                                                        onClick={(event) => event.stopPropagation()}
                                                    />
                                                    <div className={`flex h-8 w-8 items-center justify-center rounded-full text-[10px] font-bold text-white ${avatarColor(emp.id)}`}>
                                                        {getInitials(emp.name)}
                                                    </div>
                                                    <span className="text-sm font-medium">{emp.name}</span>
                                                    {selected && <CheckCircle2 className="ml-auto h-4 w-4 text-status-info" />}
                                                </label>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    {/* Step 4: Questions Preview */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-gradient-to-r from-status-success-bg to-transparent pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-status-success text-[11px] font-bold text-white">4</div>
                                Review Questions
                                {selectedTemplate && <Badge variant="outline" className="text-[9px]">{selectedTemplate.name}</Badge>}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-4">
                            <p className="mb-3 text-xs text-muted-foreground">Reviewers will rate and comment on the following {displayQuestions.length} areas:</p>
                            <div className="space-y-2">
                                {displayQuestions.map((q, i) => (
                                    <div key={q.key || i} className="flex items-start gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/30">
                                        <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-status-success-bg text-[10px] font-bold text-status-success">{i + 1}</div>
                                        <div>
                                            <p className="text-sm font-medium capitalize">{q.key.replace(/_/g, ' ')}</p>
                                            <p className="text-xs text-muted-foreground">{q.question}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Summary & Submit */}
                    <div className="flex items-center justify-between rounded-xl border bg-primary/10 p-4">
                        <div className="text-sm">
                            {selectedSubject ? (
                                <p>Requesting feedback for <strong>{selectedSubject.name}</strong> from <strong>{form.data.reviewer_user_ids.length}</strong> reviewer{form.data.reviewer_user_ids.length !== 1 ? 's' : ''} using <strong>{selectedTemplate?.name || 'default questions'}</strong></p>
                            ) : (
                                <p className="text-muted-foreground">Complete the form above to send feedback requests</p>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={() => router.get('/hr/feedback')}>Cancel</Button>
                            <Button type="submit" className="gap-1.5 bg-primary hover:bg-primary" disabled={form.processing}>
                                <Send className="h-3.5 w-3.5" />Send Requests
                            </Button>
                        </div>
                    </div>
                </form>
            </div>

            {/* Create Template Dialog */}
            <Dialog open={showCreateTemplate} onOpenChange={setShowCreateTemplate}>
                <DialogContent className="sm:max-w-lg max-h-[80vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Create Question Template</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Template Name *</Label>
                            <Input value={newTemplate.name} onChange={e => setNewTemplate(prev => ({ ...prev, name: e.target.value }))} placeholder="e.g. Leadership Review" />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Description</Label>
                            <Textarea value={newTemplate.description} onChange={e => setNewTemplate(prev => ({ ...prev, description: e.target.value }))} placeholder="Brief description of when to use this template" className="min-h-[50px]" />
                        </div>
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Questions *</Label>
                                <Button type="button" variant="outline" size="sm" className="gap-1 text-xs" onClick={addQuestion}>
                                    <Plus className="h-3 w-3" />Add Question
                                </Button>
                            </div>
                            <div className="space-y-3">
                                {newTemplate.questions.map((q, i) => (
                                    <div key={i} className="flex gap-2 rounded-lg border p-3">
                                        <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">{i + 1}</div>
                                        <div className="flex-1 space-y-2">
                                            <Input value={q.question} onChange={e => updateQuestion(i, 'question', e.target.value)} placeholder="e.g. How effectively does this person communicate?" className="text-sm" />
                                            <Input value={q.key} onChange={e => updateQuestion(i, 'key', e.target.value)} placeholder="Key (auto-generated)" className="text-xs text-muted-foreground h-7" />
                                        </div>
                                        {newTemplate.questions.length > 1 && (
                                            <Button type="button" variant="ghost" size="sm" className="h-7 w-7 p-0 text-status-critical shrink-0" onClick={() => removeQuestion(i)}>
                                                <Trash2 className="h-3 w-3" />
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setShowCreateTemplate(false)}>Cancel</Button>
                        <Button type="button" className="bg-primary hover:bg-primary" disabled={!newTemplate.name || newTemplate.questions.some(q => !q.question)} onClick={submitTemplate}>
                            Create Template
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
