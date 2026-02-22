import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { ClipboardCheck, Plus, X, Calendar, CheckCircle2, AlertCircle, Clock } from 'lucide-react';
import { useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Schedule = {
    id: number;
    inspection_type: string;
    title: string;
    description?: string;
    frequency: string;
    next_due_date: string;
    assigned_to?: { id: number; name: string } | null;
    is_active: boolean;
};

type InspectionRecord = {
    id: number;
    due_date: string;
    completed_at?: string;
    completed_by?: { id: number; name: string } | null;
    result?: 'pass' | 'fail' | 'partial' | 'na';
    findings?: string;
};

type Props = {
    site: Site;
    schedules: Schedule[];
    records: {
        data: InspectionRecord[];
    };
};

const frequencyLabels: Record<string, string> = {
    weekly: 'Weekly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    bi_annual: 'Bi-annual',
    annual: 'Annual',
    custom: 'Custom',
};

const resultColors: Record<string, string> = {
    pass: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
    fail: 'border-red-500/30 text-red-400 bg-red-500/10',
    partial: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
    na: 'border-slate-500/30 text-slate-400',
};

export default function SiteInspections({ site, schedules, records }: Props) {
    const [showForm, setShowForm] = useState(false);

    const form = useForm({
        inspection_type: '',
        title: '',
        description: '',
        frequency: 'monthly' as const,
        first_due_date: '',
        assigned_to_user_id: '',
        auto_create_calendar_event: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${site.id}/inspections`, {
            onSuccess: () => {
                setShowForm(false);
                form.reset();
            },
        });
    };

    const isOverdue = (date: string) => new Date(date) < new Date();

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Inspections', href: `/sites/${site.id}/inspections` }]}>
            <Head title={`${site.name} - Inspections`} />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <ClipboardCheck className="w-5 h-5" />
                            Inspections & Maintenance
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <Button onClick={() => setShowForm(true)}>
                        <Plus className="w-4 h-4 mr-1" />
                        Schedule Inspection
                    </Button>
                </div>

                {/* Add Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Schedule New Inspection</CardTitle>
                            <Button variant="ghost" size="sm" onClick={() => setShowForm(false)}>
                                <X className="w-4 h-4" />
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Inspection Type *</Label>
                                        <Input
                                            value={form.data.inspection_type}
                                            onChange={(e) => form.setData('inspection_type', e.target.value)}
                                            placeholder="e.g., Fire Safety, Electrical"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Title *</Label>
                                        <Input
                                            value={form.data.title}
                                            onChange={(e) => form.setData('title', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Frequency *</Label>
                                        <Select
                                            value={form.data.frequency}
                                            onValueChange={(v) => form.setData('frequency', v as any)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="weekly">Weekly</SelectItem>
                                                <SelectItem value="monthly">Monthly</SelectItem>
                                                <SelectItem value="quarterly">Quarterly</SelectItem>
                                                <SelectItem value="bi_annual">Bi-annual</SelectItem>
                                                <SelectItem value="annual">Annual</SelectItem>
                                                <SelectItem value="custom">Custom</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>First Due Date *</Label>
                                        <Input
                                            type="date"
                                            value={form.data.first_due_date}
                                            onChange={(e) => form.setData('first_due_date', e.target.value)}
                                            required
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        rows={3}
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={form.data.auto_create_calendar_event}
                                        onChange={(e) => form.setData('auto_create_calendar_event', e.target.checked)}
                                    />
                                    <Label className="font-normal">Create calendar event</Label>
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={form.processing}>
                                        Schedule
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => setShowForm(false)}>
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Active Schedules */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base flex items-center gap-2">
                            <Calendar className="w-4 h-4" />
                            Active Schedules
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {schedules.length === 0 ? (
                            <p className="text-center text-slate-400 py-4">No inspection schedules</p>
                        ) : (
                            <div className="space-y-2">
                                {schedules.map((schedule) => (
                                    <div key={schedule.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50">
                                        <div>
                                            <div className="font-medium">{schedule.title}</div>
                                            <div className="text-sm text-slate-400">
                                                {schedule.inspection_type} - {frequencyLabels[schedule.frequency]}
                                            </div>
                                            {schedule.assigned_to && (
                                                <div className="text-xs text-slate-500">
                                                    Assigned: {schedule.assigned_to.name}
                                                </div>
                                            )}
                                        </div>
                                        <div className="text-right">
                                            <div className={`text-sm flex items-center gap-1 ${isOverdue(schedule.next_due_date) ? 'text-red-400' : 'text-slate-300'}`}>
                                                {isOverdue(schedule.next_due_date) ? <AlertCircle className="w-4 h-4" /> : <Clock className="w-4 h-4" />}
                                                Due: {new Date(schedule.next_due_date).toLocaleDateString()}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Recent Records */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base flex items-center gap-2">
                            <CheckCircle2 className="w-4 h-4" />
                            Recent Records
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {records.data.length === 0 ? (
                            <p className="text-center text-slate-400 py-4">No inspection records yet</p>
                        ) : (
                            <div className="space-y-2">
                                {records.data.slice(0, 10).map((record) => (
                                    <div key={record.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50">
                                        <div>
                                            <div className="font-medium">{record.due_date}</div>
                                            {record.findings && (
                                                <div className="text-sm text-slate-400">{record.findings}</div>
                                            )}
                                        </div>
                                        {record.result && (
                                            <Badge className={resultColors[record.result]}>
                                                {record.result.toUpperCase()}
                                            </Badge>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
