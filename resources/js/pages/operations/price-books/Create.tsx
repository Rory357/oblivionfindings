import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

export default function PriceBookCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        is_default: false,
        effective_from: '',
        effective_to: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/price-books');
    };

    return (
        <AppLayout>
            <Head title="Create Price Book" />
            <PageHeader title="Create Price Book" description="Create a new price book for service rates." backHref="/operations/price-books" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader><CardTitle className="text-base">Price Book Details</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Name *</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. Standard Rate Card 2026"
                                />
                                {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Describe this price book..."
                                    rows={3}
                                />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="effective_from">Effective From</Label>
                                    <Input id="effective_from" type="date" value={data.effective_from} onChange={(e) => setData('effective_from', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="effective_to">Effective To</Label>
                                    <Input id="effective_to" type="date" value={data.effective_to} onChange={(e) => setData('effective_to', e.target.value)} />
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <input
                                    id="is_default"
                                    type="checkbox"
                                    checked={data.is_default}
                                    onChange={(e) => setData('is_default', e.target.checked)}
                                    className="h-4 w-4 rounded border-border"
                                />
                                <Label htmlFor="is_default" className="cursor-pointer">Set as default price book</Label>
                            </div>
                        </CardContent>
                    </Card>
                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/price-books')}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Create Price Book</Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
