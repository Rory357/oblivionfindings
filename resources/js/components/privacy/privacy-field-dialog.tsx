/**
 * A small "fill in a field or two, then act" pop-up for the privacy record
 * pages (breach, privacy impact assessment and privacy request). It replaces
 * the chains of browser prompts those pages used (DESIGN.md anti-pattern
 * "Browser prompt / confirm as a form").
 *
 * Each field mirrors the server's validation rule for its payload key, so an
 * empty required field or an over-long value is caught here before anything
 * is sent. Server validation errors come back under the matching field, and
 * the dialog stays open (and can't be dismissed) while the request is in
 * flight. It closes only when the request succeeds.
 */
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { type FormEvent, type ReactNode, useId, useState } from 'react';

export type PrivacyDialogField = {
    /** The payload key the server expects. */
    name: string;
    label: string;
    kind: 'text' | 'textarea' | 'date';
    /**
     * The message shown when the field is left empty. Its presence mirrors the
     * server's `required` rule; leave it out for `nullable` fields.
     */
    required?: string;
    /** Mirrors the server's `max:` rule, in characters. */
    maxLength?: number;
    /** Short help shown under the label. */
    hint?: string;
    placeholder?: string;
    /** Date fields: the earliest allowed day, as YYYY-MM-DD. */
    min?: string;
    /** Date fields: the message shown for a day before `min`. */
    minMessage?: string;
};

type Errors = Record<string, string>;

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function characters(value: string): number {
    // Laravel's max: rule counts characters, not UTF-16 code units.
    return [...value].length;
}

export function validatePrivacyFields(
    fields: PrivacyDialogField[],
    values: Record<string, string>,
): Errors {
    const errors: Errors = {};

    for (const field of fields) {
        const value = (values[field.name] ?? '').trim();

        if (value === '') {
            if (field.required) errors[field.name] = field.required;
            continue;
        }

        if (
            field.maxLength !== undefined &&
            characters(value) > field.maxLength
        ) {
            errors[field.name] =
                `Keep this to ${field.maxLength} characters or fewer.`;
            continue;
        }

        if (field.kind === 'date') {
            if (!ISO_DATE.test(value)) {
                errors[field.name] = 'Enter a valid date.';
            } else if (field.min && value < field.min) {
                errors[field.name] =
                    field.minMessage ?? 'Choose a later date.';
            }
        }
    }

    return errors;
}

const emptyValues = (fields: PrivacyDialogField[]) =>
    Object.fromEntries(fields.map((field) => [field.name, '']));

export function PrivacyFieldDialog({
    open,
    onClose,
    title,
    description,
    fields,
    submitLabel,
    variant,
    url,
}: {
    open: boolean;
    onClose: () => void;
    /** A question naming the thing, e.g. "Refuse this request?". */
    title: string;
    /** The plain-language effect of going ahead. */
    description: ReactNode;
    fields: PrivacyDialogField[];
    submitLabel: string;
    variant: 'default' | 'destructive';
    /** POST target; the payload is one key per field. */
    url: string;
}) {
    const idPrefix = useId();
    const [values, setValues] = useState<Record<string, string>>(() =>
        emptyValues(fields),
    );
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);

    // Start clean every time the dialog opens (reset during render, so the
    // previous attempt's text never flashes up).
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setValues(emptyValues(fields));
            setErrors({});
            setProcessing(false);
        }
    }

    const fieldNames = new Set(fields.map((field) => field.name));
    const otherErrors = Object.entries(errors)
        .filter(([key]) => !fieldNames.has(key))
        .map(([, message]) => message);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (processing) return;

        const found = validatePrivacyFields(fields, values);
        setErrors(found);
        if (Object.keys(found).length > 0) return;

        const payload = Object.fromEntries(
            fields.map((field) => [
                field.name,
                (values[field.name] ?? '').trim(),
            ]),
        );

        router.post(url, payload, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: (serverErrors) => setErrors(serverErrors),
            onSuccess: () => onClose(),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(isOpen) => {
                if (!isOpen && !processing) onClose();
            }}
        >
            <DialogContent className="max-w-md">
                <form onSubmit={submit} noValidate className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription asChild>
                            <div className="space-y-2">{description}</div>
                        </DialogDescription>
                    </DialogHeader>

                    {fields.map((field) => {
                        const id = `${idPrefix}-${field.name}`;
                        const hintId = field.hint ? `${id}-hint` : undefined;
                        const errorId = errors[field.name]
                            ? `${id}-error`
                            : undefined;
                        const describedBy =
                            [hintId, errorId].filter(Boolean).join(' ') ||
                            undefined;
                        const common = {
                            id,
                            name: field.name,
                            value: values[field.name] ?? '',
                            placeholder: field.placeholder,
                            disabled: processing,
                            required: Boolean(field.required),
                            'aria-invalid': errors[field.name]
                                ? true
                                : undefined,
                            'aria-describedby': describedBy,
                        };
                        const update = (value: string) =>
                            setValues((current) => ({
                                ...current,
                                [field.name]: value,
                            }));

                        return (
                            <div key={field.name} className="grid gap-1.5">
                                <Label htmlFor={id}>
                                    {field.label}
                                    {field.required ? null : (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            (optional)
                                        </span>
                                    )}
                                </Label>
                                {field.hint ? (
                                    <p
                                        id={hintId}
                                        className="text-xs text-muted-foreground"
                                    >
                                        {field.hint}
                                    </p>
                                ) : null}
                                {field.kind === 'textarea' ? (
                                    <Textarea
                                        {...common}
                                        rows={3}
                                        onChange={(event) =>
                                            update(event.target.value)
                                        }
                                    />
                                ) : (
                                    <Input
                                        {...common}
                                        type={
                                            field.kind === 'date'
                                                ? 'date'
                                                : 'text'
                                        }
                                        min={
                                            field.kind === 'date'
                                                ? field.min
                                                : undefined
                                        }
                                        onChange={(event) =>
                                            update(event.target.value)
                                        }
                                    />
                                )}
                                <InputError
                                    id={errorId}
                                    message={errors[field.name]}
                                />
                            </div>
                        );
                    })}

                    {otherErrors.length > 0 ? (
                        <InputError message={otherErrors.join(' ')} />
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={variant}
                            disabled={processing}
                        >
                            {processing ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : null}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** "2026-10-21" — the day after an ISO calendar date. */
export function dayAfter(isoDate: string): string {
    const date = new Date(`${isoDate}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + 1);
    return date.toISOString().slice(0, 10);
}

export default PrivacyFieldDialog;
