import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Props = {
    client: { id: number; first_name: string; last_name: string };
    can_edit: boolean;
    documents: Array<any>;
};

export default function ClientDocuments({ client, can_edit, documents }: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`.trim();

    const uploadForm = useForm<{ file: File | null; title: string; category: string; version: string; effective_date: string; expiry_date: string; portal_visible: boolean; notes: string }>({
        file: null,
        title: '',
        category: '',
        version: '',
        effective_date: '',
        expiry_date: '',
        portal_visible: false,
        notes: '',
    });

    const [editingId, setEditingId] = useState<number | null>(null);
    const editForm = useForm<{ title: string; category: string; version: string; effective_date: string; expiry_date: string; portal_visible: boolean; notes: string }>({
        title: '',
        category: '',
        version: '',
        effective_date: '',
        expiry_date: '',
        portal_visible: false,
        notes: '',
    });

    const editingDoc = useMemo(() => documents.find((d) => d.id === editingId) ?? null, [documents, editingId]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: labels?.['client.plural'] ?? 'Clients', href: '/clients' },
                { title: name, href: `/clients/${client.id}` },
                { title: 'Documents', href: `/clients/${client.id}/documents` },
            ]}
        >
            <Head title={`Documents - ${name}`} />

            <div className="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Documents</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {can_edit && (
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">Upload document</div>
                                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div className="md:col-span-2">
                                        <Label>File</Label>
                                        <Input
                                            type="file"
                                            onChange={(e) => uploadForm.setData('file', e.target.files?.[0] ?? null)}
                                        />
                                    </div>
                                    <div>
                                        <Label>Title</Label>
                                        <Input value={uploadForm.data.title} onChange={(e) => uploadForm.setData('title', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label>Category</Label>
                                        <Input value={uploadForm.data.category} onChange={(e) => uploadForm.setData('category', e.target.value)} placeholder="care_plan / policy / assessment" />
                                    </div>
                                    <div>
                                        <Label>Version</Label>
                                        <Input value={uploadForm.data.version} onChange={(e) => uploadForm.setData('version', e.target.value)} placeholder="v1" />
                                    </div>
                                    <div>
                                        <Label>Effective date</Label>
                                        <Input type="date" value={uploadForm.data.effective_date} onChange={(e) => uploadForm.setData('effective_date', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label>Expiry date</Label>
                                        <Input type="date" value={uploadForm.data.expiry_date} onChange={(e) => uploadForm.setData('expiry_date', e.target.value)} />
                                    </div>
                                    <div className="flex items-center gap-2 pt-6">
                                        <Checkbox checked={uploadForm.data.portal_visible} onCheckedChange={(v) => uploadForm.setData('portal_visible', !!v)} />
                                        <div className="text-sm">Share in portal</div>
                                    </div>
                                    <div className="md:col-span-2">
                                        <Label>Notes</Label>
                                        <Textarea value={uploadForm.data.notes} onChange={(e) => uploadForm.setData('notes', e.target.value)} />
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <Button
                                        onClick={() =>
                                            uploadForm.post(`/clients/${client.id}/documents`, {
                                                forceFormData: true,
                                                preserveScroll: true,
                                                onSuccess: () => uploadForm.reset(),
                                            })
                                        }
                                        disabled={uploadForm.processing || !uploadForm.data.file}
                                    >
                                        Upload
                                    </Button>
                                </div>
                            </div>
                        )}

                        {can_edit && editingDoc && (
                            <div className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="text-sm font-medium">Edit document</div>
                                    <Button variant="ghost" onClick={() => setEditingId(null)}>
                                        Close
                                    </Button>
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div>
                                        <Label>Title</Label>
                                        <Input value={editForm.data.title} onChange={(e) => editForm.setData('title', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label>Category</Label>
                                        <Input value={editForm.data.category} onChange={(e) => editForm.setData('category', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label>Version</Label>
                                        <Input value={editForm.data.version} onChange={(e) => editForm.setData('version', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label>Effective date</Label>
                                        <Input type="date" value={editForm.data.effective_date} onChange={(e) => editForm.setData('effective_date', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label>Expiry date</Label>
                                        <Input type="date" value={editForm.data.expiry_date} onChange={(e) => editForm.setData('expiry_date', e.target.value)} />
                                    </div>
                                    <div className="flex items-center gap-2 pt-6">
                                        <Checkbox checked={editForm.data.portal_visible} onCheckedChange={(v) => editForm.setData('portal_visible', !!v)} />
                                        <div className="text-sm">Share in portal</div>
                                    </div>
                                    <div className="md:col-span-2">
                                        <Label>Notes</Label>
                                        <Textarea value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} />
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <Button
                                        onClick={() =>
                                            editForm.put(`/clients/${client.id}/documents/${editingDoc.id}`, {
                                                preserveScroll: true,
                                                onSuccess: () => setEditingId(null),
                                            })
                                        }
                                        disabled={editForm.processing}
                                    >
                                        Save
                                    </Button>
                                </div>
                            </div>
                        )}

                        <div className="space-y-2">
                            {documents.map((d) => (
                                <div key={d.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                    <div>
                                        <div className="text-sm font-medium">{d.title || d.original_name}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {[
                                                d.category && `Category: ${d.category}`,
                                                d.version && `Version: ${d.version}`,
                                                d.effective_date && `Effective: ${d.effective_date}`,
                                                d.expiry_date && `Expires: ${d.expiry_date}`,
                                                d.portal_visible ? 'Portal: yes' : 'Portal: no',
                                                d.mime_type && d.mime_type,
                                            ]
                                                .filter(Boolean)
                                                .join(' - ')}
                                        </div>
                                        {d.notes && <div className="mt-2 text-xs text-muted-foreground whitespace-pre-wrap">{d.notes}</div>}
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <a
                                            href={`/clients/${client.id}/documents/${d.id}/download`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Download
                                        </a>

                                        {can_edit && (
                                            <>
                                                <Button
                                                    variant="secondary"
                                                    onClick={() => {
                                                        setEditingId(d.id);
                                                        editForm.setData({
                                                            title: d.title ?? '',
                                                            category: d.category ?? '',
                                                            version: d.version ?? '',
                                                            effective_date: d.effective_date ?? '',
                                                            expiry_date: d.expiry_date ?? '',
                                                            portal_visible: !!d.portal_visible,
                                                            notes: d.notes ?? '',
                                                        });
                                                    }}
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    onClick={() => uploadForm.delete(`/clients/${client.id}/documents/${d.id}`, { preserveScroll: true })}
                                                >
                                                    Delete
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ))}

                            {!documents.length && <div className="text-sm text-muted-foreground">No documents uploaded.</div>}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
