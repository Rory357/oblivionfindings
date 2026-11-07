import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ANOTHER_GENDER,
    GENDER_DESCRIPTION_MAX_LENGTH,
    GENDER_OPTIONS,
    genderRecordedAsNotice,
    isGenderValue,
} from '@/lib/client-gender';
import { AlertTriangle } from 'lucide-react';
import { useId } from 'react';

/**
 * Gender for the full-page client forms: the shared dropdown, the "describe"
 * box shown only for Another gender, and the notice for text typed before the
 * dropdown existed. The Add client wizard renders the same list with its own
 * field primitives.
 */
export function ClientGenderFields({
    gender,
    description,
    recordedAs,
    onGenderChange,
    onDescriptionChange,
    errors,
}: {
    gender: string | null | undefined;
    description: string | null | undefined;
    /** Old typed text still waiting for a choice, if any. */
    recordedAs?: string | null;
    onGenderChange: (value: string) => void;
    onDescriptionChange: (value: string) => void;
    errors?: { gender?: string; gender_description?: string };
}) {
    const id = useId();
    const chosen = isGenderValue(gender) ? gender : undefined;

    return (
        <div className="space-y-3">
            <div>
                <Label htmlFor={`${id}-gender`}>Gender</Label>
                <Select value={chosen} onValueChange={onGenderChange}>
                    <SelectTrigger
                        id={`${id}-gender`}
                        className="mt-1"
                        aria-invalid={!!errors?.gender}
                    >
                        <SelectValue placeholder="Select gender" />
                    </SelectTrigger>
                    <SelectContent>
                        {GENDER_OPTIONS.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError className="mt-1" message={errors?.gender} />
            </div>

            {chosen === ANOTHER_GENDER ? (
                <div>
                    <Label htmlFor={`${id}-description`}>
                        Describe their gender
                    </Label>
                    <Input
                        id={`${id}-description`}
                        className="mt-1"
                        value={description ?? ''}
                        onChange={(e) => onDescriptionChange(e.target.value)}
                        maxLength={GENDER_DESCRIPTION_MAX_LENGTH}
                        placeholder="e.g. Non-binary, takatāpui"
                        aria-invalid={!!errors?.gender_description}
                    />
                    <p className="text-caption mt-1 text-muted-foreground">
                        In their own words.
                    </p>
                    <InputError
                        className="mt-1"
                        message={errors?.gender_description}
                    />
                </div>
            ) : null}

            {recordedAs && !chosen ? (
                <Alert variant="warning">
                    <AlertTriangle />
                    <AlertDescription>
                        {genderRecordedAsNotice(recordedAs)}
                    </AlertDescription>
                </Alert>
            ) : null}
        </div>
    );
}
