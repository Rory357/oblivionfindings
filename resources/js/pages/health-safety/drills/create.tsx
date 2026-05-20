import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Head, useForm } from '@inertiajs/react';

type Props = {
    sites: Array<{ id: number; name: string }>;
};

export default function DrillCreate({ sites }: Props) {
    const form = useForm({
        site_id: '',
        drill_type: 'fire_evacuation',
        title: '',
        scheduled_at: '',
        scenario_description: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Emergency Drills', href: '/health-safety/drills' },
                { title: 'Schedule Drill', href: '/health-safety/drills/create' },
            ]}
        >
            <Head title="Schedule Drill" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/health-safety/drills"
                        title="Schedule Drill"
                        description="Schedule a new emergency drill for a site"
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Drill Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
                                <Label>Drill Type</Label>
                                <Select
                                    value={form.data.drill_type}
                                    onValueChange={(v) => form.setData('drill_type', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="fire_evacuation">Fire Evacuation</SelectItem>
                                        <SelectItem value="earthquake">Earthquake</SelectItem>
                                        <SelectItem value="lockdown">Lockdown</SelectItem>
                                        <SelectItem value="tsunami">Tsunami</SelectItem>
                                        <SelectItem value="chemical_spill">Chemical Spill</SelectItem>
                                        <SelectItem value="medical_emergency">Medical Emergency</SelectItem>
                                        <SelectItem value="other">Other</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label>Title</Label>
                            <Input
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="e.g. Q1 Fire Evacuation Drill"
                            />
                            {form.errors.title && <p className="text-xs text-status-critical">{form.errors.title}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label>Scheduled Date & Time</Label>
                            <Input
                                type="datetime-local"
                                value={form.data.scheduled_at}
                                onChange={(e) => form.setData('scheduled_at', e.target.value)}
                            />
                            {form.errors.scheduled_at && <p className="text-xs text-status-critical">{form.errors.scheduled_at}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label>Scenario Description</Label>
                            <Textarea
                                value={form.data.scenario_description}
                                onChange={(e) => form.setData('scenario_description', e.target.value)}
                                placeholder="Describe the drill scenario, objectives, and any special instructions"
                                rows={4}
                            />
                        </div>

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

                        <div className="flex items-center justify-end">
                            <Button
                                disabled={form.processing}
                                onClick={() => form.post('/health-safety/drills', { preserveScroll: true })}
                            >
                                Schedule Drill
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
