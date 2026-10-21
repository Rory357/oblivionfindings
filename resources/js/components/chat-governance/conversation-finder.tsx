import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import { Search } from 'lucide-react';
import { useId, useState } from 'react';
import {
    conversationLabel,
    governanceRequest,
    INVESTIGATION_API,
    type InvestigableConversation,
} from './types';

/**
 * Finds private staff conversations by a member's name. Results are
 * metadata only (type, members, hold state); the search itself is logged
 * in the investigation audit.
 */
export function ConversationFinder({
    actorId,
    label = 'Authorised scope',
    selected,
    onSelect,
    disabled = false,
}: {
    actorId: number;
    label?: string;
    selected: InvestigableConversation | null;
    onSelect: (conversation: InvestigableConversation | null) => void;
    disabled?: boolean;
}) {
    const id = useId();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<InvestigableConversation[] | null>(
        null,
    );
    const [searching, setSearching] = useState(false);
    const [error, setError] = useState('');

    async function search() {
        if (query.trim().length < 2 || searching) return;
        setSearching(true);
        setError('');
        try {
            const result = await governanceRequest<{
                conversations: InvestigableConversation[];
            }>(
                actorId,
                `${INVESTIGATION_API}/conversations?q=${encodeURIComponent(query.trim())}`,
            );
            setResults(result.conversations);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setSearching(false);
        }
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <p className="text-caption">
                Case-approved conversation only. Search by a member’s name;
                results show who is in each conversation, never its messages.
            </p>
            <div className="flex gap-2">
                <Input
                    id={id}
                    value={query}
                    disabled={disabled}
                    placeholder="Staff member’s name"
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            void search();
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled || searching || query.trim().length < 2}
                    onClick={() => void search()}
                >
                    <Search className="size-4" />
                    {searching ? 'Searching…' : 'Find'}
                </Button>
            </div>
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            {results && results.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No private staff conversations include that name.
                </p>
            )}
            {results && results.length > 0 && (
                <ul
                    aria-label="Matching conversations"
                    className="grid max-h-56 gap-1 overflow-y-auto rounded-lg border bg-muted/40 p-1"
                >
                    {results.map((conversation) => {
                        const chosen = selected?.id === conversation.id;
                        return (
                            <li key={conversation.id}>
                                {/* eslint-disable-next-line no-restricted-syntax -- selectable result tile with a two-part row layout */}
                                <button
                                    type="button"
                                    aria-pressed={chosen}
                                    disabled={disabled}
                                    onClick={() =>
                                        onSelect(chosen ? null : conversation)
                                    }
                                    className={cn(
                                        'flex min-h-[44px] w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        chosen &&
                                            'bg-primary-fill/10 text-primary',
                                    )}
                                >
                                    <span className="min-w-0 break-words">
                                        {conversationLabel(conversation)}
                                    </span>
                                    {conversation.held && (
                                        <StatusBadge variant="warning">
                                            On hold
                                        </StatusBadge>
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            {selected && (
                <p className="text-sm" role="status">
                    Selected: <strong>{conversationLabel(selected)}</strong>
                </p>
            )}
        </div>
    );
}
