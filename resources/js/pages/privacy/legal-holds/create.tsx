import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, useForm } from '@inertiajs/react';
import { Scale } from 'lucide-react';

const HOLD_TYPES = [
    { value: 'litigation', label: 'Litigation' },
    { value: 'investigation', label: 'Investigation' },
    { value: 'regulatory', label: 'Regulatory' },
    { value: 'audit', label: 'Audit' },
    { value: 'other', label: 'Other' },
];

const parseRecords = (value: string) => {
    const entries = value
        .split(/\r?\n|,/)
        .map((v) => v.trim())
        .filter(Boolean);
    return entries.length ? entries : null;
};

export default function CreateLegalHold() {
    const form = useForm({
        hold_type: '',
        reason: '',
        legal_authority: '',
        review_date: '',
        related_records: '',
        holdable_type: '',
        holdable_id: '',
    });

    const { data, setData, processing, errors } = form;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const payload = {
            ...data,
            related_records: parseRecords(data.related_records),
            legal_authority: data.legal_authority || null,
            review_date: data.review_date || null,
            holdable_type: data.holdable_type || null,
            holdable_id: data.holdable_id ? Number(data.holdable_id) : null,
        };
        form.transform(() => payload);
        form.post('/privacy/legal-holds', {
            onFinish: () => form.transform((d) => d),
        });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Legal Holds', href: '/privacy/legal-holds' },
            { title: 'New Hold', href: '/privacy/legal-holds/create' },
        ]}>
            <Head title="New Legal Hold" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">New Legal Hold</h1>
                    <div className="mt-1 text-sm text-slate-500">
                        Preserve records for litigation, investigations, or regulatory matters.
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Scale className="h-5 w-5 text-purple-500" />
                                Hold Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Hold Type *</Label>
                                    <Select value={data.hold_type} onValueChange={(v) => setData('hold_type', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select hold type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {HOLD_TYPES.map((t) => (
                                                <SelectItem key={t.value} value={t.value}>
                                                    {t.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.hold_type && <p className="text-xs text-red-500">{errors.hold_type}</p>}
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
                                <Label>Reason *</Label>
                                <Textarea
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    rows={4}
                                    placeholder="Why is this legal hold being imposed?"
                                />
                                {errors.reason && <p className="text-xs text-red-500">{errors.reason}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Legal Authority</Label>
                                    <Input
                                        value={data.legal_authority}
                                        onChange={(e) => setData('legal_authority', e.target.value)}
                                        placeholder="Court order, regulator, legal counsel, etc."
                                    />
                                    {errors.legal_authority && <p className="text-xs text-red-500">{errors.legal_authority}</p>}
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
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Link to a Record (Optional)</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Record Type</Label>
                                <Input
                                    value={data.holdable_type}
                                    onChange={(e) => setData('holdable_type', e.target.value)}
                                    placeholder="e.g. App\\Models\\Client"
                                />
                                {errors.holdable_type && <p className="text-xs text-red-500">{errors.holdable_type}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Record ID</Label>
                                <Input
                                    type="number"
                                    min="1"
                                    value={data.holdable_id}
                                    onChange={(e) => setData('holdable_id', e.target.value)}
                                    placeholder="Numeric ID"
                                />
                                {errors.holdable_id && <p className="text-xs text-red-500">{errors.holdable_id}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Hold'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
