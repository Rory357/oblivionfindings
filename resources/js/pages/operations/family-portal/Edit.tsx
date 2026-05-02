import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type FamilyPortalSetting = {
    show_shift_schedule: boolean;
    show_respite: boolean;
    show_care_notes: boolean;
    show_care_plans: boolean;
    show_medication_status: boolean;
    show_incidents: boolean;
    notify_shift_arrival: boolean;
    notify_shift_completion: boolean;
    notify_incident: boolean;
} | null;

type ClientData = {
    id: number;
    first_name: string;
    last_name: string;
    family_portal_setting: FamilyPortalSetting;
};

type Props = {
    client: ClientData;
};

export default function FamilyPortalEdit({ client }: Props) {
    const setting = client.family_portal_setting;

    const [form, setForm] = useState({
        show_shift_schedule: setting?.show_shift_schedule ?? true,
        show_respite: setting?.show_respite ?? true,
        show_care_notes: setting?.show_care_notes ?? true,
        show_care_plans: setting?.show_care_plans ?? false,
        show_medication_status: setting?.show_medication_status ?? false,
        show_incidents: setting?.show_incidents ?? false,
        notify_shift_arrival: setting?.notify_shift_arrival ?? true,
        notify_shift_completion: setting?.notify_shift_completion ?? true,
        notify_incident: setting?.notify_incident ?? true,
    });

    const [saving, setSaving] = useState(false);

    const toggle = (key: keyof typeof form) => {
        setForm((prev) => ({ ...prev, [key]: !prev[key] }));
    };

    const save = () => {
        setSaving(true);
        router.put(`/operations/family-portal/${client.id}`, form, {
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AppLayout>
            <Head title={`Edit Portal - ${client.first_name} ${client.last_name}`} />
            <PageHeader
                title={`Edit Portal Settings`}
                description={`${client.first_name} ${client.last_name} — configure what families can see and receive.`}
                backHref={`/operations/family-portal/${client.id}`}
            />
            <PageShell>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium">Visibility</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {([
                                ['show_shift_schedule', 'Shift Schedule'],
                                ['show_respite', 'Respite Stays'],
                                ['show_care_notes', 'Care Notes'],
                                ['show_care_plans', 'Care Plans'],
                                ['show_medication_status', 'Medication Status'],
                                ['show_incidents', 'Incidents'],
                            ] as const).map(([key, label]) => (
                                <div key={key} className="flex items-center justify-between">
                                    <Label htmlFor={key} className="text-sm">{label}</Label>
                                    <Switch id={key} checked={form[key]} onCheckedChange={() => toggle(key)} />
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium">Notifications</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {([
                                ['notify_shift_arrival', 'Shift Arrival'],
                                ['notify_shift_completion', 'Shift Completion'],
                                ['notify_incident', 'Incident Alerts'],
                            ] as const).map(([key, label]) => (
                                <div key={key} className="flex items-center justify-between">
                                    <Label htmlFor={key} className="text-sm">{label}</Label>
                                    <Switch id={key} checked={form[key]} onCheckedChange={() => toggle(key)} />
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                    <Button onClick={save} disabled={saving}>
                        {saving ? 'Saving...' : 'Save Settings'}
                    </Button>
                </div>
            </PageShell>
        </AppLayout>
    );
}
