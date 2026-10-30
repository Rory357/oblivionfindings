import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';

export type ProfileHandoverField = {
    key: string;
    label: string;
    current: string | string[] | null;
    can_add: boolean;
};
export type ProfileHandoverDraft = {
    values: Record<string, string>;
    note: string;
    confirmed: boolean;
};
export function profileHandoverPayload(draft: ProfileHandoverDraft) {
    const fields: Record<string, string | string[]> = {};
    for (const [key, raw] of Object.entries(draft.values)) {
        const value = raw.trim();
        if (!value) continue;
        if (key === 'languages') {
            const languages = [
                ...new Set(
                    value
                        .split(',')
                        .map((v) => v.trim())
                        .filter(Boolean),
                ),
            ];
            if (languages.length) fields[key] = languages;
        } else fields[key] = value;
    }
    return Object.keys(fields).length
        ? {
              fields,
              verification_note: draft.note.trim(),
              confirmed: draft.confirmed,
          }
        : undefined;
}
export function profileHandoverValid(draft: ProfileHandoverDraft) {
    return (
        !profileHandoverPayload(draft) ||
        (draft.confirmed && draft.note.trim().length >= 10)
    );
}

export function ProfileHandoverFields({
    fields,
    draft,
    onChange,
}: {
    fields: ProfileHandoverField[];
    draft: ProfileHandoverDraft;
    onChange: (draft: ProfileHandoverDraft) => void;
}) {
    if (!fields.length) return null;
    return (
        <details className="rounded-lg border p-4">
            <summary className="min-h-[44px] cursor-pointer font-medium">
                Culture and language · optional profile details
            </summary>
            <div className="space-y-4 pt-3">
                <p className="text-sm text-muted-foreground">
                    Record only information the person has chosen to share and
                    you have verified for their profile. Leave unknown or
                    declined details blank. Existing values are kept;
                    corrections belong in the person profile.
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                    {fields.map((field) => (
                        <Field
                            key={field.key}
                            label={field.label}
                            hint={
                                field.key === 'languages' && field.can_add
                                    ? 'Separate languages with commas.'
                                    : undefined
                            }
                        >
                            {field.can_add ? (
                                <Input
                                    value={draft.values[field.key] ?? ''}
                                    maxLength={
                                        field.key === 'preferred_pronouns'
                                            ? 50
                                            : field.key === 'languages' ||
                                                field.key ===
                                                    'cultural_dietary_needs'
                                              ? 2000
                                              : 255
                                    }
                                    onChange={(event) =>
                                        onChange({
                                            ...draft,
                                            values: {
                                                ...draft.values,
                                                [field.key]: event.target.value,
                                            },
                                            confirmed: false,
                                        })
                                    }
                                />
                            ) : (
                                <p className="text-sm break-words">
                                    {Array.isArray(field.current)
                                        ? field.current.join(', ')
                                        : field.current}{' '}
                                    <span className="text-muted-foreground">
                                        · Existing value kept
                                    </span>
                                </p>
                            )}
                        </Field>
                    ))}
                </div>
                {profileHandoverPayload(draft) && (
                    <>
                        <Field label="How were these profile details verified?">
                            <Textarea
                                value={draft.note}
                                maxLength={2000}
                                onChange={(event) =>
                                    onChange({
                                        ...draft,
                                        note: event.target.value,
                                        confirmed: false,
                                    })
                                }
                            />
                        </Field>
                        <label className="flex min-h-[44px] items-start gap-3 text-sm">
                            <Checkbox
                                className="mt-1"
                                checked={draft.confirmed}
                                onCheckedChange={(checked) =>
                                    onChange({
                                        ...draft,
                                        confirmed: checked === true,
                                    })
                                }
                            />
                            <span>
                                These are the person’s own details, verified
                                with them or their authorised representative,
                                and they have agreed to include them in their
                                profile.
                            </span>
                        </label>
                    </>
                )}
            </div>
        </details>
    );
}
