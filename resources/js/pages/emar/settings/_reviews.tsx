import { Button } from '@/components/ui/button';
import { ReviewCadenceSettingsFragment } from '../reviews/settings-fragment';
import { useSettings } from './_context';
import { reviewerOf } from './_model';
import { NoMatches, useRow } from './_sections';
import { Changed, Section } from './_ui';

/** P05's canonical definition uses the existing Rules draft/save/keep path. */
export function ReviewCadenceSettings({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const { s, value, edit, state, disabled, shown } = useRow('review_cadence');
    const { open, errors, clearError } = useSettings();
    const changed = state('months') === 'changed';
    const error = errors['review_cadence.months'];
    if (
        !shown(
            show,
            q,
            'months',
            'Regular medication reviews',
            'Default interval in calendar months',
        )
    ) {
        return <NoMatches q={q} clear={clear} />;
    }
    return (
        <Section
            id="sc-reviews"
            title="Medication reviews"
            caption="Every house"
        >
            <ReviewCadenceSettingsFragment
                months={Number(value('months'))}
                reviewed={!!reviewerOf(s, 'review_cadence', 'months')}
                disabled={disabled}
                onChange={(months) => {
                    edit('months', String(months));
                    clearError('review_cadence.months');
                }}
            />
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
            <div className="flex flex-wrap items-center gap-3">
                {changed && <Changed />}
                {!reviewerOf(s, 'review_cadence', 'months') && (
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={disabled || changed}
                        onClick={() =>
                            open({
                                kind: 'keep',
                                group: 'review_cadence',
                                key: 'months',
                            })
                        }
                    >
                        Keep today's value
                    </Button>
                )}
            </div>
        </Section>
    );
}
