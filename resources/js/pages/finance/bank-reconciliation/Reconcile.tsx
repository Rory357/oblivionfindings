import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    Banknote,
    CheckCircle,
    XCircle,
    Link2,
    Unlink,
    AlertTriangle,
    ArrowRight,
    Sparkles,
} from 'lucide-react';
import { type BreadcrumbItem } from '@/types';
import { useState, useMemo } from 'react';
import { formatMoney } from '@/components/finance/money';

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

interface AdjustmentAccount {
    id: number;
    code: string;
    name: string;
}

interface Props {
    reconciliation: Reconciliation;
    matchedLines: MatchedLine[];
    unreconciledTransactions: BankTransaction[];
    unmatchedJournalLines: JournalLine[];
    suggestedMatches: SuggestedMatch[];
    adjustmentAccounts: AdjustmentAccount[];
}

const confidenceColors: Record<string, string> = {
    high: 'bg-status-success-bg text-status-success border-status-success/30',
    medium: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    low: 'bg-muted text-muted-foreground border-border',
};

export default function Reconcile({
    reconciliation,
    matchedLines,
    unreconciledTransactions,
    unmatchedJournalLines,
    suggestedMatches,
    adjustmentAccounts,
}: Props) {
    const [selectedTransaction, setSelectedTransaction] = useState<number | null>(null);
    const [selectedJournalLine, setSelectedJournalLine] = useState<number | null>(null);
    const [adjustmentAccountId, setAdjustmentAccountId] = useState<string>('');
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
                adjustment_account_id: adjustmentAccountId ? Number(adjustmentAccountId) : null,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setSelectedTransaction(null);
                    setSelectedJournalLine(null);
                    setAdjustmentAccountId('');
                },
            },
        );
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Reconciliation', href: '/finance/bank-reconciliation' },
        { title: `${reconciliation.bank_account_name} - ${reconciliation.statement_date}`, href: `/finance/bank-reconciliation/${reconciliation.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Reconcile - ${reconciliation.bank_account_name}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Banknote}
                        backHref="/finance/bank-reconciliation"
                        title="Bank Reconciliation"
                        description={`${reconciliation.bank_account_name} — Statement date: ${reconciliation.statement_date}`}
                        actions={
                            isCompleted ? (
                                <Badge className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm">
                                    <CheckCircle className="h-4 w-4 mr-1" />
                                    Completed {reconciliation.completed_at}
                                    {reconciliation.completed_by_name && ` by ${reconciliation.completed_by_name}`}
                                </Badge>
                            ) : null
                        }
                    />
                }
            >

                {/* Summary Cards */}
                <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <Card>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Starting Balance</p>
                            <p className="text-lg font-semibold font-mono tabular-nums mt-1">
                                {formatMoney(reconciliation.starting_balance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Statement Balance</p>
                            <p className="text-lg font-semibold font-mono tabular-nums mt-1">
                                {formatMoney(reconciliation.statement_balance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Calculated Balance</p>
                            <p className="text-lg font-semibold font-mono tabular-nums mt-1">
                                {formatMoney(calculatedBalance)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className={isBalanced ? 'border-status-success/30 bg-status-success' : 'border-status-warning/30 bg-status-warning'}>
                        <CardContent className="pt-4 pb-4">
                            <p className="text-xs text-muted-foreground uppercase tracking-wider">Difference</p>
                            <p className={`text-lg font-semibold font-mono tabular-nums mt-1 ${isBalanced ? 'text-status-success' : 'text-status-warning'}`}>
                                {formatMoney(difference)}
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
                        <select
                            value={adjustmentAccountId}
                            onChange={(e) => setAdjustmentAccountId(e.target.value)}
                            disabled={!selectedTransaction || processing}
                            aria-label="Adjustment account"
                            className="h-9 rounded-md border border-input bg-background px-2 text-sm text-foreground disabled:opacity-50"
                        >
                            <option value="">Adjustment account…</option>
                            {adjustmentAccounts.map((acc) => (
                                <option key={acc.id} value={String(acc.id)}>
                                    {acc.code} · {acc.name}
                                </option>
                            ))}
                        </select>
                        <Button
                            variant="outline"
                            onClick={handleMatchWithoutJournal}
                            disabled={!selectedTransaction || processing}
                            title={adjustmentAccountId ? 'Posts a balanced adjustment journal against the chosen account' : 'Marks reconciled without a journal'}
                        >
                            {adjustmentAccountId ? 'Match as Adjustment' : 'Match Without Journal Entry'}
                        </Button>
                        <div className="flex-1" />
                        <Button
                            onClick={handleComplete}
                            disabled={!isBalanced || processing}
                            className={isBalanced ? 'bg-status-success hover:bg-status-success text-white' : ''}
                        >
                            <CheckCircle className="h-4 w-4 mr-2" />
                            Complete Reconciliation
                        </Button>
                    </div>
                )}

                {/* Suggested Matches */}
                {!isCompleted && suggestedMatches.length > 0 && (
                    <Card className="border-status-info/30 bg-status-info">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base flex items-center gap-2">
                                <Sparkles className="h-4 w-4 text-status-info" />
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
                                            className="flex items-center gap-4 p-3 bg-background rounded-lg border"
                                        >
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium truncate">{txn.description}</span>
                                                    <span className={`font-mono tabular-nums text-sm ${txn.amount >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                                        {formatMoney(txn.amount)}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">{txn.transaction_date}</span>
                                                </div>
                                            </div>
                                            <ArrowRight className="h-4 w-4 text-muted-foreground shrink-0" />
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium truncate">{jl.description || jl.journal_description}</span>
                                                    <span className="font-mono tabular-nums text-sm">
                                                        {jl.debit > 0 ? formatMoney(jl.debit) : formatMoney(-jl.credit)}
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
                                                            ? 'bg-status-info hover:bg-status-info'
                                                            : hasSuggestion
                                                              ? 'bg-status-info hover:bg-status-info'
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
                                                    <TableCell className={`text-right font-mono tabular-nums text-sm ${txn.amount >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                                        {formatMoney(txn.amount)}
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
                                                            ? 'bg-status-info hover:bg-status-info'
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
                                                    <TableCell className={`text-right font-mono tabular-nums text-sm ${amount >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                                        {formatMoney(amount)}
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
                                <CheckCircle className="h-4 w-4 text-status-success" />
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
                                            <TableCell className={`text-right font-mono tabular-nums ${(line.bank_transaction?.amount ?? 0) >= 0 ? 'text-status-success' : 'text-status-critical'}`}>
                                                {line.bank_transaction ? formatMoney(line.bank_transaction.amount) : '-'}
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
                                                    ? formatMoney(line.journal_line.debit > 0 ? line.journal_line.debit : -line.journal_line.credit)
                                                    : '-'}
                                            </TableCell>
                                            {!isCompleted && (
                                                <TableCell>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive hover:text-destructive hover:bg-destructive/10"
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
            </PageLayout>
        </AppLayout>
    );
}
