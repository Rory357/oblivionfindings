/* Medication › Settings › Medication rules › Medicine photos (P11 v5
 * `MedicinePhotos`). Lane A11 builds the "At recording" group: whether the
 * recording dialog shows the latest photo of the supplied pack, and what
 * happens when there's no photo. Taking photos at receipt is Lane C's group
 * and joins this section when it's built — nothing unbuilt is shown. */
import { Camera } from 'lucide-react';
import { NoMatches, useRow } from './_sections';
import {
    Choice,
    GroupGrid,
    GroupRow,
    Note,
    OnOff,
    Section,
    SettingGroup,
} from './_ui';

const G = 'packPhoto';

export function MedicinePhotos({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const { s, value, edit, state, disabled, shown } = useRow(G);
    const def = (key: string) => s.definitions[G]?.[key];
    const showLabel = 'Show the pack photo when a dose is recorded';
    const showHint =
        'The latest staff photo of the pack supplied, with who took it and when.';
    const noPhotoLabel = 'When there’s no photo';
    const noPhotoHint =
        'A warning never stops recording. A tick asks the worker to confirm they’ve checked the label first.';
    const on = value('show') === 'yes';
    return (
        <Section
            id="sc-photos"
            title="Medicine photos"
            caption="Photos of the pack actually supplied"
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                {def('show') ? (
                    <SettingGroup
                        id="photos-recording"
                        icon={Camera}
                        title="At recording"
                        caption="In the record-a-dose dialog, before the dose is given"
                        wide
                    >
                        <GroupRow
                            id="ph-show"
                            label={showLabel}
                            hint={showHint}
                            state={state('show')}
                            hidden={
                                !shown(
                                    show,
                                    q,
                                    'show',
                                    showLabel,
                                    showHint,
                                    def('show')?.label ?? '',
                                )
                            }
                            control={
                                <OnOff
                                    id="ph-show"
                                    checked={on}
                                    disabled={disabled}
                                    onChange={(v) =>
                                        edit('show', v ? 'yes' : 'no')
                                    }
                                />
                            }
                        />
                        {def('no_photo') ? (
                            <GroupRow
                                id="ph-no-photo"
                                label={noPhotoLabel}
                                hint={noPhotoHint}
                                state={state('no_photo')}
                                hidden={
                                    !on ||
                                    !shown(
                                        show,
                                        q,
                                        'no_photo',
                                        noPhotoLabel,
                                        noPhotoHint,
                                    )
                                }
                            >
                                <Choice
                                    value={value('no_photo')}
                                    disabled={disabled}
                                    onChange={(v) => edit('no_photo', v)}
                                    options={[
                                        ['warn', 'Warn'],
                                        [
                                            'tick',
                                            'Ask for “I’ve checked the label”',
                                        ],
                                    ]}
                                />
                            </GroupRow>
                        ) : null}
                    </SettingGroup>
                ) : null}
            </GroupGrid>
            <Note>
                A photo is a guide only. The dialog always says “Check the label
                — the picture is a guide only”, and neither choice switches
                label checking off.
            </Note>
        </Section>
    );
}
