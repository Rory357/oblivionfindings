import { AlertTriangle } from 'lucide-react';
import { useRow, NoMatches } from './_sections';
import { Choice, GroupGrid, GroupRow, SettingGroup } from './_ui';

/** P08b fragment: drafts, review, permission and history remain P11's. */
export function ErrorTriageSettings({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const { value, edit, state, disabled, shown } = useRow('errorTriage');
    if (
        !shown(
            show,
            q,
            'due',
            'Medication errors must be triaged',
            'Error triage',
        )
    )
        return <NoMatches q={q} clear={clear} />;
    return (
        <GroupGrid>
            <SettingGroup
                icon={AlertTriangle}
                title="Error triage"
                caption="The time to assign an owner and start looking into a report. Existing errors keep their due time."
            >
                <GroupRow
                    id="errorTriage-due"
                    label="Medication errors must be triaged"
                    state={state('due')}
                    hint="Calendar deadlines use New Zealand time."
                >
                    <Choice
                        value={value('due') ?? 'nextDay'}
                        onChange={(v) => edit('due', v)}
                        disabled={disabled}
                        options={[
                            ['fourHours', 'Within 4 hours'],
                            ['endOfDay', 'By the end of the day'],
                            ['nextDay', 'By the end of the next day'],
                        ]}
                    />
                </GroupRow>
            </SettingGroup>
        </GroupGrid>
    );
}
