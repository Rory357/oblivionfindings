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
import { Plus, Trash2 } from 'lucide-react';

type Agreement = {
    id: number;
    title: string;
    reference_number?: string | null;
    client_id: number;
    client?: { id: number; first_name: string; last_name: string } | null;
};

type ClaimItem = {
    description: string;
    quantity: string;
    unit_price: string;
    service_date: string;
    service_agreement_line_item_id: string;
    ndis_line_item_code: string;
};

type Props = {
    agreements: Agreement[];
};

const blankItem = (): ClaimItem => ({
    description: '',
    quantity: '1',
    unit_price: '0',
    service_date: '',
    service_agreement_line_item_id: '',
    ndis_line_item_code: '',
});

export default function FundingClaimCreate({ agreements }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        service_agreement_id: '',
        client_id: '',
        claim_reference: '',
        period_start: '',
        period_end: '',
        items: [blankItem()],
    });

    const selectedAgreement = agreements.find(
        (agreement) => String(agreement.id) === data.service_agreement_id,
    );

    const addItem = () => {
        setData('items', [...data.items, blankItem()]);
    };

    const removeItem = (index: number) => {
        setData('items', data.items.filter((_, itemIndex) => itemIndex !== index));
    };

    const updateItem = (
        index: number,
        key: keyof ClaimItem,
        value: string,
    ) => {
        setData('items', data.items.map((item, itemIndex) => (
            itemIndex === index ? { ...item, [key]: value } : item
        )));
    };

    const total = data.items.reduce((sum, item) => {
        const quantity = Number(item.quantity || 0);
        const price = Number(item.unit_price || 0);

        return sum + quantity * price;
    }, 0);

    return (
        <AppLayout>
            <Head title="Create Funding Claim" />
            <PageHero variant="compact"
                title="Create Funding Claim"
                description="Assemble a draft claim from a service agreement and billable line items."
                backHref="/operations/funding/claims"
            />
            <PageShell>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        post('/operations/funding/claims');
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Claim Setup</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Service Agreement</Label>
                                <Select
                                    value={data.service_agreement_id}
                                    onValueChange={(value) => {
                                        const agreement = agreements.find(
                                            (item) => String(item.id) === value,
                                        );
                                        setData({
                                            ...data,
                                            service_agreement_id: value,
                                            client_id: agreement ? String(agreement.client_id) : '',
                                            claim_reference: agreement?.reference_number ?? data.claim_reference,
                                        });
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select an agreement" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {agreements.map((agreement) => (
                                            <SelectItem key={agreement.id} value={String(agreement.id)}>
                                                {agreement.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.service_agreement_id && (
                                    <p className="text-xs text-destructive">{errors.service_agreement_id}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label>Claim Reference</Label>
                                <Input
                                    value={data.claim_reference}
                                    onChange={(event) => setData('claim_reference', event.target.value)}
                                    placeholder="Optional external claim reference"
                                />
                            </div>

                            <div className="space-y-2">
                                <Label>Period Start</Label>
                                <Input
                                    type="date"
                                    value={data.period_start}
                                    onChange={(event) => setData('period_start', event.target.value)}
                                />
                                {errors.period_start && (
                                    <p className="text-xs text-destructive">{errors.period_start}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label>Period End</Label>
                                <Input
                                    type="date"
                                    value={data.period_end}
                                    onChange={(event) => setData('period_end', event.target.value)}
                                />
                                {errors.period_end && (
                                    <p className="text-xs text-destructive">{errors.period_end}</p>
                                )}
                            </div>

                            <div className="rounded-lg border bg-muted/30 p-3 text-sm text-muted-foreground md:col-span-2">
                                Claiming for{' '}
                                <span className="font-medium text-foreground">
                                    {selectedAgreement?.client
                                        ? `${selectedAgreement.client.first_name} ${selectedAgreement.client.last_name}`
                                        : 'No client selected yet'}
                                </span>
                                .
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="text-base">Claim Items</CardTitle>
                            <Button type="button" size="sm" variant="outline" onClick={addItem}>
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                Add Item
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {data.items.map((item, index) => (
                                <div key={index} className="grid gap-3 rounded-lg border p-4 md:grid-cols-[1.4fr,0.7fr,0.7fr,1fr,40px]">
                                    <div className="space-y-2">
                                        <Label>Description</Label>
                                        <Input
                                            value={item.description}
                                            onChange={(event) => updateItem(index, 'description', event.target.value)}
                                            placeholder="Describe the delivered support"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Qty</Label>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            value={item.quantity}
                                            onChange={(event) => updateItem(index, 'quantity', event.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Unit Price</Label>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            value={item.unit_price}
                                            onChange={(event) => updateItem(index, 'unit_price', event.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Service Date</Label>
                                        <Input
                                            type="date"
                                            value={item.service_date}
                                            onChange={(event) => updateItem(index, 'service_date', event.target.value)}
                                        />
                                    </div>
                                    <div className="flex items-end">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="h-10 w-10 p-0"
                                            onClick={() => removeItem(index)}
                                            disabled={data.items.length === 1}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            {errors.items && (
                                <p className="text-xs text-destructive">{errors.items}</p>
                            )}

                            <div className="rounded-lg border bg-muted/30 px-4 py-3 text-sm">
                                Draft total:{' '}
                                <span className="font-semibold">
                                    {new Intl.NumberFormat('en-NZ', {
                                        style: 'currency',
                                        currency: 'NZD',
                                    }).format(total)}
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get('/operations/funding/claims')}
                        >
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
