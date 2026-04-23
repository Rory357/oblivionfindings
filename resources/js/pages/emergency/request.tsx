import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

export default function EmergencyRequestPage({ client, redirectTo }: any) {
    const [reason, setReason] = useState('');
    const [minutes, setMinutes] = useState('60');

    const submit = () => {
        router.post(
            `/clients/${client.id}/break-glass`,
            { reason, minutes: minutes ? Number(minutes) : 60 },
            { onSuccess: () => router.visit(redirectTo ?? `/clients/${client.id}/mar`) },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Emergency Access', href: '/emergency-access' }]}>
            <Head title="Emergency Access" />

            <div className="max-w-2xl space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Emergency access required</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="text-sm text-muted-foreground">
                            You’re not assigned to <span className="font-medium">{client.first_name} {client.last_name}</span>.
                            If this is an emergency, you can request temporary medication access.
                        </div>

                        <div className="space-y-2">
                            <Label>Reason (required)</Label>
                            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Covering unplanned sick leave – meds due" />
                        </div>

                        <div className="space-y-2">
                            <Label>Duration (minutes)</Label>
                            <Input value={minutes} onChange={(e) => setMinutes(e.target.value)} placeholder="60" />
                            <div className="text-xs text-muted-foreground">Default: 60 minutes</div>
                        </div>

                        <div className="flex gap-2">
                            <Button variant="secondary" onClick={() => router.visit('/emergency-access')}>Back</Button>
                            <Button onClick={submit} disabled={!reason.trim()}>Request access</Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
