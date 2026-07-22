import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { router } from '@inertiajs/react';
import { Mail, Pencil, Phone, Plus, Trash2, UsersRound } from 'lucide-react';
import { useState } from 'react';
import {
    AddContactDialog,
    EditContactDialog,
    type ContactRecord,
} from '../contacts/_dialogs';
import { SiteProfileEmptyState } from './site-profile-states';

export type SiteContactsData = {
    items: ContactRecord[];
    can_manage: boolean;
};

export function SiteProfileContacts({
    siteId,
    data,
}: {
    siteId: number;
    data: SiteContactsData;
}) {
    const [addOpen, setAddOpen] = useState(false);
    const [editContact, setEditContact] = useState<ContactRecord | null>(null);
    const [deleteContact, setDeleteContact] = useState<ContactRecord | null>(
        null,
    );
    const refreshPeople = () =>
        router.reload({
            only: ['contactsData'],
            preserveState: true,
            preserveScroll: true,
        });

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">Contacts</h2>
                    <p className="text-sm text-muted-foreground">
                        Managers, emergency contacts, health professionals, and
                        other Site contacts.
                    </p>
                </div>
                {data.can_manage ? (
                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={() => setAddOpen(true)}
                    >
                        <Plus className="mr-2 h-4 w-4" />
                        Add contact
                    </Button>
                ) : null}
            </div>

            {data.items.length ? (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {data.items.map((contact) => (
                        <Card key={contact.id}>
                            <CardContent className="space-y-3 p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold">
                                            {contact.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {contact.role ||
                                                contact.type ||
                                                'Site contact'}
                                        </p>
                                    </div>
                                    {contact.is_primary ? (
                                        <Badge variant="outline">Primary</Badge>
                                    ) : null}
                                </div>
                                <div className="space-y-1.5 text-sm">
                                    {contact.phone ? (
                                        <a
                                            className="flex min-h-11 items-center gap-2 hover:underline"
                                            href={`tel:${contact.phone}`}
                                        >
                                            <Phone className="h-4 w-4 text-muted-foreground" />
                                            {contact.phone}
                                        </a>
                                    ) : null}
                                    {contact.email ? (
                                        <a
                                            className="flex min-h-11 items-center gap-2 truncate hover:underline"
                                            href={`mailto:${contact.email}`}
                                        >
                                            <Mail className="h-4 w-4 shrink-0 text-muted-foreground" />
                                            <span className="truncate">
                                                {contact.email}
                                            </span>
                                        </a>
                                    ) : null}
                                </div>
                                {data.can_manage ? (
                                    <div className="flex justify-end gap-2 border-t pt-3">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="min-h-11"
                                            onClick={() =>
                                                setEditContact(contact)
                                            }
                                        >
                                            <Pencil className="mr-2 h-4 w-4" />
                                            Edit
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="min-h-11 text-status-critical"
                                            onClick={() =>
                                                setDeleteContact(contact)
                                            }
                                        >
                                            <Trash2 className="mr-2 h-4 w-4" />
                                            Delete
                                        </Button>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : (
                <SiteProfileEmptyState
                    icon={UsersRound}
                    title="No Site contacts yet"
                    description="Add the people staff should contact for operational, emergency, and care matters."
                    action={
                        data.can_manage
                            ? {
                                  label: 'Add contact',
                                  onClick: () => setAddOpen(true),
                              }
                            : undefined
                    }
                />
            )}

            <AddContactDialog
                siteId={siteId}
                isOpen={addOpen}
                onClose={() => setAddOpen(false)}
                onSaved={refreshPeople}
            />
            <EditContactDialog
                siteId={siteId}
                contact={editContact}
                isOpen={editContact !== null}
                onClose={() => setEditContact(null)}
                onSaved={refreshPeople}
            />
            <ConfirmDialog
                open={deleteContact !== null}
                onClose={() => setDeleteContact(null)}
                onConfirm={() => {
                    if (!deleteContact) return;
                    router.delete(
                        `/sites/${siteId}/contacts/${deleteContact.id}`,
                        {
                            preserveScroll: true,
                            onSuccess: refreshPeople,
                        },
                    );
                }}
                title="Delete Site contact?"
                description={`${deleteContact?.name ?? 'This contact'} will be removed from the Site contact list. This cannot be undone.`}
                confirmText="Delete contact"
            />
        </div>
    );
}
