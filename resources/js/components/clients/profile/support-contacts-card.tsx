import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';

export type SupportContact = {
    id: number;
    version: number;
    review_current: boolean;
    purpose: string | null;
    released_at: string;
    review_url: string | null;
    details: {
        name: string;
        role: string;
        relationship: string;
        phone?: string | null;
        email?: string | null;
        channel?: string;
        safe_time?: string | null;
        may_contact?: boolean;
        leave_message?: boolean;
        communication?: string | null;
        authority_note?: string | null;
        review_due_at?: string | null;
    };
};

export function ProfileSupportContactsCard({
    contacts,
}: {
    contacts: SupportContact[];
}) {
    if (!contacts.length) return null;
    return (
        <Card className="mb-4">
            <CardHeader>
                <CardTitle>People involved in care</CardTitle>
                <p className="text-subtle">
                    Reviewed support contacts. Emergency contacts and portal
                    access are managed separately.
                </p>
            </CardHeader>
            <CardContent
                className={
                    contacts.length > 1
                        ? 'grid gap-4 lg:grid-cols-2'
                        : 'grid gap-4'
                }
            >
                {contacts.map((contact) => (
                    <section
                        key={contact.id}
                        className="min-w-0 space-y-3 rounded-lg border p-4"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 className="font-medium break-words">
                                    {contact.details.name}
                                </h3>
                                <p className="text-subtle">
                                    <span className="capitalize">
                                        {contact.details.role}
                                    </span>{' '}
                                    · {contact.details.relationship}
                                </p>
                            </div>
                            <StatusBadge
                                variant={
                                    contact.review_current
                                        ? contact.details.may_contact
                                            ? 'success'
                                            : 'neutral'
                                        : 'warning'
                                }
                            >
                                {contact.review_current
                                    ? contact.details.may_contact
                                        ? 'Contact permitted'
                                        : 'No direct contact'
                                    : 'Review needed'}
                            </StatusBadge>
                        </div>
                        {contact.review_current && contact.purpose && (
                            <p className="break-words">{contact.purpose}</p>
                        )}
                        {!contact.review_current ? (
                            <p className="text-subtle">
                                These instructions need a fresh review. Contact
                                details are withheld; ask the referral team to
                                update the care handover before using them.
                            </p>
                        ) : (
                            <dl
                                className={
                                    contacts.length === 1
                                        ? 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3'
                                        : 'grid gap-3 sm:grid-cols-2'
                                }
                            >
                                {[
                                    [
                                        'Phone',
                                        contact.details.phone || 'Not recorded',
                                    ],
                                    [
                                        'Email',
                                        contact.details.email || 'Not recorded',
                                    ],
                                    [
                                        'Preferred channel',
                                        contact.details.channel === 'none'
                                            ? 'No direct contact'
                                            : contact.details.channel ===
                                                'phone'
                                              ? 'Phone'
                                              : 'Email',
                                    ],
                                    [
                                        'Safe contact',
                                        contact.details.safe_time ||
                                            'Check before contacting',
                                    ],
                                    [
                                        'Messages',
                                        contact.details.may_contact &&
                                        contact.details.leave_message
                                            ? 'Phone message permitted'
                                            : 'Do not leave messages',
                                    ],
                                    [
                                        'Communication support',
                                        contact.details.communication ||
                                            'Not recorded',
                                    ],
                                    [
                                        'Role and limits',
                                        contact.details.authority_note ||
                                            'No decision-making powers granted',
                                    ],
                                    [
                                        'Review due',
                                        contact.details.review_due_at
                                            ? formatDateTimeLong(
                                                  contact.details.review_due_at,
                                              )
                                            : 'Not recorded',
                                    ],
                                ].map(([name, value]) => (
                                    <div key={name} className="min-w-0">
                                        <dt className="text-subtle">{name}</dt>
                                        <dd className="break-words">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {contact.review_url && (
                            <Button
                                variant="outline"
                                asChild
                                className="min-h-[44px] sm:min-h-0"
                            >
                                <Link href={contact.review_url}>
                                    Review referral contact
                                </Link>
                            </Button>
                        )}
                    </section>
                ))}
            </CardContent>
        </Card>
    );
}
