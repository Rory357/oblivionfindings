import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm } from '@inertiajs/react';

type Props = {
    staff: Array<{ id: number; name: string }>;
    sites: Array<{ id: number; name: string }>;
};

export default function InjuryCreate({ staff, sites }: Props) {
    const form = useForm({
        user_id: '',
        site_id: '',
        injury_date: '',
        injury_type: 'strain',
        body_part_affected: '',
        severity: 'minor',
        description: '',
        immediate_treatment: '',
        medical_treatment_type: 'none',
        worksafe_notifiable: false,
        acc_claim_lodged: false,
        acc_claim_number: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Injuries & Return to Work', href: '/health-safety/injuries' },
                { title: 'Record Injury', href: '/health-safety/injuries/create' },
            ]}
        >
            <Head title="Record Injury" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Record Injury</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Record a new workplace injury or illness
                        </div>
                    </div>
                    <Link href="/health-safety/injuries" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back
                    </Link>
                </div>

                {/* Worker & Location */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Worker & Location</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Worker</Label>
                                <Select
                                    value={form.data.user_id || '__none__'}
                                    onValueChange={(v) => form.setData('user_id', v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">Select...</SelectItem>
                                        {staff.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.user_id && <p className="text-xs text-status-critical">{form.errors.user_id}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Site</Label>
                                <Select
                                    value={form.data.site_id || '__none__'}
                                    onValueChange={(v) => form.setData('site_id', v === '__none__' ? '' : v)}
                                >
                                    <SelectTrigger><SelectValue placeholder="Select site" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">Select...</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.site_id && <p className="text-xs text-status-critical">{form.errors.site_id}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Injury Date</Label>
                                <Input
                                    type="date"
                                    value={form.data.injury_date}
                                    onChange={(e) => form.setData('injury_date', e.target.value)}
                                />
                                {form.errors.injury_date && <p className="text-xs text-status-critical">{form.errors.injury_date}</p>}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Injury Details */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Injury Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label>Injury Type</Label>
                                <Select
                                    value={form.data.injury_type}
                                    onValueChange={(v) => form.setData('injury_type', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="strain">Strain / Sprain</SelectItem>
                                        <SelectItem value="laceration">Laceration / Cut</SelectItem>
                                        <SelectItem value="fracture">Fracture</SelectItem>
                                        <SelectItem value="burn">Burn</SelectItem>
                                        <SelectItem value="contusion">Contusion / Bruise</SelectItem>
                                        <SelectItem value="chemical_exposure">Chemical Exposure</SelectItem>
                                        <SelectItem value="needle_stick">Needle Stick</SelectItem>
                                        <SelectItem value="slip_trip_fall">Slip, Trip, or Fall</SelectItem>
                                        <SelectItem value="manual_handling">Manual Handling</SelectItem>
                                        <SelectItem value="psychological">Psychological</SelectItem>
                                        <SelectItem value="illness">Illness</SelectItem>
                                        <SelectItem value="other">Other</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Body Part Affected</Label>
                                <Input
                                    value={form.data.body_part_affected}
                                    onChange={(e) => form.setData('body_part_affected', e.target.value)}
                                    placeholder="e.g. Lower back, Left wrist"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Severity</Label>
                                <Select
                                    value={form.data.severity}
                                    onValueChange={(v) => form.setData('severity', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="minor">Minor</SelectItem>
                                        <SelectItem value="moderate">Moderate</SelectItem>
                                        <SelectItem value="serious">Serious</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Describe how the injury occurred"
                                rows={3}
                            />
                            {form.errors.description && <p className="text-xs text-status-critical">{form.errors.description}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label>Immediate Treatment</Label>
                            <Textarea
                                value={form.data.immediate_treatment}
                                onChange={(e) => form.setData('immediate_treatment', e.target.value)}
                                placeholder="Describe any first aid or immediate treatment provided"
                            />
                        </div>

                        <div className="space-y-1">
                            <Label>Medical Treatment Type</Label>
                            <Select
                                value={form.data.medical_treatment_type}
                                onValueChange={(v) => form.setData('medical_treatment_type', v)}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">None</SelectItem>
                                    <SelectItem value="first_aid">First Aid Only</SelectItem>
                                    <SelectItem value="gp_visit">GP Visit</SelectItem>
                                    <SelectItem value="emergency_department">Emergency Department</SelectItem>
                                    <SelectItem value="hospitalisation">Hospitalisation</SelectItem>
                                    <SelectItem value="specialist">Specialist Treatment</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Notification & ACC */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Notification & ACC</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!form.data.worksafe_notifiable}
                                onCheckedChange={(v) => form.setData('worksafe_notifiable', !!v)}
                            />
                            <Label>WorkSafe notifiable event</Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                checked={!!form.data.acc_claim_lodged}
                                onCheckedChange={(v) => form.setData('acc_claim_lodged', !!v)}
                            />
                            <Label>ACC claim lodged</Label>
                        </div>
                        {form.data.acc_claim_lodged && (
                            <div className="space-y-1">
                                <Label>ACC Claim Number</Label>
                                <Input
                                    value={form.data.acc_claim_number}
                                    onChange={(e) => form.setData('acc_claim_number', e.target.value)}
                                    placeholder="e.g. ACC12345678"
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Errors */}
                {Object.keys(form.errors).length > 0 && (
                    <div className="rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                        <p className="font-medium">Please fix the following errors:</p>
                        <ul className="mt-1 list-disc pl-5">
                            {Object.entries(form.errors).map(([field, message]) => (
                                <li key={field}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Submit */}
                <div className="flex items-center justify-end">
                    <Button
                        disabled={form.processing}
                        onClick={() => form.post('/health-safety/injuries', { preserveScroll: true })}
                    >
                        Record Injury
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
