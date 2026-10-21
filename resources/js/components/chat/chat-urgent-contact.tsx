import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { AlertTriangle, Phone } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChatError, chatRequest } from './api';
import { watchChatUpdates } from './chat-update-watch';

export type UrgentContactSetting = {
    label: string;
    phone: string;
    instructions: string;
    confirmed: boolean;
};
type Contact = Omit<UrgentContactSetting, 'confirmed'> & { phone_uri: string };

function validContact(value: unknown): value is Contact {
    if (!value || typeof value !== 'object') return false;
    const contact = value as Contact;
    return (
        typeof contact.label === 'string' &&
        contact.label.trim().length > 0 &&
        contact.label.length <= 100 &&
        typeof contact.instructions === 'string' &&
        contact.instructions.length <= 500 &&
        typeof contact.phone === 'string' &&
        /^\+?[0-9][0-9 ()-]*[0-9]$/.test(contact.phone) &&
        typeof contact.phone_uri === 'string' &&
        /^tel:\+?[0-9]{6,15}$/.test(contact.phone_uri) &&
        contact.phone_uri ===
            `tel:${contact.phone.startsWith('+') ? '+' : ''}${contact.phone.replace(/[^0-9]/g, '')}`
    );
}

export function ChatUrgentHelp({
    actorId,
    clientId,
}: {
    actorId: number;
    clientId: number;
}) {
    return (
        <UrgentHelpSession
            key={`${actorId}:${clientId}`}
            actorId={actorId}
            clientId={clientId}
        />
    );
}

function UrgentHelpSession({
    actorId,
    clientId,
}: {
    actorId: number;
    clientId: number;
}) {
    const [open, setOpen] = useState(false);
    const [contact, setContact] = useState<Contact | null>(null);
    const [checked, setChecked] = useState(false);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setContact(null);
        setChecked(false);
        setError('');
        const offline = () => {
            setContact(null);
            setChecked(false);
            setError(
                'The service contact could not be checked. Check your connection and try again.',
            );
        };
        window.addEventListener('offline', offline);
        if (!navigator.onLine) offline();
        const stop = watchChatUpdates(
            async () => {
                try {
                    const result = await chatRequest<{
                        actor_id: number;
                        client_id: number;
                        contact: unknown;
                    }>(`/chat/api/clients/${clientId}/urgent-contact`, {
                        headers: { 'X-Chat-Actor': String(actorId) },
                        signal: AbortSignal.any([
                            controller.signal,
                            AbortSignal.timeout(10000),
                        ]),
                    });
                    if (controller.signal.aborted || !navigator.onLine)
                        return false;
                    if (
                        result.actor_id !== actorId ||
                        result.client_id !== clientId ||
                        (result.contact !== null &&
                            !validContact(result.contact))
                    )
                        throw new Error(
                            'The service contact could not be confirmed.',
                        );
                    setContact(result.contact);
                    setChecked(true);
                    setError('');
                    return true;
                } catch (failure) {
                    if (controller.signal.aborted) return false;
                    setContact(null);
                    setChecked(false);
                    setError(
                        failure instanceof ChatError &&
                            [401, 403, 404, 419].includes(failure.status)
                            ? 'This service contact is no longer available to your account.'
                            : 'The service contact could not be checked. Check your connection and try again.',
                    );
                    return false;
                }
            },
            { interval: () => 10000, whenHidden: true },
        );
        return () => {
            controller.abort();
            stop();
            window.removeEventListener('offline', offline);
        };
    }, [open, actorId, clientId, retry]);
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setContact(null);
                setChecked(false);
                setError('');
                setOpen(next);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-[44px] whitespace-normal"
                >
                    Need urgent help?
                </Button>
            </DialogTrigger>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                className="max-h-[85dvh] overflow-x-hidden overflow-y-auto"
            >
                <DialogHeader className="min-w-0 pr-6">
                    <DialogTitle>Need urgent help?</DialogTitle>
                    <DialogDescription>
                        Use the service contact for urgent help. Do not wait for
                        a Chat reply.
                    </DialogDescription>
                </DialogHeader>
                <p className="flex min-w-0 gap-2 rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning">
                    <AlertTriangle className="size-5 shrink-0" />
                    For immediate danger, use your local emergency service.
                </p>
                <section
                    className="min-w-0 space-y-3 rounded-lg border p-4"
                    aria-label="Service urgent contact"
                >
                    <h3 className="font-semibold">Service urgent contact</h3>
                    {error ? (
                        <div role="alert" className="space-y-2 text-sm">
                            <p>{error}</p>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-[44px]"
                                onClick={() => setRetry((v) => v + 1)}
                            >
                                Try again
                            </Button>
                        </div>
                    ) : !checked ? (
                        <p role="status" className="text-sm">
                            Checking service contact…
                        </p>
                    ) : contact ? (
                        <>
                            <p className="font-medium break-words">
                                {contact.label}
                            </p>
                            {contact.instructions && (
                                <p className="text-sm break-words whitespace-pre-wrap text-muted-foreground">
                                    {contact.instructions}
                                </p>
                            )}
                            <Button
                                asChild
                                className="min-h-[44px] w-full whitespace-normal"
                            >
                                <a href={contact.phone_uri}>
                                    <Phone className="size-4 shrink-0" />
                                    {contact.phone}
                                </a>
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                Opens your phone app. This is separate from an
                                Oblivion voice call.
                            </p>
                        </>
                    ) : (
                        <>
                            <p className="text-sm text-muted-foreground">
                                The service has not configured an approved
                                urgent contact here.
                            </p>
                            <Button
                                disabled
                                variant="outline"
                                className="min-h-[44px] w-full whitespace-normal"
                            >
                                Urgent contact not configured
                            </Button>
                        </>
                    )}
                </section>
            </DialogContent>
        </Dialog>
    );
}
