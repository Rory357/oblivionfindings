import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

export default function ClientFundCreate({ clients }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        client_id: '',
        name: '',
        funding_source: '',
        total_budget: '',
        balance: '',
        starts_at: '',
        ends_at: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/client-funds');
    };

    return (
        <AppLayout>
            <Head title="Create Client Fund" />
            <PageHero variant="compact" title="Create Client Fund" description="Set up a new funding allocation for a client." backHref="/operations/client-funds" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Fund Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="client_id">Client *</Label>
                                    <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                        <SelectTrigger id="client_id">
                                            <SelectValue placeholder="Select client" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.first_name} {c.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && <p className="text-xs text-destructive">{errors.client_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="name">Fund Name *</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g. IF Funding 2026"
                                    />
                                    {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="funding_source">Funding Source</Label>
                                <Input
                                    id="funding_source"
                                    value={data.funding_source}
                                    onChange={(e) => setData('funding_source', e.target.value)}
                                    placeholder="e.g. Te Whatu Ora, ACC, NASC"
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="total_budget">Total Budget ($) *</Label>
                                    <Input
                                        id="total_budget"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.total_budget}
                                        onChange={(e) => setData('total_budget', e.target.value)}
                                        placeholder="0.00"
                                    />
                                    {errors.total_budget && <p className="text-xs text-destructive">{errors.total_budget}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="balance">Opening Balance ($)</Label>
                                    <Input
                                        id="balance"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.balance}
                                        onChange={(e) => setData('balance', e.target.value)}
                                        placeholder="Defaults to total budget"
                                    />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="starts_at">Start Date</Label>
                                    <Input id="starts_at" type="date" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ends_at">End Date</Label>
                                    <Input id="ends_at" type="date" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/client-funds')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Create Fund
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
