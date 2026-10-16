import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
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
    Eye,
    EyeOff,
    GripVertical,
    Plus,
    Settings2,
    Trash2,
} from 'lucide-react';
import { useRef, useState, type CSSProperties } from 'react';
import { BlockContent } from './_blocks';
import {
    BlockResizeHandle,
    blockWidthLabel,
    type BlockSize,
} from './_designer-resize';
import {
    blockLibrary,
    blockSpan,
    duplicateSection,
    moveBlock,
} from './_layout';
import { HubSelect } from './_select';
import type { HubBlock, HubProps, HubSection } from './_types';

export type DesignerSelection = { section: string; block?: string } | null;
type DesignerProps = {
    sections: HubSection[];
    data: HubProps;
    selection: DesignerSelection;
    onSelect: (selection: DesignerSelection) => void;
    onChange: (sections: HubSection[]) => void;
    onAddBlock: (sectionId: string) => void;
    onAddSection: () => void;
    onSectionRemoved?: () => void;
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
                onClick={props.onAddSection}
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
    const [removeOpen, setRemoveOpen] = useState(false);
    const removeConfirmed = useRef(false);
    const {
        setNodeRef,
        transform,
        transition,
        isDragging,
        attributes,
        listeners,
    } = useSortable({ id: `section:${section.id}` });
    const select = () => onSelect({ section: section.id });
    const { setNodeRef: dropRef } = useDroppable({ id: `drop:${section.id}` });
    return (
        <section
            id={`hub-designer-section-${section.id}`}
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition: transition,
            }}
            className="hub-designer-section"
            data-dragging={isDragging}
            data-hidden={section.hidden || undefined}
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
                {section.hidden && (
                    <span className="text-xs text-muted-foreground">
                        Hidden from staff
                    </span>
                )}
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={
                        section.hidden ? 'Show section' : 'Hide section'
                    }
                    onClick={() =>
                        onChange(
                            sections.map((item) =>
                                item.id === section.id
                                    ? { ...item, hidden: !item.hidden }
                                    : item,
                            ),
                        )
                    }
                >
                    {section.hidden ? (
                        <Eye className="size-4" />
                    ) : (
                        <EyeOff className="size-4" />
                    )}
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Section settings"
                    data-designer-edit
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
                    onClick={() => setRemoveOpen(true)}
                >
                    <Trash2 className="size-4" />
                </Button>
            </div>
            <Dialog open={removeOpen} onOpenChange={setRemoveOpen}>
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        if (removeConfirmed.current) event.preventDefault();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Remove {section.title || 'this section'}?
                        </DialogTitle>
                        <DialogDescription>
                            This removes the section and its{' '}
                            {section.blocks.length}{' '}
                            {section.blocks.length === 1 ? 'block' : 'blocks'}{' '}
                            from your draft. You can use Undo to bring it back.
                            The published page stays unchanged.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setRemoveOpen(false)}
                        >
                            Keep section
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                removeConfirmed.current = true;
                                onChange(
                                    sections.filter(
                                        (item) => item.id !== section.id,
                                    ),
                                );
                                onSelect(null);
                                props.onSectionRemoved?.();
                            }}
                        >
                            Remove section and blocks
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
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
                <Button
                    variant="outline"
                    className="w-full border-dashed"
                    disabled={section.blocks.length >= 12}
                    aria-label={`Add block to ${section.title || `section ${index + 1}`}`}
                    onClick={() => props.onAddBlock(section.id)}
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
    const label =
        block.title ||
        blockLibrary.find(([type]) => type === block.type)?.[1] ||
        'Content';
    const [resizing, setResizing] = useState<BlockSize | null>(null);
    const size = {
        width: blockSpan(section, block, blockIndex),
        min_height: block.min_height ?? 0,
    };
    const displayed = resizing ?? size;
    const resize = (next: BlockSize) =>
        onChange(
            sections.map((item) =>
                item.id === section.id
                    ? {
                          ...item,
                          blocks: item.blocks.map((current) =>
                              current.id === block.id
                                  ? {
                                        ...current,
                                        ...(next.width !== size.width
                                            ? { width: next.width }
                                            : {}),
                                        ...(next.min_height !== size.min_height
                                            ? { min_height: next.min_height }
                                            : {}),
                                    }
                                  : current,
                          ),
                      }
                    : item,
            ),
        );
    const style = {
        gridColumn: `span ${displayed.width}`,
        transform: CSS.Transform.toString(transform),
        transition: transition,
    } as CSSProperties;
    return (
        <article
            id={`hub-designer-block-${block.id}`}
            ref={setNodeRef}
            style={style}
            className="hub-designer-block"
            data-dragging={isDragging}
            data-selected={selection?.block === block.id}
            data-hidden={block.hidden || undefined}
            data-resizing={!!resizing}
        >
            <div className="hub-designer-block-toolbar">
                <Button
                    size="icon"
                    variant="ghost"
                    {...attributes}
                    {...listeners}
                    aria-label={`Drag ${label}`}
                >
                    <GripVertical className="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    onClick={() =>
                        onSelect({ section: section.id, block: block.id })
                    }
                    aria-label={`Edit ${label}`}
                    data-designer-edit
                >
                    <Settings2 className="size-3" />
                    <span className="truncate">{label}</span>
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Duplicate ${label}`}
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
            <div
                className="hub-designer-render"
                data-block-type={block.type}
                data-tone={block.tone ?? 'plain'}
                style={{ minHeight: displayed.min_height || undefined }}
                inert
            >
                {block.hidden && (
                    <p className="mb-3 text-xs font-semibold text-muted-foreground">
                        Hidden from staff · kept in your design
                    </p>
                )}
                {block.type !== 'featured' && (
                    <div className="hub-section-heading">
                        <h2 className="text-section-title">{block.title}</h2>
                    </div>
                )}
                {block.type === 'text' && !block.body ? (
                    <p className="text-sm text-muted-foreground">
                        Select this block to write your content.
                    </p>
                ) : (
                    <BlockContent
                        preview
                        block={resizing ? { ...block, ...resizing } : block}
                        data={{
                            ...data,
                            participation: null,
                        }}
                        onEvent={() => {}}
                    />
                )}
            </div>
            <div className="hub-block-size">
                <span
                    className="hub-block-size-value"
                    aria-label={`Size of ${label}`}
                >
                    {blockWidthLabel(displayed.width)} ·{' '}
                    {displayed.min_height
                        ? `${displayed.min_height}px min`
                        : 'Auto height'}
                </span>
                <span className="hub-block-size-hint">
                    Drag corner to resize
                </span>
            </div>
            <BlockResizeHandle
                label={label}
                size={size}
                onPreview={setResizing}
                onCommit={resize}
                onSelect={() =>
                    onSelect({ section: section.id, block: block.id })
                }
            />
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
            <Label className="flex items-start justify-between gap-4 rounded-lg border p-3">
                <span>
                    <span className="block">Show section to staff</span>
                    <span className="mt-1 block text-xs font-normal text-muted-foreground">
                        Hidden sections stay in your draft, including their
                        blocks. Publish to update what staff see.
                    </span>
                </span>
                <Switch
                    checked={!section.hidden}
                    onCheckedChange={(visible) =>
                        onChange({ hidden: !visible })
                    }
                />
            </Label>
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
                <HubSelect
                    label="Columns"
                    value={String(section.columns)}
                    onChange={(value) =>
                        onChange({
                            columns: Number(value),
                            blocks: section.blocks.map((b) => ({
                                ...b,
                                width: undefined,
                            })),
                        })
                    }
                    options={[1, 2, 3].map((n) => ({
                        value: String(n),
                        label: `${n} ${n === 1 ? 'column' : 'columns'}`,
                    }))}
                />
            </Label>
            {section.columns === 2 && (
                <Label className="grid gap-2">
                    Column balance
                    <HubSelect
                        label="Column balance"
                        value={section.layout ?? 'equal'}
                        onChange={(value) =>
                            onChange({
                                layout: value as HubSection['layout'],
                            })
                        }
                        options={[
                            { value: 'equal', label: 'Equal columns' },
                            { value: 'wide-left', label: 'Wide left' },
                            { value: 'wide-right', label: 'Wide right' },
                        ]}
                    />
                </Label>
            )}
            <Label className="grid gap-2">
                Background
                <HubSelect
                    label="Background"
                    value={section.tone ?? 'plain'}
                    onChange={(value) =>
                        onChange({ tone: value as HubSection['tone'] })
                    }
                    options={[
                        { value: 'plain', label: 'Page background' },
                        { value: 'soft', label: 'Soft contrast' },
                        { value: 'brand', label: 'Company colour tint' },
                    ]}
                />
            </Label>
            <Label className="grid gap-2">
                Spacing
                <HubSelect
                    label="Spacing"
                    value={section.spacing ?? 'comfortable'}
                    onChange={(value) =>
                        onChange({
                            spacing: value as HubSection['spacing'],
                        })
                    }
                    options={[
                        { value: 'compact', label: 'Compact' },
                        { value: 'comfortable', label: 'Comfortable' },
                        { value: 'spacious', label: 'Spacious' },
                    ]}
                />
            </Label>
        </div>
    );
}
