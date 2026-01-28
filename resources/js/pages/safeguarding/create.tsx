import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Props = {
    clients?: Array<{ id: number; first_name: string; last_name: string }>;
    staff?: Array<{ id: number; first_name: string; last_name: string }>;
};

export default function SafeguardingCreate({ clients = [], staff = [] }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        // Subject information
        subject_type: '',
        subject_id: '',
        other_subject_name: '',

        // Concern details
        concern_type: '',
        abuse_category: '',
        severity: 'medium',
        description: '',
        occurred_at: '',
        reported_at: '',
        location: '',
        witnesses: '',

        // Alleged perpetrator
        alleged_perpetrator_type: '',
        alleged_perpetrator_id: '',
        other_perpetrator_name: '',
        perpetrator_relationship: '',

        // Response
        immediate_action_taken: false,
        immediate_action_description: '',
        police_notified: false,
        police_reference: '',
        requires_external_referral: false,
        suggested_referral_agencies: '',

        // Mental Capacity
        subject_capacity_assessed: false,
        subject_has_capacity: '',
        capacity_assessment_notes: '',

        // Subject informed
        subject_informed: false,
        subject_informed_at: '',
        subject_response: '',
        reason_not_informed: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/safeguarding');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Safeguarding', href: '/safeguarding' },
                { title: 'New Concern', href: '/safeguarding/create' }
            ]}
        >
            <Head title="New Safeguarding Concern" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">New Safeguarding Concern</h1>
                    <div className="mt-1 text-sm text-slate-500">
                        Record a new safeguarding concern or allegation
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    {/* Subject Information */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Subject Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Subject Type *</Label>
                                    <Select value={data.subject_type} onValueChange={(v) => setData('subject_type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="client">Client</SelectItem>
                                            <SelectItem value="staff">Staff Member</SelectItem>
                                            <SelectItem value="other">Other Person</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.subject_type && <div className="mt-1 text-xs text-red-500">{errors.subject_type}</div>}
                                </div>

                                {data.subject_type === 'client' && (
                                    <div>
                                        <Label>Client *</Label>
                                        <Select value={data.subject_id} onValueChange={(v) => setData('subject_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                                            <SelectContent>
                                                {clients.map((c) => (
                                                    <SelectItem key={c.id} value={String(c.id)}>
                                                        {c.first_name} {c.last_name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.subject_id && <div className="mt-1 text-xs text-red-500">{errors.subject_id}</div>}
                                    </div>
                                )}

                                {data.subject_type === 'staff' && (
                                    <div>
                                        <Label>Staff Member *</Label>
                                        <Select value={data.subject_id} onValueChange={(v) => setData('subject_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                            <SelectContent>
                                                {staff.map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>
                                                        {s.first_name} {s.last_name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.subject_id && <div className="mt-1 text-xs text-red-500">{errors.subject_id}</div>}
                                    </div>
                                )}

                                {data.subject_type === 'other' && (
                                    <div>
                                        <Label>Person Name *</Label>
                                        <Input
                                            value={data.other_subject_name}
                                            onChange={(e) => setData('other_subject_name', e.target.value)}
                                        />
                                        {errors.other_subject_name && <div className="mt-1 text-xs text-red-500">{errors.other_subject_name}</div>}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Concern Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Concern Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Concern Type *</Label>
                                    <Select value={data.concern_type} onValueChange={(v) => setData('concern_type', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="concern">Concern</SelectItem>
                                            <SelectItem value="allegation">Allegation</SelectItem>
                                            <SelectItem value="disclosure">Disclosure</SelectItem>
                                            <SelectItem value="observation">Observation</SelectItem>
                                            <SelectItem value="third_party_report">Third Party Report</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.concern_type && <div className="mt-1 text-xs text-red-500">{errors.concern_type}</div>}
                                </div>

                                <div>
                                    <Label>Abuse Category</Label>
                                    <Select value={data.abuse_category} onValueChange={(v) => setData('abuse_category', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select category" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="physical">Physical</SelectItem>
                                            <SelectItem value="sexual">Sexual</SelectItem>
                                            <SelectItem value="emotional">Emotional/Psychological</SelectItem>
                                            <SelectItem value="financial">Financial</SelectItem>
                                            <SelectItem value="neglect">Neglect</SelectItem>
                                            <SelectItem value="discriminatory">Discriminatory</SelectItem>
                                            <SelectItem value="domestic">Domestic Abuse</SelectItem>
                                            <SelectItem value="institutional">Institutional</SelectItem>
                                            <SelectItem value="self_neglect">Self-Neglect</SelectItem>
                                            <SelectItem value="modern_slavery">Modern Slavery</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.abuse_category && <div className="mt-1 text-xs text-red-500">{errors.abuse_category}</div>}
                                </div>

                                <div>
                                    <Label>Severity *</Label>
                                    <Select value={data.severity} onValueChange={(v) => setData('severity', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select severity" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">Low</SelectItem>
                                            <SelectItem value="medium">Medium</SelectItem>
                                            <SelectItem value="high">High</SelectItem>
                                            <SelectItem value="critical">Critical</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.severity && <div className="mt-1 text-xs text-red-500">{errors.severity}</div>}
                                </div>
                            </div>

                            <div>
                                <Label>Description of Concern *</Label>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    rows={6}
                                    placeholder="Provide a detailed description of the concern, including what happened, when, where, and who was involved..."
                                />
                                {errors.description && <div className="mt-1 text-xs text-red-500">{errors.description}</div>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Occurred At</Label>
                                    <Input
                                        type="datetime-local"
                                        value={data.occurred_at}
                                        onChange={(e) => setData('occurred_at', e.target.value)}
                                    />
                                    {errors.occurred_at && <div className="mt-1 text-xs text-red-500">{errors.occurred_at}</div>}
                                </div>

                                <div>
                                    <Label>Reported At</Label>
                                    <Input
                                        type="datetime-local"
                                        value={data.reported_at}
                                        onChange={(e) => setData('reported_at', e.target.value)}
                                    />
                                    {errors.reported_at && <div className="mt-1 text-xs text-red-500">{errors.reported_at}</div>}
                                </div>

                                <div>
                                    <Label>Location</Label>
                                    <Input
                                        value={data.location}
                                        onChange={(e) => setData('location', e.target.value)}
                                        placeholder="Where did this occur?"
                                    />
                                    {errors.location && <div className="mt-1 text-xs text-red-500">{errors.location}</div>}
                                </div>
                            </div>

                            <div>
                                <Label>Witnesses</Label>
                                <Textarea
                                    value={data.witnesses}
                                    onChange={(e) => setData('witnesses', e.target.value)}
                                    rows={2}
                                    placeholder="Names and details of any witnesses..."
                                />
                                {errors.witnesses && <div className="mt-1 text-xs text-red-500">{errors.witnesses}</div>}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Immediate Response */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Immediate Response</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="immediate_action"
                                    checked={data.immediate_action_taken}
                                    onCheckedChange={(checked) => setData('immediate_action_taken', !!checked)}
                                />
                                <Label htmlFor="immediate_action">Immediate action was taken</Label>
                            </div>

                            {data.immediate_action_taken && (
                                <div>
                                    <Label>Describe Immediate Action</Label>
                                    <Textarea
                                        value={data.immediate_action_description}
                                        onChange={(e) => setData('immediate_action_description', e.target.value)}
                                        rows={3}
                                    />
                                </div>
                            )}

                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="police_notified"
                                    checked={data.police_notified}
                                    onCheckedChange={(checked) => setData('police_notified', !!checked)}
                                />
                                <Label htmlFor="police_notified">Police were notified</Label>
                            </div>

                            {data.police_notified && (
                                <div>
                                    <Label>Police Reference Number</Label>
                                    <Input
                                        value={data.police_reference}
                                        onChange={(e) => setData('police_reference', e.target.value)}
                                    />
                                </div>
                            )}

                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="requires_referral"
                                    checked={data.requires_external_referral}
                                    onCheckedChange={(checked) => setData('requires_external_referral', !!checked)}
                                />
                                <Label htmlFor="requires_referral">Requires external referral (e.g., Local Authority, CQC)</Label>
                            </div>

                            {data.requires_external_referral && (
                                <div>
                                    <Label>Suggested Referral Agencies</Label>
                                    <Textarea
                                        value={data.suggested_referral_agencies}
                                        onChange={(e) => setData('suggested_referral_agencies', e.target.value)}
                                        rows={2}
                                        placeholder="Which agencies should this be referred to?"
                                    />
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Concern'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
