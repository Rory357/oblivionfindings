import { FileText, ShieldCheck } from 'lucide-react';
import { useRow } from './_sections';
import { Choice, GroupGrid, GroupRow, Note, OnOff, SettingGroup } from './_ui';

/** P09 fragment inside the P11 draft/review/history shell. */
export function RecordsReporting({ q, show }: { q: string; show: string }) {
    const { s, value, edit, state, disabled, shown } = useRow('records');
    const def = (key: string) => s.definitions.records?.[key];
    const options = (key: string): [string, string][] => (def(key)?.options ?? []).map((o) => [o.value, o.label]);
    return <div className="space-y-5">
        <Note>These organisation settings start as defaults that have not yet been reviewed. SAC ratings remain off until an authorised settings editor chooses to enable them. Existing records are kept.</Note>
        <GroupGrid>
            <SettingGroup icon={FileText} title="Keep medication records" caption="After the person’s last service">
                <GroupRow id="records.retention" label="Keep medication records for" state={state('retention')} hidden={!shown(show, q, 'retention', 'Keep medication records retention')} hint="The default needs the organisation’s review. Changing this value does not delete any records.">
                    <Choice value={value('retention')} onChange={(v) => edit('retention', v)} options={options('retention')} disabled={disabled} />
                </GroupRow>
            </SettingGroup>
            <SettingGroup icon={ShieldCheck} title="SAC ratings" caption="Confirmed by the person closing each error">
                <GroupRow id="records.sac" label="Add SAC ratings when an error is closed" state={state('sac')} hidden={!shown(show, q, 'sac', 'SAC error rating')} control={<OnOff id="records.sac" checked={value('sac') === 'on'} onChange={(v) => edit('sac', v ? 'on' : 'off')} disabled={disabled} />} hint="Near misses have no SAC. A proposed mapping never replaces confirmation at close." />
                {value('sac') === 'on' && ['sac_death', 'sac_moderate', 'sac_minor'].map((key) => <GroupRow key={key} id={`records.${key}`} label={def(key)?.label ?? key} state={state(key)}><Choice value={value(key)} onChange={(v) => edit(key, v)} options={options(key)} disabled={disabled} /></GroupRow>)}
                {value('sac') === 'on' && <GroupRow id="records.severe" label="Severe or permanent harm" hint="The closer chooses SAC 1 or SAC 2. Nothing is preselected." />}
            </SettingGroup>
        </GroupGrid>
    </div>;
}
