import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Bell, CalendarDays, FileText, Pencil, Pill, Shield } from 'lucide-react';

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

const VISIBILITY_SETTINGS = [
    { key: 'show_shift_schedule', label: 'Shift Schedule', icon: CalendarDays },
    { key: 'show_respite', label: 'Respite Stays', icon: CalendarDays },
    { key: 'show_care_notes', label: 'Care Notes', icon: FileText },
    { key: 'show_care_plans', label: 'Care Plans', icon: FileText },
    { key: 'show_medication_status', label: 'Medication Status', icon: Pill },
    { key: 'show_incidents', label: 'Incidents', icon: Shield },
] as const;

const NOTIFICATION_SETTINGS = [
    { key: 'notify_shift_arrival', label: 'Shift Arrival' },
    { key: 'notify_shift_completion', label: 'Shift Completion' },
    { key: 'notify_incident', label: 'Incident Alerts' },
] as const;

export default function FamilyPortalShow({ client }: Props) {
    const setting = client.family_portal_setting;

    return (
        <AppLayout>
            <Head title={`Family Portal - ${client.first_name} ${client.last_name}`} />
            <PageHeader
                title={`${client.first_name} ${client.last_name}`}
                description="Family portal visibility and notification settings."
                backHref="/operations/family-portal"
            />
            <PageShell>
                <div className="flex items-center justify-between">
                    <Badge variant={setting ? 'default' : 'secondary'}>
                        {setting ? 'Portal Configured' : 'Not Configured'}
                    </Badge>
                    <Button asChild size="sm">
                        <Link href={`/operations/family-portal/${client.id}/edit`}>
                            <Pencil className="mr-1.5 h-3.5 w-3.5" />
                            Edit Settings
                        </Link>
                    </Button>
                </div>

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium">Visibility Settings</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {VISIBILITY_SETTINGS.map(({ key, label, icon: Icon }) => {
                                const enabled = setting?.[key] ?? false;
                                return (
                                    <div key={key} className="flex items-center gap-2">
                                        <Icon className={`h-4 w-4 ${enabled ? 'text-status-success' : 'text-muted-foreground/40'}`} />
                                        <span className={`text-sm ${enabled ? '' : 'text-muted-foreground/60'}`}>{label}</span>
                                        <Badge variant={enabled ? 'default' : 'outline'} className="ml-auto h-5 px-2 text-[10px]">
                                            {enabled ? 'Visible' : 'Hidden'}
                                        </Badge>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-medium">Notification Settings</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {NOTIFICATION_SETTINGS.map(({ key, label }) => {
                                const enabled = setting?.[key] ?? false;
                                return (
                                    <div key={key} className="flex items-center gap-2">
                                        <Bell className={`h-4 w-4 ${enabled ? 'text-status-info' : 'text-muted-foreground/40'}`} />
                                        <span className={`text-sm ${enabled ? '' : 'text-muted-foreground/60'}`}>{label}</span>
                                        <Badge variant={enabled ? 'default' : 'outline'} className="ml-auto h-5 px-2 text-[10px]">
                                            {enabled ? 'On' : 'Off'}
                                        </Badge>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
