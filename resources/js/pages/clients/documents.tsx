import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    client: { id: number; first_name: string; last_name: string };
    can_edit: boolean;
    documents: Array<any>;
};

export default function ClientDocuments({ client, can_edit, documents }: Props) {
    const name = `${client.first_name} ${client.last_name}`.trim();

    const uploadForm = useForm<{ file: File | null; title: string; category: string; notes: string }>({
        file: null,
        title: '',
        category: '',
        notes: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
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

                        <div className="space-y-2">
                            {documents.map((d) => (
                                <div key={d.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                    <div>
                                        <div className="text-sm font-medium">{d.title || d.original_name}</div>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {[d.category && `Category: ${d.category}`, d.mime_type && d.mime_type].filter(Boolean).join(' - ')}
                                        </div>
                                        {d.notes && <div className="mt-2 text-xs text-slate-600 whitespace-pre-wrap">{d.notes}</div>}
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <a
                                            href={`/clients/${client.id}/documents/${d.id}/download`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Download
                                        </a>

                                        {can_edit && (
                                            <Button
                                                variant="destructive"
                                                onClick={() => uploadForm.delete(`/clients/${client.id}/documents/${d.id}`, { preserveScroll: true })}
                                            >
                                                Delete
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}

                            {!documents.length && <div className="text-sm text-slate-500">No documents uploaded.</div>}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
