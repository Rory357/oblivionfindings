import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useId, useState } from 'react';
import {
    readChatCallbackMemory,
    saveChatCallbackMemory,
    type ChatCallbackEdit,
} from './api';
import type { ChatCallback, ChatCallbackPage } from './chat-callback-sync';

// Radix Select items cannot hold an empty value; Unassigned ('') maps to this.
const UNASSIGNED = '__none';

export function CallbackActions({
    row,
    owners,
    actorId,
    memoryKey,
    disabled,
    onSubmit,
}: {
    row: ChatCallback;
    owners: ChatCallbackPage['owners'];
    actorId: number;
    memoryKey: string;
    disabled: boolean;
    onSubmit: (
        row: ChatCallback,
        action: string,
        data?: Record<string, unknown>,
    ) => Promise<boolean>;
}) {
    const ownerId = useId();
    const [draft, setDraft] = useState<ChatCallbackEdit | null>(
        () => readChatCallbackMemory(memoryKey).edits[row.id] ?? null,
    );
    const value = draft ?? {
        owner: row.assigned_to ? String(row.assigned_to) : '',
        agreed: row.agreed_time ?? '',
        version: row.version,
    };
    const stale = !!draft && draft.version !== row.version;
    function change(next: ChatCallbackEdit | null) {
        setDraft(next);
        const edits = { ...readChatCallbackMemory(memoryKey).edits };
        if (next) edits[row.id] = next;
        else delete edits[row.id];
        saveChatCallbackMemory(memoryKey, { edits });
    }
    async function save(action: string, data?: Record<string, unknown>) {
        if (await onSubmit({ ...row, version: value.version }, action, data))
            change(null);
    }
    return (
        <div className="space-y-3">
            {row.can_manage && (
                <>
                    {stale && (
                        <div
                            role="status"
                            className="rounded-lg bg-status-warning-bg p-3 text-sm"
                        >
                            This request changed. Review the current owner and
                            agreed time above. Your unsaved choices are kept.
                            <Button
                                variant="ghost"
                                className="min-h-[44px]"
                                disabled={disabled}
                                onClick={() => change(null)}
                            >
                                Use current details
                            </Button>
                        </div>
                    )}
                    <div className="block text-sm font-medium">
                        <label htmlFor={ownerId}>Callback owner</label>
                        <Select
                            value={value.owner || UNASSIGNED}
                            disabled={disabled}
                            onValueChange={(next) =>
                                change({
                                    ...value,
                                    owner: next === UNASSIGNED ? '' : next,
                                })
                            }
                        >
                            <SelectTrigger
                                id={ownerId}
                                className="mt-1 min-h-[44px] w-full rounded-lg bg-background"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={UNASSIGNED}>
                                    Unassigned
                                </SelectItem>
                                {owners
                                    .filter((person) =>
                                        row.eligible_owner_ids.includes(
                                            person.id,
                                        ),
                                    )
                                    .map((person) => (
                                        <SelectItem
                                            key={person.id}
                                            value={String(person.id)}
                                        >
                                            {person.name}
                                        </SelectItem>
                                    ))}
                                {value.owner &&
                                    !owners.some(
                                        (person) =>
                                            String(person.id) === value.owner &&
                                            row.eligible_owner_ids.includes(
                                                person.id,
                                            ),
                                    ) && (
                                        <SelectItem
                                            value={value.owner}
                                            disabled
                                        >
                                            Previous owner unavailable
                                        </SelectItem>
                                    )}
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        variant="outline"
                        className="min-h-[44px]"
                        disabled={
                            disabled ||
                            stale ||
                            value.owner === String(row.assigned_to ?? '')
                        }
                        onClick={() =>
                            void save('assign', {
                                assigned_to: value.owner
                                    ? Number(value.owner)
                                    : null,
                            })
                        }
                    >
                        Save owner
                    </Button>
                    <label className="block text-sm font-medium">
                        Agreed callback time
                        <Input
                            className="mt-1 min-h-[44px]"
                            maxLength={300}
                            value={value.agreed}
                            disabled={disabled}
                            placeholder="For example, Friday 16 October at 3 pm"
                            onChange={(event) =>
                                change({ ...value, agreed: event.target.value })
                            }
                        />
                    </label>
                    <Button
                        variant="outline"
                        className="min-h-[44px]"
                        disabled={
                            disabled ||
                            stale ||
                            !row.assigned_to ||
                            !value.agreed.trim() ||
                            value.agreed === row.agreed_time
                        }
                        onClick={() =>
                            void save('schedule', { agreed_time: value.agreed })
                        }
                    >
                        Save agreed time
                    </Button>
                    {row.assigned_to === actorId && (
                        <div className="flex flex-wrap gap-2">
                            {[
                                ['attempt', 'Record no-answer attempt'],
                                ['complete', 'Complete callback'],
                                ['unreachable', 'Unable to contact'],
                            ].map(([action, label]) => (
                                <Button
                                    key={action}
                                    variant="outline"
                                    className="min-h-[44px]"
                                    disabled={disabled || stale}
                                    onClick={() => void save(action)}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    )}
                </>
            )}
            {row.can_cancel && (
                <Button
                    variant="ghost"
                    className="min-h-[44px]"
                    disabled={disabled || stale}
                    onClick={() => void save('cancel')}
                >
                    Cancel request
                </Button>
            )}
        </div>
    );
}
