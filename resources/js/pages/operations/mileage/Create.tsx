import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

export default function MileageClaimCreate() {
    const { data, setData, post, processing, errors } = useForm({
        date: '',
        from_location: '',
        to_location: '',
        distance: '',
        rate: '0.97',
        purpose: '',
        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/mileage');
    };

    return (
        <AppLayout>
            <Head title="Create Mileage Claim" />
            <PageHero variant="compact" title="Create Mileage Claim" description="Submit a new mileage reimbursement claim." backHref="/operations/mileage" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Claim Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="date">Date *</Label>
                                <Input
                                    id="date"
                                    type="date"
                                    value={data.date}
                                    onChange={(e) => setData('date', e.target.value)}
                                />
                                {errors.date && <p className="text-xs text-destructive">{errors.date}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="from_location">From *</Label>
                                    <Input
                                        id="from_location"
                                        value={data.from_location}
                                        onChange={(e) => setData('from_location', e.target.value)}
                                        placeholder="e.g. Office, 45 Queen St"
                                    />
                                    {errors.from_location && <p className="text-xs text-destructive">{errors.from_location}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="to_location">To *</Label>
                                    <Input
                                        id="to_location"
                                        value={data.to_location}
                                        onChange={(e) => setData('to_location', e.target.value)}
                                        placeholder="e.g. Client Home, 12 Park Ave"
                                    />
                                    {errors.to_location && <p className="text-xs text-destructive">{errors.to_location}</p>}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="distance">Distance (km) *</Label>
                                    <Input
                                        id="distance"
                                        type="number"
                                        step="0.1"
                                        min="0"
                                        value={data.distance}
                                        onChange={(e) => setData('distance', e.target.value)}
                                        placeholder="0.0"
                                    />
                                    {errors.distance && <p className="text-xs text-destructive">{errors.distance}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="rate">Rate ($/km) *</Label>
                                    <Input
                                        id="rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.rate}
                                        onChange={(e) => setData('rate', e.target.value)}
                                        placeholder="0.97"
                                    />
                                    {errors.rate && <p className="text-xs text-destructive">{errors.rate}</p>}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="purpose">Purpose *</Label>
                                <Input
                                    id="purpose"
                                    value={data.purpose}
                                    onChange={(e) => setData('purpose', e.target.value)}
                                    placeholder="e.g. Client visit - John Smith"
                                />
                                {errors.purpose && <p className="text-xs text-destructive">{errors.purpose}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Any additional notes..."
                                    rows={2}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/mileage')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Create Claim
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
