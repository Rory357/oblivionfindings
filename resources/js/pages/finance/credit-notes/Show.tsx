import { formatMoney } from '@/components/finance/money';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle, FileText } from 'lucide-react';

interface CreditNoteLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    gst_amount: string;
    line_total: string;
    account: { id: number; code: string; name: string } | null;
}

interface CreditNote {
    id: number;
    credit_note_number: string;
    type: string;
    vendor: { id: number; name: string } | null;
    status: string;
    credit_date: string;
    subtotal: string;
    gst_amount: string;
    total_amount: string;
    reason: string | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    journal: {
        id: number;
        journal_number: string;
        status: string;
        posted_at: string;
    } | null;
    lines: CreditNoteLine[];
}

interface Props extends PageProps {
    creditNote: CreditNote;
}

const formatDate = (date: string | null) =>
    date
        ? new Date(date).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '-';

const formatDateTime = (date: string | null) =>
    date
        ? new Date(date).toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '-';

const typeConfig: Record<string, { label: string; className: string }> = {
    payable: {
        label: 'Accounts Payable',
        className: 'bg-primary/10 text-primary',
    },
    receivable: {
        label: 'Accounts Receivable',
        className: 'bg-status-info-bg text-status-info',
    },
};

export default function CreditNoteShow({ auth, creditNote }: Props) {
    const isDraft = creditNote.status === 'draft';

    const handleApprove = () => {
        router.post(`/finance/credit-notes/${creditNote.id}/approve`);
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance' },
                { title: 'Credit Notes', href: '/finance/credit-notes' },
                {
                    title: creditNote.credit_note_number,
                    href: `/finance/credit-notes/${creditNote.id}`,
                },
            ]}
        >
            <Head title={`Credit Note ${creditNote.credit_note_number}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/credit-notes"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                {creditNote.credit_note_number}
                                <StatusBadge status={creditNote.status} />
                                <Badge
                                    className={
                                        typeConfig[creditNote.type]
                                            ?.className ??
                                        'bg-muted text-foreground'
                                    }
                                >
                                    {typeConfig[creditNote.type]?.label ??
                                        creditNote.type}
                                </Badge>
                            </span>
                        }
                        description={creditNote.vendor?.name ?? 'Unknown'}
                        actions={
                            isDraft && (
                                <Button onClick={handleApprove}>
                                    <CheckCircle className="mr-2 h-4 w-4" />
                                    Approve
                                </Button>
                            )
                        }
                    />
                }
            >
                <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Credit Note Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Credit Note Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Credit Date
                                </span>
                                <span className="font-medium">
                                    {formatDate(creditNote.credit_date)}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Type
                                </span>
                                <span className="font-medium">
                                    {creditNote.type === 'payable'
                                        ? 'Accounts Payable'
                                        : 'Accounts Receivable'}
                                </span>
                            </div>
                            {creditNote.approved_by && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Approved By
                                    </span>
                                    <span className="font-medium">
                                        {creditNote.approved_by.name}
                                    </span>
                                </div>
                            )}
                            {creditNote.approved_at && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Approved At
                                    </span>
                                    <span className="font-medium">
                                        {formatDateTime(creditNote.approved_at)}
                                    </span>
                                </div>
                            )}
                            {creditNote.reason && (
                                <div className="border-t pt-2">
                                    <span className="mb-1 block text-muted-foreground">
                                        Reason
                                    </span>
                                    <p className="whitespace-pre-wrap text-foreground">
                                        {creditNote.reason}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Amounts */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Amounts</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Subtotal
                                </span>
                                <span>{formatMoney(creditNote.subtotal)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    GST
                                </span>
                                <span>
                                    {formatMoney(creditNote.gst_amount)}
                                </span>
                            </div>
                            <Separator />
                            <div className="flex justify-between font-bold">
                                <span>Total</span>
                                <span>
                                    {formatMoney(creditNote.total_amount)}
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* GL Journal */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                GL Journal
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {creditNote.journal ? (
                                <div className="space-y-3 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Journal #
                                        </span>
                                        <Link
                                            href={`/finance/journals/${creditNote.journal.id}`}
                                            className="font-medium text-status-info hover:underline"
                                        >
                                            {creditNote.journal.journal_number}
                                        </Link>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Status
                                        </span>
                                        <Badge className="bg-status-success-bg text-status-success">
                                            {creditNote.journal.status}
                                        </Badge>
                                    </div>
                                    {creditNote.journal.posted_at && (
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                Posted
                                            </span>
                                            <span className="font-medium">
                                                {formatDateTime(
                                                    creditNote.journal
                                                        .posted_at,
                                                )}
                                            </span>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-4 text-muted-foreground">
                                    <FileText className="mb-2 h-8 w-8" />
                                    <p className="text-sm">
                                        No journal posted yet
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Line Items */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Line Items</CardTitle>
                    </CardHeader>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Description</TableHead>
                                <TableHead className="text-right">
                                    Qty
                                </TableHead>
                                <TableHead className="text-right">
                                    Unit Price
                                </TableHead>
                                <TableHead className="text-right">
                                    GST %
                                </TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead className="text-right">
                                    GST
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {creditNote.lines.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell>{line.description}</TableCell>
                                    <TableCell className="text-right">
                                        {Number(line.quantity).toFixed(2)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(line.unit_price)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {Number(line.gst_rate).toFixed(2)}%
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {line.account
                                            ? `${line.account.code} - ${line.account.name}`
                                            : '-'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(line.gst_amount)}
                                    </TableCell>
                                    <TableCell className="text-right font-medium">
                                        {formatMoney(line.line_total)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
