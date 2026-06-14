import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CheckCircle, DollarSign, Send, XCircle } from 'lucide-react';
import { useState } from 'react';

type ExpenseItem = {
    id: number;
    description: string;
    category: string;
    amount: number;
    expense_date: string;
    receipt_path: string | null;
    tax_amount: number | null;
    notes: string | null;
};

type ClaimData = {
    id: number;
    claim_number: string;
    title: string;
    staff_name: string;
    status: string;
    total_amount: number;
    currency: string;
    notes: string | null;
    rejection_reason: string | null;
    submitted_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    paid_at: string | null;
    journal_id: number | null;
    gl_posted_at: string | null;
    items: ExpenseItem[];
};

type Props = {
    claim: ClaimData;
    can: { approve: boolean; manage: boolean; pay: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Expenses', href: '/hr/expenses' },
    { title: 'Claim Detail', href: '#' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className: 'border-border/30 bg-muted text-muted-foreground',
        label: 'Draft',
    },
    submitted: {
        className:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
        label: 'Submitted',
    },
    approved: {
        className:
            'border-status-success/30 bg-status-success-bg text-status-success',
        label: 'Approved',
    },
    rejected: {
        className:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        label: 'Rejected',
    },
    paid: {
        className: 'border-status-info/30 bg-status-info-bg text-status-info',
        label: 'Paid',
    },
};

const categoryLabels: Record<string, string> = {
    travel: 'Travel',
    meals: 'Meals',
    accommodation: 'Accommodation',
    supplies: 'Supplies',
    mileage: 'Mileage',
    other: 'Other',
};

const formatCurrency = (amount: number, currency = 'NZD') => {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency,
    }).format(amount);
};

export default function ExpenseShow({ claim, can }: Props) {
    const config = statusConfig[claim.status] || statusConfig.draft;
    const [rejectionReason, setRejectionReason] = useState('');
    const [showRejectForm, setShowRejectForm] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Claim ${claim.claim_number}`} />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/expenses"
                        title={claim.title}
                        description={
                            <span className="flex items-center gap-2">
                                <span className="font-mono text-sm text-muted-foreground">
                                    {claim.claim_number}
                                </span>
                                <Badge
                                    variant="outline"
                                    className={config.className}
                                >
                                    {config.label}
                                </Badge>
                                {claim.gl_posted_at && (
                                    <Badge
                                        variant="outline"
                                        className="border-status-success/30 bg-status-success-bg text-status-success"
                                    >
                                        Posted to GL
                                    </Badge>
                                )}
                            </span>
                        }
                        actions={
                            <>
                                {claim.status === 'draft' && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/hr/expenses/${claim.id}/submit`,
                                            )
                                        }
                                    >
                                        <Send className="mr-1.5 h-3.5 w-3.5" />
                                        Submit
                                    </Button>
                                )}
                                {can.approve && (
                                    <>
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/hr/expenses/${claim.id}/approve`,
                                                )
                                            }
                                        >
                                            <CheckCircle className="mr-1.5 h-3.5 w-3.5" />
                                            Approve
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={() =>
                                                setShowRejectForm(true)
                                            }
                                        >
                                            <XCircle className="mr-1.5 h-3.5 w-3.5" />
                                            Reject
                                        </Button>
                                    </>
                                )}
                                {can.pay && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/hr/expenses/${claim.id}/pay`,
                                            )
                                        }
                                    >
                                        <DollarSign className="mr-1.5 h-3.5 w-3.5" />
                                        Mark Paid
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                {/* Rejection Form */}
                {showRejectForm && (
                    <Card className="border-destructive/30">
                        <CardContent className="pt-6">
                            <div className="space-y-3">
                                <Label>Rejection Reason</Label>
                                <Textarea
                                    rows={3}
                                    value={rejectionReason}
                                    onChange={(e) =>
                                        setRejectionReason(e.target.value)
                                    }
                                    placeholder="Provide a reason for rejecting this claim..."
                                />
                                <div className="flex gap-2">
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        disabled={!rejectionReason.trim()}
                                        onClick={() =>
                                            router.post(
                                                `/hr/expenses/${claim.id}/reject`,
                                                {
                                                    rejection_reason:
                                                        rejectionReason,
                                                },
                                            )
                                        }
                                    >
                                        Confirm Rejection
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setShowRejectForm(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Claim Info */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">
                                Employee
                            </p>
                            <p className="font-medium">{claim.staff_name}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">
                                Total Amount
                            </p>
                            <p className="text-xl font-bold">
                                {formatCurrency(
                                    claim.total_amount,
                                    claim.currency,
                                )}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">
                                Submitted
                            </p>
                            <p className="font-medium">
                                {claim.submitted_at || 'Not yet'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">
                                Approved By
                            </p>
                            <p className="font-medium">
                                {claim.approved_by || '-'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Rejection Reason */}
                {claim.rejection_reason && (
                    <Card className="border-status-critical/30">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-status-critical">
                                Rejection Reason
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm">{claim.rejection_reason}</p>
                        </CardContent>
                    </Card>
                )}

                {/* Notes */}
                {claim.notes && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">
                                Notes
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm">{claim.notes}</p>
                        </CardContent>
                    </Card>
                )}

                {/* Expense Items */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Expense Items
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Description</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Tax
                                    </TableHead>
                                    <TableHead>Receipt</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {claim.items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium">
                                            {item.description}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                {categoryLabels[
                                                    item.category
                                                ] || item.category}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {item.expense_date}
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {formatCurrency(
                                                item.amount,
                                                claim.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right text-muted-foreground">
                                            {item.tax_amount
                                                ? formatCurrency(
                                                      item.tax_amount,
                                                      claim.currency,
                                                  )
                                                : '-'}
                                        </TableCell>
                                        <TableCell>
                                            {item.receipt_path ? (
                                                <Badge
                                                    variant="outline"
                                                    className="border-status-success/30 text-status-success"
                                                >
                                                    Attached
                                                </Badge>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    -
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow>
                                    <TableCell
                                        colSpan={3}
                                        className="text-right font-medium"
                                    >
                                        Total
                                    </TableCell>
                                    <TableCell className="text-right text-lg font-bold">
                                        {formatCurrency(
                                            claim.total_amount,
                                            claim.currency,
                                        )}
                                    </TableCell>
                                    <TableCell />
                                    <TableCell />
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
