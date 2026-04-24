import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

type Props = {
    staff: Array<{ id: number; name: string }>;
};

const toLocalDateTimeInput = () => {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
};

export default function CreateDataBreach({ staff }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        nature_of_breach: '',
        discovered_at: toLocalDateTimeInput(),
        approximate_individuals_affected: '',
        likely_consequences: '',
        measures_taken: '',
        requires_authority_notification: false,
        requires_subject_notification: false,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/privacy/breaches');
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Data Breaches', href: '/privacy/breaches' },
            { title: 'Report Breach', href: '/privacy/breaches/create' },
        ]}>
            <Head title="Report Data Breach" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Report Data Breach</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            GDPR Article 33 - ICO notification required within 72 hours
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-5 w-5 text-red-500" />
                                Breach Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="discovered_at">Date Discovered *</Label>
                                    <Input
                                        id="discovered_at"
                                        type="datetime-local"
                                        value={data.discovered_at}
                                        onChange={(e) => setData('discovered_at', e.target.value)}
                                    />
                                    {errors.discovered_at && (
                                        <p className="text-xs text-red-500">{errors.discovered_at}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="approximate_individuals_affected">Approximate Individuals Affected</Label>
                                    <Input
                                        id="approximate_individuals_affected"
                                        type="number"
                                        min="0"
                                        value={data.approximate_individuals_affected}
                                        onChange={(e) => setData('approximate_individuals_affected', e.target.value)}
                                        placeholder="0"
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="nature_of_breach">Nature of Breach *</Label>
                                <Textarea
                                    id="nature_of_breach"
                                    value={data.nature_of_breach}
                                    onChange={(e) => setData('nature_of_breach', e.target.value)}
                                    placeholder="Describe the nature of the breach, including categories of data involved"
                                    rows={3}
                                />
                                {errors.nature_of_breach && (
                                    <p className="text-xs text-red-500">{errors.nature_of_breach}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="likely_consequences">Likely Consequences</Label>
                                <Textarea
                                    id="likely_consequences"
                                    value={data.likely_consequences}
                                    onChange={(e) => setData('likely_consequences', e.target.value)}
                                    placeholder="Describe the likely consequences of the breach"
                                    rows={3}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="measures_taken">Measures Taken</Label>
                                <Textarea
                                    id="measures_taken"
                                    value={data.measures_taken}
                                    onChange={(e) => setData('measures_taken', e.target.value)}
                                    placeholder="Describe measures taken or proposed to address the breach"
                                    rows={3}
                                />
                            </div>

                            <div className="space-y-3 pt-2">
                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="requires_authority_notification"
                                        checked={data.requires_authority_notification}
                                        onCheckedChange={(checked) => setData('requires_authority_notification', checked as boolean)}
                                    />
                                    <Label htmlFor="requires_authority_notification" className="text-sm font-normal">
                                        Requires ICO notification (within 72 hours)
                                    </Label>
                                </div>

                                <div className="flex items-center space-x-2">
                                    <Checkbox
                                        id="requires_subject_notification"
                                        checked={data.requires_subject_notification}
                                        onCheckedChange={(checked) => setData('requires_subject_notification', checked as boolean)}
                                    />
                                    <Label htmlFor="requires_subject_notification" className="text-sm font-normal">
                                        Requires notification to affected individuals
                                    </Label>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 pt-4">
                                <Button type="button" variant="outline" onClick={() => window.history.back()}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing} variant="destructive">
                                    {processing ? 'Reporting...' : 'Report Breach'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AppLayout>
    );
}
