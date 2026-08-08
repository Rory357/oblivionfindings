import { BankingTabsFooter, ConfirmDialog } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Pencil,
    Plus,
    Settings,
    SlidersHorizontal,
    Trash2,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

type MatchRule = {
    id: number;
    name: string;
    priority: number;
    rule_type: string;
    conditions: Record<string, unknown> | null;
    auto_confirm_threshold: number;
    is_active: boolean;
    match_count: number;
    created_by_name: string | null;
    created_at: string;
};

type PageProps = {
    rules: MatchRule[];
};

type MatchRuleFormData = {
    name: string;
    priority: number;
    rule_type: string;
    auto_confirm_threshold: number;
    is_active: boolean;
    conditions: Record<string, string | number | boolean | null>;
};

const ruleTypeLabels: Record<string, string> = {
    exact_amount: 'Exact Amount',
    reference_match: 'Reference Match',
    vendor_pattern: 'Vendor Pattern',
    recurring_pattern: 'Recurring Pattern',
    amount_tolerance: 'Amount Tolerance',
};

function CreateRuleDialog() {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } =
        useForm<MatchRuleFormData>({
            name: '',
            priority: 0,
            rule_type: 'exact_amount',
            auto_confirm_threshold: 95,
            is_active: true,
            conditions: {},
        });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/finance/match-rules', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    Add Rule
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Match Rule</DialogTitle>
                    <DialogDescription>
                        Add a new rule for automatic payment matching.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="rule-name">Name *</Label>
                        <Input
                            id="rule-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Exact amount match for utilities"
                        />
                        {errors.name && (
                            <p className="text-sm text-destructive">
                                {errors.name}
                            </p>
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="rule-type">Rule Type *</Label>
                            <Select
                                value={data.rule_type}
                                onValueChange={(value) =>
                                    setData('rule_type', value)
                                }
                            >
                                <SelectTrigger id="rule-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="exact_amount">
                                        Exact Amount
                                    </SelectItem>
                                    <SelectItem value="reference_match">
                                        Reference Match
                                    </SelectItem>
                                    <SelectItem value="vendor_pattern">
                                        Vendor Pattern
                                    </SelectItem>
                                    <SelectItem value="recurring_pattern">
                                        Recurring Pattern
                                    </SelectItem>
                                    <SelectItem value="amount_tolerance">
                                        Amount Tolerance
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.rule_type && (
                                <p className="text-sm text-destructive">
                                    {errors.rule_type}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rule-priority">Priority</Label>
                            <Input
                                id="rule-priority"
                                type="number"
                                value={data.priority}
                                onChange={(e) =>
                                    setData(
                                        'priority',
                                        parseInt(e.target.value) || 0,
                                    )
                                }
                                min={0}
                            />
                            {errors.priority && (
                                <p className="text-sm text-destructive">
                                    {errors.priority}
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="rule-threshold">
                            Auto-confirm Threshold (%)
                        </Label>
                        <Input
                            id="rule-threshold"
                            type="number"
                            value={data.auto_confirm_threshold}
                            onChange={(e) =>
                                setData(
                                    'auto_confirm_threshold',
                                    parseFloat(e.target.value) || 95,
                                )
                            }
                            min={0}
                            max={100}
                            step={0.01}
                        />
                        <p className="text-xs text-muted-foreground">
                            Matches above this score will be automatically
                            confirmed.
                        </p>
                        {errors.auto_confirm_threshold && (
                            <p className="text-sm text-destructive">
                                {errors.auto_confirm_threshold}
                            </p>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="rule-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked === true)
                            }
                        />
                        <Label htmlFor="rule-active" className="font-normal">
                            Active
                        </Label>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditRuleDialog({ rule }: { rule: MatchRule }) {
    const [open, setOpen] = useState(false);
    const { data, setData, put, processing, errors } =
        useForm<MatchRuleFormData>({
            name: rule.name,
            priority: rule.priority,
            rule_type: rule.rule_type,
            auto_confirm_threshold: rule.auto_confirm_threshold,
            is_active: rule.is_active,
            conditions: (rule.conditions ??
                {}) as MatchRuleFormData['conditions'],
        });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        put(`/finance/match-rules/${rule.id}`, {
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon">
                    <Pencil className="h-4 w-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit Match Rule</DialogTitle>
                    <DialogDescription>
                        Update match rule configuration.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="edit-rule-name">Name *</Label>
                        <Input
                            id="edit-rule-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        {errors.name && (
                            <p className="text-sm text-destructive">
                                {errors.name}
                            </p>
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-rule-type">Rule Type *</Label>
                            <Select
                                value={data.rule_type}
                                onValueChange={(value) =>
                                    setData('rule_type', value)
                                }
                            >
                                <SelectTrigger id="edit-rule-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="exact_amount">
                                        Exact Amount
                                    </SelectItem>
                                    <SelectItem value="reference_match">
                                        Reference Match
                                    </SelectItem>
                                    <SelectItem value="vendor_pattern">
                                        Vendor Pattern
                                    </SelectItem>
                                    <SelectItem value="recurring_pattern">
                                        Recurring Pattern
                                    </SelectItem>
                                    <SelectItem value="amount_tolerance">
                                        Amount Tolerance
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.rule_type && (
                                <p className="text-sm text-destructive">
                                    {errors.rule_type}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-rule-priority">Priority</Label>
                            <Input
                                id="edit-rule-priority"
                                type="number"
                                value={data.priority}
                                onChange={(e) =>
                                    setData(
                                        'priority',
                                        parseInt(e.target.value) || 0,
                                    )
                                }
                                min={0}
                            />
                            {errors.priority && (
                                <p className="text-sm text-destructive">
                                    {errors.priority}
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="edit-rule-threshold">
                            Auto-confirm Threshold (%)
                        </Label>
                        <Input
                            id="edit-rule-threshold"
                            type="number"
                            value={data.auto_confirm_threshold}
                            onChange={(e) =>
                                setData(
                                    'auto_confirm_threshold',
                                    parseFloat(e.target.value) || 95,
                                )
                            }
                            min={0}
                            max={100}
                            step={0.01}
                        />
                        {errors.auto_confirm_threshold && (
                            <p className="text-sm text-destructive">
                                {errors.auto_confirm_threshold}
                            </p>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="edit-rule-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked === true)
                            }
                        />
                        <Label
                            htmlFor="edit-rule-active"
                            className="font-normal"
                        >
                            Active
                        </Label>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function MatchRulesIndex({ rules }: PageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Payment Matching', href: '/finance/payment-matching' },
        { title: 'Match Rules', href: '/finance/match-rules' },
    ];

    const [deleteTarget, setDeleteTarget] = useState<MatchRule | null>(null);
    const [deleting, setDeleting] = useState(false);

    function confirmDelete() {
        if (!deleteTarget) return;
        router.delete(`/finance/match-rules/${deleteTarget.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    }

    const activeCount = rules.filter((r) => r.is_active).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Match Rules" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        icon={SlidersHorizontal}
                        title="Match Rules"
                        description="Configure rules for automatic payment matching and auto-confirmation thresholds"
                        stats={[
                            { label: 'Total rules', value: rules.length },
                            { label: 'Active', value: activeCount },
                        ]}
                        actions={<CreateRuleDialog />}
                        footer={<BankingTabsFooter active="match-rules" />}
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Settings className="h-5 w-5 text-muted-foreground" />
                            <div>
                                <CardTitle>All Rules</CardTitle>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    Rules are evaluated in priority order during
                                    automatic matching
                                </p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead className="text-center">
                                        Priority
                                    </TableHead>
                                    <TableHead className="text-center">
                                        Threshold
                                    </TableHead>
                                    <TableHead className="text-center">
                                        Matches
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">
                                        Actions
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rules.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No match rules configured. Create a
                                            rule to control automatic matching
                                            behaviour.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    rules.map((rule) => (
                                        <TableRow key={rule.id}>
                                            <TableCell className="font-medium">
                                                {rule.name}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">
                                                    {ruleTypeLabels[
                                                        rule.rule_type
                                                    ] || rule.rule_type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-center">
                                                {rule.priority}
                                            </TableCell>
                                            <TableCell className="text-center font-mono tabular-nums">
                                                {rule.auto_confirm_threshold}%
                                            </TableCell>
                                            <TableCell className="text-center font-mono tabular-nums">
                                                {rule.match_count}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        rule.is_active
                                                            ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                            : 'border-border bg-muted text-muted-foreground'
                                                    }
                                                >
                                                    {rule.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <EditRuleDialog
                                                        rule={rule}
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Delete ${rule.name}`}
                                                        onClick={() =>
                                                            setDeleteTarget(
                                                                rule,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4 text-destructive" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>

            <ConfirmDialog
                open={!!deleteTarget}
                onOpenChange={(open) => !open && setDeleteTarget(null)}
                title="Delete match rule?"
                description={
                    <>
                        This permanently deletes the match rule{' '}
                        <span className="font-medium text-foreground">
                            {deleteTarget?.name}
                        </span>
                        . New bank transactions will no longer be auto-matched
                        by this rule.
                    </>
                }
                confirmLabel="Delete rule"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
