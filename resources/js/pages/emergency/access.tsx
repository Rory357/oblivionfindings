import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { ShieldAlert } from 'lucide-react';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';

type ClientLite = {
    id: number;
    first_name: string;
    last_name: string;
    date_of_birth?: string | null;
    status?: string | null;
    site?: { id: number; name: string } | null;
};

type ActiveAccess = {
    id: number;
    client: { id: number; first_name: string; last_name: string };
    reason: string;
    expires_at?: string | null;
    created_at?: string | null;
};

export default function EmergencyAccessPage({ query, results, activeAccesses }: any) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const [q, setQ] = useState<string>(query ?? '');
    const [selected, setSelected] = useState<ClientLite | null>(null);
    const [reason, setReason] = useState('');
    const [minutes, setMinutes] = useState<string>('60');
    const [open, setOpen] = useState(false);

    const hasBreakGlass = !!can?.medications?.breakGlass;

    const submitSearch = () => {
        router.get('/emergency-access', { q }, { preserveState: true, replace: true });
    };

    const requestAccess = () => {
        if (!selected) return;
        router.post(
            `/clients/${selected.id}/break-glass`,
            {
                reason,
                minutes: minutes ? Number(minutes) : 60,
            },
            {
                onSuccess: () => {
                    setOpen(false);
                    setReason('');
                    setMinutes('60');
                    router.visit(`/clients/${selected.id}/mar`);
                },
            },
        );
    };

    const revokeAccess = (clientId: number, accessId: number) => {
        router.delete(`/clients/${clientId}/break-glass/${accessId}`);
    };

    const breadcrumbs = useMemo(
        () => [{ title: 'Emergency Access', href: '/emergency-access' }],
        [],
    );

    if (!hasBreakGlass) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Emergency Access" />
                <Card>
                    <CardHeader>
                        <CardTitle>Emergency Access</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="text-sm text-slate-600">
                            You don’t have permission to use emergency access.
                        </div>
                    </CardContent>
                </Card>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Emergency Access" />

            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="Emergency Access"
                    description="Urgent medication access for clients you are not assigned to. A reason is required and access is time-limited."
                    icon={<ShieldAlert className="h-7 w-7 text-white" />}
                />

                <Card>
                    <CardContent className="space-y-3 pt-6">

                        <div className="flex gap-2">
                            <Input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search client by name…"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') submitSearch();
                                }}
                            />
                            <Button onClick={submitSearch}>Search</Button>
                        </div>

                        <div className="text-xs text-slate-500">
                            Tip: enter at least 2 characters.
                        </div>
                    </CardContent>
                </Card>

                {(activeAccesses?.length ?? 0) > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Your active emergency accesses</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {(activeAccesses as ActiveAccess[]).map((a) => (
                                <div
                                    key={a.id}
                                    className="flex items-center justify-between rounded-md border p-3"
                                >
                                    <div className="space-y-0.5">
                                        <div className="text-sm font-medium">
                                            {a.client.first_name} {a.client.last_name}
                                        </div>
                                        <div className="text-xs text-slate-500">
                                            Reason: {a.reason}
                                            {a.expires_at ? ` • Expires: ${new Date(a.expires_at).toLocaleString()}` : ''}
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="secondary"
                                            onClick={() => router.visit(`/clients/${a.client.id}/mar`)}
                                        >
                                            Open MAR
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            onClick={() => revokeAccess(a.client.id, a.id)}
                                        >
                                            Revoke
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Search results</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {(results?.length ?? 0) === 0 ? (
                            <div className="text-sm text-slate-600">No results.</div>
                        ) : (
                            (results as ClientLite[]).map((c) => (
                                <div
                                    key={c.id}
                                    className="flex items-center justify-between rounded-md border p-3"
                                >
                                    <div className="space-y-0.5">
                                        <div className="text-sm font-medium">
                                            {c.first_name} {c.last_name}
                                        </div>
                                        <div className="text-xs text-slate-500">
                                            {c.date_of_birth ? `DOB: ${c.date_of_birth}` : ''}
                                            {c.site?.name ? ` • Site: ${c.site.name}` : ''}
                                            {c.status ? ` • Status: ${c.status}` : ''}
                                        </div>
                                    </div>
                                    <Button
                                        onClick={() => {
                                            setSelected(c);
                                            setOpen(true);
                                        }}
                                    >
                                        Request access
                                    </Button>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Request emergency access</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-3">
                        <div className="text-sm">
                            Client: <span className="font-medium">{selected?.first_name} {selected?.last_name}</span>
                        </div>
                        <Separator />
                        <div className="space-y-2">
                            <Label>Reason (required)</Label>
                            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Covering unplanned sick leave – meds due" />
                        </div>
                        <div className="space-y-2">
                            <Label>Duration in minutes</Label>
                            <Input value={minutes} onChange={(e) => setMinutes(e.target.value)} placeholder="60" />
                            <div className="text-xs text-slate-500">Default: 60 minutes</div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={requestAccess} disabled={!reason.trim()}>
                            Grant access
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
