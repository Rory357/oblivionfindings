import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import { Search, X } from 'lucide-react';
import { useId, useState } from 'react';
import {
    conversationLabel,
    governanceRequest,
    INVESTIGATION_API,
    type InvestigableConversation,
} from './types';

type SingleSelection = {
    multiple?: false;
    selected: InvestigableConversation | null;
    onSelect: (conversation: InvestigableConversation | null) => void;
};

/** Several conversations for one case: ticks survive a new search, so one case can cover several people. */
type MultipleSelection = {
    multiple: true;
    selection: InvestigableConversation[];
    onSelectionChange: (conversations: InvestigableConversation[]) => void;
    max: number;
};

/**
 * Finds private staff conversations by a member's name. Results are
 * metadata only (type, members, hold state); the search itself is logged
 * in the investigation audit.
 */
export function ConversationFinder({
    actorId,
    label = 'Authorised scope',
    disabled = false,
    ...mode
}: {
    actorId: number;
    label?: string;
    disabled?: boolean;
} & (SingleSelection | MultipleSelection)) {
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

    const ticked = (conversation: InvestigableConversation) =>
        mode.multiple === true &&
        mode.selection.some((item) => item.id === conversation.id);
    const full = mode.multiple === true && mode.selection.length >= mode.max;

    function toggle(conversation: InvestigableConversation, on: boolean) {
        if (mode.multiple !== true) return;
        const rest = mode.selection.filter(
            (item) => item.id !== conversation.id,
        );
        mode.onSelectionChange(on ? [...rest, conversation] : rest);
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <p className="text-caption">
                {mode.multiple
                    ? 'Search by a member’s name and tick each conversation this case covers. Search again to add conversations with other people. Results show who is in each conversation, never its messages.'
                    : 'Case-approved conversation only. Search by a member’s name; results show who is in each conversation, never its messages.'}
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
                        if (mode.multiple) {
                            const checked = ticked(conversation);
                            const boxId = `${id}-conversation-${conversation.id}`;
                            return (
                                <li
                                    key={conversation.id}
                                    className={cn(
                                        'flex min-h-[44px] items-center gap-3 rounded-md px-3 py-2 text-sm hover:bg-muted',
                                        checked &&
                                            'bg-primary-fill/10 text-primary',
                                    )}
                                >
                                    <Checkbox
                                        id={boxId}
                                        checked={checked}
                                        disabled={
                                            disabled || (full && !checked)
                                        }
                                        onCheckedChange={(next) =>
                                            toggle(conversation, next === true)
                                        }
                                    />
                                    <Label
                                        htmlFor={boxId}
                                        className="min-w-0 flex-1 cursor-pointer font-normal break-words"
                                    >
                                        {conversationLabel(conversation)}
                                    </Label>
                                    {conversation.held && (
                                        <StatusBadge variant="warning">
                                            On hold
                                        </StatusBadge>
                                    )}
                                </li>
                            );
                        }
                        const chosen = mode.selected?.id === conversation.id;
                        return (
                            <li key={conversation.id}>
                                {/* eslint-disable-next-line no-restricted-syntax -- selectable result tile with a two-part row layout */}
                                <button
                                    type="button"
                                    aria-pressed={chosen}
                                    disabled={disabled}
                                    onClick={() =>
                                        mode.onSelect(
                                            chosen ? null : conversation,
                                        )
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
            {!mode.multiple && mode.selected && (
                <p className="text-sm" role="status">
                    Selected:{' '}
                    <strong>{conversationLabel(mode.selected)}</strong>
                </p>
            )}
            {mode.multiple && mode.selection.length > 0 && (
                <div className="grid gap-1" role="status">
                    <p className="text-sm font-medium">
                        Selected for this case ({mode.selection.length}
                        {full ? ' · the most one case can cover' : ''})
                    </p>
                    <ul
                        aria-label="Conversations selected for this case"
                        className="grid gap-1"
                    >
                        {mode.selection.map((conversation) => (
                            <li
                                key={conversation.id}
                                className="flex items-center justify-between gap-2 rounded-md border px-3 py-1 text-sm"
                            >
                                <span className="min-w-0 break-words">
                                    {conversationLabel(conversation)}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={disabled}
                                    aria-label={`Remove ${conversationLabel(conversation)}`}
                                    onClick={() => toggle(conversation, false)}
                                >
                                    <X className="size-4" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
