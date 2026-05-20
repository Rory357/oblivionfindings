import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { show as showBudget } from '@/routes/governance/budgets';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { DollarSign, CheckCircle, AlertTriangle, Clock, FileText } from 'lucide-react';
import { cn } from '@/lib/utils';
import { governanceStatusColor } from '@/lib/governance-status';
import { EmptyList } from '@/components/ui/empty-state';

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

  const getStatusColor = (status: string) => governanceStatusColor(status);

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'approved': return <CheckCircle className="w-4 h-4 text-status-success" />;
      case 'proposed':
      case 'under_review': return <Clock className="w-4 h-4 text-status-warning" />;
      case 'rejected': return <AlertTriangle className="w-4 h-4 text-status-critical" />;
      default: return <FileText className="w-4 h-4 text-muted-foreground" />;
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

      <PageLayout
        hero={
          <PageHero
            icon={DollarSign}
            title="Budgets"
            description="Plan, approve, and monitor financial budgets across fiscal years."
            stats={[
              { label: 'Total budgets', value: budgetItems.length },
              { label: 'Approved', value: budgetItems.filter((b) => b.status === 'approved').length },
              { label: 'Pending review', value: budgetItems.filter((b) => ['proposed', 'under_review'].includes(b.status)).length },
            ]}
            actions={
              (auth.can as any)?.governance?.budgets?.create ? (
                <Button asChild>
                  <Link href="/governance/budgets/create">New Budget</Link>
                </Button>
              ) : undefined
            }
          />
        }
      >
        {/* Summary Stats */}
        {budgetItems.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Total Budgets</p>
                    <p className="text-2xl font-bold">{budgetItems.length}</p>
                  </div>
                  <DollarSign className="w-8 h-8 text-muted-foreground" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Approved</p>
                    <p className="text-2xl font-bold text-status-success">
                      {budgetItems.filter(b => b.status === 'approved').length}
                    </p>
                  </div>
                  <CheckCircle className="w-8 h-8 text-status-success" />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm text-muted-foreground">Pending Review</p>
                    <p className="text-2xl font-bold text-status-warning">
                      {budgetItems.filter(b => ['proposed', 'under_review'].includes(b.status)).length}
                    </p>
                  </div>
                  <Clock className="w-8 h-8 text-status-warning" />
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {/* Budgets List */}
        {budgetItems.length === 0 ? (
          <EmptyList
            icon={DollarSign}
            itemName="budget"
            description="Create your first budget to start financial planning."
            createHref="/governance/budgets/create"
            createLabel="Create Budget"
          />
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
                              className="hover:text-status-info"
                            >
                              {budget.title || `Budget ${budget.fiscal_year}`}
                            </Link>
                          </h3>
                          <Badge className={cn(getStatusColor(budget.status))}>
                            {budget.status.replace('_', ' ')}
                          </Badge>
                          <Badge variant="outline">v{budget.version_number}</Badge>
                        </div>
                        <p className="text-muted-foreground mb-3">Fiscal Year: {budget.fiscal_year}</p>

                        {/* Budget progress bar */}
                        <div className="flex items-center gap-4">
                          <div className="flex-1 max-w-md">
                            <div className="flex justify-between text-xs text-muted-foreground mb-1">
                              <span>{formatCurrency(budget.total_actual)} spent</span>
                              <span>{formatCurrency(budget.total_allocated)} budgeted</span>
                            </div>
                            <Progress value={Math.min(utilization, 100)} className="h-2" />
                          </div>
                          <span className="text-sm text-muted-foreground">
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
                            budget.approved_by_board_at ? 'text-status-success' : 'text-status-warning',
                          )}>
                            {budget.approved_by_board_at ? 'Approved' : 'Pending Approval'}
                          </span>
                        </div>
                        {variance !== 0 && budget.total_allocated > 0 && (
                          <p className={cn(
                            'text-xs mt-1',
                            variance > 0 ? 'text-status-critical' : 'text-status-success',
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
      </PageLayout>
    </AppLayout>
  );
}
