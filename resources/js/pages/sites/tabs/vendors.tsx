import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Eye, KeyRound, Lock, Plus, Truck } from 'lucide-react';
import { useState } from 'react';
import type { CredentialPickerOption, SiteOption } from '../_dialog-shared';
import {
    AddCredentialDialog,
    type CredentialRecord,
    DeleteCredentialDialog,
    EditCredentialDialog,
    RemoveTotpDialog,
    ShowCredentialDialog,
} from '../credentials/_dialogs';
import { AuditLogDialog } from '../vendors-credentials/_audit-dialog';
import {
    AddVendorDialog,
    DeleteVendorDialog,
    EditVendorDialog,
    ShowVendorDialog,
    type VendorRecord,
} from '../vendors/_dialogs';
import { registerLabel } from './safety-register';

type VendorMode = 'add' | 'show' | 'edit' | 'delete' | null;
type CredentialMode = 'add' | 'show' | 'edit' | 'delete' | 'remove-totp' | null;

export type SiteVendorsCredentialsData = {
    locked?: boolean;
    site: SiteOption;
    vendors: VendorRecord[];
    credentials: CredentialRecord[];
    credentialTypeOptions: CredentialPickerOption[];
    can: {
        vendors: boolean;
        credentials: boolean;
        vendorsManage: boolean;
        credentialsManage: boolean;
        credentialsReveal: boolean;
    };
    href: string;
};

export function SiteProfileVendors({
    data,
}: {
    data: SiteVendorsCredentialsData;
}) {
    const [section, setSection] = useState<'vendors' | 'credentials'>(
        data.can.vendors ? 'vendors' : 'credentials',
    );
    const [vendorDialog, setVendorDialog] = useState<{
        mode: VendorMode;
        target: VendorRecord | null;
    }>({ mode: null, target: null });
    const [credentialDialog, setCredentialDialog] = useState<{
        mode: CredentialMode;
        target: CredentialRecord | null;
    }>({ mode: null, target: null });
    const [auditLabel, setAuditLabel] = useState<string | null>(null);

    return (
        <div className="space-y-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold">
                        Vendors &amp; Credentials
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Complete Site-scoped vendor directory and encrypted
                        credential metadata. Secret reveals remain separate,
                        re-authenticated and audited.
                    </p>
                </div>
                <Button
                    asChild
                    size="sm"
                    variant="outline"
                    className="min-h-11"
                >
                    <Link href={data.href}>
                        Open unified workspace
                        <ArrowUpRight className="ml-1.5 h-4 w-4" />
                    </Link>
                </Button>
            </div>

            <div className="inline-flex rounded-xl border bg-card p-1">
                {data.can.vendors ? (
                    <Button
                        size="sm"
                        className="min-h-11"
                        variant={section === 'vendors' ? 'secondary' : 'ghost'}
                        onClick={() => setSection('vendors')}
                    >
                        <Truck className="mr-1.5 h-4 w-4" /> Vendors{' '}
                        <Badge variant="outline" className="ml-2">
                            {data.vendors.length}
                        </Badge>
                    </Button>
                ) : null}
                {data.can.credentials ? (
                    <Button
                        size="sm"
                        className="min-h-11"
                        variant={
                            section === 'credentials' ? 'secondary' : 'ghost'
                        }
                        onClick={() => setSection('credentials')}
                    >
                        <Lock className="mr-1.5 h-4 w-4" /> Credentials{' '}
                        <Badge variant="outline" className="ml-2">
                            {data.credentials.length}
                        </Badge>
                    </Button>
                ) : null}
            </div>

            {section === 'vendors' && data.can.vendors ? (
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-base">
                            Site vendors
                        </CardTitle>
                        {data.can.vendorsManage ? (
                            <Button
                                size="sm"
                                className="min-h-11"
                                onClick={() =>
                                    setVendorDialog({
                                        mode: 'add',
                                        target: null,
                                    })
                                }
                            >
                                <Plus className="mr-1.5 h-4 w-4" /> Add vendor
                            </Button>
                        ) : null}
                    </CardHeader>
                    <CardContent>
                        <div className="divide-y rounded-xl border">
                            {data.vendors.map((vendor) => (
                                <button
                                    key={vendor.id}
                                    type="button"
                                    onClick={() =>
                                        setVendorDialog({
                                            mode: 'show',
                                            target: vendor,
                                        })
                                    }
                                    className="flex min-h-11 w-full items-start justify-between gap-3 p-4 text-left hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <div>
                                        <div className="font-medium">
                                            {vendor.company_name}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {registerLabel(vendor.service_type)}
                                            {vendor.contact_name
                                                ? ` · ${vendor.contact_name}`
                                                : ''}
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {vendor.is_preferred ? (
                                                <Badge variant="secondary">
                                                    Preferred
                                                </Badge>
                                            ) : null}
                                            <Badge variant="outline">
                                                {vendor.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </Badge>
                                            {vendor.insurance_verified ? (
                                                <Badge variant="outline">
                                                    Insurance verified
                                                </Badge>
                                            ) : null}
                                        </div>
                                    </div>
                                    <Eye className="mt-1 h-4 w-4 text-muted-foreground" />
                                </button>
                            ))}
                            {!data.vendors.length ? (
                                <p className="p-8 text-center text-sm text-muted-foreground">
                                    No vendors are recorded for this Site.
                                </p>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>
            ) : null}

            {section === 'credentials' && data.can.credentials ? (
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-base">
                            Credential vault
                        </CardTitle>
                        {data.can.credentialsManage ? (
                            <Button
                                size="sm"
                                className="min-h-11"
                                onClick={() =>
                                    setCredentialDialog({
                                        mode: 'add',
                                        target: null,
                                    })
                                }
                            >
                                <Plus className="mr-1.5 h-4 w-4" /> Add
                                credential
                            </Button>
                        ) : null}
                    </CardHeader>
                    <CardContent>
                        <div className="divide-y rounded-xl border">
                            {data.credentials.map((credential) => (
                                <button
                                    key={credential.id}
                                    type="button"
                                    onClick={() =>
                                        setCredentialDialog({
                                            mode: 'show',
                                            target: credential,
                                        })
                                    }
                                    className="flex min-h-11 w-full items-start justify-between gap-3 p-4 text-left hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2 font-medium">
                                            {credential.label}
                                            {credential.has_totp ? (
                                                <Badge variant="outline">
                                                    <KeyRound className="mr-1 h-3 w-3" />
                                                    OTP
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {registerLabel(
                                                credential.credential_type,
                                            )}
                                            {credential.vendor_name
                                                ? ` · ${credential.vendor_name}`
                                                : ''}
                                        </div>
                                    </div>
                                    <Badge variant="outline">
                                        {data.can.credentialsReveal
                                            ? 'Reveal securely'
                                            : 'View metadata'}
                                    </Badge>
                                </button>
                            ))}
                            {!data.credentials.length ? (
                                <p className="p-8 text-center text-sm text-muted-foreground">
                                    No credentials are recorded for this Site.
                                </p>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>
            ) : null}

            <AddVendorDialog
                isOpen={vendorDialog.mode === 'add'}
                siteId={data.site.id}
                lockedSite={data.site}
                onClose={() => setVendorDialog({ mode: null, target: null })}
            />
            {vendorDialog.target ? (
                <>
                    <ShowVendorDialog
                        isOpen={vendorDialog.mode === 'show'}
                        vendor={vendorDialog.target}
                        canManage={data.can.vendorsManage}
                        onClose={() =>
                            setVendorDialog({ mode: null, target: null })
                        }
                        onEdit={() =>
                            setVendorDialog((value) => ({
                                ...value,
                                mode: 'edit',
                            }))
                        }
                        onDelete={() =>
                            setVendorDialog((value) => ({
                                ...value,
                                mode: 'delete',
                            }))
                        }
                    />
                    <EditVendorDialog
                        isOpen={vendorDialog.mode === 'edit'}
                        siteId={data.site.id}
                        vendor={vendorDialog.target}
                        lockedSite={data.site}
                        onClose={() =>
                            setVendorDialog({ mode: null, target: null })
                        }
                    />
                    <DeleteVendorDialog
                        isOpen={vendorDialog.mode === 'delete'}
                        siteId={data.site.id}
                        vendor={vendorDialog.target}
                        onClose={() =>
                            setVendorDialog({ mode: null, target: null })
                        }
                    />
                </>
            ) : null}

            <AddCredentialDialog
                isOpen={credentialDialog.mode === 'add'}
                siteId={data.site.id}
                lockedSite={data.site}
                vendors={data.vendors}
                typeOptions={data.credentialTypeOptions}
                onClose={() =>
                    setCredentialDialog({ mode: null, target: null })
                }
            />
            {credentialDialog.target ? (
                <>
                    <ShowCredentialDialog
                        isOpen={credentialDialog.mode === 'show'}
                        siteId={data.site.id}
                        credential={credentialDialog.target}
                        canManage={data.can.credentialsManage}
                        canReveal={data.can.credentialsReveal}
                        onClose={() =>
                            setCredentialDialog({ mode: null, target: null })
                        }
                        onEdit={() =>
                            setCredentialDialog((value) => ({
                                ...value,
                                mode: 'edit',
                            }))
                        }
                        onDelete={() =>
                            setCredentialDialog((value) => ({
                                ...value,
                                mode: 'delete',
                            }))
                        }
                        onRemoveTotp={() =>
                            setCredentialDialog((value) => ({
                                ...value,
                                mode: 'remove-totp',
                            }))
                        }
                        onHistory={() => {
                            setAuditLabel(credentialDialog.target?.label ?? '');
                            setCredentialDialog({
                                mode: null,
                                target: null,
                            });
                        }}
                    />
                    <EditCredentialDialog
                        isOpen={credentialDialog.mode === 'edit'}
                        siteId={data.site.id}
                        credential={credentialDialog.target}
                        lockedSite={data.site}
                        vendors={data.vendors}
                        typeOptions={data.credentialTypeOptions}
                        onClose={() =>
                            setCredentialDialog({ mode: null, target: null })
                        }
                    />
                    <DeleteCredentialDialog
                        isOpen={credentialDialog.mode === 'delete'}
                        siteId={data.site.id}
                        credential={credentialDialog.target}
                        onClose={() =>
                            setCredentialDialog({ mode: null, target: null })
                        }
                    />
                    <RemoveTotpDialog
                        isOpen={credentialDialog.mode === 'remove-totp'}
                        siteId={data.site.id}
                        credential={credentialDialog.target}
                        onClose={() =>
                            setCredentialDialog({ mode: null, target: null })
                        }
                    />
                </>
            ) : null}
            <AuditLogDialog
                isOpen={auditLabel !== null}
                focusLabel={auditLabel ?? undefined}
                siteId={data.site.id}
                onClose={() => setAuditLabel(null)}
            />
        </div>
    );
}
