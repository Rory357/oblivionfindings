import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as budgetsIndex } from '@/routes/governance/budgets';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { DollarSign, TrendingUp, TrendingDown, AlertTriangle, CheckCircle, Wallet } from 'lucide-react';
import { cn } from '@/lib/utils';

interface LineItem {
  id: number;
  category: string;
  line_item_code: string;
  description: string;
  budget_amount: number;
  forecast_amount: number;
  actual_amount: number;
  variance: number;
  variance_explanation: string | null;
}

interface Adjustment {
  id: number;
  adjustment_type: string;
  from_line_item: { description: string } | null;
  to_line_item: { description: string } | null;
  amount: number;
  reason: string;
  status: string;
  requested_by: { name: string };
  approved_by: { name: string } | null;
}

interface Budget {
  id: number;
  fiscal_year: string;
  title: string;
  total_budget: number;
  currency: string;
  status: string;
  version_number: number;
  approval_resolution: { resolution_reference: string; outcome: string } | null;
  approved_at: string | null;
  approved_by: { name: string } | null;
  line_items: LineItem[];
  adjustments: Adjustment[];
}

interface Props extends PageProps {
  budget: Budget;
}

export default function BudgetShow({ auth, budget }: Props) {
  const getCategoryLabel = (category: string) => {
    const labels: Record<string, string> = {
      staffing: 'Staffing',
      operations: 'Operations',
      fleet: 'Fleet',
      compliance: 'Compliance',
      capital: 'Capital',
      admin: 'Administration',
    };
    return labels[category] || category;
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'approved':
        return 'bg-green-100 text-green-800';
      case 'under_review':
        return 'bg-yellow-100 text-yellow-800';
      case 'draft':
        return 'bg-gray-100 text-gray-800';
      case 'rejected':
        return 'bg-red-100 text-red-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-NZ', {
      style: 'currency',
      currency: budget.currency || 'NZD',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(amount);
  };

  const groupLineItemsByCategory = () => {
    const grouped: Record<string, LineItem[]> = {};
    budget.line_items.forEach((item) => {
      if (!grouped[item.category]) {
        grouped[item.category] = [];
      }
      grouped[item.category].push(item);
    });
    return grouped;
  };

  const calculateTotals = () => {
    const totals = budget.line_items.reduce(
      (acc, item) => ({
        budget: acc.budget + item.budget_amount,
        forecast: acc.forecast + item.forecast_amount,
        actual: acc.actual + item.actual_amount,
      }),
      { budget: 0, forecast: 0, actual: 0 }
    );
    return {
      ...totals,
      variance: totals.actual - totals.budget,
      variancePercent: totals.budget > 0 ? ((totals.actual - totals.budget) / totals.budget) * 100 : 0,
      utilization: totals.budget > 0 ? (totals.actual / totals.budget) * 100 : 0,
    };
  };

  const totals = calculateTotals();

  const getCategoryTotals = (items: LineItem[]) => {
    return items.reduce(
      (acc, item) => ({
        budget: acc.budget + item.budget_amount,
        actual: acc.actual + item.actual_amount,
        variance: acc.variance + item.variance,
      }),
      { budget: 0, actual: 0, variance: 0 }
    );
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Budgets', href: '/governance/budgets' },
        { title: 'Budget', href: `/governance/budgets/${budget.id}` },
      ]}
    >
      <Head title={`Budget - ${budget.fiscal_year}`} />

      <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
          {/* Back Link */}
          <div className="mb-4">
            <Link href={budgetsIndex.url()} className="text-sm text-blue-600 hover:underline">
              ← Back to Budgets
            </Link>
          </div>

          {/* Header */}
          <div className="flex items-start justify-between mb-6">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <Wallet className="w-8 h-8 text-green-500" />
                <div>
                  <h1 className="text-2xl font-bold text-gray-900">{budget.title || `FY${budget.fiscal_year} Budget`}</h1>
                  <p className="text-gray-500">Fiscal Year {budget.fiscal_year}</p>
                </div>
              </div>
              <div className="flex items-center gap-2 mt-2">
                <Badge className={getStatusColor(budget.status)}>{budget.status}</Badge>
                <Badge variant="outline">v{budget.version_number}</Badge>
              </div>
            </div>
            {budget.status === 'draft' && (
              <Button>Submit for Approval</Button>
            )}
          </div>

          {/* Summary Cards */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-gray-500">Total Budget</p>
                    <p className="text-2xl font-bold text-gray-900">{formatCurrency(totals.budget)}</p>
                  </div>
                  <DollarSign className="w-8 h-8 text-gray-400" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-gray-500">Actual Spend</p>
                    <p className="text-2xl font-bold text-gray-900">{formatCurrency(totals.actual)}</p>
                  </div>
                  <Wallet className="w-8 h-8 text-blue-400" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-gray-500">Utilization</p>
                    <p className="text-2xl font-bold text-gray-900">{totals.utilization.toFixed(1)}%</p>
                  </div>
                  <Progress value={totals.utilization} className="w-16" />
                </div>
              </CardContent>
            </Card>
            <Card className={cn(
              totals.variance > 0 && 'border-red-200',
              totals.variance < 0 && 'border-green-200',
            )}>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-gray-500">Variance</p>
                    <p className={cn(
                      'text-2xl font-bold',
                      totals.variance > 0 && 'text-red-600',
                      totals.variance < 0 && 'text-green-600',
                      totals.variance === 0 && 'text-gray-900',
                    )}>
                      {totals.variance > 0 ? '+' : ''}{formatCurrency(totals.variance)}
                    </p>
                  </div>
                  {totals.variance > 0 ? (
                    <TrendingUp className="w-8 h-8 text-red-400" />
                  ) : (
                    <TrendingDown className="w-8 h-8 text-green-400" />
                  )}
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Approval Status */}
          {budget.approval_resolution && (
            <Card className="mb-6 border-green-200 bg-green-50">
              <CardContent className="pt-6">
                <div className="flex items-center gap-3">
                  <CheckCircle className="w-6 h-6 text-green-600" />
                  <div>
                    <p className="font-medium text-green-800">Board Approved</p>
                    <p className="text-sm text-green-600">
                      Resolution {budget.approval_resolution.resolution_reference} •
                      Approved by {budget.approved_by?.name} on {budget.approved_at}
                    </p>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Line Items by Category */}
          <div className="space-y-6 mb-6">
            <h2 className="text-xl font-bold text-gray-900">Budget Line Items</h2>

            {Object.entries(groupLineItemsByCategory()).map(([category, items]) => {
              const categoryTotals = getCategoryTotals(items);
              return (
                <Card key={category}>
                  <CardHeader>
                    <div className="flex items-center justify-between">
                      <CardTitle>{getCategoryLabel(category)}</CardTitle>
                      <div className="text-right">
                        <p className="text-sm text-gray-500">Budget: {formatCurrency(categoryTotals.budget)}</p>
                        <p className={cn(
                          'text-sm font-medium',
                          categoryTotals.variance > 0 && 'text-red-600',
                          categoryTotals.variance < 0 && 'text-green-600',
                        )}>
                          Variance: {categoryTotals.variance > 0 ? '+' : ''}{formatCurrency(categoryTotals.variance)}
                        </p>
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent>
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead>
                          <tr className="border-b">
                            <th className="text-left py-2 font-medium">Description</th>
                            <th className="text-right py-2 font-medium">Budget</th>
                            <th className="text-right py-2 font-medium">Actual</th>
                            <th className="text-right py-2 font-medium">Variance</th>
                            <th className="text-right py-2 font-medium">%</th>
                          </tr>
                        </thead>
                        <tbody>
                          {items.map((item) => {
                            const variancePct = item.budget_amount > 0
                              ? (item.variance / item.budget_amount) * 100
                              : 0;
                            return (
                              <tr key={item.id} className="border-b last:border-0">
                                <td className="py-2">
                                  <div>
                                    <p className="font-medium">{item.description}</p>
                                    <p className="text-xs text-gray-500">{item.line_item_code}</p>
                                  </div>
                                </td>
                                <td className="text-right py-2">{formatCurrency(item.budget_amount)}</td>
                                <td className="text-right py-2">{formatCurrency(item.actual_amount)}</td>
                                <td className={cn(
                                  'text-right py-2 font-medium',
                                  item.variance > 0 && 'text-red-600',
                                  item.variance < 0 && 'text-green-600',
                                )}>
                                  {item.variance > 0 ? '+' : ''}{formatCurrency(item.variance)}
                                </td>
                                <td className={cn(
                                  'text-right py-2',
                                  Math.abs(variancePct) > 10 && 'font-medium',
                                  variancePct > 10 && 'text-red-600',
                                  variancePct < -10 && 'text-green-600',
                                )}>
                                  {variancePct > 0 ? '+' : ''}{variancePct.toFixed(1)}%
                                  {Math.abs(variancePct) > 10 && (
                                    <AlertTriangle className="inline w-4 h-4 ml-1" />
                                  )}
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>

          {/* Adjustments */}
          {budget.adjustments.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Budget Adjustments</CardTitle>
                <CardDescription>Approved and pending budget modifications</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {budget.adjustments.map((adj) => (
                    <div key={adj.id} className="p-4 border rounded-lg">
                      <div className="flex items-start justify-between">
                        <div>
                          <Badge variant="outline" className="capitalize mb-2">{adj.adjustment_type}</Badge>
                          <p className="font-medium">{formatCurrency(adj.amount)}</p>
                          <p className="text-sm text-gray-600">{adj.reason}</p>
                          {adj.from_line_item && adj.to_line_item && (
                            <p className="text-xs text-gray-500 mt-1">
                              From: {adj.from_line_item.description} → To: {adj.to_line_item.description}
                            </p>
                          )}
                        </div>
                        <div className="text-right">
                          <Badge className={cn(
                            adj.status === 'approved' && 'bg-green-100 text-green-800',
                            adj.status === 'rejected' && 'bg-red-100 text-red-800',
                            adj.status === 'under_review' && 'bg-yellow-100 text-yellow-800',
                            adj.status === 'draft' && 'bg-gray-100 text-gray-800',
                          )}>
                            {adj.status.replace('_', ' ')}
                          </Badge>
                          <p className="text-xs text-gray-500 mt-1">
                            by {adj.requested_by?.name}
                          </p>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}
      </div>
    </AppLayout>
  );
}
