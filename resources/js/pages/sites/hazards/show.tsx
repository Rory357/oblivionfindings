import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { 
    ShieldAlert, 
    AlertTriangle, 
    AlertCircle, 
    Clock, 
    CheckCircle2,
    ArrowLeft,
    User,
    Calendar
} from 'lucide-react';
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

type UserType = {
    id: number;
    name: string;
};

type Hazard = {
    id: number;
    reference_number: string;
    site: Site;
    hazard_type: string;
    custom_hazard_type?: string;
    severity: string;
    likelihood: string;
    risk_rating: string;
    description: string;
    photo_paths?: string[];
    immediate_action_applied: boolean;
    immediate_action_taken?: string;
    reported_by: UserType;
    assigned_to?: UserType | null;
    assigned_at?: string;
    status: 'open' | 'in_progress' | 'mitigated' | 'closed';
    due_date?: string;
    resolution_summary?: string;
    closed_at?: string;
    created_at: string;
};

type Props = {
    hazard: Hazard;
    users: UserType[];
    canAssign: boolean;
    canClose: boolean;
};

const severityColors: Record<string, string> = {
    low: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
    medium: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
    high: 'border-orange-500/30 text-orange-400 bg-orange-500/10',
    critical: 'border-red-500/30 text-red-400 bg-red-500/10',
};

const statusColors: Record<string, string> = {
    open: 'border-red-500/30 text-red-400',
    in_progress: 'border-blue-500/30 text-blue-400',
    mitigated: 'border-emerald-500/30 text-emerald-400',
    closed: 'border-slate-500/30 text-slate-400',
};

const statusIcons = {
    open: AlertCircle,
    in_progress: Clock,
    mitigated: CheckCircle2,
    closed: CheckCircle2,
};

export default function HazardShow({ hazard, users, canAssign, canClose }: Props) {
    const [showAssignForm, setShowAssignForm] = useState(false);
    const [showCloseForm, setShowCloseForm] = useState(false);

    const assignForm = useForm({
        assigned_to_user_id: hazard.assigned_to?.id?.toString() || '',
    });

    const closeForm = useForm({
        resolution_summary: '',
    });

    const StatusIcon = statusIcons[hazard.status];

    const isOverdue = hazard.due_date && new Date(hazard.due_date) < new Date() && 
        !['closed', 'mitigated'].includes(hazard.status);

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: hazard.site.name, href: `/sites/${hazard.site.id}` }, { title: 'Hazards', href: `/sites/${hazard.site.id}/hazards` }, { title: hazard.reference_number, href: `/hazards/${hazard.id}` }]}>
            <Head title={`Hazard ${hazard.reference_number}`} />

            <div className="m-4 max-w-4xl">
                <Button asChild variant="ghost" size="sm" className="mb-4">
                    <Link href={`/sites/${hazard.site.id}/hazards`}>
                        <ArrowLeft className="w-4 h-4 mr-1" />
                        Back to Hazards
                    </Link>
                </Button>

                {/* Header */}
                <div className="flex items-start justify-between mb-6">
                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            <span className="font-mono text-lg text-slate-400">{hazard.reference_number}</span>
                            <Badge variant="outline" className={severityColors[hazard.severity]}>
                                {hazard.severity}
                            </Badge>
                            <Badge variant="outline" className={statusColors[hazard.status]}>
                                <StatusIcon className="w-3 h-3 mr-1" />
                                {hazard.status.replace('_', ' ')}
                            </Badge>
                            {isOverdue && (
                                <Badge variant="outline" className="border-red-500/50 text-red-400">
                                    <AlertTriangle className="w-3 h-3 mr-1" />
                                    Overdue
                                </Badge>
                            )}
                        </div>
                        <h1 className="text-xl font-semibold">
                            {hazard.custom_hazard_type || hazard.hazard_type}
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            {hazard.site.name} • Logged by {hazard.reported_by.name} on {new Date(hazard.created_at).toLocaleDateString()}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {hazard.status !== 'closed' && canAssign && (
                            <Button variant="outline" onClick={() => setShowAssignForm(!showAssignForm)}>
                                <User className="w-4 h-4 mr-1" />
                                {hazard.assigned_to ? 'Reassign' : 'Assign'}
                            </Button>
                        )}
                        {['open', 'in_progress', 'mitigated'].includes(hazard.status) && canClose && (
                            <Button onClick={() => setShowCloseForm(!showCloseForm)}>
                                <CheckCircle2 className="w-4 h-4 mr-1" />
                                Close
                            </Button>
                        )}
                    </div>
                </div>

                {/* Assignment Form */}
                {showAssignForm && (
                    <Card className="mb-4">
                        <CardContent className="p-4">
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    assignForm.post(`/hazards/${hazard.id}/assign`, {
                                        onSuccess: () => setShowAssignForm(false),
                                    });
                                }}
                                className="flex items-end gap-2"
                            >
                                <div className="flex-1">
                                    <Label>Assign to</Label>
                                    <Select
                                        value={assignForm.data.assigned_to_user_id || undefined}
                                        onValueChange={(v) => assignForm.setData('assigned_to_user_id', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select user..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {users.map((u) => (
                                                <SelectItem key={u.id} value={u.id.toString()}>{u.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <Button type="submit" disabled={assignForm.processing}>
                                    Assign
                                </Button>
                                <Button type="button" variant="outline" onClick={() => setShowAssignForm(false)}>
                                    Cancel
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Close Form */}
                {showCloseForm && (
                    <Card className="mb-4">
                        <CardContent className="p-4">
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    closeForm.post(`/hazards/${hazard.id}/close`, {
                                        onSuccess: () => setShowCloseForm(false),
                                    });
                                }}
                                className="space-y-3"
                            >
                                <div>
                                    <Label>Resolution Summary *</Label>
                                    <Textarea
                                        value={closeForm.data.resolution_summary}
                                        onChange={(e) => closeForm.setData('resolution_summary', e.target.value)}
                                        rows={3}
                                        placeholder="Describe how the hazard was resolved..."
                                        required
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={closeForm.processing}>
                                        Close Hazard
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => setShowCloseForm(false)}>
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Description */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Description</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="whitespace-pre-wrap">{hazard.description}</p>
                        </CardContent>
                    </Card>

                    {/* Risk Assessment */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Risk Assessment</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <div className="text-sm text-slate-400">Severity</div>
                                    <Badge variant="outline" className={severityColors[hazard.severity]}>
                                        {hazard.severity}
                                    </Badge>
                                </div>
                                <div>
                                    <div className="text-sm text-slate-400">Likelihood</div>
                                    <div className="capitalize">{hazard.likelihood.replace('_', ' ')}</div>
                                </div>
                            </div>
                            <div className="pt-3 border-t">
                                <div className="text-sm text-slate-400">Risk Rating</div>
                                <Badge 
                                    variant="outline" 
                                    className={`mt-1 text-lg ${
                                        hazard.risk_rating === 'extreme' ? 'border-red-500/50 text-red-400' :
                                        hazard.risk_rating === 'high' ? 'border-orange-500/50 text-orange-400' :
                                        hazard.risk_rating === 'medium' ? 'border-yellow-500/50 text-yellow-400' :
                                        'border-slate-500/30'
                                    }`}
                                >
                                    {hazard.risk_rating.toUpperCase()}
                                </Badge>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Assignment Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Assignment</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="flex items-center gap-2">
                                <User className="w-4 h-4 text-slate-400" />
                                <span className="text-slate-400">Assigned to:</span>
                                <span>{hazard.assigned_to?.name || 'Unassigned'}</span>
                            </div>
                            {hazard.assigned_at && (
                                <div className="flex items-center gap-2 text-sm text-slate-500">
                                    <Clock className="w-4 h-4" />
                                    Assigned on {new Date(hazard.assigned_at).toLocaleDateString()}
                                </div>
                            )}
                            {hazard.due_date && (
                                <div className={`flex items-center gap-2 ${isOverdue ? 'text-red-400' : ''}`}>
                                    <Calendar className="w-4 h-4" />
                                    Due: {new Date(hazard.due_date).toLocaleDateString()}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Immediate Action */}
                    {hazard.immediate_action_applied && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Immediate Action Taken</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap text-slate-300">
                                    {hazard.immediate_action_taken || 'No details provided'}
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {/* Resolution */}
                    {hazard.resolution_summary && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Resolution</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap">{hazard.resolution_summary}</p>
                                {hazard.closed_at && (
                                    <div className="text-sm text-slate-500 mt-2">
                                        Closed on {new Date(hazard.closed_at).toLocaleDateString()}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
