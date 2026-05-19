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
    charge: {
        id: number;
        client_id: number | null;
        description: string;
        amount: string;
        frequency: string;
        next_charge_date: string | null;
        is_active: boolean;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

export default function RecurringChargeEdit({ charge, clients }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        client_id: charge.client_id ? String(charge.client_id) : '',
        description: charge.description ?? '',
        amount: charge.amount ?? '',
        frequency: charge.frequency ?? 'monthly',
        next_charge_date: charge.next_charge_date ?? '',
        is_active: charge.is_active,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/operations/recurring-charges/${charge.id}`);
    };

    return (
        <AppLayout>
            <Head title="Edit Recurring Charge" />
            <PageHero variant="compact" title="Edit Recurring Charge" description="Update an existing recurring billing charge." backHref="/operations/recurring-charges" />
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
                                    <Select value={data.client_id} onValueChange={(value) => setData('client_id', value)}>
                                        <SelectTrigger id="client_id">
                                            <SelectValue placeholder="Select client" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {clients.map((client) => (
                                                <SelectItem key={client.id} value={String(client.id)}>
                                                    {client.first_name} {client.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && <p className="text-xs text-destructive">{errors.client_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="frequency">Frequency *</Label>
                                    <Select value={data.frequency} onValueChange={(value) => setData('frequency', value)}>
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
                                />
                                {errors.description && <p className="text-xs text-destructive">{errors.description}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="amount">Amount ($) *</Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
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
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/recurring-charges')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save Changes
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
