import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    rectSortingStrategy,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    ArrowDown,
    ArrowUp,
    Copy,
    GripVertical,
    Plus,
    Settings2,
    Trash2,
} from 'lucide-react';
import { useState, type CSSProperties } from 'react';
import { BlockContent } from './_blocks';
import {
    blockLibrary,
    blockSpan,
    createBlock,
    duplicateSection,
    moveBlock,
} from './_layout';
import type { HubBlock, HubProps, HubSection } from './_types';

export type DesignerSelection = { section: string; block?: string } | null;
type DesignerProps = {
    sections: HubSection[];
    data: HubProps;
    selection: DesignerSelection;
    onSelect: (selection: DesignerSelection) => void;
    onChange: (sections: HubSection[]) => void;
};

export function HubDesigner(props: DesignerProps) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
    const drag = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) return;
        const id = String(active.id),
            target = String(over.id);
        if (id.startsWith('section:') && target.startsWith('section:')) {
            const from = props.sections.findIndex(
                    (s) => `section:${s.id}` === id,
                ),
                to = props.sections.findIndex(
                    (s) => `section:${s.id}` === target,
                );
            if (from >= 0 && to >= 0)
                props.onChange(arrayMove(props.sections, from, to));
        } else if (id.startsWith('block:')) {
            const destination = props.sections.find(
                (s) =>
                    `section:${s.id}` === target ||
                    `drop:${s.id}` === target ||
                    s.blocks.some((b) => `block:${b.id}` === target),
            );
            if (!destination) return;
            const index = destination.blocks.findIndex(
                (b) => `block:${b.id}` === target,
            );
            props.onChange(
                moveBlock(
                    props.sections,
                    id.slice(6),
                    destination.id,
                    index < 0 ? destination.blocks.length : index,
                ),
            );
            props.onSelect({ section: destination.id, block: id.slice(6) });
        }
    };
    return (
        <DndContext
            sensors={sensors}
            collisionDetection={(args) =>
                closestCenter({
                    ...args,
                    droppableContainers: args.droppableContainers.filter(
                        (container) =>
                            String(args.active.id).startsWith('section:')
                                ? String(container.id).startsWith('section:')
                                : !String(container.id).startsWith('section:'),
                    ),
                })
            }
            onDragEnd={drag}
        >
            <SortableContext
                items={props.sections.map((s) => `section:${s.id}`)}
                strategy={verticalListSortingStrategy}
            >
                <div className="space-y-5">
                    {props.sections.map((section, index) => (
                        <DesignerSection
                            key={section.id}
                            {...props}
                            section={section}
                            index={index}
                        />
                    ))}
                </div>
            </SortableContext>
            <Button
                className="mt-5 w-full border-dashed"
                variant="outline"
                disabled={props.sections.length >= 12}
                onClick={() => {
                    const section = {
                        id: crypto.randomUUID(),
                        title: 'New section',
                        columns: 1,
                        blocks: [createBlock('text')],
                    };
                    props.onChange([...props.sections, section]);
                    props.onSelect({ section: section.id });
                }}
            >
                <Plus className="size-4" />
                Add section
            </Button>
        </DndContext>
    );
}

function DesignerSection(
    props: DesignerProps & { section: HubSection; index: number },
) {
    const { section, index, sections, selection, onChange, onSelect } = props;
    const {
        setNodeRef,
        transform,
        transition,
        isDragging,
        attributes,
        listeners,
    } = useSortable({ id: `section:${section.id}` });
    const [type, setType] = useState('text');
    const select = () => onSelect({ section: section.id });
    const { setNodeRef: dropRef } = useDroppable({ id: `drop:${section.id}` });
    return (
        <section
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition: transition,
            }}
            className="hub-designer-section"
            data-dragging={isDragging}
            data-selected={
                selection?.section === section.id && !selection.block
            }
        >
            <div className="hub-designer-toolbar">
                <Button
                    variant="ghost"
                    size="icon"
                    {...attributes}
                    {...listeners}
                    aria-label={`Drag ${section.title || `section ${index + 1}`}`}
                >
                    <GripVertical className="size-4" />
                </Button>
                <strong>{section.title || `Section ${index + 1}`}</strong>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Section settings"
                    onClick={select}
                >
                    <Settings2 className="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Move section up"
                    disabled={index === 0}
                    onClick={() =>
                        onChange(arrayMove(sections, index, index - 1))
                    }
                >
                    <ArrowUp className="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Move section down"
                    disabled={index === sections.length - 1}
                    onClick={() =>
                        onChange(arrayMove(sections, index, index + 1))
                    }
                >
                    <ArrowDown className="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Duplicate section"
                    disabled={sections.length >= 12}
                    onClick={() => {
                        const next = [...sections];
                        next.splice(index + 1, 0, duplicateSection(section));
                        onChange(next);
                    }}
                >
                    <Copy className="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Remove section"
                    onClick={() => {
                        onChange(sections.filter((s) => s.id !== section.id));
                        onSelect(null);
                    }}
                >
                    <Trash2 className="size-4" />
                </Button>
            </div>
            <SortableContext
                items={section.blocks.map((b) => `block:${b.id}`)}
                strategy={rectSortingStrategy}
            >
                <div
                    className="hub-section-layout"
                    data-tone={section.tone}
                    data-spacing={section.spacing}
                >
                    {section.blocks.map((block, i) => (
                        <DesignerBlock
                            key={block.id}
                            {...props}
                            block={block}
                            blockIndex={i}
                        />
                    ))}
                </div>
            </SortableContext>
            <div ref={dropRef} className="hub-designer-add">
                {!section.blocks.length && (
                    <div className="hub-designer-drop">Drop a block here</div>
                )}
                <select
                    className="hub-native-select"
                    aria-label={`Block type for ${section.title || `section ${index + 1}`}`}
                    value={type}
                    onChange={(e) => setType(e.target.value)}
                >
                    {blockLibrary.map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
                <Button
                    variant="outline"
                    disabled={section.blocks.length >= 12}
                    onClick={() => {
                        const block = createBlock(type);
                        onChange(
                            sections.map((s) =>
                                s.id === section.id
                                    ? { ...s, blocks: [...s.blocks, block] }
                                    : s,
                            ),
                        );
                        onSelect({ section: section.id, block: block.id });
                    }}
                >
                    <Plus className="size-4" />
                    Add block
                </Button>
            </div>
        </section>
    );
}

function DesignerBlock({
    block,
    blockIndex,
    section,
    sections,
    data,
    selection,
    onSelect,
    onChange,
}: DesignerProps & {
    section: HubSection;
    block: HubBlock;
    blockIndex: number;
}) {
    const {
        setNodeRef,
        transform,
        transition,
        isDragging,
        attributes,
        listeners,
    } = useSortable({ id: `block:${block.id}` });
    const style = {
        gridColumn: `span ${blockSpan(section, block, blockIndex)}`,
        transform: CSS.Transform.toString(transform),
        transition: transition,
    } as CSSProperties;
    return (
        <article
            ref={setNodeRef}
            style={style}
            className="hub-designer-block"
            data-dragging={isDragging}
            data-selected={selection?.block === block.id}
        >
            <div className="hub-designer-block-toolbar">
                <Button
                    size="icon"
                    variant="ghost"
                    {...attributes}
                    {...listeners}
                    aria-label={`Drag ${block.title}`}
                >
                    <GripVertical className="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    onClick={() =>
                        onSelect({ section: section.id, block: block.id })
                    }
                    aria-label={`Edit ${block.title}`}
                >
                    <Settings2 className="size-3" />
                    <span className="truncate">{block.title}</span>
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Duplicate ${block.title}`}
                    disabled={section.blocks.length >= 12}
                    onClick={() => {
                        const copy = {
                            ...structuredClone(block),
                            id: crypto.randomUUID(),
                        };
                        onChange(
                            sections.map((s) => {
                                if (s.id !== section.id) return s;
                                const blocks = [...s.blocks];
                                blocks.splice(blockIndex + 1, 0, copy);
                                return { ...s, blocks };
                            }),
                        );
                        onSelect({ section: section.id, block: copy.id });
                    }}
                >
                    <Copy className="size-3" />
                </Button>
            </div>
            <div className="hub-designer-render" inert>
                <div className="hub-section-heading">
                    <h2 className="text-section-title">{block.title}</h2>
                </div>
                {block.type === 'text' && !block.body ? (
                    <p className="text-sm text-muted-foreground">
                        Select this block to write your content.
                    </p>
                ) : (
                    <BlockContent
                        block={block}
                        data={{ ...data, participation: null }}
                        onEvent={() => {}}
                    />
                )}
            </div>
            <label className="hub-block-resize">
                Width
                <input
                    type="range"
                    min={3}
                    max={12}
                    step={1}
                    value={blockSpan(section, block, blockIndex)}
                    aria-label={`Width of ${block.title}`}
                    onChange={(event) =>
                        onChange(
                            sections.map((s) =>
                                s.id === section.id
                                    ? {
                                          ...s,
                                          blocks: s.blocks.map((b) =>
                                              b.id === block.id
                                                  ? {
                                                        ...b,
                                                        width: Number(
                                                            event.target.value,
                                                        ),
                                                    }
                                                  : b,
                                          ),
                                      }
                                    : s,
                            ),
                        )
                    }
                />
                <span>{blockSpan(section, block, blockIndex)}/12</span>
            </label>
        </article>
    );
}

export function SectionSettings({
    section,
    onChange,
}: {
    section: HubSection;
    onChange: (update: Partial<HubSection>) => void;
}) {
    return (
        <div className="space-y-4">
            <h2 className="text-section-title">Section settings</h2>
            <Label className="grid gap-2">
                Section name
                <Input
                    value={section.title ?? ''}
                    maxLength={100}
                    onChange={(e) => onChange({ title: e.target.value })}
                />
            </Label>
            <Label className="grid gap-2">
                Columns
                <select
                    className="hub-native-select"
                    value={section.columns}
                    onChange={(e) =>
                        onChange({
                            columns: Number(e.target.value),
                            blocks: section.blocks.map((b) => ({
                                ...b,
                                width: undefined,
                            })),
                        })
                    }
                >
                    {[1, 2, 3].map((n) => (
                        <option key={n} value={n}>
                            {n} {n === 1 ? 'column' : 'columns'}
                        </option>
                    ))}
                </select>
            </Label>
            {section.columns === 2 && (
                <Label className="grid gap-2">
                    Column balance
                    <select
                        className="hub-native-select"
                        value={section.layout ?? 'equal'}
                        onChange={(e) =>
                            onChange({
                                layout: e.target.value as HubSection['layout'],
                            })
                        }
                    >
                        <option value="equal">Equal columns</option>
                        <option value="wide-left">Wide left</option>
                        <option value="wide-right">Wide right</option>
                    </select>
                </Label>
            )}
            <Label className="grid gap-2">
                Background
                <select
                    className="hub-native-select"
                    value={section.tone ?? 'plain'}
                    onChange={(e) =>
                        onChange({ tone: e.target.value as HubSection['tone'] })
                    }
                >
                    <option value="plain">Page background</option>
                    <option value="soft">Soft contrast</option>
                    <option value="brand">Company colour tint</option>
                </select>
            </Label>
            <Label className="grid gap-2">
                Spacing
                <select
                    className="hub-native-select"
                    value={section.spacing ?? 'comfortable'}
                    onChange={(e) =>
                        onChange({
                            spacing: e.target.value as HubSection['spacing'],
                        })
                    }
                >
                    <option value="compact">Compact</option>
                    <option value="comfortable">Comfortable</option>
                    <option value="spacious">Spacious</option>
                </select>
            </Label>
        </div>
    );
}
