import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock, Flag, MapPin, Shield } from 'lucide-react';

type EvvRecord = {
    id: number;
    status: string;
    check_in_time: string | null;
    check_out_time: string | null;
    check_in_latitude: string | number | null;
    check_in_longitude: string | number | null;
    check_out_latitude: string | number | null;
    check_out_longitude: string | number | null;
    distance_from_site_in: string | number | null;
    distance_from_site_out: string | number | null;
    issue_description: string | null;
    notes: string | null;
    worker: { id: number; name: string } | null;
    client: { id: number; first_name: string; last_name: string } | null;
    shift: {
        id: number;
        starts_at: string | null;
        ends_at: string | null;
        site: { id: number; name: string } | null;
    } | null;
};

type Props = {
    record: EvvRecord;
};

function formatDateTime(value: string | null): string {
    if (!value) return '-';
    return new Date(value).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function coordinate(latitude: string | number | null, longitude: string | number | null): string {
    if (latitude === null || longitude === null) return '-';
    return `${latitude}, ${longitude}`;
}

export default function EvvShow({ record }: Props) {
    const { data, setData, patch, processing, errors } = useForm({
        verification_status: record.status === 'flagged' ? 'flagged' : 'verified',
        verification_notes: record.issue_description ?? record.notes ?? '',
    });

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(`/operations/evv/${record.id}/verify`, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={`EVV Record #${record.id}`} />
            <PageHeader title={`EVV Record #${record.id}`} description="Review visit timing, location evidence, and verification status." backHref="/operations/evv" />
            <PageShell>
                <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
                    <div className="space-y-4">
                        <Card>
                            <CardContent className="flex flex-wrap items-center gap-4 p-5">
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                    <Shield className="h-6 w-6" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <h2 className="text-lg font-semibold">
                                            {record.client ? `${record.client.first_name} ${record.client.last_name}` : 'Client visit'}
                                        </h2>
                                        <Badge variant={record.status === 'flagged' ? 'destructive' : record.status === 'verified' ? 'default' : 'outline'} className="capitalize">
                                            {record.status}
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {record.worker?.name ?? 'Unassigned worker'}
                                        {record.shift?.site ? ` at ${record.shift.site.name}` : ''}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 md:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Clock className="h-4 w-4" />
                                        Visit Times
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">Check in</span>
                                        <span className="text-right font-medium">{formatDateTime(record.check_in_time)}</span>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">Check out</span>
                                        <span className="text-right font-medium">{formatDateTime(record.check_out_time)}</span>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">Scheduled</span>
                                        <span className="text-right font-medium">
                                            {formatDateTime(record.shift?.starts_at ?? null)} - {formatDateTime(record.shift?.ends_at ?? null)}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <MapPin className="h-4 w-4" />
                                        Location Evidence
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">Check in GPS</span>
                                        <span className="text-right font-medium">{coordinate(record.check_in_latitude, record.check_in_longitude)}</span>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">Check out GPS</span>
                                        <span className="text-right font-medium">{coordinate(record.check_out_latitude, record.check_out_longitude)}</span>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <span className="text-muted-foreground">Distance in/out</span>
                                        <span className="text-right font-medium">
                                            {record.distance_from_site_in ?? '-'}m / {record.distance_from_site_out ?? '-'}m
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {(record.issue_description || record.notes) && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Flag className="h-4 w-4" />
                                        Notes
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="whitespace-pre-wrap text-sm text-muted-foreground">
                                    {record.issue_description ?? record.notes}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Verification</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="verification_status">Status</Label>
                                    <select
                                        id="verification_status"
                                        value={data.verification_status}
                                        onChange={(event) => setData('verification_status', event.target.value)}
                                        className="border-input bg-background flex h-9 w-full rounded-md border px-3 py-1 text-sm"
                                    >
                                        <option value="verified">Verified</option>
                                        <option value="flagged">Flagged</option>
                                    </select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="verification_notes">Notes</Label>
                                    <Textarea
                                        id="verification_notes"
                                        value={data.verification_notes}
                                        onChange={(event) => setData('verification_notes', event.target.value)}
                                        rows={4}
                                    />
                                    {errors.verification_notes && <p className="text-xs text-destructive">{errors.verification_notes}</p>}
                                </div>
                                <Button type="submit" disabled={processing} className="w-full">
                                    <CheckCircle2 className="mr-1.5 h-4 w-4" />
                                    Save Verification
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
