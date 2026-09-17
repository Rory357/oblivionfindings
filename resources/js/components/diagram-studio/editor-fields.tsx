import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    DiagramEdgeV2,
    DiagramNodeV2,
    DiagramPageV2,
} from '@/lib/diagram-studio/contract';
import { DIAGRAM_ENUMS as E } from '@/lib/diagram-studio/contract';
import type { ReactNode } from 'react';
import { useEffect, useId, useState } from 'react';

export function Field({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <label className="ds-field">
            <span>{label}</span>
            {children}
        </label>
    );
}
export function DraftField({
    label,
    value,
    onCommit,
    multiline = false,
    type = 'text',
    disabled = false,
}: {
    label: string;
    value: string | number | null;
    onCommit: (value: string) => void;
    multiline?: boolean;
    type?: string;
    disabled?: boolean;
}) {
    const [draft, setDraft] = useState(String(value ?? ''));
    const id = useId();
    useEffect(() => setDraft(String(value ?? '')), [value]);
    const save = () => {
        if (draft !== String(value ?? '')) onCommit(draft);
    };
    return (
        <div className="ds-field">
            <Label htmlFor={id}>{label}</Label>
            {multiline ? (
                <Textarea
                    id={id}
                    value={draft}
                    disabled={disabled}
                    rows={4}
                    onChange={(e) => setDraft(e.target.value)}
                    onBlur={save}
                />
            ) : (
                <Input
                    id={id}
                    type={type}
                    value={draft}
                    disabled={disabled}
                    onChange={(e) => setDraft(e.target.value)}
                    onBlur={save}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            save();
                            e.currentTarget.blur();
                        }
                        if (e.key === 'Escape') setDraft(String(value ?? ''));
                    }}
                />
            )}
        </div>
    );
}
export function EnumField<T extends string>({
    label,
    value,
    values,
    onChange,
    disabled,
}: {
    label: string;
    value: T;
    values: readonly T[];
    onChange: (value: T) => void;
    disabled?: boolean;
}) {
    return (
        <Field label={label}>
            <select
                aria-label={label}
                value={value}
                onChange={(e) => onChange(e.target.value as T)}
                disabled={disabled}
            >
                {values.map((v) => (
                    <option key={v} value={v}>
                        {v.charAt(0).toUpperCase() + v.slice(1)}
                    </option>
                ))}
            </select>
        </Field>
    );
}
export function Inspector({
    node,
    edge,
    page,
    patchNode,
    patchEdge,
    enabled,
}: {
    node?: DiagramNodeV2;
    edge?: DiagramEdgeV2;
    page: DiagramPageV2;
    enabled: boolean;
    patchNode: (patch: Partial<DiagramNodeV2>) => void;
    patchEdge: (patch: Partial<DiagramEdgeV2>) => void;
}) {
    if (edge)
        return (
            <fieldset disabled={!enabled} className="ds-inspector-fields">
                <DraftField
                    label="Connector label"
                    value={edge.label}
                    onCommit={(label) => patchEdge({ label: label || null })}
                />
                {(['from', 'to'] as const).map((key) => (
                    <Field
                        key={key}
                        label={
                            key === 'from' ? 'Connector start' : 'Connector end'
                        }
                    >
                        <select
                            aria-label={
                                key === 'from'
                                    ? 'Connector start'
                                    : 'Connector end'
                            }
                            value={edge[key]}
                            onChange={(e) =>
                                patchEdge({ [key]: e.target.value })
                            }
                        >
                            {page.nodes.map((n) => (
                                <option key={n.id} value={n.id}>
                                    {n.text || n.type}
                                </option>
                            ))}
                        </select>
                    </Field>
                ))}
                <EnumField
                    label="Connector route"
                    value={edge.route}
                    values={E.route}
                    onChange={(route) => patchEdge({ route })}
                />
                <div className="ds-fields-two">
                    <EnumField
                        label="Start port"
                        value={edge.fromPort}
                        values={E.port}
                        onChange={(fromPort) => patchEdge({ fromPort })}
                    />
                    <EnumField
                        label="End port"
                        value={edge.toPort}
                        values={E.port}
                        onChange={(toPort) => patchEdge({ toPort })}
                    />
                </div>
                <div className="ds-fields-two">
                    <EnumField
                        label="Start arrow"
                        value={edge.startArrow}
                        values={E.arrow}
                        onChange={(startArrow) => patchEdge({ startArrow })}
                    />
                    <EnumField
                        label="End arrow"
                        value={edge.arrow}
                        values={E.arrow}
                        onChange={(arrow) => patchEdge({ arrow })}
                    />
                </div>
                <EnumField
                    label="Connector line style"
                    value={edge.dash}
                    values={E.dash}
                    onChange={(dash) => patchEdge({ dash })}
                />
                <DraftField
                    label="Connector bend"
                    type="number"
                    value={edge.bend}
                    onCommit={(v) => patchEdge({ bend: Number(v) })}
                />
                <DraftField
                    label="Connector weight"
                    type="number"
                    value={edge.width}
                    onCommit={(v) => patchEdge({ width: Number(v) })}
                />
                <Field label="Connector colour">
                    <input
                        aria-label="Connector colour"
                        type="color"
                        value={edge.stroke}
                        onChange={(e) => patchEdge({ stroke: e.target.value })}
                    />
                </Field>
                <Field label="Label position">
                    <input
                        aria-label="Label position"
                        type="range"
                        min="0"
                        max="1"
                        step=".05"
                        value={edge.labelPos}
                        onChange={(e) =>
                            patchEdge({ labelPos: Number(e.target.value) })
                        }
                    />
                </Field>
            </fieldset>
        );
    if (!node)
        return (
            <div className="ds-empty">
                <strong>Make each detail clear.</strong>
                <p>
                    Select a shape or connector to edit its text, appearance and
                    position.
                </p>
            </div>
        );
    return (
        <fieldset disabled={!enabled} className="ds-inspector-fields">
            <DraftField
                label="Selected shape label"
                value={node.text}
                multiline
                onCommit={(text) => patchNode({ text: text || null })}
            />
            <div className="ds-fields-two">
                <EnumField
                    label="Font family"
                    value={node.style.font}
                    values={E.font}
                    onChange={(font) =>
                        patchNode({ style: { ...node.style, font } })
                    }
                />
                <DraftField
                    label="Font size"
                    type="number"
                    value={node.style.fontSize}
                    onCommit={(v) =>
                        patchNode({
                            style: { ...node.style, fontSize: Number(v) },
                        })
                    }
                />
            </div>
            <div className="ds-button-row">
                {(['bold', 'italic', 'underline'] as const).map((style) => (
                    <Button
                        key={style}
                        type="button"
                        variant="outline"
                        size="sm"
                        aria-pressed={node.style[style]}
                        onClick={() =>
                            patchNode({
                                style: {
                                    ...node.style,
                                    [style]: !node.style[style],
                                },
                            })
                        }
                    >
                        {style.charAt(0).toUpperCase() + style.slice(1)}
                    </Button>
                ))}
            </div>
            <EnumField
                label="Text alignment"
                value={node.style.align}
                values={E.align}
                onChange={(align) =>
                    patchNode({ style: { ...node.style, align } })
                }
            />
            <div className="ds-fields-two">
                {(['fill', 'stroke', 'color'] as const).map((key) => (
                    <Field
                        key={key}
                        label={
                            key === 'color'
                                ? 'Text colour'
                                : key === 'fill'
                                  ? 'Fill'
                                  : 'Stroke'
                        }
                    >
                        <input
                            aria-label={
                                key === 'color'
                                    ? 'Text colour'
                                    : key === 'fill'
                                      ? 'Fill'
                                      : 'Stroke'
                            }
                            type="color"
                            value={
                                node.style[key] === 'none'
                                    ? '#ffffff'
                                    : node.style[key]
                            }
                            onChange={(e) =>
                                patchNode({
                                    style: {
                                        ...node.style,
                                        [key]: e.target.value,
                                    },
                                })
                            }
                        />
                    </Field>
                ))}
                <DraftField
                    label="Stroke weight"
                    value={node.style.strokeWidth}
                    type="number"
                    onCommit={(v) =>
                        patchNode({
                            style: { ...node.style, strokeWidth: Number(v) },
                        })
                    }
                />
            </div>
            <label className="ds-check">
                <input
                    type="checkbox"
                    checked={node.style.fill === 'none'}
                    onChange={(e) =>
                        patchNode({
                            style: {
                                ...node.style,
                                fill: e.target.checked ? 'none' : '#ffffff',
                            },
                        })
                    }
                />
                Transparent fill
            </label>
            <EnumField
                label="Shape line style"
                value={node.style.dash}
                values={E.dash}
                onChange={(dash) =>
                    patchNode({ style: { ...node.style, dash } })
                }
            />
            <Field label="Opacity">
                <input
                    aria-label="Opacity"
                    type="range"
                    min="0"
                    max="1"
                    step=".05"
                    value={node.style.opacity}
                    onChange={(e) =>
                        patchNode({
                            style: {
                                ...node.style,
                                opacity: Number(e.target.value),
                            },
                        })
                    }
                />
            </Field>
            <div className="ds-fields-two">
                {(['x', 'y', 'w', 'h', 'rotation'] as const).map((key) => (
                    <DraftField
                        key={key}
                        label={
                            {
                                x: 'Shape X',
                                y: 'Shape Y',
                                w: 'Shape width',
                                h: 'Shape height',
                                rotation: 'Rotation',
                            }[key]
                        }
                        value={node[key]}
                        type="number"
                        onCommit={(v) => patchNode({ [key]: Number(v) })}
                    />
                ))}
            </div>
            <Field label="Shape layer">
                <select
                    aria-label="Shape layer"
                    value={node.layerId}
                    onChange={(e) => patchNode({ layerId: e.target.value })}
                >
                    {page.layers.map((l) => (
                        <option key={l.id} value={l.id}>
                            {l.name}
                        </option>
                    ))}
                </select>
            </Field>
        </fieldset>
    );
}
