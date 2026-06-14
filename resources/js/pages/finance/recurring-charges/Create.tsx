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

export default function RecurringChargeCreate({ clients }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        client_id: '',
        description: '',
        amount: '',
        frequency: 'monthly',
        next_charge_date: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/recurring-charges');
    };

    return (
        <AppLayout>
            <Head title="Create Recurring Charge" />
            <PageHero category="finance" variant="compact" title="Create Recurring Charge" description="Set up a new recurring billing charge for a client." backHref="/finance/recurring-charges" />
            <PageShell>
                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Charge Details</CardTitle>
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
                                    <Label htmlFor="frequency">Frequency *</Label>
                                    <Select value={data.frequency} onValueChange={(v) => setData('frequency', v)}>
                                        <SelectTrigger id="frequency">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="weekly">Weekly</SelectItem>
                                            <SelectItem value="fortnightly">Fortnightly</SelectItem>
                                            <SelectItem value="monthly">Monthly</SelectItem>
                                            <SelectItem value="quarterly">Quarterly</SelectItem>
                                            <SelectItem value="annually">Annually</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.frequency && <p className="text-xs text-destructive">{errors.frequency}</p>}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="description">Description *</Label>
                                <Input
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="e.g. Weekly community access transport"
                                />
                                {errors.description && <p className="text-xs text-destructive">{errors.description}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="amount">Amount ($) *</Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        placeholder="0.00"
                                    />
                                    {errors.amount && <p className="text-xs text-destructive">{errors.amount}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="next_charge_date">Next Charge Date *</Label>
                                    <Input
                                        id="next_charge_date"
                                        type="date"
                                        value={data.next_charge_date}
                                        onChange={(e) => setData('next_charge_date', e.target.value)}
                                    />
                                    {errors.next_charge_date && <p className="text-xs text-destructive">{errors.next_charge_date}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="mt-4 flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/finance/recurring-charges')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Create Charge
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
