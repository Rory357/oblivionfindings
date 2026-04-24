import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type BreadcrumbItem } from '@/types';
import { PenTool, Eye } from 'lucide-react';

type SignatureRequest = {
    id: number;
    document_title: string;
    document_category: string | null;
    requested_by: string;
    requested_at: string;
    status: string;
};

type Props = {
    signatures: SignatureRequest[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Signatures', href: '/hr/signatures/pending' },
    { title: 'Pending', href: '/hr/signatures/pending' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: { className: 'border-status-warning/30 text-status-warning bg-status-warning', label: 'Pending' },
    signed: { className: 'border-status-success/30 text-status-success bg-status-success', label: 'Signed' },
    declined: { className: 'border-status-critical/30 text-status-critical bg-status-critical', label: 'Declined' },
};

export default function PendingSignatures({ signatures }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pending Signatures" />
            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Documents Awaiting Signature</h1>
                    <p className="text-sm text-muted-foreground">Review and sign documents that require your signature</p>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Document</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Requested By</TableHead>
                                    <TableHead>Requested</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-24" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {signatures.map((sig) => {
                                    const config = statusConfig[sig.status] || statusConfig.pending;
                                    return (
                                        <TableRow key={sig.id}>
                                            <TableCell className="font-medium">{sig.document_title}</TableCell>
                                            <TableCell className="text-muted-foreground capitalize">
                                                {sig.document_category || '-'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">{sig.requested_by}</TableCell>
                                            <TableCell className="text-muted-foreground">{sig.requested_at}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={`/hr/signatures/${sig.id}`}>
                                                        {sig.status === 'pending' ? (
                                                            <PenTool className="h-3.5 w-3.5" />
                                                        ) : (
                                                            <Eye className="h-3.5 w-3.5" />
                                                        )}
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {signatures.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                            No documents awaiting your signature.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
