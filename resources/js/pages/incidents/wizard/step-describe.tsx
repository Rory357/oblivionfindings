import DictateButton from '@/components/dictate-button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type StepTwoData = {
    description: string;
};

type Props = {
    data: StepTwoData;
    onChange: (patch: Partial<StepTwoData>) => void;
    errors?: Partial<Record<keyof StepTwoData, string>>;
};

export default function StepDescribe({ data, onChange, errors }: Props) {
    return (
        <div className="space-y-6">
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">What happened</h2>
                <p className="text-sm text-muted-foreground">
                    In your own words is fine. You can add more detail later.
                </p>
            </div>

            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <Label
                        htmlFor="incident-description"
                        className="text-sm font-medium"
                    >
                        Describe what happened
                    </Label>
                    <DictateButton
                        value={data.description}
                        onChange={(next) => onChange({ description: next })}
                        fieldLabel="Describe what happened"
                    />
                </div>
                <Textarea
                    id="incident-description"
                    data-test="incident-description"
                    value={data.description}
                    onChange={(e) => onChange({ description: e.target.value })}
                    placeholder="In your own words, what happened?"
                    rows={8}
                    className="text-base"
                    aria-invalid={!!errors?.description}
                    aria-describedby={
                        errors?.description
                            ? 'incident-description-error'
                            : undefined
                    }
                    autoFocus
                />
                {errors?.description && (
                    <p
                        id="incident-description-error"
                        data-test="incident-description-error"
                        className="text-xs text-status-critical"
                    >
                        {errors.description}
                    </p>
                )}
                <p className="text-xs text-muted-foreground">
                    Tap <span className="font-medium">Save and continue</span>{' '}
                    and we&rsquo;ll save the incident so you don&rsquo;t lose
                    it.
                </p>
            </div>
        </div>
    );
}
