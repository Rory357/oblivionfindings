import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

export default function GeofenceCreate({ clients }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        latitude: '',
        longitude: '',
        radius: '100',
        description: '',
        is_active: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/geofences');
    };

    return (
        <AppLayout>
            <Head title="Create Geofence" />
            <PageHero variant="compact" title="Create Geofence" description="Define a new geofence zone for electronic visit verification." backHref="/operations/geofences" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Zone Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Zone Name *</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. Client Home - 123 Main St"
                                />
                                {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="latitude">Latitude *</Label>
                                    <Input
                                        id="latitude"
                                        type="number"
                                        step="any"
                                        value={data.latitude}
                                        onChange={(e) => setData('latitude', e.target.value)}
                                        placeholder="-36.8485"
                                    />
                                    {errors.latitude && <p className="text-xs text-destructive">{errors.latitude}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="longitude">Longitude *</Label>
                                    <Input
                                        id="longitude"
                                        type="number"
                                        step="any"
                                        value={data.longitude}
                                        onChange={(e) => setData('longitude', e.target.value)}
                                        placeholder="174.7633"
                                    />
                                    {errors.longitude && <p className="text-xs text-destructive">{errors.longitude}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="radius">Radius (metres) *</Label>
                                    <Input
                                        id="radius"
                                        type="number"
                                        min="1"
                                        value={data.radius}
                                        onChange={(e) => setData('radius', e.target.value)}
                                        placeholder="100"
                                    />
                                    {errors.radius && <p className="text-xs text-destructive">{errors.radius}</p>}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Optional description for this geofence zone..."
                                    rows={3}
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    id="is_active"
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                    className="h-4 w-4 rounded border-border"
                                />
                                <Label htmlFor="is_active" className="cursor-pointer">Active</Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/geofences')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Create Geofence
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
