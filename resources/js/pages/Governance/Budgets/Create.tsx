import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { store as budgetsStore } from '@/routes/governance/budgets';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';

export default function BudgetsCreate({ auth }: PageProps) {
    const { data, setData, post, processing, errors } = useForm({
        fiscal_year: new Date().getFullYear(),
        title: '',
        total_budget: '',
        board_approved: false,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(budgetsStore.url());
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Budgets', href: '/governance/budgets' },
                { title: 'Create', href: '/governance/budgets/create' },
            ]}
        >
            <Head title="New Budget" />

            <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <Card>
                    <CardHeader>
                        <CardTitle>Create Budget</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label htmlFor="fiscal_year">Fiscal Year</Label>
                                <Input
                                    id="fiscal_year"
                                    type="number"
                                    min={2000}
                                    max={2100}
                                    value={data.fiscal_year}
                                    onChange={(e) =>
                                        setData(
                                            'fiscal_year',
                                            parseInt(e.target.value, 10),
                                        )
                                    }
                                />
                                {errors.fiscal_year && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.fiscal_year}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="title">Title (optional)</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    placeholder="e.g., FY2026 Operating Budget"
                                />
                                {errors.title && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="total_budget">
                                    Total Budget
                                </Label>
                                <Input
                                    id="total_budget"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={data.total_budget}
                                    onChange={(e) =>
                                        setData('total_budget', e.target.value)
                                    }
                                />
                                {errors.total_budget && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {errors.total_budget}
                                    </p>
                                )}
                            </div>

                            <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Checkbox
                                    checked={data.board_approved}
                                    onCheckedChange={(v) =>
                                        setData('board_approved', !!v)
                                    }
                                />
                                Already approved by board
                            </label>

                            <div className="flex gap-2 pt-2">
                                <Button type="submit" disabled={processing}>
                                    Create Budget
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
