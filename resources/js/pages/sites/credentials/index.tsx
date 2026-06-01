import { PageHero } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Eye,
    History,
    KeyRound,
    Lock,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    Trash2,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import { type CredentialPickerOption, credentialTypeLabel, formatDate } from '../_dialog-shared';
import {
    AddCredentialDialog,
    type CredentialVendorOption,
    DeleteCredentialDialog,
    EditCredentialDialog,
    RemoveTotpDialog,
    ShowCredentialDialog,
    type CredentialRecord,
} from './_dialogs';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Props = {
    site: Site;
    credentials: CredentialRecord[];
    vendors?: CredentialVendorOption[];
    credentialTypeOptions?: CredentialPickerOption[];
    canReveal: boolean;
    canManage: boolean;
};

type CredentialDialogMode =
    | 'add'
    | 'edit'
    | 'show'
    | 'delete'
    | 'remove-totp'
    | null;

export default function SiteCredentials({
    site,
    credentials,
    vendors = [],
    credentialTypeOptions = [],
    canReveal,
    canManage,
}: Props) {
    const [search, setSearch] = useState('');
    const [credentialDialog, setCredentialDialog] = useState<{
        mode: CredentialDialogMode;
        target: CredentialRecord | null;
    }>({ mode: null, target: null });

    const filteredCredentials = credentials.filter((credential) => {
        const query = search.toLowerCase();

        return (
            credential.label.toLowerCase().includes(query) ||
            credential.credential_type.toLowerCase().includes(query) ||
            (credential.username ?? '').toLowerCase().includes(query) ||
            (credential.vendor_name ?? '').toLowerCase().includes(query)
        );
    });

    const reauthCount = credentials.filter((credential) => credential.requires_reauth).length;
    const totpCount = credentials.filter((credential) => credential.has_totp).length;

    const closeCredentialDialog = () =>
        setCredentialDialog({ mode: null, target: null });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Credentials', href: `/sites/${site.id}/credentials` },
            ]}
        >
            <Head title={`${site.name} - Credentials`} />

            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    title="Credentials Vault"
                    description={site.name}
                    icon={<Lock className="h-7 w-7 text-white" />}
                    backHref={`/sites/${site.id}`}
                    backLabel={`Back to ${site.name}`}
                    stats={[
                        { label: 'Total', value: credentials.length },
                        { label: 'Re-auth', value: reauthCount },
                        { label: 'Authenticator', value: totpCount },
                    ]}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild size="sm" variant="outline">
                                <Link href={`/sites/${site.id}/vendors`}>
                                    <Truck className="mr-1.5 h-4 w-4" />
                                    Vendors
                                </Link>
                            </Button>
                            {canManage && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setCredentialDialog({
                                            mode: 'add',
                                            target: null,
                                        })
                                    }
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add Credential
                                </Button>
                            )}
                        </div>
                    }
                />

                {credentials.length > 0 && (
                    <div className="relative max-w-md">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search by label, username, vendor, or type..."
                            className="pl-9"
                        />
                    </div>
                )}

                {credentials.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <Lock className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p className="mb-1 text-lg font-medium">
                                No credentials stored
                            </p>
                            <p className="text-sm">
                                Add credentials to securely store access codes,
                                passwords, and keys for this site.
                            </p>
                            {canManage && (
                                <Button
                                    onClick={() =>
                                        setCredentialDialog({
                                            mode: 'add',
                                            target: null,
                                        })
                                    }
                                    className="mt-4"
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Add Your First Credential
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : filteredCredentials.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            <Search className="mx-auto mb-3 h-10 w-10 opacity-50" />
                            <p>No credentials match &quot;{search}&quot;</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-2">
                        {filteredCredentials.map((credential) => (
                            <Card key={credential.id}>
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">
                                                    {credential.label}
                                                </span>
                                                <Badge variant="outline">
                                                    {credentialTypeLabel(credential.credential_type)}
                                                </Badge>
                                                {credential.has_totp && (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-status-success/30 text-status-success"
                                                    >
                                                        <KeyRound className="mr-1 h-3 w-3" />
                                                        OTP
                                                    </Badge>
                                                )}
                                                {credential.requires_reauth && (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-status-warning/30 text-status-warning"
                                                    >
                                                        <ShieldCheck className="mr-1 h-3 w-3" />
                                                        Re-auth
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-1 text-sm text-muted-foreground">
                                                {credential.username
                                                    ? `${credential.username} · `
                                                    : ''}
                                                {credential.vendor_name ??
                                                    'No vendor linked'}
                                            </div>
                                            {credential.last_rotated_at && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    Last updated:{' '}
                                                    {formatDate(
                                                        credential.last_rotated_at,
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label="Show credential"
                                                onClick={() =>
                                                    setCredentialDialog({
                                                        mode: 'show',
                                                        target: credential,
                                                    })
                                                }
                                            >
                                                <Eye className="h-4 w-4" />
                                            </Button>
                                            {canManage && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label="Edit credential"
                                                    onClick={() =>
                                                        setCredentialDialog({
                                                            mode: 'edit',
                                                            target: credential,
                                                        })
                                                    }
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link
                                                    href={`/sites/${site.id}/credentials/${credential.id}/audit`}
                                                    aria-label="View audit history"
                                                >
                                                    <History className="h-4 w-4" />
                                                </Link>
                                            </Button>
                                            {canManage && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label="Delete credential"
                                                    className="text-status-critical hover:text-status-critical"
                                                    onClick={() =>
                                                        setCredentialDialog({
                                                            mode: 'delete',
                                                            target: credential,
                                                        })
                                                    }
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {credentialDialog.mode === 'add' && canManage && (
                    <AddCredentialDialog
                        siteId={site.id}
                        lockedSite={{ id: site.id, name: site.name, type: site.type }}
                        vendors={vendors}
                        typeOptions={credentialTypeOptions}
                        isOpen
                        onClose={closeCredentialDialog}
                    />
                )}
                {credentialDialog.mode === 'edit' && canManage && (
                    <EditCredentialDialog
                        siteId={site.id}
                        credential={credentialDialog.target}
                        lockedSite={{ id: site.id, name: site.name, type: site.type }}
                        vendors={vendors}
                        typeOptions={credentialTypeOptions}
                        isOpen
                        onClose={closeCredentialDialog}
                    />
                )}
                {credentialDialog.mode === 'show' && (
                    <ShowCredentialDialog
                        siteId={site.id}
                        credential={credentialDialog.target}
                        isOpen
                        canManage={canManage}
                        canReveal={canReveal}
                        onClose={closeCredentialDialog}
                        onEdit={() =>
                            setCredentialDialog((previous) => ({
                                ...previous,
                                mode: 'edit',
                            }))
                        }
                        onDelete={() =>
                            setCredentialDialog((previous) => ({
                                ...previous,
                                mode: 'delete',
                            }))
                        }
                        onRemoveTotp={() =>
                            setCredentialDialog((previous) => ({
                                ...previous,
                                mode: 'remove-totp',
                            }))
                        }
                        onHistory={() => {
                            const id = credentialDialog.target?.id;
                            closeCredentialDialog();
                            if (id) {
                                router.visit(`/sites/${site.id}/credentials/${id}/audit`);
                            }
                        }}
                    />
                )}
                {credentialDialog.mode === 'delete' && canManage && (
                    <DeleteCredentialDialog
                        siteId={site.id}
                        credential={credentialDialog.target}
                        isOpen
                        onClose={closeCredentialDialog}
                    />
                )}
                {credentialDialog.mode === 'remove-totp' && canManage && (
                    <RemoveTotpDialog
                        siteId={site.id}
                        credential={credentialDialog.target}
                        isOpen
                        onClose={closeCredentialDialog}
                    />
                )}
            </div>
        </AppLayout>
    );
}
