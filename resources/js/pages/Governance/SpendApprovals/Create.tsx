import { PageHero, PageLayout } from '@/components/page';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, HandCoins } from 'lucide-react';

interface Props extends PageProps {
    categories: Record<string, string>;
    thresholds: Record<string, number>;
    sites: Array<{ id: number; name: string }>;
}

export default function CreateSpendApproval({
    auth,
    categories,
    thresholds,
    sites,
}: Props) {
    const form = useForm({
        title: '',
        description: '',
        category: 'capex',
        amount: '' as string | number,
        currency: 'NZD',
        site_id: sites.length === 1 ? String(sites[0].id) : '',
        valid_until: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/governance/spend-approvals');
    };

    const numericAmount = Number(form.data.amount) || 0;
    const threshold =
        thresholds[form.data.category as keyof typeof thresholds] ?? 0;
    const requiresBoard = numericAmount >= threshold;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                {
                    title: 'Spend Approvals',
                    href: '/governance/spend-approvals',
                },
                {
                    title: 'New Request',
                    href: '/governance/spend-approvals/create',
                },
            ]}
        >
            <Head title="New Spend Approval" />

            <PageLayout
                hero={
                    <PageHero
                        icon={HandCoins}
                        category="governance"
                        title="Request Spend Approval"
                        description="Submit a spend item for board or finance-committee sign-off."
                        actions={
                            <Button asChild variant="outline">
                                <Link href="/governance/spend-approvals">
                                    <ArrowLeft className="mr-2 h-4 w-4" /> Back
                                </Link>
                            </Button>
                        }
                    />
                }
            >
                <form onSubmit={submit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>Request details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 lg:grid-cols-2">
                                <div>
                                    <Label htmlFor="title">Title</Label>
                                    <Input
                                        id="title"
                                        value={form.data.title}
                                        onChange={(e) =>
                                            form.setData(
                                                'title',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {form.errors.title && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.title}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="category">Category</Label>
                                    <Select
                                        value={form.data.category}
                                        onValueChange={(v) =>
                                            form.setData('category', v)
                                        }
                                    >
                                        <SelectTrigger id="category">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(categories).map(
                                                ([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-2">
                                <div>
                                    <Label htmlFor="site_id">Site</Label>
                                    <Select
                                        value={form.data.site_id}
                                        onValueChange={(value) =>
                                            form.setData('site_id', value)
                                        }
                                    >
                                        <SelectTrigger id="site_id">
                                            <SelectValue placeholder="Select a site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {sites.map((site) => (
                                                <SelectItem
                                                    key={site.id}
                                                    value={String(site.id)}
                                                >
                                                    {site.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.site_id && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.site_id}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="amount">Amount (NZD)</Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={form.data.amount}
                                        onChange={(e) =>
                                            form.setData(
                                                'amount',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    {form.errors.amount && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.amount}
                                        </p>
                                    )}
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Threshold for this category:{' '}
                                        {new Intl.NumberFormat('en-NZ', {
                                            style: 'currency',
                                            currency: 'NZD',
                                        }).format(threshold)}
                                    </p>
                                    {requiresBoard && numericAmount > 0 && (
                                        <p className="mt-1 text-xs font-medium text-status-warning">
                                            ⚠ This amount exceeds the threshold
                                            and will require a board resolution.
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="valid_until">
                                        Valid until (optional)
                                    </Label>
                                    <Input
                                        id="valid_until"
                                        type="date"
                                        value={form.data.valid_until}
                                        onChange={(e) =>
                                            form.setData(
                                                'valid_until',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    rows={5}
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="What is this spend for? Which site / service / project does it relate to?"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {form.processing ? 'Saving…' : 'Save Draft'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
