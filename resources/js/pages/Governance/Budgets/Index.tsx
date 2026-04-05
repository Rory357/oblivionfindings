import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { show as showBudget } from '@/routes/governance/budgets';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { DollarSign, CheckCircle, AlertTriangle, Clock, FileText } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Budget {
  id: number;
  fiscal_year: string;
  title: string | null;
  total_budget: number;
  status: string;
  version_number: number;
  approved_by_board_at: string | null;
  line_items_count: number;
  total_allocated: number;
  total_actual: number;
}

interface Props extends PageProps {
  budgets: Budget[] | { data: Budget[] };
}

export default function BudgetsIndex({ auth, budgets }: Props) {
  const budgetItems = Array.isArray(budgets) ? budgets : budgets?.data ?? [];

  const getStatusColor = (status: string) => {
    return {
      drafting: 'bg-gray-100 text-gray-800',
      proposed: 'bg-yellow-100 text-yellow-800',
      under_review: 'bg-blue-100 text-blue-800',
      approved: 'bg-green-100 text-green-800',
      rejected: 'bg-red-100 text-red-800',
    }[status] || 'bg-gray-100 text-gray-800';
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'approved': return <CheckCircle className="w-4 h-4 text-green-600" />;
      case 'proposed':
      case 'under_review': return <Clock className="w-4 h-4 text-yellow-600" />;
      case 'rejected': return <AlertTriangle className="w-4 h-4 text-red-600" />;
      default: return <FileText className="w-4 h-4 text-gray-400" />;
    }
  };

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-NZ', {
      style: 'currency',
      currency: 'NZD',
      maximumFractionDigits: 0,
    }).format(amount);
  };

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Budgets', href: '/governance/budgets' },
      ]}
    >
      <Head title="Budgets" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Budgets</h1>
            <p className="text-gray-500 mt-1">Financial planning and oversight</p>
          </div>
          {(auth.can as any)?.governance?.budgets?.create && (
            <Button asChild>
              <Link href="/governance/budgets/create">New Budget</Link>
            </Button>
          )}
        </div>

        {/* Summary Stats */}
        {budgetItems.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-gray-500">Total Budgets</p>
                    <p className="text-2xl font-bold">{budgetItems.length}</p>
                  </div>
                  <DollarSign className="w-8 h-8 text-gray-400" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-gray-500">Approved</p>
                    <p className="text-2xl font-bold text-green-600">
                      {budgetItems.filter(b => b.status === 'approved').length}
                    </p>
                  </div>
                  <CheckCircle className="w-8 h-8 text-green-400" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-gray-500">Pending Review</p>
                    <p className="text-2xl font-bold text-yellow-600">
                      {budgetItems.filter(b => ['proposed', 'under_review'].includes(b.status)).length}
                    </p>
                  </div>
                  <Clock className="w-8 h-8 text-yellow-400" />
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {/* Budgets List */}
        {budgetItems.length === 0 ? (
          <Card>
            <CardContent className="pt-6">
              <div className="text-center py-12">
                <DollarSign className="mx-auto h-12 w-12 text-gray-300" />
                <h3 className="mt-2 text-sm font-semibold text-gray-900">No budgets yet</h3>
                <p className="mt-1 text-sm text-gray-500">Create your first budget to start financial planning.</p>
                <div className="mt-6">
                  <Button asChild>
                    <Link href="/governance/budgets/create">Create Budget</Link>
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-4">
            {budgetItems.map((budget) => {
              const utilization = budget.total_allocated > 0
                ? (budget.total_actual / budget.total_allocated) * 100
                : 0;
              const variance = budget.total_actual - budget.total_allocated;

              return (
                <Card key={budget.id} className="hover:shadow-md transition-shadow">
                  <CardContent className="pt-6">
                    <div className="flex items-start justify-between">
                      <div className="flex-1">
                        <div className="flex items-center gap-3 mb-2">
                          <h3 className="text-xl font-semibold">
                            <Link
                              href={showBudget.url({ budget: budget.id })}
                              className="hover:text-blue-600"
                            >
                              {budget.title || `Budget ${budget.fiscal_year}`}
                            </Link>
                          </h3>
                          <Badge className={cn(getStatusColor(budget.status))}>
                            {budget.status.replace('_', ' ')}
                          </Badge>
                          <Badge variant="outline">v{budget.version_number}</Badge>
                        </div>
                        <p className="text-gray-500 mb-3">Fiscal Year: {budget.fiscal_year}</p>

                        {/* Budget progress bar */}
                        <div className="flex items-center gap-4">
                          <div className="flex-1 max-w-md">
                            <div className="flex justify-between text-xs text-gray-500 mb-1">
                              <span>{formatCurrency(budget.total_actual)} spent</span>
                              <span>{formatCurrency(budget.total_allocated)} budgeted</span>
                            </div>
                            <Progress value={Math.min(utilization, 100)} className="h-2" />
                          </div>
                          <span className="text-sm text-gray-500">
                            {budget.line_items_count || 0} line items
                          </span>
                        </div>
                      </div>

                      <div className="text-right ml-6">
                        <p className="text-2xl font-bold">{formatCurrency(budget.total_budget)}</p>
                        <div className="flex items-center justify-end gap-1 mt-1">
                          {getStatusIcon(budget.status)}
                          <span className={cn(
                            'text-sm',
                            budget.approved_by_board_at ? 'text-green-600' : 'text-yellow-600',
                          )}>
                            {budget.approved_by_board_at ? 'Approved' : 'Pending Approval'}
                          </span>
                        </div>
                        {variance !== 0 && budget.total_allocated > 0 && (
                          <p className={cn(
                            'text-xs mt-1',
                            variance > 0 ? 'text-red-500' : 'text-green-500',
                          )}>
                            {variance > 0 ? '+' : ''}{formatCurrency(variance)} variance
                          </p>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
