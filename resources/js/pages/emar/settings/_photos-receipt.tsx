/* Medication › Settings › Medication rules › Medicine photos (approved P11
 * v5 Q18, P06 Q6; Lane C11). Lane C owns the "Taking photos — when stock is
 * received" group. Lane A11 owns "At recording" (`_photos.tsx`), which
 * renders <ReceiptPhotoGroup /> beside its own group once both are merged;
 * until then <ReceiptPhotoSection /> shows this group on its own. */
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

const G = 'packPhotoReceipt';

export function ReceiptPhotoGroup({ q, show }: { q: string; show: string }) {
    const { s, value, edit, state, disabled, shown } = useRow(G);
    const label = (key: string) => s.definitions[G]?.[key]?.label ?? key;
    const prompt = value('prompt');
    const prompting = prompt !== 'off';
    const savedPrompt = s.values[G]?.prompt;
    if (!s.definitions[G]) return null;
    return (
        <SettingGroup
            id="photos-receipt"
            icon={Camera}
            title="Taking photos"
            caption="When stock is received"
            wide
        >
            <GroupRow
                id="ph-who"
                label={label('who')}
                hint="They can add a photo at receipt or replace one later. Everyone who can open the medicine sees it."
                state={state('who')}
                hidden={!shown(show, q, 'who', label('who'))}
            >
                <Choice
                    value={value('who')}
                    disabled={disabled}
                    onChange={(v) => edit('who', v)}
                    options={[
                        ['stock', 'Anyone who can receive stock'],
                        ['leads', 'Leads only'],
                        ['recorders', 'Anyone who records doses'],
                    ]}
                />
            </GroupRow>
            <GroupRow
                id="ph-prompt"
                label={label('prompt')}
                hint="Staff can always skip it. A photo never blocks recording a dose."
                state={state('prompt')}
                hidden={!shown(show, q, 'prompt', label('prompt'))}
                control={
                    <OnOff
                        id="ph-prompt"
                        checked={prompting}
                        disabled={disabled}
                        onChange={(on) =>
                            edit(
                                'prompt',
                                on
                                    ? savedPrompt && savedPrompt !== 'off'
                                        ? savedPrompt
                                        : 'needed'
                                    : 'off',
                            )
                        }
                    />
                }
            >
                {prompting ? (
                    <Choice
                        value={prompt}
                        disabled={disabled}
                        onChange={(v) => edit('prompt', v)}
                        options={[
                            [
                                'needed',
                                'When there’s no photo yet, or the pack or brand changed',
                            ],
                            ['always', 'At every delivery'],
                        ]}
                    />
                ) : null}
            </GroupRow>
            <GroupRow
                id="ph-controlled"
                label={label('controlled_required')}
                hint="The house lead adds it in the receive dialog, before the witness. Ordinary deliveries stay optional."
                state={state('controlled_required')}
                hidden={
                    !shown(
                        show,
                        q,
                        'controlled_required',
                        label('controlled_required'),
                    )
                }
                control={
                    <OnOff
                        id="ph-controlled"
                        checked={value('controlled_required') === 'yes'}
                        disabled={disabled}
                        onChange={(on) =>
                            edit('controlled_required', on ? 'yes' : 'no')
                        }
                    />
                }
            />
        </SettingGroup>
    );
}

/** The Medicine photos section with only Lane C's group (see the file note). */
export function ReceiptPhotoSection({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    return (
        <Section
            id="sc-photos"
            title="Medicine photos"
            caption="Photos of the pack actually supplied"
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <ReceiptPhotoGroup q={q} show={show} />
            </GroupGrid>
            <Note>
                Photos are stored privately, with controlled-medicine
                concealment. Every screen that shows one says “Check the label —
                the picture is a guide only”. A catalogue picture appears only
                when a reviewed catalogue is connected to that medicine, and it
                is labelled a reference image.
            </Note>
        </Section>
    );
}
