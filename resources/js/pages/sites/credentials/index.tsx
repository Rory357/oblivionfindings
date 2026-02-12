import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Lock, Eye, EyeOff, Copy, Plus, X, History, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Credential = {
    id: number;
    label: string;
    credential_type: string;
    vendor?: { id: number; company_name: string } | null;
    notes?: string;
    last_rotated_at?: string;
    requires_reauth: boolean;
    value_preview: string;
};

type Props = {
    site: Site;
    credentials: Credential[];
    canReveal: boolean;
    canManage: boolean;
};

export default function SiteCredentials({ site, credentials, canReveal, canManage }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [editingCredential, setEditingCredential] = useState<Credential | null>(null);
    const [revealedValue, setRevealedValue] = useState<string | null>(null);
    const [revealDialogOpen, setRevealDialogOpen] = useState(false);
    const [selectedCredential, setSelectedCredential] = useState<Credential | null>(null);
    const [password, setPassword] = useState('');
    const [revealing, setRevealing] = useState(false);
    const [search, setSearch] = useState('');

    const filteredCredentials = credentials.filter(
        (c) =>
            c.label.toLowerCase().includes(search.toLowerCase()) ||
            c.credential_type.toLowerCase().includes(search.toLowerCase()),
    );

    const form = useForm({
        label: '',
        credential_type: 'password',
        value: '',
        vendor_id: '',
        notes: '',
        requires_reauth: false,
    });

    const startEdit = (cred: Credential) => {
        setEditingCredential(cred);
        form.setData({
            label: cred.label,
            credential_type: cred.credential_type,
            value: '', // Don't show existing value
            vendor_id: cred.vendor?.id?.toString() || '',
            notes: cred.notes || '',
            requires_reauth: cred.requires_reauth,
        });
        setShowForm(true);
    };

    const resetForm = () => {
        setEditingCredential(null);
        setShowForm(false);
        form.reset();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingCredential) {
            form.put(`/sites/${site.id}/credentials/${editingCredential.id}`, {
                onSuccess: resetForm,
            });
        } else {
            form.post(`/sites/${site.id}/credentials`, {
                onSuccess: resetForm,
            });
        }
    };

    const requestReveal = (cred: Credential) => {
        if (cred.requires_reauth) {
            setSelectedCredential(cred);
            setRevealDialogOpen(true);
        } else {
            performReveal(cred.id);
        }
    };

    const performReveal = async (credentialId: number) => {
        setRevealing(true);
        try {
            const response = await fetch(`/sites/${site.id}/credentials/${credentialId}/reveal`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ password }),
            });
            const data = await response.json();
            if (data.value) {
                setRevealedValue(data.value);
                setRevealDialogOpen(false);
                setPassword('');
            }
        } catch (error) {
            console.error('Failed to reveal credential:', error);
        } finally {
            setRevealing(false);
        }
    };

    const copyToClipboard = async (value: string, credentialId: number) => {
        await navigator.clipboard.writeText(value);

        await fetch(`/sites/${site.id}/credentials/${credentialId}/copy`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Credentials', href: `/sites/${site.id}/credentials` }]}>
            <Head title={`${site.name} - Credentials`} />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <Lock className="w-5 h-5" />
                            Credentials Vault
                            <Badge variant="outline" className="ml-1 text-xs">
                                {credentials.length} credential{credentials.length !== 1 ? 's' : ''}
                            </Badge>
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button asChild variant="secondary">
                            <Link href={`/sites/${site.id}/vendors`}>Vendors</Link>
                        </Button>
                        {canManage && (
                            <Button onClick={() => setShowForm(true)}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Credential
                            </Button>
                        )}
                    </div>
                </div>

                {/* Search Bar */}
                {credentials.length > 0 && (
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by label or type..."
                            className="pl-10"
                        />
                    </div>
                )}

                {/* Add/Edit Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>{editingCredential ? 'Edit Credential' : 'Add Credential'}</CardTitle>
                            <Button variant="ghost" size="sm" onClick={resetForm}>
                                <X className="w-4 h-4" />
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Label *</Label>
                                        <Input
                                            value={form.data.label}
                                            onChange={(e) => form.setData('label', e.target.value)}
                                            placeholder="e.g., WiFi Password, Alarm Code"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Type *</Label>
                                        <Input
                                            value={form.data.credential_type}
                                            onChange={(e) => form.setData('credential_type', e.target.value)}
                                            placeholder="e.g., password, pin, key"
                                            required
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Value {editingCredential && '(leave blank to keep current)'}</Label>
                                    <Input
                                        type="password"
                                        value={form.data.value}
                                        onChange={(e) => form.setData('value', e.target.value)}
                                        required={!editingCredential}
                                    />
                                </div>
                                <div>
                                    <Label>Notes</Label>
                                    <Textarea
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        rows={3}
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={form.data.requires_reauth}
                                        onChange={(e) => form.setData('requires_reauth', e.target.checked)}
                                    />
                                    <Label className="font-normal">Require re-authentication to reveal</Label>
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={form.processing}>
                                        {editingCredential ? 'Save Changes' : 'Add Credential'}
                                    </Button>
                                    <Button type="button" variant="outline" onClick={resetForm}>
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Reveal Dialog */}
                <Dialog open={revealDialogOpen} onOpenChange={setRevealDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Authentication Required</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            <p className="text-sm text-slate-400">
                                Please enter your password to reveal this credential.
                            </p>
                            <Input
                                type="password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                placeholder="Your password"
                            />
                            <div className="flex gap-2">
                                <Button 
                                    onClick={() => selectedCredential && performReveal(selectedCredential.id)}
                                    disabled={revealing || !password}
                                >
                                    {revealing ? 'Verifying...' : 'Reveal'}
                                </Button>
                                <Button variant="outline" onClick={() => setRevealDialogOpen(false)}>
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </DialogContent>
                </Dialog>

                {/* Credentials List */}
                {credentials.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-slate-400">
                            <Lock className="w-12 h-12 mx-auto mb-3 opacity-50" />
                            <p className="text-lg font-medium mb-1">No credentials stored</p>
                            <p className="text-sm">Add credentials to securely store access codes, passwords, and keys for this site.</p>
                            {canManage && (
                                <Button onClick={() => setShowForm(true)} className="mt-4">
                                    <Plus className="w-4 h-4 mr-1" />
                                    Add Your First Credential
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : filteredCredentials.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-slate-400">
                            <Search className="w-10 h-10 mx-auto mb-3 opacity-50" />
                            <p>No credentials match &quot;{search}&quot;</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-2">
                        {filteredCredentials.map((cred) => (
                            <Card key={cred.id}>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-medium">{cred.label}</span>
                                                <Badge variant="outline">{cred.credential_type}</Badge>
                                                {cred.requires_reauth && (
                                                    <Badge variant="outline" className="border-amber-500/30 text-amber-400">
                                                        <Lock className="w-3 h-3 mr-1" />
                                                        Protected
                                                    </Badge>
                                                )}
                                            </div>
                                            {cred.vendor && (
                                                <div className="text-sm text-slate-400">{cred.vendor.company_name}</div>
                                            )}
                                            {cred.last_rotated_at && (
                                                <div className="text-xs text-slate-500 mt-1">
                                                    Last updated: {new Date(cred.last_rotated_at).toLocaleDateString()}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2 shrink-0">
                                            {revealedValue && selectedCredential?.id === cred.id ? (
                                                <>
                                                    <code className="bg-muted px-2 py-1 rounded text-sm">
                                                        {revealedValue}
                                                    </code>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => copyToClipboard(revealedValue, cred.id)}
                                                    >
                                                        <Copy className="w-4 h-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => setRevealedValue(null)}
                                                    >
                                                        <EyeOff className="w-4 h-4" />
                                                    </Button>
                                                </>
                                            ) : (
                                                <>
                                                    <code className="bg-muted px-2 py-1 rounded text-sm text-slate-500">
                                                        {cred.value_preview}
                                                    </code>
                                                    {canReveal && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => {
                                                                setSelectedCredential(cred);
                                                                requestReveal(cred);
                                                            }}
                                                        >
                                                            <Eye className="w-4 h-4" />
                                                        </Button>
                                                    )}
                                                </>
                                            )}
                                            {canManage && (
                                                <>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => startEdit(cred)}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link href={`/sites/${site.id}/credentials/${cred.id}/audit`}>
                                                            <History className="w-4 h-4" />
                                                        </Link>
                                                    </Button>
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <Button variant="ghost" size="sm" className="text-red-400 hover:text-red-300">
                                                                <Trash2 className="w-4 h-4" />
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>Delete Credential</AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Permanently delete &quot;{cred.label}&quot;? This action cannot be undone and the credential value will be lost.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                <AlertDialogAction
                                                                    className="bg-red-600 hover:bg-red-700"
                                                                    onClick={() => router.delete(`/sites/${site.id}/credentials/${cred.id}`)}
                                                                >
                                                                    Delete
                                                                </AlertDialogAction>
                                                            </AlertDialogFooter>
                                                        </AlertDialogContent>
                                                    </AlertDialog>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
