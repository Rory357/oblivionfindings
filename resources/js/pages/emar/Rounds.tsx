import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle, ChevronLeft, ChevronRight, Clock, ListChecks, Pencil, Play, Plus, Trash2, Users, Zap } from 'lucide-react';
import { useState } from 'react';

type MedRound = {
    id: number;
    name: string;
    round_type: string;
    scheduled_time: string;
    window_minutes: number;
    status: string;
    total_medications: number;
    administered_count: number;
    refused_count: number;
    withheld_count: number;
    missed_count: number;
    started_at: string | null;
    completed_at: string | null;
    assigned_to: { id: number; name: string } | null;
    started_by: { id: number; name: string } | null;
    completed_by: { id: number; name: string } | null;
    notes: string | null;
};

type Template = {
    id: number;
    name: string;
    scheduled_time: string;
    window_minutes: number;
    days_of_week: number[] | null;
    active: boolean;
    default_assigned_to: { id: number; name: string } | null;
};

type Props = {
    rounds: MedRound[];
    templates: Template[];
    date: string;
    staff: { id: number; name: string }[];
    lastGenerated: string | null;
};

const statusConfig: Record<string, { color: string; icon: any }> = {
    pending: { color: 'bg-gray-100 text-gray-700', icon: Clock },
    in_progress: { color: 'bg-blue-100 text-blue-700', icon: Play },
    completed: { color: 'bg-green-100 text-green-700', icon: CheckCircle },
    partial: { color: 'bg-amber-100 text-amber-700', icon: AlertTriangle },
};

const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function EditTemplateDialog({ template, staff, open, onOpenChange }: { template: Template; staff: { id: number; name: string }[]; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({
        name: template.name ?? '',
        scheduled_time: template.scheduled_time ?? '',
        window_minutes: template.window_minutes ?? 60,
        days_of_week: template.days_of_week ?? ([] as number[]),
        default_assigned_to: template.default_assigned_to?.id?.toString() ?? '',
    });

    function toggleDay(day: number) {
        const current = form.data.days_of_week;
        if (current.includes(day)) {
            form.setData('days_of_week', current.filter((d) => d !== day));
        } else {
            form.setData('days_of_week', [...current, day].sort());
        }
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/emar/rounds/templates/${template.id}`, {
            onSuccess: () => { onOpenChange(false); form.reset(); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit Round Template</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <Label htmlFor="edit-tpl-name">Name</Label>
                        <Input id="edit-tpl-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Morning Round" />
                        {form.errors.name && <p className="mt-1 text-xs text-red-500">{form.errors.name}</p>}
                    </div>
                    <div>
                        <Label htmlFor="edit-tpl-time">Scheduled Time</Label>
                        <Input id="edit-tpl-time" type="time" value={form.data.scheduled_time} onChange={(e) => form.setData('scheduled_time', e.target.value)} />
                        {form.errors.scheduled_time && <p className="mt-1 text-xs text-red-500">{form.errors.scheduled_time}</p>}
                    </div>
                    <div>
                        <Label htmlFor="edit-tpl-window">Window (minutes)</Label>
                        <Input id="edit-tpl-window" type="number" min={0} value={form.data.window_minutes} onChange={(e) => form.setData('window_minutes', parseInt(e.target.value) || 0)} />
                        {form.errors.window_minutes && <p className="mt-1 text-xs text-red-500">{form.errors.window_minutes}</p>}
                    </div>
                    <div>
                        <Label>Default Assigned Staff</Label>
                        <Select value={form.data.default_assigned_to} onValueChange={(v) => form.setData('default_assigned_to', v)}>
                            <SelectTrigger className="mt-1">
                                <SelectValue placeholder="Unassigned" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">Unassigned</SelectItem>
                                {staff.map((s) => (
                                    <SelectItem key={s.id} value={s.id.toString()}>{s.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label>Days of Week</Label>
                        <div className="mt-2 flex flex-wrap gap-3">
                            {DAY_NAMES.map((name, idx) => {
                                const day = idx + 1;
                                return (
                                    <label key={day} className="flex items-center gap-1.5 text-sm">
                                        <Checkbox checked={form.data.days_of_week.includes(day)} onCheckedChange={() => toggleDay(day)} />
                                        {name}
                                    </label>
                                );
                            })}
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">Leave unchecked for every day.</p>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Save Changes</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Rounds({ rounds, templates, date, staff, lastGenerated }: Props) {
    const [templateOpen, setTemplateOpen] = useState(false);
    const [editTemplateOpen, setEditTemplateOpen] = useState(false);
    const [editingTemplate, setEditingTemplate] = useState<Template | null>(null);

    function openEditTemplate(template: Template) {
        setEditingTemplate(template);
        setEditTemplateOpen(true);
    }

    function deleteTemplate(id: number) {
        if (!confirm('Are you sure you want to delete this template?')) return;
        router.delete(`/emar/rounds/templates/${id}`);
    }

    function toggleTemplateActive(template: Template) {
        router.put(`/emar/rounds/templates/${template.id}`, { active: !template.active }, { preserveState: true });
    }

    const templateForm = useForm({
        name: '',
        scheduled_time: '',
        window_minutes: 60,
        days_of_week: [] as number[],
        default_assigned_to: '' as string,
    });

    function navigateDate(offset: number) {
        const d = new Date(date);
        d.setDate(d.getDate() + offset);
        router.get('/emar/rounds', { date: d.toISOString().split('T')[0] }, { preserveState: true });
    }

    function submitTemplate(e: React.FormEvent) {
        e.preventDefault();
        templateForm.post('/emar/rounds/templates', {
            onSuccess: () => {
                setTemplateOpen(false);
                templateForm.reset();
            },
        });
    }

    function generateRounds() {
        router.post('/emar/rounds/generate', { date });
    }

    function generateAllToday() {
        router.post('/emar/rounds/generate', { date: new Date().toISOString().split('T')[0], generate_all: true });
    }

    function toggleDay(day: number) {
        const current = templateForm.data.days_of_week;
        if (current.includes(day)) {
            templateForm.setData('days_of_week', current.filter((d) => d !== day));
        } else {
            templateForm.setData('days_of_week', [...current, day].sort());
        }
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medication Rounds" />
            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="Medication Rounds"
                    description="Manage daily medication administration rounds, assignments, and completion tracking"
                    icon={<Clock className="h-7 w-7 text-white" />}
                    backHref="/emar"
                    backLabel="Back"
                />
                {/* Date Navigation & Actions */}
                <div className="mb-6 flex flex-wrap items-center gap-3">
                    <Button variant="outline" size="icon" onClick={() => navigateDate(-1)}><ChevronLeft className="h-4 w-4" /></Button>
                    <Input type="date" value={date} onChange={(e) => router.get('/emar/rounds', { date: e.target.value }, { preserveState: true })} className="w-40" />
                    <Button variant="outline" size="icon" onClick={() => navigateDate(1)}><ChevronRight className="h-4 w-4" /></Button>
                    <Button variant="outline" size="sm" onClick={() => router.get('/emar/rounds', { date: new Date().toISOString().split('T')[0] })}>Today</Button>
                    <div className="ml-auto flex items-center gap-3">
                        {lastGenerated && (
                            <span className="text-xs text-muted-foreground">
                                Last generated: {new Date(lastGenerated).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                            </span>
                        )}
                        <Button variant="outline" size="sm" onClick={generateAllToday}>
                            <Zap className="mr-1 h-4 w-4" /> Generate All Today
                        </Button>
                        <Button variant="outline" size="sm" onClick={generateRounds}>
                            <Zap className="mr-1 h-4 w-4" /> Generate Rounds
                        </Button>
                        <Dialog open={templateOpen} onOpenChange={setTemplateOpen}>
                            <DialogTrigger asChild>
                                <Button size="sm"><Plus className="mr-1 h-4 w-4" /> New Template</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>New Round Template</DialogTitle>
                                </DialogHeader>
                                <form onSubmit={submitTemplate} className="space-y-4">
                                    <div>
                                        <Label htmlFor="tpl-name">Name</Label>
                                        <Input id="tpl-name" value={templateForm.data.name} onChange={(e) => templateForm.setData('name', e.target.value)} placeholder="e.g. Morning Round" />
                                        {templateForm.errors.name && <p className="mt-1 text-xs text-red-500">{templateForm.errors.name}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="tpl-time">Scheduled Time</Label>
                                        <Input id="tpl-time" type="time" value={templateForm.data.scheduled_time} onChange={(e) => templateForm.setData('scheduled_time', e.target.value)} />
                                        {templateForm.errors.scheduled_time && <p className="mt-1 text-xs text-red-500">{templateForm.errors.scheduled_time}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="tpl-window">Window (minutes)</Label>
                                        <Input id="tpl-window" type="number" min={0} value={templateForm.data.window_minutes} onChange={(e) => templateForm.setData('window_minutes', parseInt(e.target.value) || 0)} />
                                        {templateForm.errors.window_minutes && <p className="mt-1 text-xs text-red-500">{templateForm.errors.window_minutes}</p>}
                                    </div>
                                    <div>
                                        <Label>Default Assigned Staff</Label>
                                        <Select value={templateForm.data.default_assigned_to} onValueChange={(v) => templateForm.setData('default_assigned_to', v)}>
                                            <SelectTrigger className="mt-1">
                                                <SelectValue placeholder="Unassigned" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="">Unassigned</SelectItem>
                                                {staff.map((s) => (
                                                    <SelectItem key={s.id} value={s.id.toString()}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Days of Week</Label>
                                        <div className="mt-2 flex flex-wrap gap-3">
                                            {DAY_NAMES.map((name, idx) => {
                                                const day = idx + 1;
                                                return (
                                                    <label key={day} className="flex items-center gap-1.5 text-sm">
                                                        <Checkbox checked={templateForm.data.days_of_week.includes(day)} onCheckedChange={() => toggleDay(day)} />
                                                        {name}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">Leave unchecked for every day.</p>
                                    </div>
                                    <div className="flex justify-end gap-2">
                                        <Button type="button" variant="outline" onClick={() => setTemplateOpen(false)}>Cancel</Button>
                                        <Button type="submit" disabled={templateForm.processing}>Create Template</Button>
                                    </div>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                {/* Rounds Grid */}
                <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {rounds.map((round) => {
                        const cfg = statusConfig[round.status] ?? statusConfig.pending;
                        const Icon = cfg.icon;
                        const completionPct = round.total_medications > 0 ? Math.round((round.administered_count / round.total_medications) * 100) : 0;
                        return (
                            <Card key={round.id}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">{round.name}</CardTitle>
                                        <Badge className={`text-xs ${cfg.color}`}>
                                            <Icon className="mr-1 h-3 w-3" /> {round.status}
                                        </Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground">{round.scheduled_time} ({round.round_type})</p>
                                </CardHeader>
                                <CardContent>
                                    <div className="mb-3">
                                        <div className="mb-1 flex justify-between text-xs text-muted-foreground">
                                            <span>Progress</span>
                                            <span>{round.administered_count} / {round.total_medications}</span>
                                        </div>
                                        <Progress value={completionPct} className="h-2" />
                                    </div>
                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center text-xs">
                                        <div>
                                            <p className="font-bold text-green-600">{round.administered_count}</p>
                                            <p className="text-muted-foreground">Given</p>
                                        </div>
                                        <div>
                                            <p className="font-bold text-orange-500">{round.refused_count}</p>
                                            <p className="text-muted-foreground">Refused</p>
                                        </div>
                                        <div>
                                            <p className="font-bold text-amber-500">{round.withheld_count}</p>
                                            <p className="text-muted-foreground">Withheld</p>
                                        </div>
                                        <div>
                                            <p className="font-bold text-red-500">{round.missed_count}</p>
                                            <p className="text-muted-foreground">Missed</p>
                                        </div>
                                    </div>
                                    {round.assigned_to && (
                                        <div className="mt-3 flex items-center gap-1 text-xs text-muted-foreground">
                                            <Users className="h-3 w-3" /> {round.assigned_to.name}
                                        </div>
                                    )}
                                    {round.started_at && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Started: {new Date(round.started_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}
                                            {round.completed_at && ` — Completed: ${new Date(round.completed_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' })}`}
                                        </p>
                                    )}
                                    {/* Round Actions */}
                                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t pt-3">
                                        {round.status !== 'completed' && (
                                            <Button size="sm" asChild>
                                                <Link href={`/emar/rounds/${round.id}/guided`}>
                                                    <ListChecks className="mr-1 h-3 w-3" />
                                                    {round.status === 'in_progress' ? 'Resume round' : 'Start guided round'}
                                                </Link>
                                            </Button>
                                        )}
                                        {round.status === 'pending' && (
                                            <Button size="sm" variant="outline" onClick={() => router.post(`/emar/rounds/${round.id}/start`)}>
                                                <Play className="mr-1 h-3 w-3" /> Mark started
                                            </Button>
                                        )}
                                        {round.status === 'in_progress' && (
                                            <Button size="sm" variant="outline" onClick={() => router.post(`/emar/rounds/${round.id}/complete`)}>
                                                <CheckCircle className="mr-1 h-3 w-3" /> Complete
                                            </Button>
                                        )}
                                        <Select
                                            value={round.assigned_to?.id?.toString() ?? ''}
                                            onValueChange={(v) => router.put(`/emar/rounds/${round.id}/assign`, { assigned_to: parseInt(v) })}
                                        >
                                            <SelectTrigger className="h-8 w-36 text-xs">
                                                <SelectValue placeholder="Assign to..." />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {staff.map((s) => (
                                                    <SelectItem key={s.id} value={s.id.toString()}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                    {rounds.length === 0 && (
                        <Card className="sm:col-span-2 lg:col-span-3">
                            <CardContent className="flex flex-col items-center py-12">
                                <Clock className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <p className="text-muted-foreground">No medication rounds scheduled for this date.</p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Round Templates */}
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Round Templates</CardTitle>
                            <p className="text-xs text-muted-foreground">Auto-generation runs daily at 00:05 NZT for active templates</p>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">Name</th>
                                    <th className="p-3 text-left font-medium">Time</th>
                                    <th className="p-3 text-left font-medium">Window</th>
                                    <th className="p-3 text-left font-medium">Days</th>
                                    <th className="p-3 text-left font-medium">Default Staff</th>
                                    <th className="p-3 text-center font-medium">Auto-Generate</th>
                                    <th className="p-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {templates.map((t) => {
                                    const dayLabels = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                    return (
                                        <tr key={t.id} className="border-b last:border-0">
                                            <td className="p-3 font-medium">{t.name}</td>
                                            <td className="p-3">{t.scheduled_time}</td>
                                            <td className="p-3">&plusmn;{t.window_minutes} min</td>
                                            <td className="p-3 text-xs">{t.days_of_week && t.days_of_week.length > 0 ? t.days_of_week.map((d) => dayLabels[d]).join(', ') : 'Every day'}</td>
                                            <td className="p-3 text-xs text-muted-foreground">{t.default_assigned_to?.name ?? 'Unassigned'}</td>
                                            <td className="p-3 text-center">
                                                <Switch
                                                    checked={t.active}
                                                    onCheckedChange={() => toggleTemplateActive(t)}
                                                    aria-label={`Toggle auto-generate for ${t.name}`}
                                                />
                                            </td>
                                            <td className="p-3 text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button variant="ghost" size="icon" onClick={() => openEditTemplate(t)}>
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="icon" className="text-red-600 hover:text-red-700" onClick={() => deleteTemplate(t.id)}>
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {templates.length === 0 && <tr><td colSpan={7} className="p-6 text-center text-muted-foreground">No round templates configured.</td></tr>}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            {editingTemplate && (
                <EditTemplateDialog
                    template={editingTemplate}
                    staff={staff}
                    open={editTemplateOpen}
                    onOpenChange={(open) => { setEditTemplateOpen(open); if (!open) setEditingTemplate(null); }}
                />
            )}
        </AppLayout>
    );
}
