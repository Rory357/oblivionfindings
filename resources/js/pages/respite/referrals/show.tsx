import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { Head, Link, useForm } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';

type Props = {
    referral: any;
};

export default function RespiteReferralShow({ referral }: Props) {
    const form = useForm({
        status: referral.status,
        triage_notes: referral.triage_notes ?? '',
        risk_level: referral.risk_level ?? '',
    });

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Referral', href: `/respite/referrals/${referral.id}` },
        ]}>
            <Head title="Respite Referral" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <CalendarDays className="h-5 w-5 text-muted-foreground" />
                            {referral.client?.first_name} {referral.client?.last_name}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="outline">{referral.status}</Badge>
                            <Badge variant="outline">{referral.urgency}</Badge>
                        </div>
                    </div>
                    <Link href="/respite" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to list
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Referral Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        <div>Referrer: {referral.referrer_name}</div>
                        <div>Contact: {referral.referrer_contact || 'Not provided'}</div>
                        <div>Reason: {referral.referral_reason}</div>
                    </CardContent>
                </Card>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/respite/referrals/${referral.id}`);
                    }}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Triage</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label className="text-xs text-muted-foreground">Status</label>
                                    <select
                                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                                        value={form.data.status}
                                        onChange={(e) => form.setData('status', e.target.value)}
                                    >
                                        {['received', 'triaged', 'accepted', 'declined'].map((s) => (
                                            <option key={s} value={s}>{s}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="text-xs text-muted-foreground">Risk Level</label>
                                    <select
                                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                                        value={form.data.risk_level}
                                        onChange={(e) => form.setData('risk_level', e.target.value)}
                                    >
                                        <option value="">Not set</option>
                                        {['low', 'medium', 'high', 'critical'].map((s) => (
                                            <option key={s} value={s}>{s}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label className="text-xs text-muted-foreground">Triage Notes</label>
                                <textarea
                                    className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                                    rows={4}
                                    value={form.data.triage_notes}
                                    onChange={(e) => form.setData('triage_notes', e.target.value)}
                                />
                            </div>
                            <div className="flex justify-end">
                                <Button type="submit" size="sm">Update</Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AppLayout>
    );
}
