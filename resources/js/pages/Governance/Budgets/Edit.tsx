import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Wallet } from 'lucide-react';

interface Budget {
    id: number;
    fiscal_year: string;
    title: string;
    description: string | null;
    total_budget: number;
    notes: string | null;
    status: string;
}

export default function EditBudget({
    auth,
    budget,
}: {
    auth: any;
    budget: Budget;
}) {
    const { data, setData, put, processing, errors } = useForm({
        fiscal_year: budget.fiscal_year,
        title: budget.title,
        description: budget.description ?? '',
        total_budget: budget.total_budget,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/governance/budgets/${budget.id}`);
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Budgets', href: '/governance/budgets' },
                {
                    title: budget.title || `FY${budget.fiscal_year}`,
                    href: `/governance/budgets/${budget.id}`,
                },
                {
                    title: 'Edit',
                    href: `/governance/budgets/${budget.id}/edit`,
                },
            ]}
        >
            <Head title={`Edit: ${budget.title}`} />
            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref={`/governance/budgets/${budget.id}`}
                        icon={Wallet}
                        title="Edit Budget"
                        description={budget.title || `FY${budget.fiscal_year}`}
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Budget Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label>Title</Label>
                                    <Input
                                        value={data.title}
                                        onChange={(e) =>
                                            setData('title', e.target.value)
                                        }
                                    />
                                    {errors.title && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.title}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Fiscal Year</Label>
                                    <Input
                                        value={data.fiscal_year}
                                        onChange={(e) =>
                                            setData(
                                                'fiscal_year',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="2025-2026"
                                    />
                                    {errors.fiscal_year && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {errors.fiscal_year}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div>
                                <Label>Total Budget ($)</Label>
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={data.total_budget}
                                    onChange={(e) =>
                                        setData(
                                            'total_budget',
                                            parseFloat(e.target.value),
                                        )
                                    }
                                />
                                {errors.total_budget && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.total_budget}
                                    </p>
                                )}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    This is automatically recalculated when line
                                    items are added or removed.
                                </p>
                            </div>
                            <div>
                                <Label>Description</Label>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    rows={3}
                                    placeholder="Budget purpose and scope..."
                                />
                            </div>
                            <div className="flex gap-2 pt-4">
                                <Button type="submit" disabled={processing}>
                                    Update Budget
                                </Button>
                                <Button type="button" variant="outline" asChild>
                                    <Link
                                        href={`/governance/budgets/${budget.id}`}
                                    >
                                        Cancel
                                    </Link>
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
