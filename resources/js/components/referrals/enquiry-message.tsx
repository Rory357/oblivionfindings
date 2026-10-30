import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent } from '@/components/ui/card';
import { MessageSquareText } from 'lucide-react';
import type { ReactNode } from 'react';
import type { ReferralDetail } from './types';

const TOPICS: Record<string, string> = {
    support: 'Asking about support',
    referral: 'About making a referral',
    careers: 'A careers question',
    other: 'Something else',
};
const REPLY_BY: Record<string, string> = {
    phone: 'Phone call',
    text: 'Text message',
    email: 'Email',
};

function Fact({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-caption text-muted-foreground">{title}</dt>
            <dd className="mt-0.5 text-sm font-medium break-words">
                {children}
            </dd>
        </div>
    );
}

/**
 * A general enquiry's summary: what it is (not a referral, and how it becomes one) and, for a
 * Contact us message, what the person wrote and how they asked us to reply.
 */
export function EnquiryMessage({ referral }: { referral: ReferralDetail }) {
    const enquiry = referral.enquiry;
    const contact = referral.contact;
    return (
        <>
            <Alert variant="info">
                <AlertTitle>
                    General enquiry
                    {referral.enquiry_reference
                        ? ` ${referral.enquiry_reference}`
                        : ''}
                </AlertTitle>
                <AlertDescription>
                    This isn’t counted as a referral or in reports. Reply, link
                    it to a referral or close it. If it’s about someone’s
                    support, review authority, then complete the intake to turn
                    it into a referral.
                </AlertDescription>
            </Alert>
            {enquiry?.message && (
                <Card>
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex items-start gap-3">
                            <MessageSquareText
                                className="mt-1 size-4 shrink-0 text-primary"
                                aria-hidden
                            />
                            <div className="min-w-0 flex-1">
                                <h2 className="text-section-title">
                                    Their message
                                </h2>
                                <p className="text-subtle">
                                    {[
                                        TOPICS[enquiry.topic ?? ''],
                                        'Sent from the website’s Contact us page',
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </div>
                        </div>
                        <p className="text-sm leading-relaxed break-words whitespace-pre-wrap">
                            {enquiry.message}
                        </p>
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <Fact title="Reply by">
                                {REPLY_BY[enquiry.reply_by ?? ''] ??
                                    'The contact details they gave'}
                            </Fact>
                            <Fact title="Contact">
                                {contact.email || contact.phone || 'Not given'}
                            </Fact>
                            <Fact title="Safe to leave a message">
                                {contact.leave_message
                                    ? 'Yes'
                                    : 'No, don’t leave a message'}
                            </Fact>
                        </dl>
                    </CardContent>
                </Card>
            )}
        </>
    );
}
