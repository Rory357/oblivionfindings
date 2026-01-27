import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Tabs } from '@/components/ui/tabs';
import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Site = {
    id: number;
    name: string;
    phone?: string | null;
    email?: string | null;
    manager_name?: string | null;
    manager_phone?: string | null;
    after_hours_phone?: string | null;
    emergency_plan_location?: string | null;
    medication_storage_location?: string | null;
    notes?: string | null;
    address?: string;
    is_active: boolean;
};

type Contact = {
    id: number;
    type?: string | null;
    name: string;
    role?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
    notes?: string | null;
};

type Doc = {
    id: number;
    title?: string | null;
    category?: string | null;
    version?: string | null;
    effective_date?: string | null;
    expiry_date?: string | null;
    notes?: string | null;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    created_at?: string | null;
    uploaded_by?: { id: number; name: string; email: string } | null;
};

type AssetLite = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    status: string;
    risk_level: string;
    location?: string | null;
    owner: { type: 'site' | 'client'; label: string; id: number };
    updated_at?: string | null;
};

type ClientLite = { id: number; first_name: string; last_name: string; status: string };
type ChecklistItem = { key: string; label: string; done: boolean };

type Props = {
    site: Site;
    clients: ClientLite[];
    contacts: Contact[];
    documents: Doc[];
    assets: AssetLite[];
    checklist: ChecklistItem[];
    can_edit: boolean;
    can?: { createAsset?: boolean };
};

type TabKey = 'overview' | 'clients' | 'assets' | 'contacts' | 'documents' | 'compliance';

function bytes(n?: number | null): string {
    if (!n || n <= 0) return '—';
    const kb = n / 1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    const mb = kb / 1024;
    return `${mb.toFixed(1)} MB`;
}

export default function SiteShow({ site, clients, assets, contacts, documents, checklist, can_edit, can }: Props) {
    const tabs = useMemo(
        () => [
            { key: 'overview' as const, label: 'Overview' },
            { key: 'clients' as const, label: `Clients (${clients.length})` },
            { key: 'assets' as const, label: `Assets (${assets.length})` },
            { key: 'contacts' as const, label: `Contacts (${contacts.length})` },
            { key: 'documents' as const, label: `Documents (${documents.length})` },
            { key: 'compliance' as const, label: 'Compliance' },
        ],
        [clients.length, assets.length, contacts.length, documents.length],
    );

    const [tab, setTab] = useState<TabKey>('overview');
    const [editingContactId, setEditingContactId] = useState<number | null>(null);

    const contactForm = useForm<{
        type: string;
        name: string;
        role: string;
        phone: string;
        email: string;
        is_primary: boolean;
        notes: string;
    }>({
        type: 'emergency',
        name: '',
        role: '',
        phone: '',
        email: '',
        is_primary: false,
        notes: '',
    });

    function startEditContact(c: Contact) {
        setEditingContactId(c.id);
        contactForm.setData({
            type: c.type || '',
            name: c.name || '',
            role: c.role || '',
            phone: c.phone || '',
            email: c.email || '',
            is_primary: !!c.is_primary,
            notes: c.notes || '',
        });
        contactForm.clearErrors();
    }

    function resetContactForm() {
        setEditingContactId(null);
        contactForm.reset();
        contactForm.clearErrors();
    }

    const docForm = useForm<{
        file: File | null;
        title: string;
        category: string;
        version: string;
        effective_date: string;
        expiry_date: string;
        notes: string;
    }>({
        file: null,
        title: '',
        category: 'evacuation_plan',
        version: '',
        effective_date: '',
        expiry_date: '',
        notes: '',
    });

    const percent = Math.round((checklist.filter((c) => c.done).length / Math.max(1, checklist.length)) * 100);

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }]}>
            <Head title={site.name} />

            <PageShell>
                <PageHeader
                    title={site.name}
                    subtitle={site.address || '—'}
                    actions={
                        <div className="flex items-center gap-2">
                            <span
                                className={`inline-flex rounded-full border px-2 py-0.5 text-xs ${
                                    site.is_active ? 'border-emerald-500/30 text-emerald-300' : 'border-slate-500/30 text-slate-300'
                                }`}
                            >
                                {site.is_active ? 'Active' : 'Inactive'}
                            </span>
                            {can_edit && (
                                <Button asChild variant="secondary">
                                    <Link href={`/sites/${site.id}/edit`}>Edit</Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Setup completeness</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-between gap-4">
                                <div className="text-sm text-slate-300">
                                    {checklist.filter((c) => c.done).length} / {checklist.length} items complete
                                </div>
                                <div className="w-48">
                                    <div className="h-2 w-full rounded-full bg-slate-800">
                                        <div className="h-2 rounded-full bg-indigo-500" style={{ width: `${percent}%` }} />
                                    </div>
                                    <div className="mt-1 text-right text-xs text-slate-400">{percent}%</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Tabs
                        value={tab}
                        onValueChange={(v) => setTab(v as TabKey)}
                        tabs={tabs}
                    />

                    {tab === 'overview' && (
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Contacts</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-sm">
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="text-slate-400">Phone</div>
                                        <div className="col-span-2">{site.phone || '—'}</div>

                                        <div className="text-slate-400">Email</div>
                                        <div className="col-span-2">{site.email || '—'}</div>

                                        <div className="text-slate-400">Manager</div>
                                        <div className="col-span-2">{site.manager_name || '—'}</div>

                                        <div className="text-slate-400">Manager phone</div>
                                        <div className="col-span-2">{site.manager_phone || '—'}</div>

                                        <div className="text-slate-400">After hours</div>
                                        <div className="col-span-2">{site.after_hours_phone || '—'}</div>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Safety & medication storage</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div>
                                        <div className="text-slate-400">Emergency plan location</div>
                                        <div className="mt-1">{site.emergency_plan_location || '—'}</div>
                                    </div>
                                    <div>
                                        <div className="text-slate-400">Medication storage location</div>
                                        <div className="mt-1">{site.medication_storage_location || '—'}</div>
                                    </div>
                                    <div>
                                        <div className="text-slate-400">Notes</div>
                                        <div className="mt-1 whitespace-pre-wrap">{site.notes || '—'}</div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    )}

                    {tab === 'clients' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Clients at this site</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {clients.length === 0 ? (
                                    <div className="text-sm text-slate-400">No clients linked to this site yet.</div>
                                ) : (
                                    <div className="overflow-hidden rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-slate-50/5">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">Client</th>
                                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                                    <th className="px-4 py-3" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {clients.map((c) => (
                                                    <tr key={c.id} className="border-b last:border-b-0">
                                                        <td className="px-4 py-3 font-medium">{`${c.first_name} ${c.last_name}`.trim()}</td>
                                                        <td className="px-4 py-3 text-slate-300">{c.status}</td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link href={`/clients/${c.id}`} className="text-indigo-300 hover:text-indigo-200">
                                                                View
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {tab === 'assets' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Assets at this site</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {assets.length === 0 ? (
                                    <div className="text-sm text-slate-400">No assets linked to this site yet.</div>
                                ) : (
                                    <div className="overflow-hidden rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-slate-50/5">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">Asset</th>
                                                    <th className="px-4 py-3 text-left font-medium">Owner</th>
                                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                                    <th className="px-4 py-3 text-left font-medium">Risk</th>
                                                    <th className="px-4 py-3" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {assets.map((a) => (
                                                    <tr key={a.id} className="border-b last:border-b-0">
                                                        <td className="px-4 py-3">
                                                            <div className="font-medium">{a.name}</div>
                                                            <div className="text-xs text-slate-400">
                                                                {[a.asset_tag, a.category, a.location].filter(Boolean).join(' • ') || '—'}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-300">
                                                            <span
                                                                className={`inline-flex rounded-full border px-2 py-0.5 text-xs ${
                                                                    a.owner.type === 'client'
                                                                        ? 'border-indigo-500/30 text-indigo-200'
                                                                        : 'border-slate-500/30 text-slate-300'
                                                                }`}
                                                            >
                                                                {a.owner.type === 'client' ? `Client: ${a.owner.label}` : 'Site-owned'}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-300">{a.status}</td>
                                                        <td className="px-4 py-3 text-slate-300">{a.risk_level}</td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link href={`/assets/${a.id}`} className="text-indigo-300 hover:text-indigo-200">
                                                                View
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                {can?.createAsset && (
                                    <div className="mt-3">
                                        <Button asChild variant="secondary">
                                            <Link href={`/assets/create?site_id=${site.id}`}>Add asset</Link>
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {tab === 'contacts' && (
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Site contacts</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {contacts.length === 0 ? (
                                        <div className="text-sm text-slate-400">No contacts yet.</div>
                                    ) : (
                                        <div className="space-y-2">
                                            {contacts.map((c) => (
                                                <div key={c.id} className="rounded-xl border p-3 text-sm">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div>
                                                            <div className="font-medium">
                                                                {c.name}{' '}
                                                                {c.is_primary && (
                                                                    <span className="ml-2 rounded-full border border-emerald-500/30 px-2 py-0.5 text-xs text-emerald-300">
                                                                        Primary
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <div className="text-slate-400">{[c.type, c.role].filter(Boolean).join(' • ') || '—'}</div>
                                                        </div>
                                                        {can_edit && (
                                                            <div className="flex items-center gap-2">
                                                                <Button variant="secondary" size="sm" onClick={() => startEditContact(c)}>
                                                                    Edit
                                                                </Button>
                                                                <Button
                                                                    variant="destructive"
                                                                    size="sm"
                                                                    onClick={() => {
                                                                        if (!confirm('Remove this contact?')) return;
                                                                        contactForm.delete(`/sites/${site.id}/contacts/${c.id}`, {
                                                                            preserveScroll: true,
                                                                            onSuccess: () => {
                                                                                if (editingContactId === c.id) resetContactForm();
                                                                            },
                                                                        });
                                                                    }}
                                                                >
                                                                    Remove
                                                                </Button>
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="mt-2 grid gap-1 text-slate-300">
                                                        <div>{c.phone || '—'}</div>
                                                        <div>{c.email || '—'}</div>
                                                        {c.notes && <div className="mt-1 whitespace-pre-wrap text-slate-400">{c.notes}</div>}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>{editingContactId ? 'Edit contact' : 'Add contact'}</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {!can_edit ? (
                                        <div className="text-sm text-slate-400">You don’t have access to manage contacts.</div>
                                    ) : (
                                        <form
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                const url = editingContactId
                                                    ? `/sites/${site.id}/contacts/${editingContactId}`
                                                    : `/sites/${site.id}/contacts`;
                                                const method = editingContactId ? contactForm.put : contactForm.post;
                                                method(url, {
                                                    preserveScroll: true,
                                                    onSuccess: () => resetContactForm(),
                                                });
                                            }}
                                            className="space-y-3"
                                        >
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <Label>Type</Label>
                                                    <Input value={contactForm.data.type} onChange={(e) => contactForm.setData('type', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Role</Label>
                                                    <Input value={contactForm.data.role} onChange={(e) => contactForm.setData('role', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Name</Label>
                                                    <Input value={contactForm.data.name} onChange={(e) => contactForm.setData('name', e.target.value)} />
                                                    {contactForm.errors.name && <div className="mt-1 text-xs text-red-400">{contactForm.errors.name}</div>}
                                                </div>
                                                <div>
                                                    <Label>Phone</Label>
                                                    <Input value={contactForm.data.phone} onChange={(e) => contactForm.setData('phone', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Email</Label>
                                                    <Input value={contactForm.data.email} onChange={(e) => contactForm.setData('email', e.target.value)} />
                                                </div>
                                                <div className="flex items-end gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={contactForm.data.is_primary}
                                                        onChange={(e) => contactForm.setData('is_primary', e.target.checked)}
                                                    />
                                                    <span className="text-sm">Primary</span>
                                                </div>
                                            </div>
                                            <div>
                                                <Label>Notes</Label>
                                                <Textarea value={contactForm.data.notes} onChange={(e) => contactForm.setData('notes', e.target.value)} />
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Button type="submit" disabled={contactForm.processing}>
                                                    {contactForm.processing ? 'Saving…' : editingContactId ? 'Save changes' : 'Add contact'}
                                                </Button>
                                                {editingContactId && (
                                                    <Button type="button" variant="secondary" onClick={() => resetContactForm()}>
                                                        Cancel
                                                    </Button>
                                                )}
                                            </div>
                                        </form>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    )}

                    {tab === 'documents' && (
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Site documents</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {documents.length === 0 ? (
                                        <div className="text-sm text-slate-400">No documents uploaded yet.</div>
                                    ) : (
                                        <div className="overflow-hidden rounded-xl border">
                                            <table className="w-full text-sm">
                                                <thead className="border-b bg-slate-50/5">
                                                    <tr>
                                                        <th className="px-4 py-3 text-left font-medium">Title</th>
                                                        <th className="px-4 py-3 text-left font-medium">Category</th>
                                                        <th className="px-4 py-3 text-left font-medium">Size</th>
                                                        <th className="px-4 py-3" />
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {documents.map((d) => (
                                                        <tr key={d.id} className="border-b last:border-b-0">
                                                            <td className="px-4 py-3 font-medium">{d.title || d.original_name}</td>
                                                            <td className="px-4 py-3 text-slate-300">{d.category || '—'}</td>
                                                            <td className="px-4 py-3 text-slate-300">{bytes(d.size_bytes)}</td>
                                                            <td className="px-4 py-3 text-right">
                                                                <Link
                                                                    href={`/sites/${site.id}/documents/${d.id}/download`}
                                                                    className="text-indigo-300 hover:text-indigo-200"
                                                                >
                                                                    Download
                                                                </Link>
                                                                {can_edit && (
                                                                    <Button
                                                                        variant="destructive"
                                                                        size="sm"
                                                                        className="ml-2"
                                                                        onClick={() => {
                                                                            if (!confirm('Delete this document?')) return;
                                                                            docForm.delete(`/sites/${site.id}/documents/${d.id}`, { preserveScroll: true });
                                                                        }}
                                                                    >
                                                                        Delete
                                                                    </Button>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Upload document</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {!can_edit ? (
                                        <div className="text-sm text-slate-400">You don’t have access to upload documents.</div>
                                    ) : (
                                        <form
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                docForm.post(`/sites/${site.id}/documents`, {
                                                    forceFormData: true,
                                                    preserveScroll: true,
                                                    onSuccess: () => docForm.reset(),
                                                });
                                            }}
                                            className="space-y-3"
                                        >
                                            <div>
                                                <Label>File</Label>
                                                <Input type="file" onChange={(e) => docForm.setData('file', e.target.files?.[0] || null)} />
                                                {docForm.errors.file && <div className="mt-1 text-xs text-red-400">{docForm.errors.file}</div>}
                                            </div>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <div>
                                                    <Label>Title</Label>
                                                    <Input value={docForm.data.title} onChange={(e) => docForm.setData('title', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Category</Label>
                                                    <Input value={docForm.data.category} onChange={(e) => docForm.setData('category', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Version</Label>
                                                    <Input value={docForm.data.version} onChange={(e) => docForm.setData('version', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Effective date</Label>
                                                    <Input type="date" value={docForm.data.effective_date} onChange={(e) => docForm.setData('effective_date', e.target.value)} />
                                                </div>
                                                <div>
                                                    <Label>Expiry date</Label>
                                                    <Input type="date" value={docForm.data.expiry_date} onChange={(e) => docForm.setData('expiry_date', e.target.value)} />
                                                </div>
                                            </div>
                                            <div>
                                                <Label>Notes</Label>
                                                <Textarea value={docForm.data.notes} onChange={(e) => docForm.setData('notes', e.target.value)} />
                                            </div>
                                            <Button type="submit" disabled={docForm.processing}>
                                                {docForm.processing ? 'Uploading…' : 'Upload'}
                                            </Button>
                                        </form>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    )}

                    {tab === 'compliance' && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Compliance checklist</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <div className="text-sm text-slate-400">
                                    This is a quick “are we ready” check for audits and onboarding. It doesn’t replace your full policy/quality system.
                                </div>
                                <div className="space-y-2">
                                    {checklist.map((c) => (
                                        <div key={c.key} className="flex items-center justify-between rounded-xl border p-3 text-sm">
                                            <div className="font-medium">{c.label}</div>
                                            <div
                                                className={`rounded-full border px-2 py-0.5 text-xs ${
                                                    c.done ? 'border-emerald-500/30 text-emerald-300' : 'border-slate-500/30 text-slate-300'
                                                }`}
                                            >
                                                {c.done ? 'Done' : 'Missing'}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div className="pt-2 text-sm">
                                    Tip: upload your evacuation plan, fire register, H&S hazards register, and medication storage SOPs under “Documents”.
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
