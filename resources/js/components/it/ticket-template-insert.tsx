import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import axios from 'axios';
import { useEffect, useState } from 'react';

type TemplateOption = {
    id: number;
    name: string;
    audience: 'public' | 'internal';
};

let templateCache: Promise<TemplateOption[]> | null = null;

function loadTemplates(): Promise<TemplateOption[]> {
    templateCache ??= axios
        .get('/it/reply-templates', { headers: { Accept: 'application/json' } })
        .then(
            (response) =>
                (response.data?.templates ?? []) as TemplateOption[],
            // Requesters and other non-agents simply have no menu.
            () => [],
        );

    return templateCache;
}

/** Test seam: forget the module-level template cache. */
export function clearTicketTemplateCache() {
    templateCache = null;
}

/**
 * Governed reply-template insertion. The server renders the template against
 * the ticket; an unresolved placeholder blocks insertion with an explanation
 * instead of pasting a raw token into the conversation.
 */
export function TicketTemplateInsert({
    ticketId,
    internal,
    disabled,
    onInsert,
}: {
    ticketId: number;
    internal: boolean;
    disabled?: boolean;
    onInsert: (body: string) => void;
}) {
    const [templates, setTemplates] = useState<TemplateOption[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        let mounted = true;
        void loadTemplates().then((loaded) => {
            if (mounted) setTemplates(loaded);
        });

        return () => {
            mounted = false;
        };
    }, []);

    // A public reply only offers public wording; internal notes may use both.
    const offered = templates.filter(
        (template) => internal || template.audience === 'public',
    );
    if (offered.length === 0) return null;

    const insert = async (id: string) => {
        setBusy(true);
        setError('');
        try {
            const response = await axios.get(
                `/it/tickets/${ticketId}/reply-templates/${id}/render`,
                { headers: { Accept: 'application/json' } },
            );
            onInsert(String(response.data?.body ?? ''));
        } catch (raised) {
            const message = axios.isAxiosError(raised)
                ? ((raised.response?.data as { errors?: { template?: string[] } })
                      ?.errors?.template?.[0] ??
                  'This template cannot be inserted right now.')
                : 'This template cannot be inserted right now.';
            setError(message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-1.5">
            <Select
                value=""
                disabled={disabled || busy}
                onValueChange={(value) => void insert(value)}
            >
                <SelectTrigger
                    aria-label="Insert template"
                    className="h-8 w-auto gap-1.5 text-xs"
                >
                    <SelectValue placeholder="Insert template…" />
                </SelectTrigger>
                <SelectContent>
                    {offered.map((template) => (
                        <SelectItem
                            key={template.id}
                            value={String(template.id)}
                        >
                            {template.name}
                            {template.audience === 'internal'
                                ? ' (internal)'
                                : ''}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
        </div>
    );
}
