import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Separator } from '@/components/ui/separator';
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
    journal: { id: number; journal_number: string; status: string; posted_at: string } | null;
    lines: CreditNoteLine[];
}

interface Props extends PageProps {
    creditNote: CreditNote;
}

const formatCurrency = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (date: string | null) =>
    date ? new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

const formatDateTime = (date: string | null) =>
    date ? new Date(date).toLocaleString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-gray-100 text-gray-800' },
    approved: { label: 'Approved', className: 'bg-green-100 text-green-800' },
    applied: { label: 'Applied', className: 'bg-blue-100 text-blue-800' },
    cancelled: { label: 'Cancelled', className: 'bg-red-100 text-red-800' },
};

const typeConfig: Record<string, { label: string; className: string }> = {
    payable: { label: 'Accounts Payable', className: 'bg-purple-100 text-purple-800' },
    receivable: { label: 'Accounts Receivable', className: 'bg-teal-100 text-teal-800' },
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
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Credit Notes', href: '/finance/credit-notes' },
                { title: creditNote.credit_note_number, href: `/finance/credit-notes/${creditNote.id}` },
            ]}
        >
            <Head title={`Credit Note ${creditNote.credit_note_number}`} />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="flex items-start justify-between mb-6">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-bold text-gray-900">{creditNote.credit_note_number}</h1>
                            <Badge className={statusConfig[creditNote.status]?.className ?? 'bg-gray-100 text-gray-800'}>
                                {statusConfig[creditNote.status]?.label ?? creditNote.status}
                            </Badge>
                            <Badge className={typeConfig[creditNote.type]?.className ?? 'bg-gray-100 text-gray-800'}>
                                {typeConfig[creditNote.type]?.label ?? creditNote.type}
                            </Badge>
                        </div>
                        <p className="text-gray-500 mt-1">
                            {creditNote.vendor?.name ?? 'Unknown'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {isDraft && (
                            <Button onClick={handleApprove}>
                                <CheckCircle className="w-4 h-4 mr-2" />
                                Approve
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    {/* Credit Note Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Credit Note Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-gray-500">Credit Date</span>
                                <span className="font-medium">{formatDate(creditNote.credit_date)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-500">Type</span>
                                <span className="font-medium">{creditNote.type === 'payable' ? 'Accounts Payable' : 'Accounts Receivable'}</span>
                            </div>
                            {creditNote.approved_by && (
                                <div className="flex justify-between">
                                    <span className="text-gray-500">Approved By</span>
                                    <span className="font-medium">{creditNote.approved_by.name}</span>
                                </div>
                            )}
                            {creditNote.approved_at && (
                                <div className="flex justify-between">
                                    <span className="text-gray-500">Approved At</span>
                                    <span className="font-medium">{formatDateTime(creditNote.approved_at)}</span>
                                </div>
                            )}
                            {creditNote.reason && (
                                <div className="pt-2 border-t">
                                    <span className="text-gray-500 block mb-1">Reason</span>
                                    <p className="text-gray-700 whitespace-pre-wrap">{creditNote.reason}</p>
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
                                <span className="text-gray-500">Subtotal</span>
                                <span>{formatCurrency(creditNote.subtotal)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-500">GST</span>
                                <span>{formatCurrency(creditNote.gst_amount)}</span>
                            </div>
                            <Separator />
                            <div className="flex justify-between font-bold">
                                <span>Total</span>
                                <span>{formatCurrency(creditNote.total_amount)}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* GL Journal */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">GL Journal</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {creditNote.journal ? (
                                <div className="space-y-3 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-gray-500">Journal #</span>
                                        <Link href={`/finance/journals/${creditNote.journal.id}`} className="text-blue-600 hover:underline font-medium">
                                            {creditNote.journal.journal_number}
                                        </Link>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-gray-500">Status</span>
                                        <Badge className="bg-green-100 text-green-800">{creditNote.journal.status}</Badge>
                                    </div>
                                    {creditNote.journal.posted_at && (
                                        <div className="flex justify-between">
                                            <span className="text-gray-500">Posted</span>
                                            <span className="font-medium">{formatDateTime(creditNote.journal.posted_at)}</span>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-4 text-gray-400">
                                    <FileText className="w-8 h-8 mb-2" />
                                    <p className="text-sm">No journal posted yet</p>
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
                                <TableHead className="text-right">Qty</TableHead>
                                <TableHead className="text-right">Unit Price</TableHead>
                                <TableHead className="text-right">GST %</TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead className="text-right">GST</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {creditNote.lines.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell>{line.description}</TableCell>
                                    <TableCell className="text-right">{Number(line.quantity).toFixed(2)}</TableCell>
                                    <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                                    <TableCell className="text-right">{Number(line.gst_rate).toFixed(2)}%</TableCell>
                                    <TableCell className="text-sm">
                                        {line.account ? `${line.account.code} - ${line.account.name}` : '-'}
                                    </TableCell>
                                    <TableCell className="text-right">{formatCurrency(line.gst_amount)}</TableCell>
                                    <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </div>
        </AppLayout>
    );
}
