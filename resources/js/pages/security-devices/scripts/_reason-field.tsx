import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { singleLine, textIssue } from './_contracts';

/**
 * A reason or note that the server stores as one line of plain text. Enter
 * does not add a line break and pasted line breaks become spaces, so the
 * action it guards never greys out without saying why.
 */
export function ReasonField({
    id,
    label,
    value,
    onChange,
    disabled,
    min = 10,
    max = 500,
    noun = 'reason',
    error,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    min?: number;
    max?: number;
    noun?: string;
    /** A message to show even before the person types (after a failed check). */
    error?: string;
}) {
    // Length shortfalls stay a neutral hint while typing; characters the
    // server will refuse are an error straight away.
    const characters =
        value === ''
            ? null
            : textIssue(value, 0, Number.MAX_SAFE_INTEGER, noun);
    const issue = characters ?? error ?? null;
    const hint = `Use ${min.toLocaleString('en-NZ')}–${max.toLocaleString('en-NZ')} characters on one line.`;
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Textarea
                id={id}
                value={value}
                maxLength={max}
                disabled={disabled}
                aria-invalid={issue ? true : undefined}
                aria-describedby={id + '-hint'}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') event.preventDefault();
                }}
                onChange={(event) => onChange(singleLine(event.target.value))}
            />
            {issue ? (
                <InputError id={id + '-hint'} message={issue} />
            ) : (
                <p
                    id={id + '-hint'}
                    className="text-caption text-muted-foreground"
                >
                    {hint}
                </p>
            )}
        </div>
    );
}
