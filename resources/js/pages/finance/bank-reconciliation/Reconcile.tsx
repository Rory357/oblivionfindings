import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    CheckCircle,
    XCircle,
    Link2,
    Unlink,
    AlertTriangle,
    ArrowRight,
    Sparkles,
} from 'lucide-react';
import { useState, useMemo } from 'react';

interface BankTransaction {
    id: number;
    transaction_date: string;
    amount: number;
    description: string;
    reference: string | null;
    source?: string;
}

interface JournalLine {
    id: number;
    debit: number;
    credit: number;
    description: string;
    journal_number: string;
    journal_date: string;
    journal_description?: string;
}

interface MatchedLine {
    id: number;
    bank_transaction: BankTransaction | null;
    journal_line: JournalLine | null;
}

interface SuggestedMatch {
    bank_transaction_id: number;
    journal_line_id: number;
    confidence: 'high' | 'medium' | 'low';
}

interface Reconciliation {
    id: number;
    bank_account_id: number;
    bank_account_name: string;
    statement_date: string;
    statement_balance: number;
    calculated_balance: number | null;
    status: string;
    completed_at: string | null;
    completed_by_name: string | null;
    starting_balance: number;
}

interface Props {
    reconciliation: Reconciliation;
    matchedLines: MatchedLine[];
    unreconciledTransactions: BankTransaction[];
    unmatchedJournalLines: JournalLine[];
    suggestedMatches: SuggestedMatch[];
}

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const confidenceColors: Record<string, string> = {
    high: 'bg-green-100 text-green-800 border-green-300',
    medium: 'bg-amber-100 text-amber-800 border-amber-300',
    low: 'bg-gray-100 text-gray-600 border-gray-300',
};

export default function Reconcile({
    reconciliation,
    matchedLines,
    unreconciledTransactions,
    unmatchedJournalLines,
    suggestedMatches,
}: Props) {
    const [selectedTransaction, setSelectedTransaction] = useState<number | null>(null);
    const [selectedJournalLine, setSelectedJournalLine] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);

    const isCompleted = reconciliation.status === 'completed';

    // Build a lookup for suggested matches
    const suggestedMatchMap = useMemo(() => {
        const map = new Map<number, SuggestedMatch>();
        suggestedMatches.forEach((match) => {
            map.set(match.bank_transaction_id, match);
        });
        return map;
    }, [suggestedMatches]);

    // Calculate running totals
    const matchedTotal = useMemo(() => {
        return matchedLines.reduce((sum, line) => {
            return sum + (line.bank_transaction?.amount ?? 0);
        }, 0);
    }, [matchedLines]);

    const calculatedBalance = reconciliation.starting_balance + matchedTotal;
    const difference = reconciliation.statement_balance - calculatedBalance;
    const isBalanced = Math.abs(difference) <= 0.01;

    const handleMatch = () => {
        if (!selectedTransaction || processing) return;

        setProcessing(true);
        router.post(
            `/finance/bank-reconciliation/${reconciliation.id}/match`,
            {
                bank_transaction_id: selectedTransaction,
                journal_line_id: selectedJournalLine,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setSelectedTransaction(null);
                    setSelectedJournalLine(null);
                },
            },
        );
    };

    const handleSuggestedMatch = (match: SuggestedMatch) => {
        if (processing) return;

        setProcessing(true);
        router.post(
            `/finance/bank-reconciliation/${reconciliation.id}/match`,
            {
                bank_transaction_id: match.bank_transaction_id,
                journal_line_id: match.journal_line_id,
            },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const handleUnmatch = (lineId: number) => {
        if (processing) return;

        setProcessing(true);
        router.post(
            `/finance/bank-reconciliation/${reconciliation.id}/unmatch`,
            { line_id: lineId },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const handleComplete = () => {
        if (processing || !isBalanced) return;

        setProcessing(true);
        router.post(
            `/finance/bank-reconciliation/${reconciliation.id}/complete`,
            {},
            { onFinish: () => setProcessing(false) },
        );
    };

    const handleMatchWithoutJournal = () => {
        if (!selectedTransaction || processing) return;

        setProcessing(true);
        router.post(
            `/finance/bank-reconciliation/${reconciliation.id}/match`,
            {
                bank_transaction_id: selectedTransaction,
                journal_line_id: null,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setSelectedTransaction(null);
                    setSelectedJournalLine(null);
                },
            },
        );
    };

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Reconciliation', href: '/finance/bank-reconciliation' },
        { title: `${reconciliation.bank_account_name} - ${reconciliation.statement_date}`, href: `/finance/bank-reconciliation/${reconciliation.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Reconcile - ${reconciliation.bank_account_name}`} />

            <div className="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">
                            Bank Reconciliation
                        </h1>
                        <p className="text-gray-500 mt-1">
                            {reconciliation.bank_account_name} &mdash; Statement date: {reconciliation.statement_date}
                        </p>
                    </div>
                    {isCompleted && (
                        <Badge className="bg-green-100 text-green-800 text-sm px-3 py-1">
                            <CheckCircle className="h-4 w-4 mr-1" />
                            Completed {reconciliation.completed_at}
                            {reconciliation.completed_by_name && ` by ${reconciliation.completed_by_name}`}
                        </Badge>
                    )}
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <Card>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Starting Balance</p>
                            <p className="text-lg font-semibold font-mono tabular-nums mt-1">
                                {formatNZD(reconciliation.starting_balance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Statement Balance</p>
                            <p className="text-lg font-semibold font-mono tabular-nums mt-1">
                                {formatNZD(reconciliation.statement_balance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Calculated Balance</p>
                            <p className="text-lg font-semibold font-mono tabular-nums mt-1">
                                {formatNZD(calculatedBalance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className={isBalanced ? 'border-green-300 bg-green-50' : 'border-amber-300 bg-amber-50'}>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Difference</p>
                            <p className={`text-lg font-semibold font-mono tabular-nums mt-1 ${isBalanced ? 'text-green-700' : 'text-amber-700'}`}>
                                {formatNZD(difference)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Matched</p>
                            <p className="text-lg font-semibold mt-1">
                                {matchedLines.length} item{matchedLines.length !== 1 ? 's' : ''}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {unreconciledTransactions.length} unmatched
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Action Buttons */}
                {!isCompleted && (
                    <div className="flex items-center gap-3">
                        <Button
                            onClick={handleMatch}
                            disabled={!selectedTransaction || processing}
                        >
                            <Link2 className="h-4 w-4 mr-2" />
                            Match Selected
                        </Button>
                        <Button
                            variant="outline"
                            onClick={handleMatchWithoutJournal}
                            disabled={!selectedTransaction || processing}
                        >
                            Match Without Journal Entry
                        </Button>
                        <div className="flex-1" />
                        <Button
                            onClick={handleComplete}
                            disabled={!isBalanced || processing}
                            className={isBalanced ? 'bg-green-600 hover:bg-green-700' : ''}
                        >
                            <CheckCircle className="h-4 w-4 mr-2" />
                            Complete Reconciliation
                        </Button>
                    </div>
                )}

                {/* Suggested Matches */}
                {!isCompleted && suggestedMatches.length > 0 && (
                    <Card className="border-blue-200 bg-blue-50/50">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base flex items-center gap-2">
                                <Sparkles className="h-4 w-4 text-blue-600" />
                                Suggested Matches ({suggestedMatches.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {suggestedMatches.map((match) => {
                                    const txn = unreconciledTransactions.find(
                                        (t) => t.id === match.bank_transaction_id,
                                    );
                                    const jl = unmatchedJournalLines.find(
                                        (l) => l.id === match.journal_line_id,
                                    );
                                    if (!txn || !jl) return null;

                                    return (
                                        <div
                                            key={`${match.bank_transaction_id}-${match.journal_line_id}`}
                                            className="flex items-center gap-4 p-3 bg-white rounded-lg border"
                                        >
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium truncate">{txn.description}</span>
                                                    <span className={`font-mono tabular-nums text-sm ${txn.amount >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                        {formatNZD(txn.amount)}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">{txn.transaction_date}</span>
                                                </div>
                                            </div>
                                            <ArrowRight className="h-4 w-4 text-muted-foreground shrink-0" />
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium truncate">{jl.description || jl.journal_description}</span>
                                                    <span className="font-mono tabular-nums text-sm">
                                                        {jl.debit > 0 ? formatNZD(jl.debit) : formatNZD(-jl.credit)}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">#{jl.journal_number}</span>
                                                </div>
                                            </div>
                                            <Badge variant="outline" className={`shrink-0 ${confidenceColors[match.confidence]}`}>
                                                {match.confidence}
                                            </Badge>
                                            <Button
                                                size="sm"
                                                onClick={() => handleSuggestedMatch(match)}
                                                disabled={processing}
                                            >
                                                Accept
                                            </Button>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Two-Column Layout */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* LEFT: Unreconciled Bank Transactions */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">
                                Unreconciled Bank Transactions ({unreconciledTransactions.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {unreconciledTransactions.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">
                                    All transactions have been matched.
                                </div>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-[100px]">Date</TableHead>
                                            <TableHead className="text-right w-[110px]">Amount</TableHead>
                                            <TableHead>Description</TableHead>
                                            <TableHead className="w-[100px]">Reference</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {unreconciledTransactions.map((txn) => {
                                            const isSelected = selectedTransaction === txn.id;
                                            const hasSuggestion = suggestedMatchMap.has(txn.id);

                                            return (
                                                <TableRow
                                                    key={txn.id}
                                                    className={`cursor-pointer transition-colors ${
                                                        isSelected
                                                            ? 'bg-blue-100 hover:bg-blue-100'
                                                            : hasSuggestion
                                                              ? 'bg-blue-50/50 hover:bg-blue-50'
                                                              : 'hover:bg-muted/50'
                                                    } ${isCompleted ? 'pointer-events-none' : ''}`}
                                                    onClick={() => {
                                                        if (isCompleted) return;
                                                        setSelectedTransaction(isSelected ? null : txn.id);
                                                        // Auto-select suggested journal line
                                                        const suggestion = suggestedMatchMap.get(txn.id);
                                                        if (suggestion && !isSelected) {
                                                            setSelectedJournalLine(suggestion.journal_line_id);
                                                        } else if (isSelected) {
                                                            setSelectedJournalLine(null);
                                                        }
                                                    }}
                                                >
                                                    <TableCell className="whitespace-nowrap text-sm">
                                                        {txn.transaction_date}
                                                    </TableCell>
                                                    <TableCell className={`text-right font-mono tabular-nums text-sm ${txn.amount >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                        {formatNZD(txn.amount)}
                                                    </TableCell>
                                                    <TableCell className="text-sm truncate max-w-[200px]">
                                                        {txn.description}
                                                    </TableCell>
                                                    <TableCell className="text-sm text-muted-foreground truncate max-w-[100px]">
                                                        {txn.reference || '-'}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    {/* RIGHT: Unmatched GL Journal Lines */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">
                                Unmatched GL Journal Lines ({unmatchedJournalLines.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {unmatchedJournalLines.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">
                                    All journal lines have been matched.
                                </div>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-[100px]">Date</TableHead>
                                            <TableHead className="text-right w-[110px]">Amount</TableHead>
                                            <TableHead>Description</TableHead>
                                            <TableHead className="w-[90px]">Journal #</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {unmatchedJournalLines.map((line) => {
                                            const isSelected = selectedJournalLine === line.id;
                                            const amount = line.debit > 0 ? line.debit : -line.credit;

                                            return (
                                                <TableRow
                                                    key={line.id}
                                                    className={`cursor-pointer transition-colors ${
                                                        isSelected
                                                            ? 'bg-blue-100 hover:bg-blue-100'
                                                            : 'hover:bg-muted/50'
                                                    } ${isCompleted ? 'pointer-events-none' : ''}`}
                                                    onClick={() => {
                                                        if (isCompleted) return;
                                                        setSelectedJournalLine(isSelected ? null : line.id);
                                                    }}
                                                >
                                                    <TableCell className="whitespace-nowrap text-sm">
                                                        {line.journal_date}
                                                    </TableCell>
                                                    <TableCell className={`text-right font-mono tabular-nums text-sm ${amount >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                        {formatNZD(amount)}
                                                    </TableCell>
                                                    <TableCell className="text-sm truncate max-w-[200px]">
                                                        {line.description || line.journal_description || '-'}
                                                    </TableCell>
                                                    <TableCell className="text-sm font-mono text-muted-foreground">
                                                        {line.journal_number}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Matched Lines */}
                {matchedLines.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base flex items-center gap-2">
                                <CheckCircle className="h-4 w-4 text-green-600" />
                                Matched Items ({matchedLines.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Bank Transaction</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead>Journal Entry</TableHead>
                                        <TableHead className="text-right">Journal Amount</TableHead>
                                        {!isCompleted && <TableHead className="w-[80px]"></TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {matchedLines.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell>
                                                <div>
                                                    <span className="text-sm font-medium">
                                                        {line.bank_transaction?.description}
                                                    </span>
                                                    <div className="text-xs text-muted-foreground">
                                                        {line.bank_transaction?.transaction_date}
                                                        {line.bank_transaction?.reference && ` | ${line.bank_transaction.reference}`}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell className={`text-right font-mono tabular-nums ${(line.bank_transaction?.amount ?? 0) >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                {line.bank_transaction ? formatNZD(line.bank_transaction.amount) : '-'}
                                            </TableCell>
                                            <TableCell>
                                                {line.journal_line ? (
                                                    <div>
                                                        <span className="text-sm font-medium">
                                                            {line.journal_line.description}
                                                        </span>
                                                        <div className="text-xs text-muted-foreground">
                                                            #{line.journal_line.journal_number} | {line.journal_line.journal_date}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground italic">No journal entry</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {line.journal_line
                                                    ? formatNZD(line.journal_line.debit > 0 ? line.journal_line.debit : -line.journal_line.credit)
                                                    : '-'}
                                            </TableCell>
                                            {!isCompleted && (
                                                <TableCell>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-red-600 hover:text-red-800 hover:bg-red-50"
                                                        onClick={() => handleUnmatch(line.id)}
                                                        disabled={processing}
                                                    >
                                                        <Unlink className="h-4 w-4" />
                                                    </Button>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
