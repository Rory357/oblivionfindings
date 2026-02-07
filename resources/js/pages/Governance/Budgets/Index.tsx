import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as budgetsIndex, show as showBudget } from '@/routes/governance/budgets';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { DollarSign, TrendingUp, AlertTriangle, CheckCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Budget {
  id: number;
  fiscal_year: string;
  title: string | null;
  total_budget: number;
  status: string;
  version_number: number;
  approved_by_board_at: string | null;
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
      approved: 'bg-green-100 text-green-800',
      rejected: 'bg-red-100 text-red-800',
    }[status] || 'bg-gray-100 text-gray-800';
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
            <Button asChild>
              <Link href="/governance/budgets/create">New Budget</Link>
            </Button>
          </div>

          {/* Budgets List */}
          <div className="space-y-4">
            {budgetItems.map((budget) => (
              <Card key={budget.id}>
                <CardContent className="pt-6">
                  <div className="flex items-start justify-between">
                    <div>
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
                          {budget.status}
                        </Badge>
                        <Badge variant="outline">v{budget.version_number}</Badge>
                      </div>
                      <p className="text-gray-500">Fiscal Year: {budget.fiscal_year}</p>
                    </div>
                    <div className="text-right">
                      <p className="text-2xl font-bold">{formatCurrency(budget.total_budget)}</p>
                      {budget.approved_by_board_at ? (
                        <p className="text-sm text-green-600 flex items-center justify-end gap-1">
                          <CheckCircle className="w-4 h-4" />
                          Approved
                        </p>
                      ) : (
                        <p className="text-sm text-yellow-600 flex items-center justify-end gap-1">
                          <AlertTriangle className="w-4 h-4" />
                          Pending Approval
                        </p>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
      </div>
    </AppLayout>
  );
}
