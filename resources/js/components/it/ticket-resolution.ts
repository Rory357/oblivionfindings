import { CheckCircle2, ClipboardCheck, LifeBuoy, Wrench } from 'lucide-react';

export const TICKET_RESOLUTION_OUTCOMES = [
    {
        key: 'restored',
        label: 'Service restored',
        description: 'The reported fault is fixed.',
        icon: CheckCircle2,
    },
    {
        key: 'workaround',
        label: 'Workaround provided',
        description: 'Work can continue using an explained alternative.',
        icon: Wrench,
    },
    {
        key: 'fulfilled',
        label: 'Request fulfilled',
        description: 'The requested service or access is provided.',
        icon: ClipboardCheck,
    },
    {
        key: 'guidance',
        label: 'Guidance provided',
        description: 'The requester has the instructions they need.',
        icon: LifeBuoy,
    },
];

export interface TicketResolution {
    code: string;
    summary: string | null;
    verification: string | null;
}

export function ticketResolutionLabel(code: string): string {
    return (
        TICKET_RESOLUTION_OUTCOMES.find((outcome) => outcome.key === code)
            ?.label ?? code.replaceAll('_', ' ')
    );
}
