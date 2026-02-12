import { Head, Link, router, useForm } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { index as budgetsIndex } from '@/routes/governance/budgets';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  DollarSign,
  TrendingUp,
  TrendingDown,
  AlertTriangle,
  CheckCircle,
  Wallet,
  Plus,
  Pencil,
  Trash2,
  Send,
  BarChart3,
  ArrowUpDown,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState, FormEvent } from 'react';

interface LineItem {
  id: number;
  category: string;
  description: string;
  account_code: string | null;
  budget_amount: number;
  forecast_amount: number;
  actual_amount: number;
  variance_amount: number;
  variance_pct: number;
  variance_explanation: string | null;
  notes: string | null;
}

interface Adjustment {
  id: number;
  adjustment_type: string;
  budget_line_item_id: number | null;
  line_item: { description: string } | null;
  amount: number;
  reason: string;
  status: string;
  threshold_applies: boolean;
  proposed_by: { name: string };
  approved_by: { name: string } | null;
  proposed_at: string;
  approved_at: string | null;
  review_notes: string | null;
}

interface Budget {
  id: number;
  fiscal_year: string;
  title: string;
  description: string | null;
  total_budget: number;
  currency: string;
  status: string;
  version_number: number;
  approval_resolution: { resolution_reference: string; outcome: string } | null;
  approved_by_board_at: string | null;
  proposed_by: { name: string } | null;
  proposed_at: string | null;
  created_by: { name: string } | null;
  line_items: LineItem[];
  adjustments: Adjustment[];
}

interface Props extends PageProps {
  budget: Budget;
  categories: Record<string, string>;
  canEdit: boolean;
}

export default function BudgetShow({ auth, budget, categories, canEdit }: Props) {
  const [lineItemDialogOpen, setLineItemDialogOpen] = useState(false);
  const [editingLineItem, setEditingLineItem] = useState<LineItem | null>(null);
  const [editLineItemDialogOpen, setEditLineItemDialogOpen] = useState(false);
  const [adjustmentDialogOpen, setAdjustmentDialogOpen] = useState(false);
  const [actualsDialogOpen, setActualsDialogOpen] = useState(false);

  const lineItemForm = useForm({
    category: 'operations',
    description: '',
    account_code: '',
    budget_amount: '',
    forecast_amount: '',
    notes: '',
  });

  const editLineItemForm = useForm({
    category: '',
    description: '',
    account_code: '',
    budget_amount: '',
    forecast_amount: '',
    actual_amount: '',
    variance_explanation: '',
    notes: '',
  });

  const adjustmentForm = useForm({
    budget_line_item_id: '',
    adjustment_type: 'increase',
    amount: '',
    reason: '',
  });

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-NZ', {
      style: 'currency',
      currency: budget.currency || 'NZD',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(amount);
  };

  const getStatusColor = (status: string) => {
    return {
      drafting: 'bg-gray-100 text-gray-800',
      proposed: 'bg-yellow-100 text-yellow-800',
      under_review: 'bg-blue-100 text-blue-800',
      approved: 'bg-green-100 text-green-800',
      rejected: 'bg-red-100 text-red-800',
    }[status] || 'bg-gray-100 text-gray-800';
  };

  const getCategoryLabel = (category: string) => {
    return categories?.[category] || category;
  };

  const groupLineItemsByCategory = () => {
    const grouped: Record<string, LineItem[]> = {};
    (budget.line_items || []).forEach((item) => {
      if (!grouped[item.category]) grouped[item.category] = [];
      grouped[item.category].push(item);
    });
    return grouped;
  };

  const calculateTotals = () => {
    const items = budget.line_items || [];
    const totals = items.reduce(
      (acc, item) => ({
        budget: acc.budget + Number(item.budget_amount),
        forecast: acc.forecast + Number(item.forecast_amount),
        actual: acc.actual + Number(item.actual_amount),
      }),
      { budget: 0, forecast: 0, actual: 0 }
    );
    return {
      ...totals,
      variance: totals.actual - totals.budget,
      variancePercent: totals.budget > 0 ? ((totals.actual - totals.budget) / totals.budget) * 100 : 0,
      utilization: totals.budget > 0 ? (totals.actual / totals.budget) * 100 : 0,
      remaining: totals.budget - totals.actual,
    };
  };

  const totals = calculateTotals();

  const getCategoryTotals = (items: LineItem[]) => {
    return items.reduce(
      (acc, item) => ({
        budget: acc.budget + Number(item.budget_amount),
        actual: acc.actual + Number(item.actual_amount),
        variance: acc.variance + Number(item.variance_amount || 0),
      }),
      { budget: 0, actual: 0, variance: 0 }
    );
  };

  const submitLineItem = (e: FormEvent) => {
    e.preventDefault();
    lineItemForm.post(`/governance/budgets/${budget.id}/line-items`, {
      preserveScroll: true,
      onSuccess: () => {
        setLineItemDialogOpen(false);
        lineItemForm.reset();
      },
    });
  };

  const openEditLineItem = (item: LineItem) => {
    setEditingLineItem(item);
    editLineItemForm.setData({
      category: item.category,
      description: item.description,
      account_code: item.account_code || '',
      budget_amount: String(item.budget_amount),
      forecast_amount: String(item.forecast_amount),
      actual_amount: String(item.actual_amount),
      variance_explanation: item.variance_explanation || '',
      notes: item.notes || '',
    });
    setEditLineItemDialogOpen(true);
  };

  const submitEditLineItem = (e: FormEvent) => {
    e.preventDefault();
    if (!editingLineItem) return;
    editLineItemForm.put(`/governance/budgets/${budget.id}/line-items/${editingLineItem.id}`, {
      preserveScroll: true,
      onSuccess: () => {
        setEditLineItemDialogOpen(false);
        setEditingLineItem(null);
      },
    });
  };

  const deleteLineItem = (itemId: number) => {
    router.delete(`/governance/budgets/${budget.id}/line-items/${itemId}`, {
      preserveScroll: true,
    });
  };

  const submitAdjustment = (e: FormEvent) => {
    e.preventDefault();
    adjustmentForm.post(`/governance/budgets/${budget.id}/adjust`, {
      preserveScroll: true,
      onSuccess: () => {
        setAdjustmentDialogOpen(false);
        adjustmentForm.reset();
      },
    });
  };

  const proposeBudget = () => {
    router.post(`/governance/budgets/${budget.id}/propose`, {}, {
      preserveScroll: true,
    });
  };

  const approveAdjustment = (adjustmentId: number) => {
    router.post(`/governance/budgets/${budget.id}/adjustments/${adjustmentId}/approve`, {}, {
      preserveScroll: true,
    });
  };

  const pendingAdjustments = (budget.adjustments || []).filter(a => a.status === 'submitted');
  const resolvedAdjustments = (budget.adjustments || []).filter(a => a.status !== 'submitted');

  return (
    <AppLayout
      user={auth.user}
      breadcrumbs={[
        { title: 'Governance', href: '/governance/dashboard' },
        { title: 'Budgets', href: '/governance/budgets' },
        { title: budget.title || `FY${budget.fiscal_year}`, href: `/governance/budgets/${budget.id}` },
      ]}
    >
      <Head title={`Budget - ${budget.title || budget.fiscal_year}`} />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Back Link */}
        <div className="mb-4">
          <Link href={budgetsIndex.url()} className="text-sm text-blue-600 hover:underline">
            &larr; Back to Budgets
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
              <Badge className={getStatusColor(budget.status)}>{budget.status.replace('_', ' ')}</Badge>
              <Badge variant="outline">v{budget.version_number}</Badge>
              {budget.created_by && (
                <span className="text-sm text-gray-500">Created by {budget.created_by.name}</span>
              )}
            </div>
            {budget.description && (
              <p className="text-gray-600 mt-2 text-sm">{budget.description}</p>
            )}
          </div>
          <div className="flex gap-2">
            {canEdit && (
              <Button variant="outline" asChild>
                <Link href={`/governance/budgets/${budget.id}/edit`}>
                  <Pencil className="w-4 h-4 mr-1" />
                  Edit
                </Link>
              </Button>
            )}
            {budget.status === 'drafting' && (budget.line_items || []).length > 0 && (
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button>
                    <Send className="w-4 h-4 mr-1" />
                    Submit for Approval
                  </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>Submit Budget for Approval</AlertDialogTitle>
                    <AlertDialogDescription>
                      This will submit the budget ({formatCurrency(totals.budget)} across {(budget.line_items || []).length} line items) to the board for review and approval. Are you sure?
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction onClick={proposeBudget}>Submit</AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            )}
          </div>
        </div>

        {/* Summary Cards */}
        <div className="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
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
                  <p className="text-sm text-gray-500">Remaining</p>
                  <p className={cn(
                    'text-2xl font-bold',
                    totals.remaining >= 0 ? 'text-green-600' : 'text-red-600',
                  )}>{formatCurrency(totals.remaining)}</p>
                </div>
                <BarChart3 className="w-8 h-8 text-gray-400" />
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
                <Progress value={Math.min(totals.utilization, 100)} className="w-16" />
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
                    totals.variance > 0 ? 'text-red-600' : totals.variance < 0 ? 'text-green-600' : 'text-gray-900',
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

        {/* Approval Banner */}
        {budget.approval_resolution && (
          <Card className="mb-6 border-green-200 bg-green-50">
            <CardContent className="pt-6">
              <div className="flex items-center gap-3">
                <CheckCircle className="w-6 h-6 text-green-600" />
                <div>
                  <p className="font-medium text-green-800">Board Approved</p>
                  <p className="text-sm text-green-600">
                    Resolution {budget.approval_resolution.resolution_reference}
                    {budget.approved_by_board_at && ` on ${new Date(budget.approved_by_board_at).toLocaleDateString('en-NZ')}`}
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Tabs */}
        <Tabs defaultValue="line-items" className="space-y-6">
          <TabsList>
            <TabsTrigger value="line-items">Line Items ({(budget.line_items || []).length})</TabsTrigger>
            <TabsTrigger value="adjustments">
              Adjustments
              {pendingAdjustments.length > 0 && (
                <Badge variant="destructive" className="ml-2 h-5 min-w-5 text-xs">{pendingAdjustments.length}</Badge>
              )}
            </TabsTrigger>
            <TabsTrigger value="summary">Category Summary</TabsTrigger>
          </TabsList>

          {/* ========== LINE ITEMS TAB ========== */}
          <TabsContent value="line-items">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <div>
                  <CardTitle>Budget Line Items</CardTitle>
                  <CardDescription>
                    {(budget.line_items || []).length} items totaling {formatCurrency(totals.budget)}
                  </CardDescription>
                </div>
                {canEdit && (
                  <Dialog open={lineItemDialogOpen} onOpenChange={setLineItemDialogOpen}>
                    <DialogTrigger asChild>
                      <Button size="sm">
                        <Plus className="w-4 h-4 mr-1" />
                        Add Line Item
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="max-w-lg" aria-describedby={undefined}>
                      <DialogHeader>
                        <DialogTitle>Add Budget Line Item</DialogTitle>
                      </DialogHeader>
                      <form onSubmit={submitLineItem} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                          <div>
                            <Label>Category</Label>
                            <Select
                              value={lineItemForm.data.category}
                              onValueChange={v => lineItemForm.setData('category', v)}
                            >
                              <SelectTrigger><SelectValue /></SelectTrigger>
                              <SelectContent>
                                {Object.entries(categories || {}).map(([key, label]) => (
                                  <SelectItem key={key} value={key}>{label}</SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </div>
                          <div>
                            <Label>Account Code</Label>
                            <Input
                              value={lineItemForm.data.account_code}
                              onChange={e => lineItemForm.setData('account_code', e.target.value)}
                              placeholder="e.g., 4100"
                            />
                          </div>
                        </div>
                        <div>
                          <Label>Description</Label>
                          <Input
                            value={lineItemForm.data.description}
                            onChange={e => lineItemForm.setData('description', e.target.value)}
                            placeholder="e.g., Staff salaries"
                            required
                          />
                          {lineItemForm.errors.description && <p className="text-sm text-red-500 mt-1">{lineItemForm.errors.description}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                          <div>
                            <Label>Budget Amount ($)</Label>
                            <Input
                              type="number"
                              step="0.01"
                              min="0"
                              value={lineItemForm.data.budget_amount}
                              onChange={e => lineItemForm.setData('budget_amount', e.target.value)}
                              required
                            />
                          </div>
                          <div>
                            <Label>Forecast Amount ($)</Label>
                            <Input
                              type="number"
                              step="0.01"
                              min="0"
                              value={lineItemForm.data.forecast_amount}
                              onChange={e => lineItemForm.setData('forecast_amount', e.target.value)}
                              placeholder="Same as budget if blank"
                            />
                          </div>
                        </div>
                        <div>
                          <Label>Notes</Label>
                          <Textarea
                            value={lineItemForm.data.notes}
                            onChange={e => lineItemForm.setData('notes', e.target.value)}
                            rows={2}
                            placeholder="Optional notes..."
                          />
                        </div>
                        <div className="flex justify-end gap-2">
                          <Button type="button" variant="outline" onClick={() => setLineItemDialogOpen(false)}>Cancel</Button>
                          <Button type="submit" disabled={lineItemForm.processing}>
                            {lineItemForm.processing ? 'Adding...' : 'Add Line Item'}
                          </Button>
                        </div>
                      </form>
                    </DialogContent>
                  </Dialog>
                )}
              </CardHeader>
              <CardContent>
                {(budget.line_items || []).length === 0 ? (
                  <div className="text-center py-12">
                    <DollarSign className="mx-auto h-12 w-12 text-gray-300" />
                    <h3 className="mt-2 text-sm font-semibold text-gray-900">No line items</h3>
                    <p className="mt-1 text-sm text-gray-500">Get started by adding budget line items to track allocations and spending.</p>
                    {canEdit && (
                      <div className="mt-6">
                        <Button size="sm" onClick={() => setLineItemDialogOpen(true)}>
                          <Plus className="w-4 h-4 mr-1" />
                          Add First Line Item
                        </Button>
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b">
                          <th className="text-left py-2 font-medium">Category</th>
                          <th className="text-left py-2 font-medium">Description</th>
                          <th className="text-right py-2 font-medium">Budget</th>
                          <th className="text-right py-2 font-medium">Forecast</th>
                          <th className="text-right py-2 font-medium">Actual</th>
                          <th className="text-right py-2 font-medium">Variance</th>
                          <th className="text-right py-2 font-medium">%</th>
                          {canEdit && <th className="text-right py-2 font-medium w-24">Actions</th>}
                        </tr>
                      </thead>
                      <tbody>
                        {(budget.line_items || []).map((item) => {
                          const variancePct = Number(item.budget_amount) > 0
                            ? (Number(item.variance_amount) / Number(item.budget_amount)) * 100
                            : 0;
                          return (
                            <tr key={item.id} className="border-b last:border-0 hover:bg-gray-50">
                              <td className="py-2">
                                <Badge variant="outline" className="text-xs">{getCategoryLabel(item.category)}</Badge>
                              </td>
                              <td className="py-2">
                                <div>
                                  <p className="font-medium">{item.description}</p>
                                  {item.account_code && <p className="text-xs text-gray-500">{item.account_code}</p>}
                                </div>
                              </td>
                              <td className="text-right py-2">{formatCurrency(Number(item.budget_amount))}</td>
                              <td className="text-right py-2 text-gray-500">{formatCurrency(Number(item.forecast_amount))}</td>
                              <td className="text-right py-2">{formatCurrency(Number(item.actual_amount))}</td>
                              <td className={cn(
                                'text-right py-2 font-medium',
                                Number(item.variance_amount) > 0 && 'text-red-600',
                                Number(item.variance_amount) < 0 && 'text-green-600',
                              )}>
                                {Number(item.variance_amount) > 0 ? '+' : ''}{formatCurrency(Number(item.variance_amount || 0))}
                              </td>
                              <td className={cn(
                                'text-right py-2',
                                Math.abs(variancePct) > 10 && 'font-medium',
                                variancePct > 10 && 'text-red-600',
                                variancePct < -10 && 'text-green-600',
                              )}>
                                {variancePct > 0 ? '+' : ''}{variancePct.toFixed(1)}%
                                {Math.abs(variancePct) > 10 && (
                                  <AlertTriangle className="inline w-3 h-3 ml-1" />
                                )}
                              </td>
                              {canEdit && (
                                <td className="text-right py-2">
                                  <div className="flex justify-end gap-1">
                                    <Button
                                      variant="ghost"
                                      size="sm"
                                      onClick={() => openEditLineItem(item)}
                                    >
                                      <Pencil className="w-3 h-3" />
                                    </Button>
                                    <AlertDialog>
                                      <AlertDialogTrigger asChild>
                                        <Button variant="ghost" size="sm" className="text-red-500 hover:text-red-700">
                                          <Trash2 className="w-3 h-3" />
                                        </Button>
                                      </AlertDialogTrigger>
                                      <AlertDialogContent>
                                        <AlertDialogHeader>
                                          <AlertDialogTitle>Delete Line Item</AlertDialogTitle>
                                          <AlertDialogDescription>
                                            Are you sure you want to remove "{item.description}" ({formatCurrency(Number(item.budget_amount))})? This cannot be undone.
                                          </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                          <AlertDialogCancel>Cancel</AlertDialogCancel>
                                          <AlertDialogAction
                                            onClick={() => deleteLineItem(item.id)}
                                            className="bg-red-600 hover:bg-red-700"
                                          >
                                            Delete
                                          </AlertDialogAction>
                                        </AlertDialogFooter>
                                      </AlertDialogContent>
                                    </AlertDialog>
                                  </div>
                                </td>
                              )}
                            </tr>
                          );
                        })}
                      </tbody>
                      <tfoot>
                        <tr className="border-t-2 font-semibold">
                          <td className="py-2" colSpan={2}>Totals</td>
                          <td className="text-right py-2">{formatCurrency(totals.budget)}</td>
                          <td className="text-right py-2 text-gray-500">{formatCurrency(totals.forecast)}</td>
                          <td className="text-right py-2">{formatCurrency(totals.actual)}</td>
                          <td className={cn(
                            'text-right py-2',
                            totals.variance > 0 && 'text-red-600',
                            totals.variance < 0 && 'text-green-600',
                          )}>
                            {totals.variance > 0 ? '+' : ''}{formatCurrency(totals.variance)}
                          </td>
                          <td className="text-right py-2">
                            {totals.variancePercent > 0 ? '+' : ''}{totals.variancePercent.toFixed(1)}%
                          </td>
                          {canEdit && <td />}
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          {/* ========== ADJUSTMENTS TAB ========== */}
          <TabsContent value="adjustments">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <div>
                  <CardTitle>Budget Adjustments</CardTitle>
                  <CardDescription>Requests to modify the approved budget</CardDescription>
                </div>
                {canEdit && (
                  <Dialog open={adjustmentDialogOpen} onOpenChange={setAdjustmentDialogOpen}>
                    <DialogTrigger asChild>
                      <Button size="sm">
                        <ArrowUpDown className="w-4 h-4 mr-1" />
                        Request Adjustment
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="max-w-lg" aria-describedby={undefined}>
                      <DialogHeader>
                        <DialogTitle>Request Budget Adjustment</DialogTitle>
                      </DialogHeader>
                      <form onSubmit={submitAdjustment} className="space-y-4">
                        <div>
                          <Label>Adjustment Type</Label>
                          <Select
                            value={adjustmentForm.data.adjustment_type}
                            onValueChange={v => adjustmentForm.setData('adjustment_type', v)}
                          >
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                              <SelectItem value="increase">Increase</SelectItem>
                              <SelectItem value="decrease">Decrease</SelectItem>
                              <SelectItem value="reallocate">Reallocate</SelectItem>
                            </SelectContent>
                          </Select>
                        </div>
                        {(budget.line_items || []).length > 0 && (
                          <div>
                            <Label>Line Item (optional)</Label>
                            <Select
                              value={adjustmentForm.data.budget_line_item_id || undefined}
                              onValueChange={v => adjustmentForm.setData('budget_line_item_id', v)}
                            >
                              <SelectTrigger><SelectValue placeholder="Select line item..." /></SelectTrigger>
                              <SelectContent>
                                {(budget.line_items || []).map(item => (
                                  <SelectItem key={item.id} value={String(item.id)}>{item.description}</SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </div>
                        )}
                        <div>
                          <Label>Amount ($)</Label>
                          <Input
                            type="number"
                            step="0.01"
                            min="0.01"
                            value={adjustmentForm.data.amount}
                            onChange={e => adjustmentForm.setData('amount', e.target.value)}
                            required
                          />
                          {adjustmentForm.errors.amount && <p className="text-sm text-red-500 mt-1">{adjustmentForm.errors.amount}</p>}
                        </div>
                        <div>
                          <Label>Reason</Label>
                          <Textarea
                            value={adjustmentForm.data.reason}
                            onChange={e => adjustmentForm.setData('reason', e.target.value)}
                            rows={3}
                            required
                            placeholder="Explain why this adjustment is needed..."
                          />
                          {adjustmentForm.errors.reason && <p className="text-sm text-red-500 mt-1">{adjustmentForm.errors.reason}</p>}
                        </div>
                        <p className="text-xs text-gray-500">
                          Adjustments exceeding 5% of total budget will require board resolution.
                        </p>
                        <div className="flex justify-end gap-2">
                          <Button type="button" variant="outline" onClick={() => setAdjustmentDialogOpen(false)}>Cancel</Button>
                          <Button type="submit" disabled={adjustmentForm.processing}>
                            {adjustmentForm.processing ? 'Submitting...' : 'Submit Request'}
                          </Button>
                        </div>
                      </form>
                    </DialogContent>
                  </Dialog>
                )}
              </CardHeader>
              <CardContent>
                {(budget.adjustments || []).length === 0 ? (
                  <p className="text-gray-500 text-center py-8">No budget adjustments have been requested.</p>
                ) : (
                  <div className="space-y-3">
                    {pendingAdjustments.length > 0 && (
                      <div className="mb-4">
                        <h4 className="text-sm font-semibold text-gray-700 mb-2">Pending Approvals</h4>
                        {pendingAdjustments.map((adj) => (
                          <div key={adj.id} className="p-4 border rounded-lg border-yellow-200 bg-yellow-50 mb-2">
                            <div className="flex items-start justify-between">
                              <div className="flex-1">
                                <div className="flex items-center gap-2 mb-1">
                                  <Badge variant="outline" className="capitalize">{adj.adjustment_type}</Badge>
                                  <span className="font-semibold">{formatCurrency(Number(adj.amount))}</span>
                                  {adj.threshold_applies && (
                                    <Badge variant="destructive" className="text-xs">Board Approval Required</Badge>
                                  )}
                                </div>
                                <p className="text-sm text-gray-600">{adj.reason}</p>
                                {adj.line_item && (
                                  <p className="text-xs text-gray-500 mt-1">Line item: {adj.line_item.description}</p>
                                )}
                                <p className="text-xs text-gray-400 mt-1">
                                  Requested by {adj.proposed_by?.name} on {new Date(adj.proposed_at).toLocaleDateString('en-NZ')}
                                </p>
                              </div>
                              <div className="flex gap-2 ml-4">
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="text-green-600 border-green-300 hover:bg-green-50"
                                  onClick={() => approveAdjustment(adj.id)}
                                >
                                  Approve
                                </Button>
                              </div>
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                    {resolvedAdjustments.length > 0 && (
                      <div>
                        {pendingAdjustments.length > 0 && (
                          <h4 className="text-sm font-semibold text-gray-700 mb-2">Resolved</h4>
                        )}
                        {resolvedAdjustments.map((adj) => (
                          <div key={adj.id} className="p-4 border rounded-lg mb-2">
                            <div className="flex items-start justify-between">
                              <div>
                                <div className="flex items-center gap-2 mb-1">
                                  <Badge variant="outline" className="capitalize">{adj.adjustment_type}</Badge>
                                  <span className="font-medium">{formatCurrency(Number(adj.amount))}</span>
                                </div>
                                <p className="text-sm text-gray-600">{adj.reason}</p>
                                {adj.review_notes && (
                                  <p className="text-xs text-gray-500 mt-1 italic">Review: {adj.review_notes}</p>
                                )}
                              </div>
                              <Badge className={cn(
                                adj.status === 'approved' && 'bg-green-100 text-green-800',
                                adj.status === 'rejected' && 'bg-red-100 text-red-800',
                              )}>
                                {adj.status}
                              </Badge>
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          {/* ========== CATEGORY SUMMARY TAB ========== */}
          <TabsContent value="summary">
            <div className="space-y-4">
              {Object.entries(groupLineItemsByCategory()).map(([category, items]) => {
                const categoryTotals = getCategoryTotals(items);
                const pct = totals.budget > 0 ? (categoryTotals.budget / totals.budget) * 100 : 0;
                return (
                  <Card key={category}>
                    <CardHeader>
                      <div className="flex items-center justify-between">
                        <div>
                          <CardTitle className="text-lg">{getCategoryLabel(category)}</CardTitle>
                          <CardDescription>{items.length} line items &middot; {pct.toFixed(1)}% of total budget</CardDescription>
                        </div>
                        <div className="text-right">
                          <p className="text-lg font-semibold">{formatCurrency(categoryTotals.budget)}</p>
                          <p className={cn(
                            'text-sm font-medium',
                            categoryTotals.variance > 0 && 'text-red-600',
                            categoryTotals.variance < 0 && 'text-green-600',
                          )}>
                            Variance: {categoryTotals.variance > 0 ? '+' : ''}{formatCurrency(categoryTotals.variance)}
                          </p>
                        </div>
                      </div>
                      <Progress value={totals.budget > 0 ? (categoryTotals.actual / categoryTotals.budget) * 100 : 0} className="mt-2" />
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
                            </tr>
                          </thead>
                          <tbody>
                            {items.map((item) => (
                              <tr key={item.id} className="border-b last:border-0">
                                <td className="py-2">
                                  <p className="font-medium">{item.description}</p>
                                  {item.account_code && <p className="text-xs text-gray-500">{item.account_code}</p>}
                                </td>
                                <td className="text-right py-2">{formatCurrency(Number(item.budget_amount))}</td>
                                <td className="text-right py-2">{formatCurrency(Number(item.actual_amount))}</td>
                                <td className={cn(
                                  'text-right py-2 font-medium',
                                  Number(item.variance_amount) > 0 && 'text-red-600',
                                  Number(item.variance_amount) < 0 && 'text-green-600',
                                )}>
                                  {Number(item.variance_amount) > 0 ? '+' : ''}{formatCurrency(Number(item.variance_amount || 0))}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </CardContent>
                  </Card>
                );
              })}
              {Object.keys(groupLineItemsByCategory()).length === 0 && (
                <Card>
                  <CardContent className="pt-6">
                    <p className="text-gray-500 text-center py-8">No line items to summarize. Add line items first.</p>
                  </CardContent>
                </Card>
              )}
            </div>
          </TabsContent>
        </Tabs>

        {/* Edit Line Item Dialog */}
        <Dialog open={editLineItemDialogOpen} onOpenChange={setEditLineItemDialogOpen}>
          <DialogContent className="max-w-lg" aria-describedby={undefined}>
            <DialogHeader>
              <DialogTitle>Edit Line Item</DialogTitle>
            </DialogHeader>
            <form onSubmit={submitEditLineItem} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Category</Label>
                  <Select
                    value={editLineItemForm.data.category}
                    onValueChange={v => editLineItemForm.setData('category', v)}
                  >
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {Object.entries(categories || {}).map(([key, label]) => (
                        <SelectItem key={key} value={key}>{label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Account Code</Label>
                  <Input
                    value={editLineItemForm.data.account_code}
                    onChange={e => editLineItemForm.setData('account_code', e.target.value)}
                  />
                </div>
              </div>
              <div>
                <Label>Description</Label>
                <Input
                  value={editLineItemForm.data.description}
                  onChange={e => editLineItemForm.setData('description', e.target.value)}
                  required
                />
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <Label>Budget ($)</Label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={editLineItemForm.data.budget_amount}
                    onChange={e => editLineItemForm.setData('budget_amount', e.target.value)}
                    required
                  />
                </div>
                <div>
                  <Label>Forecast ($)</Label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={editLineItemForm.data.forecast_amount}
                    onChange={e => editLineItemForm.setData('forecast_amount', e.target.value)}
                  />
                </div>
                <div>
                  <Label>Actual ($)</Label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={editLineItemForm.data.actual_amount}
                    onChange={e => editLineItemForm.setData('actual_amount', e.target.value)}
                  />
                </div>
              </div>
              <div>
                <Label>Variance Explanation</Label>
                <Textarea
                  value={editLineItemForm.data.variance_explanation}
                  onChange={e => editLineItemForm.setData('variance_explanation', e.target.value)}
                  rows={2}
                  placeholder="Explain any significant variance..."
                />
              </div>
              <div>
                <Label>Notes</Label>
                <Textarea
                  value={editLineItemForm.data.notes}
                  onChange={e => editLineItemForm.setData('notes', e.target.value)}
                  rows={2}
                />
              </div>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => setEditLineItemDialogOpen(false)}>Cancel</Button>
                <Button type="submit" disabled={editLineItemForm.processing}>
                  {editLineItemForm.processing ? 'Saving...' : 'Update Line Item'}
                </Button>
              </div>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </AppLayout>
  );
}
