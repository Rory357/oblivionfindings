import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, Link, useForm } from '@inertiajs/react';
import { Scale, LockOpen } from 'lucide-react';

type LegalHold = {
    id: number;
    hold_reference: string;
    hold_type: string;
    reason: string;
    legal_authority: string | null;
    review_date: string | null;
    related_records: string[] | null;
    status: 'active' | 'released' | string;
    imposed_at: string | null;
    released_at: string | null;
    release_reason: string | null;
};

type Props = {
    hold: LegalHold;
};

const parseRecords = (value: string) => {
    const entries = value
        .split(/\r?\n|,/)
        .map((v) => v.trim())
        .filter(Boolean);
    return entries.length ? entries : null;
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString();
};

export default function EditLegalHold({ hold }: Props) {
    const updateForm = useForm({
        reason: hold.reason || '',
        legal_authority: hold.legal_authority || '',
        review_date: hold.review_date || '',
        related_records: (hold.related_records || []).join('\n'),
    });

    const releaseForm = useForm({
        release_reason: '',
    });

    const { data, setData, processing, errors } = updateForm;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const payload = {
            ...data,
            related_records: parseRecords(data.related_records),
            legal_authority: data.legal_authority || null,
            review_date: data.review_date || null,
        };
        updateForm.transform(() => payload);
        updateForm.put(`/privacy/legal-holds/${hold.id}`, {
            onFinish: () => updateForm.transform((d) => d),
        });
    };

    const handleRelease = (e: React.FormEvent) => {
        e.preventDefault();
        releaseForm.post(`/privacy/legal-holds/${hold.id}/release`);
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Legal Holds', href: '/privacy/legal-holds' },
            { title: hold.hold_reference, href: `/privacy/legal-holds/${hold.id}/edit` },
        ]}>
            <Head title={`Legal Hold ${hold.hold_reference}`} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">Legal Hold {hold.hold_reference}</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            Type: {hold.hold_type} - Status: {hold.status}
                        </div>
                    </div>
                    <Link href="/privacy/legal-holds" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to legal holds
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Scale className="h-5 w-5 text-primary" />
                            Hold Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-3">
                        <div className="text-sm">
                            <div className="text-xs text-muted-foreground">Imposed</div>
                            <div>{formatDate(hold.imposed_at)}</div>
                        </div>
                        <div className="text-sm">
                            <div className="text-xs text-muted-foreground">Review Date</div>
                            <div>{formatDate(hold.review_date)}</div>
                        </div>
                        <div className="text-sm">
                            <div className="text-xs text-muted-foreground">Released</div>
                            <div>{formatDate(hold.released_at)}</div>
                        </div>
                    </CardContent>
                </Card>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Update Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label>Reason</Label>
                                <Textarea
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    rows={4}
                                />
                                {errors.reason && <p className="text-xs text-red-500">{errors.reason}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Legal Authority</Label>
                                    <Input
                                        value={data.legal_authority}
                                        onChange={(e) => setData('legal_authority', e.target.value)}
                                    />
                                    {errors.legal_authority && <p className="text-xs text-red-500">{errors.legal_authority}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Review Date</Label>
                                    <Input
                                        type="date"
                                        value={data.review_date}
                                        onChange={(e) => setData('review_date', e.target.value)}
                                    />
                                    {errors.review_date && <p className="text-xs text-red-500">{errors.review_date}</p>}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Related Records</Label>
                                <Textarea
                                    value={data.related_records}
                                    onChange={(e) => setData('related_records', e.target.value)}
                                    rows={3}
                                    placeholder="Record IDs or references (comma or newline separated)"
                                />
                                {errors.related_records && <p className="text-xs text-red-500">{errors.related_records}</p>}
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" onClick={() => window.history.back()}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>

                {hold.status !== 'released' ? (
                    <form onSubmit={handleRelease}>
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <LockOpen className="h-5 w-5 text-amber-500" />
                                    Release Hold
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label>Release Reason *</Label>
                                    <Textarea
                                        value={releaseForm.data.release_reason}
                                        onChange={(e) => releaseForm.setData('release_reason', e.target.value)}
                                        rows={3}
                                        placeholder="Why is this hold being released?"
                                    />
                                    {releaseForm.errors.release_reason && (
                                        <p className="text-xs text-red-500">{releaseForm.errors.release_reason}</p>
                                    )}
                                </div>
                                <div className="flex justify-end">
                                    <Button type="submit" variant="destructive" disabled={releaseForm.processing}>
                                        {releaseForm.processing ? 'Releasing...' : 'Release Hold'}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </form>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Release Notes</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            {hold.release_reason || 'No release reason recorded.'}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
