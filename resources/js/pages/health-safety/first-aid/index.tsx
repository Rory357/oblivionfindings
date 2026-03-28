import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/date-format';
import { Head, router, useForm } from '@inertiajs/react';
import { Heart, Siren, Link2, Plus } from 'lucide-react';
import { useState } from 'react';

type Props = {
    records: { data: any[]; links: any[] };
    stats: { records_30d: number; ambulance_calls_30d: number; linked_to_incidents: number };
    staff: Array<{ id: number; name: string }>;
    sites: Array<{ id: number; name: string }>;
};

const PERSON_TYPES = [
    { value: 'staff', label: 'Staff' },
    { value: 'client', label: 'Client' },
    { value: 'visitor', label: 'Visitor' },
    { value: 'contractor', label: 'Contractor' },
];

const INJURY_TYPES = [
    { value: 'cut', label: 'Cut / Laceration' },
    { value: 'burn', label: 'Burn / Scald' },
    { value: 'bruise', label: 'Bruise / Contusion' },
    { value: 'sprain', label: 'Sprain / Strain' },
    { value: 'fracture', label: 'Fracture' },
    { value: 'head_injury', label: 'Head Injury' },
    { value: 'eye_injury', label: 'Eye Injury' },
    { value: 'allergic_reaction', label: 'Allergic Reaction' },
    { value: 'breathing_difficulty', label: 'Breathing Difficulty' },
    { value: 'chest_pain', label: 'Chest Pain' },
    { value: 'seizure', label: 'Seizure' },
    { value: 'fainting', label: 'Fainting / Collapse' },
    { value: 'nausea', label: 'Nausea / Vomiting' },
    { value: 'sting', label: 'Insect Sting / Bite' },
    { value: 'choking', label: 'Choking' },
    { value: 'other', label: 'Other' },
];

const OUTCOMES = [
    { value: 'returned_to_activity', label: 'Returned to Activity' },
    { value: 'sent_home', label: 'Sent Home' },
    { value: 'medical_centre', label: 'Referred to Medical Centre' },
    { value: 'hospital', label: 'Sent to Hospital' },
    { value: 'ambulance_called', label: 'Ambulance Called' },
    { value: 'ongoing_monitoring', label: 'Ongoing Monitoring' },
    { value: 'refused_treatment', label: 'Refused Treatment' },
    { value: 'other', label: 'Other' },
];

export default function FirstAidIndex({ records, stats, staff, sites }: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);

    const form = useForm({
        site_id: '',
        treated_person_name: '',
        treated_person_type: 'staff',
        treatment_date: new Date().toISOString().slice(0, 16),
        injury_illness_type: '',
        injury_illness_description: '',
        body_part: '',
        treatment_given: '',
        treatment_outcome: '',
        ambulance_called: false,
        first_aider_id: '',
        first_aider_notes: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/health-safety/first-aid', {
            onSuccess: () => {
                setDialogOpen(false);
                form.reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'First Aid', href: '/health-safety/first-aid' }]}>
            <Head title="First Aid Records" />

            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <Heart className="h-5 w-5 text-red-500" />
                            <h1 className="text-lg font-semibold">First Aid Records</h1>
                        </div>
                        <div className="mt-1 text-sm text-slate-500">Record and track first aid treatments</div>
                    </div>
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button size="sm">
                                <Plus className="mr-1.5 h-4 w-4" />
                                Record First Aid
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Record First Aid Treatment</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Site</Label>
                                        <Select value={form.data.site_id} onValueChange={(v) => form.setData('site_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select site" /></SelectTrigger>
                                            <SelectContent>
                                                {sites.map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.site_id && <p className="mt-1 text-xs text-red-600">{form.errors.site_id}</p>}
                                    </div>
                                    <div>
                                        <Label>Treatment Date & Time</Label>
                                        <Input type="datetime-local" value={form.data.treatment_date} onChange={(e) => form.setData('treatment_date', e.target.value)} />
                                        {form.errors.treatment_date && <p className="mt-1 text-xs text-red-600">{form.errors.treatment_date}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Person Treated</Label>
                                        <Input value={form.data.treated_person_name} onChange={(e) => form.setData('treated_person_name', e.target.value)} placeholder="Full name" />
                                        {form.errors.treated_person_name && <p className="mt-1 text-xs text-red-600">{form.errors.treated_person_name}</p>}
                                    </div>
                                    <div>
                                        <Label>Person Type</Label>
                                        <Select value={form.data.treated_person_type} onValueChange={(v) => form.setData('treated_person_type', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {PERSON_TYPES.map((t) => (
                                                    <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Injury / Illness Type</Label>
                                        <Select value={form.data.injury_illness_type} onValueChange={(v) => form.setData('injury_illness_type', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                            <SelectContent>
                                                {INJURY_TYPES.map((t) => (
                                                    <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.injury_illness_type && <p className="mt-1 text-xs text-red-600">{form.errors.injury_illness_type}</p>}
                                    </div>
                                    <div>
                                        <Label>Body Part</Label>
                                        <Input value={form.data.body_part} onChange={(e) => form.setData('body_part', e.target.value)} placeholder="e.g. Left hand, Head" />
                                    </div>
                                </div>

                                <div>
                                    <Label>Description of Injury / Illness</Label>
                                    <Textarea value={form.data.injury_illness_description} onChange={(e) => form.setData('injury_illness_description', e.target.value)} rows={2} />
                                </div>

                                <div>
                                    <Label>Treatment Given</Label>
                                    <Textarea value={form.data.treatment_given} onChange={(e) => form.setData('treatment_given', e.target.value)} rows={2} />
                                    {form.errors.treatment_given && <p className="mt-1 text-xs text-red-600">{form.errors.treatment_given}</p>}
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Treatment Outcome</Label>
                                        <Select value={form.data.treatment_outcome} onValueChange={(v) => form.setData('treatment_outcome', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select outcome" /></SelectTrigger>
                                            <SelectContent>
                                                {OUTCOMES.map((o) => (
                                                    <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.treatment_outcome && <p className="mt-1 text-xs text-red-600">{form.errors.treatment_outcome}</p>}
                                    </div>
                                    <div>
                                        <Label>First Aider</Label>
                                        <Select value={form.data.first_aider_id} onValueChange={(v) => form.setData('first_aider_id', v)}>
                                            <SelectTrigger><SelectValue placeholder="Select first aider" /></SelectTrigger>
                                            <SelectContent>
                                                {staff.map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.first_aider_id && <p className="mt-1 text-xs text-red-600">{form.errors.first_aider_id}</p>}
                                    </div>
                                </div>

                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="ambulance_called"
                                        checked={form.data.ambulance_called}
                                        onCheckedChange={(v) => form.setData('ambulance_called', !!v)}
                                    />
                                    <Label htmlFor="ambulance_called" className="text-sm">Ambulance was called</Label>
                                </div>

                                <div>
                                    <Label>First Aider Notes</Label>
                                    <Textarea value={form.data.first_aider_notes} onChange={(e) => form.setData('first_aider_notes', e.target.value)} rows={2} />
                                </div>

                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
                                    <Button type="submit" disabled={form.processing}>Save Record</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-slate-500">Records (30d)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.records_30d}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-slate-500">Ambulance Calls (30d)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                <Siren className="h-5 w-5 text-red-500" />
                                <div className="text-2xl font-bold">{stats.ambulance_calls_30d}</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium text-slate-500">Linked to Incidents</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                <Link2 className="h-5 w-5 text-blue-500" />
                                <div className="text-2xl font-bold">{stats.linked_to_incidents}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="pt-4">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 font-medium">Date</th>
                                        <th className="pb-2 font-medium">Person Treated</th>
                                        <th className="pb-2 font-medium">Injury / Illness</th>
                                        <th className="pb-2 font-medium">Treatment Given</th>
                                        <th className="pb-2 font-medium">Outcome</th>
                                        <th className="pb-2 font-medium">First Aider</th>
                                        <th className="pb-2 font-medium">Incident</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {records.data.map((r: any) => (
                                        <tr key={r.id} className="border-b last:border-0">
                                            <td className="py-2 whitespace-nowrap">{formatDateTime(r.treatment_date)}</td>
                                            <td className="py-2">
                                                <div className="flex items-center gap-1.5">
                                                    {r.treated_person_name}
                                                    <Badge variant="outline" className="text-[10px]">{r.treated_person_type}</Badge>
                                                </div>
                                            </td>
                                            <td className="py-2 capitalize">{r.injury_illness_type?.replace(/_/g, ' ') ?? '-'}</td>
                                            <td className="max-w-[200px] truncate py-2">{r.treatment_given ?? '-'}</td>
                                            <td className="py-2 capitalize">{r.treatment_outcome?.replace(/_/g, ' ') ?? '-'}</td>
                                            <td className="py-2">{r.first_aider?.name ?? '-'}</td>
                                            <td className="py-2 text-center">
                                                {r.incident_id ? (
                                                    <Badge className="bg-green-100 text-green-800 border-green-200">Y</Badge>
                                                ) : (
                                                    <Badge variant="outline" className="text-slate-400">N</Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {!records.data.length && (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-slate-500">No first aid records found.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {records.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {records.links.map((l: any) => (
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
            </div>
        </AppLayout>
    );
}
