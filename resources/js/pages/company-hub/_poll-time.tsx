import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    formatDateOnly,
    formatDateTime,
    toDatetimeLocal,
    workerTimeOffset,
    workerTimeOffsets,
} from '@/lib/datetime';
import { useId, useState } from 'react';
import { HubDateTimeField } from './_date-fields';
import { HubSelect } from './_select';

export function pollInstant(local: string, offset: string): string {
    const offsets = workerTimeOffsets(local);
    const choice =
        offsets.length === 1
            ? offsets[0]
            : offsets.includes(offset)
              ? offset
              : '';
    return choice ? `${local}:00${choice}` : '';
}

export function PollTimeInput({
    local,
    offset,
    onChange,
    disabled = false,
}: {
    local: string;
    offset: string;
    onChange: (local: string, offset: string) => void;
    disabled?: boolean;
}) {
    const offsets = workerTimeOffsets(local);
    return (
        <div className="space-y-2">
            <HubDateTimeField
                label="Closing date and time"
                value={local}
                disabled={disabled}
                onChange={(value) => onChange(value, '')}
            />
            <p className="text-xs text-muted-foreground">
                New Zealand time · Pacific/Auckland
            </p>
            {offsets.length > 1 && (
                <Label className="grid gap-2">
                    Which clock reading?
                    <HubSelect
                        label="Which clock reading?"
                        value={offset}
                        disabled={disabled}
                        onChange={(value) => onChange(local, value)}
                        options={[
                            { value: '', label: 'Choose a time' },
                            ...offsets.map((value, index) => ({
                                value,
                                label: `${index === 0 ? 'First' : 'Second'} occurrence · UTC${value}`,
                            })),
                        ]}
                    />
                </Label>
            )}
            {/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local) &&
                !offsets.length && (
                    <p role="alert" className="text-xs text-destructive">
                        Choose a valid New Zealand date and time. This clock
                        reading does not exist.
                    </p>
                )}
        </div>
    );
}

export function PollDeadline({
    value,
    onChange,
}: {
    value?: string;
    onChange: (value: string | undefined) => void;
}) {
    return (
        <DeadlineForm key={value ?? 'none'} value={value} onChange={onChange} />
    );
}
function DeadlineForm({
    value,
    onChange,
}: {
    value?: string;
    onChange: (value: string | undefined) => void;
}) {
    const legacy = !!value && /^\d{4}-\d{2}-\d{2}$/.test(value);
    const [local, setLocal] = useState(
        legacy ? `${value}T23:59` : toDatetimeLocal(value),
    );
    const [offset, setOffset] = useState(
        value && !legacy ? workerTimeOffset(value) : '',
    );
    const id = useId();
    const instant = pollInstant(local, offset);
    const unchanged =
        !legacy &&
        !!value &&
        !!instant &&
        Date.parse(value) === Date.parse(instant);
    return (
        <fieldset
            className="space-y-3 rounded-xl border p-3"
            aria-describedby={id}
        >
            <legend className="px-1 text-sm font-medium">
                Poll closing time
            </legend>
            <p id={id} className="text-xs text-muted-foreground">
                {value
                    ? `Set for ${legacy ? `${formatDateOnly(value)} · end of day` : formatDateTime(value)} · New Zealand time`
                    : 'No closing time set. An editor can close this poll manually.'}
            </p>
            <PollTimeInput
                local={local}
                offset={offset}
                onChange={(date, zone) => {
                    setLocal(date);
                    setOffset(zone);
                }}
            />
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={!instant || unchanged}
                    onClick={() => onChange(instant)}
                >
                    Set closing time
                </Button>
                {value && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onChange(undefined)}
                    >
                        Remove closing time
                    </Button>
                )}
            </div>
            {!unchanged && local && (
                <p className="text-xs text-muted-foreground">
                    Choose “Set closing time” to add this time to your page
                    draft.
                </p>
            )}
        </fieldset>
    );
}
