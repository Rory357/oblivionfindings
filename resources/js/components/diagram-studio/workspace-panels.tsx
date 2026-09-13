import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { remapDiagramForImport } from '@/lib/diagram-studio/compatibility';
import type {
    DiagramNodeV2,
    DiagramPageV2,
    KnowledgeDiagramV2,
} from '@/lib/diagram-studio/contract';
import { DIAGRAM_ENUMS as E } from '@/lib/diagram-studio/contract';
import { editable } from '@/lib/diagram-studio/geometry';
import {
    blankPage,
    makeId,
    newNode,
    parseCsv,
} from '@/lib/diagram-studio/operations';
import { validateDiagramSource } from '@/lib/diagram-studio/validation';
import {
    ArrowDown,
    ArrowUp,
    Copy,
    Eye,
    EyeOff,
    Lock,
    Plus,
    Printer,
    Trash2,
    Unlock,
} from 'lucide-react';
import { useState } from 'react';
import { DraftField, EnumField, Field } from './editor-fields';

type PageChange = (
    label: string,
    change: (page: DiagramPageV2) => void,
) => boolean;
export function LayerTools({
    page,
    activeLayerId,
    setActiveLayer,
    enabled,
    changePage,
}: {
    page: DiagramPageV2;
    activeLayerId: string;
    setActiveLayer: (id: string) => void;
    enabled: boolean;
    changePage: PageChange;
}) {
    return (
        <div className="ds-layer-tools">
            <p>
                Hidden layers are skipped during selection. Unlock a layer
                before changing its shapes.
            </p>
            {page.layers.map((layer) => (
                <section key={layer.id} className="ds-layer-row">
                    <label className="ds-check">
                        <input
                            type="radio"
                            name={`layer-${page.id}`}
                            aria-label={`Draw on ${layer.name} layer`}
                            checked={activeLayerId === layer.id}
                            onChange={() => setActiveLayer(layer.id)}
                        />
                        {layer.name}
                    </label>
                    <div className="ds-button-row">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label={`${layer.visible ? 'Hide' : 'Show'} ${layer.name} layer`}
                            disabled={!enabled}
                            onClick={() =>
                                changePage('Updated layer visibility', (p) => {
                                    p.layers.find(
                                        (l) => l.id === layer.id,
                                    )!.visible = !layer.visible;
                                })
                            }
                        >
                            {layer.visible ? (
                                <Eye size={16} />
                            ) : (
                                <EyeOff size={16} />
                            )}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label={`${layer.locked ? 'Unlock' : 'Lock'} ${layer.name} layer`}
                            disabled={!enabled}
                            onClick={() =>
                                changePage('Updated layer lock', (p) => {
                                    p.layers.find(
                                        (l) => l.id === layer.id,
                                    )!.locked = !layer.locked;
                                })
                            }
                        >
                            {layer.locked ? (
                                <Lock size={16} />
                            ) : (
                                <Unlock size={16} />
                            )}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label={`${layer.print ? 'Exclude' : 'Include'} ${layer.name} in print`}
                            aria-pressed={layer.print}
                            disabled={!enabled}
                            onClick={() =>
                                changePage('Updated print inclusion', (p) => {
                                    p.layers.find(
                                        (l) => l.id === layer.id,
                                    )!.print = !layer.print;
                                })
                            }
                        >
                            <Printer size={16} />
                        </Button>
                    </div>
                    {activeLayerId === layer.id && (
                        <DraftField
                            label="Layer name"
                            value={layer.name}
                            disabled={!enabled}
                            onCommit={(name) =>
                                changePage('Renamed layer', (p) => {
                                    p.layers.find(
                                        (l) => l.id === layer.id,
                                    )!.name = name;
                                })
                            }
                        />
                    )}
                </section>
            ))}
            <Button
                type="button"
                variant="outline"
                disabled={!enabled || page.layers.length >= 16}
                onClick={() => {
                    const id = makeId();
                    if (
                        changePage('Added layer', (p) =>
                            p.layers.push({
                                id,
                                name: `Layer ${p.layers.length + 1}`,
                                visible: true,
                                locked: false,
                                print: true,
                            }),
                        )
                    )
                        setActiveLayer(id);
                }}
            >
                <Plus size={16} />
                Add layer
            </Button>
        </div>
    );
}

export function PageTools({
    value,
    page,
    enabled,
    canManage,
    commit,
    changePage,
    onPage,
}: {
    value: KnowledgeDiagramV2;
    page: DiagramPageV2;
    enabled: boolean;
    canManage: boolean;
    commit: (
        label: string,
        change: (value: KnowledgeDiagramV2) => void,
    ) => boolean;
    changePage: PageChange;
    onPage: (id: string) => void;
}) {
    return (
        <div className="ds-page-tools">
            <div className="ds-page-list">
                {value.pages.map((item, index) => (
                    <div className="ds-page-row" key={item.id}>
                        <Button
                            type="button"
                            variant={
                                item.id === page.id ? 'secondary' : 'ghost'
                            }
                            onClick={() => onPage(item.id)}
                        >
                            {index + 1}. {item.name}
                        </Button>
                        <div className="ds-button-row">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`Move ${item.name} page earlier`}
                                disabled={!enabled || !canManage || index === 0}
                                onClick={() =>
                                    commit('Reordered page', (d) => {
                                        [d.pages[index - 1], d.pages[index]] = [
                                            d.pages[index],
                                            d.pages[index - 1],
                                        ];
                                    })
                                }
                            >
                                <ArrowUp size={15} />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`Move ${item.name} page later`}
                                disabled={
                                    !enabled ||
                                    !canManage ||
                                    index === value.pages.length - 1
                                }
                                onClick={() =>
                                    commit('Reordered page', (d) => {
                                        [d.pages[index + 1], d.pages[index]] = [
                                            d.pages[index],
                                            d.pages[index + 1],
                                        ];
                                    })
                                }
                            >
                                <ArrowDown size={15} />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`Duplicate ${item.name} page`}
                                disabled={!enabled || !canManage}
                                onClick={() => {
                                    const imageIds = item.nodes.flatMap((n) =>
                                        n.imageFileId === null
                                            ? []
                                            : [n.imageFileId],
                                    );
                                    const clone = remapDiagramForImport(
                                        { ...value, pages: [item] },
                                        {
                                            imageFileIds: new Map(
                                                imageIds.map((id) => [id, id]),
                                            ),
                                        },
                                    ).pages[0];
                                    clone.name = `${item.name.slice(0, 153)} (copy)`;
                                    if (
                                        commit('Duplicated page', (d) =>
                                            d.pages.splice(index + 1, 0, clone),
                                        )
                                    )
                                        onPage(clone.id);
                                }}
                            >
                                <Copy size={15} />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`Delete ${item.name} page`}
                                disabled={
                                    !enabled ||
                                    !canManage ||
                                    value.pages.length < 2
                                }
                                onClick={() => {
                                    if (
                                        commit(
                                            'Deleted page; undo is available',
                                            (d) => {
                                                d.pages = d.pages.filter(
                                                    (p) => p.id !== item.id,
                                                );
                                            },
                                        )
                                    )
                                        onPage(
                                            value.pages.find(
                                                (p) => p.id !== item.id,
                                            )!.id,
                                        );
                                }}
                            >
                                <Trash2 size={15} />
                            </Button>
                        </div>
                    </div>
                ))}
            </div>
            <fieldset disabled={!enabled} className="ds-page-settings">
                <DraftField
                    label="Page name"
                    value={page.name}
                    onCommit={(name) =>
                        changePage('Renamed page', (p) => {
                            p.name = name;
                        })
                    }
                />
                <DraftField
                    label="Page description"
                    value={page.subtitle}
                    onCommit={(subtitle) =>
                        changePage('Updated page description', (p) => {
                            p.subtitle = subtitle || null;
                        })
                    }
                />
                <div className="ds-fields-two">
                    <DraftField
                        label="Page width"
                        type="number"
                        value={page.width}
                        onCommit={(v) =>
                            changePage('Changed page size', (p) => {
                                p.width = Number(v);
                            })
                        }
                    />
                    <DraftField
                        label="Page height"
                        type="number"
                        value={page.height}
                        onCommit={(v) =>
                            changePage('Changed page size', (p) => {
                                p.height = Number(v);
                            })
                        }
                    />
                    <EnumField
                        label="Paper"
                        value={page.paper}
                        values={E.paper}
                        onChange={(paper) =>
                            changePage('Changed paper', (p) => {
                                p.paper = paper;
                            })
                        }
                    />
                    <EnumField
                        label="Orientation"
                        value={page.orientation}
                        values={E.orientation}
                        onChange={(orientation) =>
                            changePage('Changed orientation', (p) => {
                                if (p.orientation !== orientation)
                                    [p.width, p.height] = [p.height, p.width];
                                p.orientation = orientation;
                            })
                        }
                    />
                </div>
                <DraftField
                    label="Descriptive scale"
                    value={page.scale}
                    onCommit={(scale) =>
                        changePage('Updated descriptive scale', (p) => {
                            p.scale = scale;
                        })
                    }
                />
                <DraftField
                    label="Grid spacing"
                    type="number"
                    value={page.grid.size}
                    onCommit={(v) =>
                        changePage('Updated grid spacing', (p) => {
                            p.grid.size = Number(v);
                        })
                    }
                />
                <Field label="Page colour">
                    <input
                        aria-label="Page colour"
                        type="color"
                        value={page.background}
                        onChange={(e) =>
                            changePage('Updated page colour', (p) => {
                                p.background = e.target.value;
                            })
                        }
                    />
                </Field>
                <p>
                    Scale is a drawing label. Site floor-plan calibration stays
                    with the site plan.
                </p>
            </fieldset>
        </div>
    );
}

export function DataTools({
    page,
    node,
    enabled,
    patchNode,
    changePage,
    addPage,
    canAddPage,
    report,
}: {
    page: DiagramPageV2;
    node?: DiagramNodeV2;
    enabled: boolean;
    patchNode: (patch: Partial<DiagramNodeV2>) => void;
    changePage: PageChange;
    addPage: (page: DiagramPageV2) => void;
    canAddPage: boolean;
    report: (error: unknown) => void;
}) {
    const [key, setKey] = useState(''),
        [text, setText] = useState(''),
        [csv, setCsv] = useState(''),
        [matching, setMatching] = useState(''),
        [preview, setPreview] = useState('');
    const loadCsv = (file: File) => {
        if (file.size > 262144) {
            report(new Error('Use a CSV file smaller than 256 KiB.'));
            return;
        }
        void file.text().then(setCsv).catch(report);
    };
    const link = (apply: boolean) => {
        try {
            const data = parseCsv(csv),
                column = matching || data.headers[0],
                index = data.headers.indexOf(column);
            if (index < 0)
                throw new Error('Choose a column to match shape labels.');
            const entries = new Map<string, string[]>();
            for (const row of data.rows) {
                const label = row[index].trim().toLowerCase();
                if (entries.has(label))
                    throw new Error('Use one CSV row per matching label.');
                entries.set(label, row);
            }
            const matches = page.nodes.filter(
                (n) =>
                    editable(n, page) &&
                    entries.has((n.text ?? '').trim().toLowerCase()),
            );
            setPreview(
                `${matches.length} editable shapes match ${data.rows.length} CSV rows.`,
            );
            if (!apply) return;
            changePage('Linked CSV properties', (p) => {
                for (const n of p.nodes.filter((n) => editable(n, p))) {
                    const row = entries.get(
                        (n.text ?? '').trim().toLowerCase(),
                    );
                    if (!row) continue;
                    const properties = new Map(
                        n.properties.map((prop) => [
                            prop.key.toLowerCase(),
                            prop,
                        ]),
                    );
                    data.headers.forEach((header, i) => {
                        if (i !== index)
                            properties.set(header.toLowerCase(), {
                                key: header,
                                value: row[i] || null,
                            });
                    });
                    n.properties = [...properties.values()];
                }
            });
        } catch (error) {
            report(error);
        }
    };
    const organisation = () => {
        try {
            const data = parseCsv(csv),
                headers = data.headers.map((h) => h.toLowerCase()),
                idIndex = headers.indexOf('id'),
                nameIndex = headers.indexOf('name'),
                parentIndex = headers.indexOf('manager'),
                roleIndex = headers.indexOf('role');
            if ([idIndex, nameIndex, parentIndex].some((i) => i < 0))
                throw new Error(
                    'Organisation CSV needs id, name and manager columns; role is optional.',
                );
            if (data.rows.length > 80)
                throw new Error('This drawing supports at most 80 people.');
            const rows = new Map(
                data.rows.map((row) => [row[idIndex].trim(), row]),
            );
            if (rows.size !== data.rows.length || rows.has(''))
                throw new Error('Each person needs a unique ID.');
            const depth = new Map<string, number>();
            for (const [id, row] of rows) {
                const seen = new Set([id]);
                let parent = row[parentIndex].trim(),
                    level = 0;
                while (parent) {
                    if (seen.has(parent))
                        throw new Error('The reporting lines contain a cycle.');
                    const next = rows.get(parent);
                    if (!next)
                        throw new Error(
                            'A manager ID does not exist in the CSV.',
                        );
                    seen.add(parent);
                    level++;
                    parent = next[parentIndex].trim();
                }
                depth.set(id, level);
            }
            const created = blankPage('Organisation chart'),
                layers = new Map<number, string[]>();
            for (const [id, level] of depth)
                layers.set(level, [...(layers.get(level) ?? []), id]);
            created.width = Math.min(
                10000,
                Math.max(
                    1080,
                    Math.max(...[...layers.values()].map((ids) => ids.length)) *
                        240 +
                        80,
                ),
            );
            created.height = Math.min(
                10000,
                Math.max(680, (Math.max(...depth.values()) + 1) * 150 + 100),
            );
            const mapping = new Map<string, string>();
            for (const [level, ids] of layers)
                ids.forEach((id, i) => {
                    const row = rows.get(id)!;
                    const label =
                        row[nameIndex] +
                        (roleIndex >= 0 && row[roleIndex]
                            ? `\n${row[roleIndex]}`
                            : '');
                    const node = newNode(
                        'person',
                        created.layers[0].id,
                        40 +
                            ((created.width - 80) * (i + 0.5)) / ids.length -
                            105,
                        60 + level * 150,
                        label,
                    );
                    node.w = 210;
                    node.h = 90;
                    mapping.set(id, node.id);
                    created.nodes.push(node);
                });
            for (const [id, row] of rows) {
                const parent = row[parentIndex].trim();
                if (parent)
                    created.edges.push({
                        id: makeId(),
                        from: mapping.get(parent)!,
                        to: mapping.get(id)!,
                        label: null,
                        layerId: created.layers[0].id,
                        route: 'orthogonal',
                        fromPort: 'bottom',
                        toPort: 'top',
                        arrow: 'none',
                        startArrow: 'none',
                        stroke: '#83749f',
                        width: 1.8,
                        dash: 'solid',
                        bend: 0,
                        labelPos: 0.5,
                    });
            }
            addPage(created);
        } catch (error) {
            report(error);
        }
    };
    let headers: string[] = [];
    try {
        headers = csv ? parseCsv(csv).headers : [];
    } catch {
        /* Error is shown when previewing/applying, not while typing. */
    }
    return (
        <div className="ds-data-tools">
            {node && (
                <fieldset disabled={!enabled}>
                    <h3>Shape properties</h3>
                    {node.properties.map((prop, index) => (
                        <div className="ds-property-row" key={prop.key}>
                            <DraftField
                                label={prop.key}
                                value={prop.value}
                                onCommit={(value) =>
                                    patchNode({
                                        properties: node.properties.map(
                                            (p, i) =>
                                                i === index
                                                    ? {
                                                          ...p,
                                                          value: value || null,
                                                      }
                                                    : p,
                                        ),
                                    })
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`Remove ${prop.key} field`}
                                onClick={() =>
                                    patchNode({
                                        properties: node.properties.filter(
                                            (_, i) => i !== index,
                                        ),
                                        dataGraphic:
                                            node.dataGraphic === prop.key
                                                ? null
                                                : node.dataGraphic,
                                    })
                                }
                            >
                                <Trash2 size={15} />
                            </Button>
                        </div>
                    ))}
                    <div className="ds-fields-two">
                        <Field label="New field name">
                            <Input
                                aria-label="New field name"
                                value={key}
                                onChange={(e) => setKey(e.target.value)}
                            />
                        </Field>
                        <Field label="New field value">
                            <Input
                                aria-label="New field value"
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                            />
                        </Field>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!key.trim()}
                        onClick={() => {
                            patchNode({
                                properties: [
                                    ...node.properties,
                                    { key: key.trim(), value: text || null },
                                ],
                            });
                            setKey('');
                            setText('');
                        }}
                    >
                        <Plus size={16} />
                        Add field
                    </Button>
                    <Field label="Percentage bar field">
                        <select
                            aria-label="Percentage bar field"
                            value={node.dataGraphic ?? ''}
                            onChange={(e) =>
                                patchNode({
                                    dataGraphic: e.target.value || null,
                                })
                            }
                        >
                            <option value="">None</option>
                            {node.properties.map((p) => (
                                <option key={p.key} value={p.key}>
                                    {p.key}
                                </option>
                            ))}
                        </select>
                    </Field>
                </fieldset>
            )}
            <fieldset disabled={!enabled}>
                <h3>Link CSV data</h3>
                <p>
                    Match one column to shape labels. Other columns become
                    plain-text properties.
                </p>
                <Field label="Choose CSV file">
                    <input
                        aria-label="Choose CSV file"
                        type="file"
                        accept=".csv,text/csv"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (file) loadCsv(file);
                        }}
                    />
                </Field>
                <Field label="CSV data">
                    <Textarea
                        aria-label="CSV data"
                        value={csv}
                        onChange={(e) => setCsv(e.target.value)}
                        rows={7}
                        placeholder={
                            'label,owner,progress\nTriage & assign,Service desk,75'
                        }
                    />
                </Field>
                <Field label="Match column">
                    <select
                        aria-label="Match column"
                        value={matching || headers[0] || ''}
                        onChange={(e) => setMatching(e.target.value)}
                    >
                        {headers.map((h) => (
                            <option key={h} value={h}>
                                {h}
                            </option>
                        ))}
                    </select>
                </Field>
                <div className="ds-button-row">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!csv}
                        onClick={() => link(false)}
                    >
                        Preview matches
                    </Button>
                    <Button
                        type="button"
                        disabled={!csv}
                        onClick={() => link(true)}
                    >
                        Apply CSV
                    </Button>
                </div>
                {preview && <p role="status">{preview}</p>}
                <h3>Organisation chart</h3>
                <p>
                    Use id, name and manager columns. Each person must have a
                    unique ID; leave manager blank for a top-level role.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    disabled={!canAddPage || !csv}
                    onClick={organisation}
                >
                    Create organisation page
                </Button>
            </fieldset>
        </div>
    );
}

export function ValidationTools({
    value,
    page,
    onSelect,
}: {
    value: KnowledgeDiagramV2;
    page: DiagramPageV2;
    onSelect: (id: string) => void;
}) {
    const sourceIssues = validateDiagramSource(value).issues;
    const warnings = page.nodes.flatMap((n) => {
        const rows: { id: string; message: string }[] = [];
        if (
            !n.text?.trim() &&
            ['process', 'task', 'person', 'class', 'entity'].includes(n.type)
        )
            rows.push({ id: n.id, message: `Add a label to this ${n.type}.` });
        if (
            n.x < 0 ||
            n.y < 0 ||
            n.x + n.w > page.width ||
            n.y + n.h > page.height
        )
            rows.push({
                id: n.id,
                message: `${n.text?.split('\n')[0] || n.type} extends beyond the page.`,
            });
        if (
            ['decision', 'gateway'].includes(n.type) &&
            page.edges.filter((e) => e.from === n.id).length < 2
        )
            rows.push({
                id: n.id,
                message:
                    'This decision has fewer than two outgoing connectors.',
            });
        return rows;
    });
    return (
        <div className="ds-validation">
            <p>
                Checks cover drawing structure and readability. They do not
                certify a process, engineering design or emergency plan.
            </p>
            {sourceIssues.length === 0 && (
                <p className="ds-notice">
                    Page, layer and connector references are valid.
                </p>
            )}
            {sourceIssues.map((issue, i) => (
                <p key={i} role="alert">
                    {issue.message}
                </p>
            ))}
            {warnings.length ? (
                <ul>
                    {warnings.map((warning, i) => (
                        <li key={i}>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onSelect(warning.id)}
                            >
                                {warning.message}
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : (
                <p>No label, boundary or decision warnings on this page.</p>
            )}
        </div>
    );
}
