import { Head, useForm, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { BankingTabsFooter } from '@/components/finance';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus, CreditCard, Smartphone } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Terminal {
    id: number;
    terminal_id: string;
    name: string;
    location: string | null;
    provider: string;
    bank_account_name: string | null;
    gl_account_name: string | null;
    is_active: boolean;
    batch_count: number;
}

interface BankAccount {
    id: number;
    name: string;
}

interface GlAccount {
    id: number;
    code: string;
    name: string;
}

interface Props extends PageProps {
    terminals: Terminal[];
    bankAccounts: BankAccount[];
    glAccounts: GlAccount[];
}

const providerLabels: Record<string, string> = {
    paymark: 'Paymark',
    worldline: 'Worldline',
    eftpos_nz: 'EFTPOS NZ',
    windcave: 'Windcave',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'EFTPOS', href: '/finance/eftpos/batches' },
    { title: 'Terminals', href: '/finance/eftpos/terminals' },
];

export default function EftposTerminals({ terminals, bankAccounts, glAccounts }: Props) {
    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        terminal_id: '',
        name: '',
        location: '',
        provider: 'paymark',
        merchant_id: '',
        bank_account_id: '',
        gl_account_id: '',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post('/finance/eftpos/terminals', {
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const activeCount = terminals.filter((t) => t.is_active).length;
    const totalBatches = terminals.reduce((sum, t) => sum + t.batch_count, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="EFTPOS Terminals" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Smartphone}
                        title="EFTPOS Terminals"
                        description="Configure and manage EFTPOS terminal devices."
                        stats={[
                            { label: 'Total', value: terminals.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Batches', value: totalBatches },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button asChild size="sm" variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                    <Link href="/finance/eftpos/batches">View Batches</Link>
                                </Button>
                                <Button size="sm" onClick={() => setShowForm(!showForm)}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add Terminal
                                </Button>
                            </div>
                        }
                        footer={<BankingTabsFooter active="eftpos" />}
                    />
                }
            >
                {showForm && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Add EFTPOS Terminal</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <Label htmlFor="terminal_id">Terminal ID</Label>
                                    <Input
                                        id="terminal_id"
                                        value={data.terminal_id}
                                        onChange={(e) => setData('terminal_id', e.target.value)}
                                        placeholder="e.g. T001234"
                                    />
                                    {errors.terminal_id && <p className="mt-1 text-sm text-destructive">{errors.terminal_id}</p>}
                                </div>

                                <div>
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g. Front Desk Terminal"
                                    />
                                    {errors.name && <p className="mt-1 text-sm text-destructive">{errors.name}</p>}
                                </div>

                                <div>
                                    <Label htmlFor="location">Location</Label>
                                    <Input
                                        id="location"
                                        value={data.location}
                                        onChange={(e) => setData('location', e.target.value)}
                                        placeholder="e.g. Main Office"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="provider">Provider</Label>
                                    <Select value={data.provider} onValueChange={(val) => setData('provider', val)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="paymark">Paymark</SelectItem>
                                            <SelectItem value="worldline">Worldline</SelectItem>
                                            <SelectItem value="eftpos_nz">EFTPOS NZ</SelectItem>
                                            <SelectItem value="windcave">Windcave</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label htmlFor="merchant_id">Merchant ID</Label>
                                    <Input
                                        id="merchant_id"
                                        value={data.merchant_id}
                                        onChange={(e) => setData('merchant_id', e.target.value)}
                                        placeholder="Encrypted merchant ID"
                                    />
                                    {errors.merchant_id && <p className="mt-1 text-sm text-destructive">{errors.merchant_id}</p>}
                                </div>

                                <div>
                                    <Label htmlFor="bank_account_id">Settlement Account</Label>
                                    <Select value={data.bank_account_id} onValueChange={(val) => setData('bank_account_id', val)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select account" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {bankAccounts.map((acc) => (
                                                <SelectItem key={acc.id} value={String(acc.id)}>
                                                    {acc.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <Label htmlFor="gl_account_id">GL Clearing Account</Label>
                                    <Select value={data.gl_account_id} onValueChange={(val) => setData('gl_account_id', val)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select GL account" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {glAccounts.map((acc) => (
                                                <SelectItem key={acc.id} value={String(acc.id)}>
                                                    {acc.code} - {acc.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="flex items-end gap-2 sm:col-span-2 lg:col-span-3">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Adding...' : 'Add Terminal'}
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => setShowForm(false)}>
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {terminals.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <CreditCard className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">No EFTPOS terminals configured.</p>
                            <p className="text-sm text-muted-foreground">Add your first terminal to start tracking EFTPOS batches.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Terminal ID</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Location</TableHead>
                                        <TableHead>Provider</TableHead>
                                        <TableHead>Settlement Account</TableHead>
                                        <TableHead>GL Account</TableHead>
                                        <TableHead>Batches</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {terminals.map((terminal) => (
                                        <TableRow key={terminal.id}>
                                            <TableCell className="font-mono text-sm">{terminal.terminal_id}</TableCell>
                                            <TableCell className="font-medium">{terminal.name}</TableCell>
                                            <TableCell>{terminal.location ?? '-'}</TableCell>
                                            <TableCell>{providerLabels[terminal.provider] ?? terminal.provider}</TableCell>
                                            <TableCell className="text-sm">{terminal.bank_account_name ?? '-'}</TableCell>
                                            <TableCell className="text-sm">{terminal.gl_account_name ?? '-'}</TableCell>
                                            <TableCell>{terminal.batch_count}</TableCell>
                                            <TableCell>
                                                {terminal.is_active ? (
                                                    <Badge variant="outline" className="border-status-success/30 text-status-success">
                                                        Active
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">Inactive</Badge>
                                                )}
                                            </TableCell>
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
