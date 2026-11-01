/**
 * The Site page's Modes tab (S&D Phase F, drawing pg-sdr-12c): the house modes, each with its
 * schedule and safe actions, and the log of switches and what each one found. A mode never disarms
 * the alarm or unlocks a door (decision D12); it checks and reminds.
 */
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { modeIcon } from '@/components/security-devices/house-mode-popups';
import {
    Log,
    Note,
    PlaceCards,
    QuietLine,
    SideBySide,
    SideColumn,
} from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime, timedText } from '@/lib/security-devices/device-time';
import { Info, Pencil } from 'lucide-react';
import type { SiteModeItem, SiteModes } from './_types';

export function ModesTab({
    modes,
    can,
    now,
    onSwitch,
    onEdit,
}: {
    modes: SiteModes;
    can: { switch_mode: boolean };
    now: Date;
    onSwitch: (modeId: number) => void;
    /** Null adds a mode. */
    onEdit: (modeId: number | null) => void;
}) {
    const menu = useEntityContextMenu<SiteModeItem>();
    const canEdit = modes.edit !== null;
    const canAdd = canEdit && modes.items.length < (modes.edit?.max ?? 0);
    const actions = (mode: SiteModeItem): MenuItem[] => [
        ...(can.switch_mode && !mode.on
            ? [
                  {
                      label: `Switch to ${mode.name}`,
                      icon: modeIcon(mode.name),
                      onClick: () => onSwitch(mode.id),
                  },
              ]
            : []),
        ...(canEdit
            ? [
                  {
                      label: 'Edit mode',
                      icon: Pencil,
                      onClick: () => onEdit(mode.id),
                  },
              ]
            : []),
    ];

    return (
        <>
            <SideBySide cols="minmax(0, 1fr) 360px">
                <div className="flex flex-col gap-5">
                    {modes.items.length === 0 ? (
                        <QuietLine
                            title="House modes"
                            text="This Site has no house modes yet."
                            action={
                                canAdd
                                    ? {
                                          label: 'Add a mode',
                                          onClick: () => onEdit(null),
                                      }
                                    : null
                            }
                        />
                    ) : (
                        <PlaceCards
                            title="House modes"
                            sub="Each mode checks the doors and the alarm, sets camera privacy and tells the staff on shift. Switches on a schedule or by hand. A mode never disarms the alarm or unlocks a door."
                            cols={2}
                            items={modes.items.map((mode) => ({
                                id: mode.id,
                                name: mode.name,
                                sub: mode.sub,
                                flag: (
                                    <StatusBadge variant={mode.flag.tone}>
                                        {timedText(
                                            {
                                                text: mode.flag.text,
                                                at: mode.flag.at,
                                            },
                                            now,
                                        )}
                                    </StatusBadge>
                                ),
                                facts: mode.facts.map((fact) => ({
                                    tone: fact.tone,
                                    text: fact.text,
                                })),
                                menu: actions(mode),
                                onContextMenu:
                                    actions(mode).length > 0
                                        ? (event) => menu.open(event, mode)
                                        : undefined,
                            }))}
                        />
                    )}
                    {modes.log.length === 0 ? (
                        <QuietLine
                            title="Mode changes"
                            text="No switches yet. Each one shows here with what it found."
                        />
                    ) : (
                        <Log
                            title="Mode changes"
                            limit={12}
                            items={modes.log.map((row) => ({
                                key: row.id,
                                time: seenTime(row.at, now),
                                tone: row.tone,
                                title: row.title,
                                sub: row.sub || undefined,
                            }))}
                        />
                    )}
                </div>
                <SideColumn>
                    <Note icon={Info}>
                        <b>
                            Modes and automations never disarm an alarm or
                            unlock a door.
                        </b>{' '}
                        They check and remind: a door left unlocked or an alarm
                        not armed goes to the staff on shift to sort out by
                        hand.
                    </Note>
                    <Note icon={Info}>
                        Cameras are never put in bedrooms or bathrooms. Hallway
                        and shared-space cameras can have privacy hours.
                    </Note>
                    {canAdd && modes.items.length > 0 ? (
                        <QuietLine
                            title="Add a mode"
                            text="For example “Visitors”: the lounge camera’s privacy on until the evening, and the staff on shift told."
                            action={{
                                label: 'Add a mode',
                                onClick: () => onEdit(null),
                            }}
                        />
                    ) : null}
                </SideColumn>
            </SideBySide>

            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={modeIcon(menu.ctx.record.name)}
                    title={`${menu.ctx.record.name} mode`}
                    items={actions(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
        </>
    );
}
