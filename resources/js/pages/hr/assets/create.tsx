import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface SelectOption {
    value: string;
    label: string;
}

interface Props {
    categories: SelectOption[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Assets', href: '/hr/assets' },
    { title: 'New Asset', href: '/hr/assets/create' },
];

export default function CreateAsset({ categories }: Props) {
    const [form, setForm] = useState({
        asset_tag: '',
        name: '',
        category: 'laptop',
        serial_number: '',
        make: '',
        model: '',
        purchase_date: '',
        purchase_cost: '',
        warranty_expiry: '',
        notes: '',
    });

    const set = (key: string, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/assets', {
            ...form,
            purchase_cost: form.purchase_cost || null,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Asset" />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/assets"
                        title="Create Asset"
                        description="Register a new company asset"
                    />
                }
            >
                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Asset Tag</Label>
                                    <Input
                                        value={form.asset_tag}
                                        onChange={(e) =>
                                            set('asset_tag', e.target.value)
                                        }
                                        required
                                        placeholder="e.g. ASSET-001"
                                    />
                                </div>
                                <div>
                                    <Label>Name</Label>
                                    <Input
                                        value={form.name}
                                        onChange={(e) =>
                                            set('name', e.target.value)
                                        }
                                        required
                                    />
                                </div>
                                <div>
                                    <Label>Category</Label>
                                    <Select
                                        value={form.category}
                                        onValueChange={(val) =>
                                            set('category', val)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((cat) => (
                                                <SelectItem
                                                    key={cat.value}
                                                    value={cat.value}
                                                >
                                                    {cat.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Serial Number</Label>
                                    <Input
                                        value={form.serial_number}
                                        onChange={(e) =>
                                            set('serial_number', e.target.value)
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Make</Label>
                                    <Input
                                        value={form.make}
                                        onChange={(e) =>
                                            set('make', e.target.value)
                                        }
                                        placeholder="e.g. Dell, Apple"
                                    />
                                </div>
                                <div>
                                    <Label>Model</Label>
                                    <Input
                                        value={form.model}
                                        onChange={(e) =>
                                            set('model', e.target.value)
                                        }
                                        placeholder="e.g. Latitude 5540"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <Label>Purchase Date</Label>
                                    <Input
                                        type="date"
                                        value={form.purchase_date}
                                        onChange={(e) =>
                                            set('purchase_date', e.target.value)
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Purchase Cost (NZD)</Label>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        value={form.purchase_cost}
                                        onChange={(e) =>
                                            set('purchase_cost', e.target.value)
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Warranty Expiry</Label>
                                    <Input
                                        type="date"
                                        value={form.warranty_expiry}
                                        onChange={(e) =>
                                            set(
                                                'warranty_expiry',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div>
                                <Label>Notes</Label>
                                <Textarea
                                    value={form.notes}
                                    onChange={(e) =>
                                        set('notes', e.target.value)
                                    }
                                    rows={3}
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.get('/hr/assets')}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit">Create Asset</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
