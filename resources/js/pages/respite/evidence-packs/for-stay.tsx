import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    stay: any;
    pack: any;
};

const itemTypes = ['document', 'photo', 'form', 'note', 'assessment', 'incident_report', 'other'];

export default function EvidencePackForStay({ stay, pack }: Props) {
    const isSealed = pack ? !!pack.sealed_at : false;
    const [sealReason, setSealReason] = useState('');

    const addItemForm = useForm({
        type: '',
        title: '',
        description: '',
    });

    const handleAddItem = (e: React.FormEvent) => {
        e.preventDefault();
        if (!pack) return;
        addItemForm.post(`/respite/evidence-packs/${pack.id}/items`, {
            onSuccess: () => {
                addItemForm.reset();
            },
        });
    };

    const handleRemoveItem = (itemId: number) => {
        if (!pack) return;
        router.delete(`/respite/evidence-packs/${pack.id}/items/${itemId}`);
    };

    const handleSeal = () => {
        if (!pack) return;
        router.post(`/respite/evidence-packs/${pack.id}/seal`, { seal_reason: sealReason });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Stays', href: '/respite/stays' },
            { title: `${stay.client?.first_name} ${stay.client?.last_name}`, href: `/respite/stays/${stay.id}` },
            { title: 'Evidence Pack', href: `/respite/stays/${stay.id}/evidence-pack` },
        ]}>
            <Head title="Evidence Pack for Stay" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/respite/stays/${stay.id}`}
                        title={`Evidence Pack for ${stay.client?.first_name ?? ''} ${stay.client?.last_name ?? ''}`.trim()}
                        description={`${formatDateTime(stay.start_date)} — ${formatDateTime(stay.end_date)}`}
                    />
                }
            >
                <RespiteSubnav />

                {!pack ? (
                    <div className="space-y-4">
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No evidence pack exists for this stay.
                        </div>
                        <div className="flex justify-center">
                            <Link
                                href={`/respite/evidence-packs/create?stay_id=${stay.id}`}
                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                            >
                                Create Evidence Pack
                            </Link>
                        </div>
                    </div>
                ) : (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Pack Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm text-muted-foreground">
                                <div className="font-medium">{pack.title || `Evidence Pack #${pack.id}`}</div>
                                {pack.description && <div className="whitespace-pre-wrap">{pack.description}</div>}
                                <div className="flex flex-wrap gap-2">
                                    <Badge variant="outline">{pack.status?.replace(/_/g, ' ')}</Badge>
                                    {isSealed && <Badge variant="outline">Sealed</Badge>}
                                </div>
                                {isSealed && (
                                    <>
                                        <div>Sealed at: {formatDateTime(pack.sealed_at)}</div>
                                        {pack.sealed_by && <div>Sealed by: {pack.sealed_by.name || pack.sealed_by}</div>}
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Items</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {pack.items?.length > 0 ? (
                                    <div className="space-y-2">
                                        {pack.items.map((item: any) => (
                                            <div key={item.id} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <Badge variant="outline">{item.type}</Badge>
                                                        <span className="font-medium">{item.title}</span>
                                                    </div>
                                                    {item.description && (
                                                        <div className="mt-1 text-xs text-muted-foreground">{item.description}</div>
                                                    )}
                                                    {item.added_at && (
                                                        <div className="mt-1 text-xs text-muted-foreground">{formatDateTime(item.added_at)}</div>
                                                    )}
                                                </div>
                                                {!isSealed && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleRemoveItem(item.id)}
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="py-4 text-center text-sm text-muted-foreground">No items in this pack.</div>
                                )}
                            </CardContent>
                        </Card>

                        {!isSealed && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Add Item</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={handleAddItem} className="space-y-4">
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <Label>Type</Label>
                                                <Select value={addItemForm.data.type} onValueChange={(v) => addItemForm.setData('type', v)}>
                                                    <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                                    <SelectContent>
                                                        {itemTypes.map((t) => (
                                                            <SelectItem key={t} value={t}>{t.replace(/_/g, ' ')}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {addItemForm.errors.type && <div className="mt-1 text-xs text-status-critical">{addItemForm.errors.type}</div>}
                                            </div>
                                            <div>
                                                <Label>Title</Label>
                                                <Input
                                                    value={addItemForm.data.title}
                                                    onChange={(e) => addItemForm.setData('title', e.target.value)}
                                                    placeholder="Item title"
                                                />
                                                {addItemForm.errors.title && <div className="mt-1 text-xs text-status-critical">{addItemForm.errors.title}</div>}
                                            </div>
                                        </div>
                                        <div>
                                            <Label>Description</Label>
                                            <Textarea
                                                value={addItemForm.data.description}
                                                onChange={(e) => addItemForm.setData('description', e.target.value)}
                                                rows={2}
                                            />
                                            {addItemForm.errors.description && <div className="mt-1 text-xs text-status-critical">{addItemForm.errors.description}</div>}
                                        </div>
                                        <div className="flex justify-end">
                                            <Button type="submit" size="sm" disabled={addItemForm.processing}>
                                                Add Item
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}

                        {!isSealed && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Actions</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div>
                                        <Label>Seal Reason</Label>
                                        <Input
                                            value={sealReason}
                                            onChange={(e) => setSealReason(e.target.value)}
                                            placeholder="Reason for sealing this pack"
                                        />
                                    </div>
                                    <Button variant="outline" size="sm" onClick={handleSeal}>
                                        Seal Evidence Pack
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                    </>
                )}
            </PageLayout>
        </AppLayout>
    );
}
