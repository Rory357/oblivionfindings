import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/date-format';
import { Head, router, useForm } from '@inertiajs/react';
import { ShieldAlert, ClipboardList, AlertCircle, Check, X, Plus, FileEdit } from 'lucide-react';
import { useState } from 'react';

type Props = {
    events: { data: any[]; links: any[] };
    plans: any[];
    stats: { events_30d: number; active_plans: number; reviews_due: number };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    staff: Array<{ id: number; name: string }>;
    sites: Array<{ id: number; name: string }>;
    can_create: boolean;
    can_review: boolean;
};

const RESTRAINT_TYPES = [
    { value: 'physical', label: 'Physical' },
    { value: 'chemical', label: 'Chemical' },
    { value: 'mechanical', label: 'Mechanical' },
    { value: 'seclusion', label: 'Seclusion' },
    { value: 'environmental', label: 'Environmental' },
];

const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

const RESTRICTIVE_PRACTICE_TYPES = [
    { value: 'physical', label: 'Physical' },
    { value: 'chemical', label: 'Chemical' },
    { value: 'mechanical', label: 'Mechanical' },
    { value: 'seclusion', label: 'Seclusion' },
    { value: 'environmental', label: 'Environmental' },
];

function restraintTypeBadge(type: string) {
    switch (type) {
        case 'physical': return 'bg-red-100 text-red-800 border-red-200';
        case 'chemical': return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'mechanical': return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'seclusion': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'environmental': return 'bg-blue-100 text-blue-800 border-blue-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

function severityBadge(s: string) {
    switch (s) {
        case 'critical': return 'bg-red-100 text-red-800 border-red-200';
        case 'high': return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'medium': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'low': return 'bg-blue-100 text-blue-800 border-blue-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

function statusBadgeColor(s: string) {
    switch (s) {
        case 'active': return 'bg-green-100 text-green-800 border-green-200';
        case 'draft': return 'bg-slate-100 text-slate-800 border-slate-200';
        case 'expired': return 'bg-red-100 text-red-800 border-red-200';
        case 'under_review': return 'bg-amber-100 text-amber-800 border-amber-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

export default function RestraintsIndex({ events, plans, stats, clients, staff, sites, can_create, can_review }: Props) {
    const [eventDialogOpen, setEventDialogOpen] = useState(false);
    const [planDialogOpen, setPlanDialogOpen] = useState(false);
    const [reviewingEvent, setReviewingEvent] = useState<any>(null);

    // Event form
    const eventForm = useForm({
        client_id: '',
        behaviour_support_plan_id: '',
        site_id: '',
        started_at: '',
        ended_at: '',
        restraint_type: '',
        severity: 'low',
        trigger_description: '',
        de_escalation_attempted: '',
        restraint_description: '',
        person_response: '',
        post_incident_support: '',
        injury_occurred: false,
        injury_details: '',
        within_support_plan: false,
        deviation_reason: '',
    });

    // Plan form
    const planForm = useForm({
        client_id: '',
        title: '',
        triggers: '',
        de_escalation_strategies: '',
        approved_interventions: '',
        prohibited_interventions: '',
        restrictive_practice_type: '',
        review_date: '',
    });

    // Review form
    const reviewForm = useForm({
        review_notes: '',
        lessons_learned: '',
    });

    const submitEvent = (e: React.FormEvent) => {
        e.preventDefault();
        eventForm.post('/health-safety/restraints/events', {
            onSuccess: () => {
                setEventDialogOpen(false);
                eventForm.reset();
            },
        });
    };

    const submitPlan = (e: React.FormEvent) => {
        e.preventDefault();
        planForm.post('/health-safety/restraints/plans', {
            onSuccess: () => {
                setPlanDialogOpen(false);
                planForm.reset();
            },
        });
    };

    const submitReview = (e: React.FormEvent) => {
        e.preventDefault();
        if (!reviewingEvent) return;
        reviewForm.put(`/health-safety/restraints/events/${reviewingEvent.id}`, {
            onSuccess: () => {
                setReviewingEvent(null);
                reviewForm.reset();
            },
        });
    };

    const isOverdueReview = (date: string | null) => {
        if (!date) return false;
        return new Date(date) < new Date();
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Restraints', href: '/health-safety/restraints' }]}>
            <Head title="Restraints & Behaviour Support" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Restraints & Behaviour Support"
                    description="Record restraint events and manage behaviour support plans"
                    icon={<ShieldAlert className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Events (30d)', value: stats.events_30d },
                        { label: 'Active Plans', value: stats.active_plans },
                        { label: 'Reviews Due', value: stats.reviews_due },
                    ]}
                />

                {/* Tabs */}
                <Tabs defaultValue="events">
                    <TabsList>
                        <TabsTrigger value="events">Restraint Events</TabsTrigger>
                        <TabsTrigger value="plans">Behaviour Support Plans</TabsTrigger>
                    </TabsList>

                    {/* Events Tab */}
                    <TabsContent value="events" className="space-y-4">
                        {can_create && (
                            <div className="flex justify-end">
                                <Dialog open={eventDialogOpen} onOpenChange={setEventDialogOpen}>
                                    <DialogTrigger asChild>
                                        <Button size="sm">
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Record Event
                                        </Button>
                                    </DialogTrigger>
                                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                                    <DialogHeader>
                                        <DialogTitle>Record Restraint Event</DialogTitle>
                                    </DialogHeader>
                                    <form onSubmit={submitEvent} className="space-y-4">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Client</Label>
                                                <Select value={eventForm.data.client_id} onValueChange={(v) => eventForm.setData('client_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                                                    <SelectContent>
                                                        {clients.map((c) => (
                                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {eventForm.errors.client_id && <p className="mt-1 text-xs text-red-600">{eventForm.errors.client_id}</p>}
                                            </div>
                                            <div>
                                                <Label>Behaviour Support Plan (optional)</Label>
                                                <Select
                                                    value={eventForm.data.behaviour_support_plan_id || '__none__'}
                                                    onValueChange={(v) => eventForm.setData('behaviour_support_plan_id', v === '__none__' ? '' : v)}
                                                >
                                                    <SelectTrigger><SelectValue placeholder="Select plan" /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="__none__">None</SelectItem>
                                                        {plans.map((p: any) => (
                                                            <SelectItem key={p.id} value={String(p.id)}>{p.title}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <Label>Site</Label>
                                                <Select value={eventForm.data.site_id} onValueChange={(v) => eventForm.setData('site_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select site" /></SelectTrigger>
                                                    <SelectContent>
                                                        {sites.map((s) => (
                                                            <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {eventForm.errors.site_id && <p className="mt-1 text-xs text-red-600">{eventForm.errors.site_id}</p>}
                                            </div>
                                            <div>
                                                <Label>Started At</Label>
                                                <Input type="datetime-local" value={eventForm.data.started_at} onChange={(e) => eventForm.setData('started_at', e.target.value)} />
                                                {eventForm.errors.started_at && <p className="mt-1 text-xs text-red-600">{eventForm.errors.started_at}</p>}
                                            </div>
                                            <div>
                                                <Label>Ended At</Label>
                                                <Input type="datetime-local" value={eventForm.data.ended_at} onChange={(e) => eventForm.setData('ended_at', e.target.value)} />
                                            </div>
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Restraint Type</Label>
                                                <Select value={eventForm.data.restraint_type} onValueChange={(v) => eventForm.setData('restraint_type', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                                    <SelectContent>
                                                        {RESTRAINT_TYPES.map((t) => (
                                                            <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {eventForm.errors.restraint_type && <p className="mt-1 text-xs text-red-600">{eventForm.errors.restraint_type}</p>}
                                            </div>
                                            <div>
                                                <Label>Severity</Label>
                                                <Select value={eventForm.data.severity} onValueChange={(v) => eventForm.setData('severity', v)}>
                                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        {SEVERITY_OPTIONS.map((s) => (
                                                            <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>

                                        <div>
                                            <Label>Trigger Description</Label>
                                            <Textarea value={eventForm.data.trigger_description} onChange={(e) => eventForm.setData('trigger_description', e.target.value)} rows={2} />
                                        </div>

                                        <div>
                                            <Label>De-escalation Attempted</Label>
                                            <Textarea value={eventForm.data.de_escalation_attempted} onChange={(e) => eventForm.setData('de_escalation_attempted', e.target.value)} rows={2} />
                                        </div>

                                        <div>
                                            <Label>Restraint Description</Label>
                                            <Textarea value={eventForm.data.restraint_description} onChange={(e) => eventForm.setData('restraint_description', e.target.value)} rows={2} />
                                        </div>

                                        <div>
                                            <Label>Person's Response</Label>
                                            <Textarea value={eventForm.data.person_response} onChange={(e) => eventForm.setData('person_response', e.target.value)} rows={2} />
                                        </div>

                                        <div>
                                            <Label>Post-incident Support</Label>
                                            <Textarea value={eventForm.data.post_incident_support} onChange={(e) => eventForm.setData('post_incident_support', e.target.value)} rows={2} />
                                        </div>

                                        <div className="space-y-3">
                                            <div className="flex items-center space-x-2">
                                                <Checkbox
                                                    id="injury_occurred"
                                                    checked={eventForm.data.injury_occurred}
                                                    onCheckedChange={(v) => eventForm.setData('injury_occurred', !!v)}
                                                />
                                                <Label htmlFor="injury_occurred" className="text-sm">Injury occurred</Label>
                                            </div>
                                            {eventForm.data.injury_occurred && (
                                                <div>
                                                    <Label>Injury Details</Label>
                                                    <Textarea value={eventForm.data.injury_details} onChange={(e) => eventForm.setData('injury_details', e.target.value)} rows={2} />
                                                </div>
                                            )}
                                        </div>

                                        <div className="space-y-3">
                                            <div className="flex items-center space-x-2">
                                                <Checkbox
                                                    id="within_support_plan"
                                                    checked={eventForm.data.within_support_plan}
                                                    onCheckedChange={(v) => eventForm.setData('within_support_plan', !!v)}
                                                />
                                                <Label htmlFor="within_support_plan" className="text-sm">Within behaviour support plan</Label>
                                            </div>
                                            {!eventForm.data.within_support_plan && (
                                                <div>
                                                    <Label>Reason for Deviation</Label>
                                                    <Textarea value={eventForm.data.deviation_reason} onChange={(e) => eventForm.setData('deviation_reason', e.target.value)} rows={2} />
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex justify-end gap-2">
                                            <Button type="button" variant="outline" onClick={() => setEventDialogOpen(false)}>Cancel</Button>
                                            <Button type="submit" disabled={eventForm.processing}>Save Event</Button>
                                        </div>
                                    </form>
                                </DialogContent>
                                </Dialog>
                            </div>
                        )}

                        {/* Events Table */}
                        <Card>
                            <CardContent className="pt-4">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-slate-500">
                                                <th className="pb-2 font-medium">Date</th>
                                                <th className="pb-2 font-medium">Client</th>
                                                <th className="pb-2 font-medium">Type</th>
                                                <th className="pb-2 font-medium">Duration</th>
                                                <th className="pb-2 font-medium">Severity</th>
                                                <th className="pb-2 font-medium">Within Plan</th>
                                                <th className="pb-2 font-medium">Reviewed</th>
                                                <th className="pb-2 font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {events.data.map((ev: any) => {
                                                const durationMins = ev.started_at && ev.ended_at
                                                    ? Math.round((new Date(ev.ended_at).getTime() - new Date(ev.started_at).getTime()) / 60000)
                                                    : null;
                                                return (
                                                    <tr key={ev.id} className="border-b last:border-0">
                                                        <td className="py-2 whitespace-nowrap">{formatDateTime(ev.started_at)}</td>
                                                        <td className="py-2">{ev.client?.first_name} {ev.client?.last_name}</td>
                                                        <td className="py-2">
                                                            <Badge className={restraintTypeBadge(ev.restraint_type)}>{ev.restraint_type}</Badge>
                                                        </td>
                                                        <td className="py-2">{durationMins !== null ? `${durationMins} min` : '-'}</td>
                                                        <td className="py-2">
                                                            <Badge className={severityBadge(ev.severity)}>{ev.severity}</Badge>
                                                        </td>
                                                        <td className="py-2 text-center">
                                                            {ev.within_support_plan ? (
                                                                <Check className="mx-auto h-4 w-4 text-green-600" />
                                                            ) : (
                                                                <X className="mx-auto h-4 w-4 text-red-500" />
                                                            )}
                                                        </td>
                                                        <td className="py-2 text-center">
                                                            {ev.reviewed_at ? (
                                                                <Check className="mx-auto h-4 w-4 text-green-600" />
                                                            ) : (
                                                                <X className="mx-auto h-4 w-4 text-slate-400" />
                                                            )}
                                                        </td>
                                                        <td className="py-2">
                                                            {!ev.reviewed_at && can_review && (
                                                                <Button size="sm" variant="outline" onClick={() => setReviewingEvent(ev)}>
                                                                    <FileEdit className="mr-1 h-3 w-3" />
                                                                    Review
                                                                </Button>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            {!events.data.length && (
                                                <tr>
                                                    <td colSpan={8} className="py-8 text-center text-slate-500">No restraint events found.</td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Pagination */}
                        {events.links?.length ? (
                            <div className="flex flex-wrap gap-2">
                                {events.links.map((l: any) => (
                                    <button
                                        key={l.label}
                                        disabled={!l.url}
                                        className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                        onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                        dangerouslySetInnerHTML={{ __html: l.label }}
                                    />
                                ))}
                            </div>
                        ) : null}
                    </TabsContent>

                    {/* Plans Tab */}
                    <TabsContent value="plans" className="space-y-4">
                        {can_create && (
                            <div className="flex justify-end">
                                <Dialog open={planDialogOpen} onOpenChange={setPlanDialogOpen}>
                                    <DialogTrigger asChild>
                                        <Button size="sm">
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Create Plan
                                        </Button>
                                    </DialogTrigger>
                                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                                    <DialogHeader>
                                        <DialogTitle>Create Behaviour Support Plan</DialogTitle>
                                    </DialogHeader>
                                    <form onSubmit={submitPlan} className="space-y-4">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Client</Label>
                                                <Select value={planForm.data.client_id} onValueChange={(v) => planForm.setData('client_id', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                                                    <SelectContent>
                                                        {clients.map((c) => (
                                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {planForm.errors.client_id && <p className="mt-1 text-xs text-red-600">{planForm.errors.client_id}</p>}
                                            </div>
                                            <div>
                                                <Label>Title</Label>
                                                <Input value={planForm.data.title} onChange={(e) => planForm.setData('title', e.target.value)} placeholder="Plan title" />
                                                {planForm.errors.title && <p className="mt-1 text-xs text-red-600">{planForm.errors.title}</p>}
                                            </div>
                                        </div>

                                        <div>
                                            <Label>Triggers</Label>
                                            <Textarea value={planForm.data.triggers} onChange={(e) => planForm.setData('triggers', e.target.value)} rows={2} placeholder="Known triggers for the behaviour" />
                                        </div>

                                        <div>
                                            <Label>De-escalation Strategies</Label>
                                            <Textarea value={planForm.data.de_escalation_strategies} onChange={(e) => planForm.setData('de_escalation_strategies', e.target.value)} rows={2} />
                                        </div>

                                        <div>
                                            <Label>Approved Interventions</Label>
                                            <Textarea value={planForm.data.approved_interventions} onChange={(e) => planForm.setData('approved_interventions', e.target.value)} rows={2} />
                                        </div>

                                        <div>
                                            <Label>Prohibited Interventions</Label>
                                            <Textarea value={planForm.data.prohibited_interventions} onChange={(e) => planForm.setData('prohibited_interventions', e.target.value)} rows={2} />
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Restrictive Practice Type</Label>
                                                <Select value={planForm.data.restrictive_practice_type} onValueChange={(v) => planForm.setData('restrictive_practice_type', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                                    <SelectContent>
                                                        {RESTRICTIVE_PRACTICE_TYPES.map((t) => (
                                                            <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div>
                                                <Label>Review Date</Label>
                                                <Input type="date" value={planForm.data.review_date} onChange={(e) => planForm.setData('review_date', e.target.value)} />
                                            </div>
                                        </div>

                                        <div className="flex justify-end gap-2">
                                            <Button type="button" variant="outline" onClick={() => setPlanDialogOpen(false)}>Cancel</Button>
                                            <Button type="submit" disabled={planForm.processing}>Create Plan</Button>
                                        </div>
                                    </form>
                                </DialogContent>
                                </Dialog>
                            </div>
                        )}

                        {/* Plans Cards */}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map((plan: any) => (
                                <Card key={plan.id}>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <div className="font-semibold">{plan.client?.first_name} {plan.client?.last_name}</div>
                                                    <div className="mt-0.5 text-sm font-normal text-slate-600">{plan.title}</div>
                                                </div>
                                            </div>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        <div className="flex flex-wrap gap-1.5">
                                            <Badge className={statusBadgeColor(plan.status)}>
                                                {plan.status?.replace(/_/g, ' ')}
                                            </Badge>
                                            {plan.restrictive_practice_type && (
                                                <Badge className={restraintTypeBadge(plan.restrictive_practice_type)}>
                                                    {plan.restrictive_practice_type}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="text-xs text-slate-500">
                                            <span className="font-medium">Review:</span>{' '}
                                            <span className={isOverdueReview(plan.review_date) ? 'font-semibold text-red-600' : ''}>
                                                {formatDate(plan.review_date)}
                                            </span>
                                        </div>
                                        {plan.de_escalation_strategies && (
                                            <div className="text-xs text-slate-500">
                                                <span className="font-medium">De-escalation:</span>{' '}
                                                {plan.de_escalation_strategies.length > 100
                                                    ? plan.de_escalation_strategies.slice(0, 100) + '...'
                                                    : plan.de_escalation_strategies}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                            {!plans.length && (
                                <div className="col-span-full py-8 text-center text-sm text-slate-500">No behaviour support plans found.</div>
                            )}
                        </div>
                    </TabsContent>
                </Tabs>

                {/* Review Dialog */}
                <Dialog open={!!reviewingEvent} onOpenChange={(open) => { if (!open) setReviewingEvent(null); }}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Review Restraint Event</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={submitReview} className="space-y-4">
                            <div>
                                <Label>Review Notes</Label>
                                <Textarea value={reviewForm.data.review_notes} onChange={(e) => reviewForm.setData('review_notes', e.target.value)} rows={3} />
                                {reviewForm.errors.review_notes && <p className="mt-1 text-xs text-red-600">{reviewForm.errors.review_notes}</p>}
                            </div>
                            <div>
                                <Label>Lessons Learned</Label>
                                <Textarea value={reviewForm.data.lessons_learned} onChange={(e) => reviewForm.setData('lessons_learned', e.target.value)} rows={3} />
                            </div>
                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" onClick={() => setReviewingEvent(null)}>Cancel</Button>
                                <Button type="submit" disabled={reviewForm.processing}>Submit Review</Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
