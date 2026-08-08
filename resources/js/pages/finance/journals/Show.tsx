import { formatMoney } from '@/components/finance/money';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Calendar, CheckCircle, FileText, RotateCcw, User } from 'lucide-react';
import { useState } from 'react';

interface Account {
    id: number;
    code: string;
    name: string;
}

interface CostCentre {
    id: number;
    code: string;
    name: string;
}

interface FundingStream {
    id: number;
    code: string;
    name: string;
}

interface TaxRate {
    id: number;
    code: string;
    name: string;
    rate: string;
}

interface JournalLine {
    id: number;
    account: Account | null;
    description: string | null;
    debit: string;
    credit: string;
    cost_centre: CostCentre | null;
    funding_stream: FundingStream | null;
    tax_rate: TaxRate | null;
    tax_amount: string;
}

interface FiscalPeriod {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
}

interface UserRef {
    id: number;
    name: string;
}

interface ReversedByJournal {
    id: number;
    journal_number: string;
}

interface Journal {
    id: number;
    journal_number: string;
    journal_date: string;
    type: string;
    reference: string | null;
    description: string | null;
    status: string;
    total_amount: string;
    posted_at: string | null;
    fiscal_period: FiscalPeriod | null;
    posted_by: UserRef | null;
    created_by: UserRef | null;
    reversed_by_journal: ReversedByJournal | null;
    lines: JournalLine[];
}

interface Props extends PageProps {
    journal: Journal;
}

const statusBadge = (status: string) => {
    const map: Record<string, string> = {
        draft: 'bg-muted text-foreground',
        posted: 'bg-status-success-bg text-status-success',
        reversed: 'bg-status-critical-bg text-status-critical',
    };
    return map[status] ?? 'bg-muted text-foreground';
};

const typeBadge = (type: string) => {
    const map: Record<string, string> = {
        standard: 'bg-status-info-bg text-status-info',
        adjustment: 'bg-status-warning-bg text-status-warning',
        opening: 'bg-primary/10 text-primary',
    };
    return map[type] ?? 'bg-muted text-foreground';
};

export default function JournalsShow({ auth, journal }: Props) {
    const [reverseDialogOpen, setReverseDialogOpen] = useState(false);
    const [reverseReason, setReverseReason] = useState('');
    const [posting, setPosting] = useState(false);

    const totalDebits = journal.lines.reduce(
        (sum, l) => sum + Number(l.debit),
        0,
    );
    const totalCredits = journal.lines.reduce(
        (sum, l) => sum + Number(l.credit),
        0,
    );

    const handlePost = () => {
        setPosting(true);
        router.post(
            `/finance/journals/${journal.id}/post`,
            {},
            {
                onFinish: () => setPosting(false),
            },
        );
    };

    const handleReverse = () => {
        router.post(
            `/finance/journals/${journal.id}/reverse`,
            {
                reason: reverseReason,
            },
            {
                onSuccess: () => {
                    setReverseDialogOpen(false);
                    setReverseReason('');
                },
            },
        );
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance' },
                { title: 'Journals', href: '/finance/journals' },
                {
                    title: journal.journal_number,
                    href: `/finance/journals/${journal.id}`,
                },
            ]}
        >
            <Head title={`Journal ${journal.journal_number}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/journals"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                {journal.journal_number}
                                <Badge className={statusBadge(journal.status)}>
                                    {journal.status.charAt(0).toUpperCase() +
                                        journal.status.slice(1)}
                                </Badge>
                                <Badge className={typeBadge(journal.type)}>
                                    {journal.type.charAt(0).toUpperCase() +
                                        journal.type.slice(1)}
                                </Badge>
                            </span>
                        }
                        description={journal.description ?? undefined}
                        actions={
                            <>
                                {journal.status === 'draft' && (
                                    <Button
                                        onClick={handlePost}
                                        disabled={posting}
                                    >
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Post Journal
                                    </Button>
                                )}
                                {journal.status === 'posted' &&
                                    !journal.reversed_by_journal && (
                                        <Dialog
                                            open={reverseDialogOpen}
                                            onOpenChange={setReverseDialogOpen}
                                        >
                                            <DialogTrigger asChild>
                                                <Button variant="outline">
                                                    <RotateCcw className="mr-2 h-4 w-4" />
                                                    Reverse
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogHeader>
                                                    <DialogTitle>
                                                        Reverse Journal{' '}
                                                        {journal.journal_number}
                                                    </DialogTitle>
                                                </DialogHeader>
                                                <div className="space-y-4 pt-4">
                                                    <p className="text-sm text-muted-foreground">
                                                        This will create a new
                                                        reversing journal that
                                                        swaps all debits and
                                                        credits. The reversing
                                                        journal will be posted
                                                        immediately.
                                                    </p>
                                                    <div>
                                                        <Label htmlFor="reason">
                                                            Reason (optional)
                                                        </Label>
                                                        <Textarea
                                                            id="reason"
                                                            value={
                                                                reverseReason
                                                            }
                                                            onChange={(e) =>
                                                                setReverseReason(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="Reason for reversal"
                                                            rows={3}
                                                        />
                                                    </div>
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            onClick={() =>
                                                                setReverseDialogOpen(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            variant="destructive"
                                                            onClick={
                                                                handleReverse
                                                            }
                                                        >
                                                            Confirm Reversal
                                                        </Button>
                                                    </div>
                                                </div>
                                            </DialogContent>
                                        </Dialog>
                                    )}
                            </>
                        }
                    />
                }
            >
                {/* Meta info */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="mb-1 flex items-center gap-2 text-sm text-muted-foreground">
                                <Calendar className="h-4 w-4" />
                                Journal Date
                            </div>
                            <p className="font-semibold">
                                {new Date(
                                    journal.journal_date,
                                ).toLocaleDateString('en-NZ', {
                                    day: 'numeric',
                                    month: 'long',
                                    year: 'numeric',
                                })}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="pt-6">
                            <div className="mb-1 flex items-center gap-2 text-sm text-muted-foreground">
                                <FileText className="h-4 w-4" />
                                Reference
                            </div>
                            <p className="font-semibold">
                                {journal.reference || '-'}
                            </p>
                        </CardContent>
                    </Card>

                    {journal.status === 'posted' && journal.posted_by && (
                        <Card>
                            <CardContent className="pt-6">
                                <div className="mb-1 flex items-center gap-2 text-sm text-muted-foreground">
                                    <User className="h-4 w-4" />
                                    Posted By
                                </div>
                                <p className="font-semibold">
                                    {journal.posted_by.name}
                                </p>
                                {journal.posted_at && (
                                    <p className="text-xs text-muted-foreground">
                                        {new Date(
                                            journal.posted_at,
                                        ).toLocaleString('en-NZ')}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {journal.fiscal_period && (
                        <Card>
                            <CardContent className="pt-6">
                                <div className="mb-1 flex items-center gap-2 text-sm text-muted-foreground">
                                    <Calendar className="h-4 w-4" />
                                    Fiscal Period
                                </div>
                                <p className="font-semibold">
                                    {journal.fiscal_period.name}
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {journal.created_by && (
                        <Card>
                            <CardContent className="pt-6">
                                <div className="mb-1 flex items-center gap-2 text-sm text-muted-foreground">
                                    <User className="h-4 w-4" />
                                    Created By
                                </div>
                                <p className="font-semibold">
                                    {journal.created_by.name}
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Reversed notice */}
                {journal.reversed_by_journal && (
                    <div className="mb-6 rounded-md border border-status-critical/30 bg-status-critical-bg p-4">
                        <p className="text-sm text-status-critical">
                            This journal has been reversed by{' '}
                            <Link
                                href={`/finance/journals/${journal.reversed_by_journal.id}`}
                                className="font-semibold underline"
                            >
                                {journal.reversed_by_journal.journal_number}
                            </Link>
                        </p>
                    </div>
                )}

                {/* Lines Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Journal Lines</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Account</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">
                                        Debit
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Credit
                                    </TableHead>
                                    <TableHead>Cost Centre</TableHead>
                                    <TableHead>Funding Stream</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {journal.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="font-medium">
                                            {line.account ? (
                                                <span>
                                                    {line.account.code} -{' '}
                                                    {line.account.name}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    -
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {line.description ?? '-'}
                                        </TableCell>
                                        <TableCell className="text-right font-mono">
                                            {Number(line.debit) > 0
                                                ? formatMoney(line.debit)
                                                : '-'}
                                        </TableCell>
                                        <TableCell className="text-right font-mono">
                                            {Number(line.credit) > 0
                                                ? formatMoney(line.credit)
                                                : '-'}
                                        </TableCell>
                                        <TableCell>
                                            {line.cost_centre
                                                ? `${line.cost_centre.code} - ${line.cost_centre.name}`
                                                : '-'}
                                        </TableCell>
                                        <TableCell>
                                            {line.funding_stream
                                                ? `${line.funding_stream.code} - ${line.funding_stream.name}`
                                                : '-'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell
                                        colSpan={2}
                                        className="text-right font-semibold"
                                    >
                                        Totals
                                    </TableCell>
                                    <TableCell className="text-right font-mono font-semibold">
                                        {formatMoney(totalDebits)}
                                    </TableCell>
                                    <TableCell className="text-right font-mono font-semibold">
                                        {formatMoney(totalCredits)}
                                    </TableCell>
                                    <TableCell colSpan={2} />
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
