import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHero, PageLayout } from '@/components/page';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    requests: Array<{ id: number; client: { first_name: string; last_name: string } | null }>;
    pendingRequests: Array<{ id: number; client: { first_name: string; last_name: string } | null; status: string }>;
    coordinators: Array<{ id: number; name: string }>;
};

export default function RespiteBookingCreate({ clients, requests, pendingRequests, coordinators }: Props) {
    const form = useForm({
        booking_request_id: '',
        client_id: '',
        start_at: '',
        end_at: '',
        assigned_coordinator_id: '',
    });

    const { data, setData, post, processing, errors } = form;

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'New Booking', href: '/respite/bookings/create' },
        ]}>
            <Head title="New Respite Booking" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/bookings"
                        title="New Booking"
                        description="Bookings are confirmed placements. Start from an approved request where possible."
                    />
                }
            >
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/respite/bookings');
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Booking Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Approved Request</Label>
                                    <Select value={data.booking_request_id} onValueChange={(v) => setData('booking_request_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                                        <SelectContent>
                                            {requests.map((r) => (
                                                <SelectItem key={r.id} value={String(r.id)}>
                                                    #{r.id} {r.client ? `- ${r.client.first_name} ${r.client.last_name}` : ''}
                                                </SelectItem>
                                            ))}
                                            {requests.length === 0 && pendingRequests.length > 0 && (
                                                <SelectItem value="__pending__" disabled>
                                                    Pending requests (approve first)
                                                </SelectItem>
                                            )}
                                            {pendingRequests.map((r) => (
                                                <SelectItem key={`pending-${r.id}`} value={`pending-${r.id}`} disabled>
                                                    #{r.id} {r.client ? `- ${r.client.first_name} ${r.client.last_name}` : ''} ({r.status})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {requests.length === 0 && (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            No approved requests yet. Approve a booking request first, or proceed ad-hoc.
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <Label>Client *</Label>
                                    <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.first_name} {c.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && <div className="mt-1 text-xs text-status-critical">{errors.client_id}</div>}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Start *</Label>
                                    <Input type="datetime-local" value={data.start_at} onChange={(e) => setData('start_at', e.target.value)} />
                                    {errors.start_at && <div className="mt-1 text-xs text-status-critical">{errors.start_at}</div>}
                                </div>
                                <div>
                                    <Label>End *</Label>
                                    <Input type="datetime-local" value={data.end_at} onChange={(e) => setData('end_at', e.target.value)} />
                                    {errors.end_at && <div className="mt-1 text-xs text-status-critical">{errors.end_at}</div>}
                                </div>
                            </div>

                            <div>
                                <Label>Coordinator</Label>
                                <Select value={data.assigned_coordinator_id} onValueChange={(v) => setData('assigned_coordinator_id', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select coordinator" /></SelectTrigger>
                                    <SelectContent>
                                        {coordinators.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Create Booking'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
