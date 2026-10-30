import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    EyeOff,
    FileText,
    LayoutPanelTop,
    ListTree,
    MousePointer2,
    Plus,
} from 'lucide-react';
import { blockDetails as details } from './_block-catalog';
import type { DesignerSelection } from './_designer';
import { blockLibrary, blockSpan } from './_layout';
import type { HubSection } from './_types';

export { BlockLibraryPicker } from './_block-picker';

export function PageOutline({
    sections,
    selection,
    onSelect,
    onAddBlock,
    onAddSection,
}: {
    sections: HubSection[];
    selection: DesignerSelection;
    onSelect: (selection: DesignerSelection) => void;
    onAddBlock: (sectionId: string) => void;
    onAddSection: () => void;
}) {
    const blockCount = sections.reduce(
        (count, section) => count + section.blocks.length,
        0,
    );
    return (
        <div className="hub-page-outline">
            <div className="hub-outline-heading">
                <ListTree className="size-4" aria-hidden="true" />
                <h2>Your page at a glance</h2>
            </div>
            <p className="text-xs text-muted-foreground">
                {sections.length}{' '}
                {sections.length === 1 ? 'section' : 'sections'} · {blockCount}{' '}
                {blockCount === 1 ? 'block' : 'blocks'}
            </p>
            {sections.map((section, index) => (
                <div className="hub-outline-section" key={section.id}>
                    <Button
                        type="button"
                        variant="ghost"
                        className="hub-outline-section-title h-auto justify-start whitespace-normal"
                        aria-label={`Go to section ${section.title || index + 1}`}
                        aria-pressed={
                            selection?.section === section.id &&
                            !selection.block
                        }
                        onClick={() => onSelect({ section: section.id })}
                    >
                        <LayoutPanelTop className="size-4 shrink-0" />
                        <span>{section.title || `Section ${index + 1}`}</span>
                        {section.hidden ? (
                            <span className="text-xs">Hidden</span>
                        ) : (
                            <small>{section.blocks.length}</small>
                        )}
                    </Button>
                    {section.blocks.map((block, blockIndex) => {
                        const Icon = details[block.type]?.icon ?? FileText;
                        const label =
                            block.title ||
                            blockLibrary.find(
                                ([type]) => type === block.type,
                            )?.[1] ||
                            'Content';
                        const span = blockSpan(section, block, blockIndex);
                        return (
                            <Button
                                type="button"
                                variant="ghost"
                                className="hub-outline-block h-auto justify-start whitespace-normal"
                                key={block.id}
                                aria-label={`Go to ${label}${block.hidden || section.hidden ? ' (hidden from staff)' : ''}`}
                                aria-pressed={selection?.block === block.id}
                                onClick={() =>
                                    onSelect({
                                        section: section.id,
                                        block: block.id,
                                    })
                                }
                            >
                                <Icon className="size-4 shrink-0" />
                                <span>{label}</span>
                                {block.hidden || section.hidden ? (
                                    <EyeOff
                                        className="size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <small>
                                        {span === 12 ? 'Full' : `${span}/12`}
                                    </small>
                                )}
                            </Button>
                        );
                    })}
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={section.blocks.length >= 12}
                        onClick={() => onAddBlock(section.id)}
                        aria-label={`Add block to ${section.title || `section ${index + 1}`}`}
                    >
                        <Plus className="size-3.5" />
                        Add block
                    </Button>
                </div>
            ))}
            <Button
                variant="outline"
                disabled={sections.length >= 12}
                onClick={onAddSection}
            >
                <Plus className="size-4" />
                Add section
            </Button>
        </div>
    );
}

export function DesignerShortcuts({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>A few useful shortcuts</DialogTitle>
                    <DialogDescription>
                        Arrange your shared page with a mouse or keyboard.
                    </DialogDescription>
                </DialogHeader>
                <dl className="hub-designer-shortcuts">
                    <div>
                        <dt>
                            <MousePointer2 className="size-4" />
                            Move a block
                        </dt>
                        <dd>Drag its grip to a new position or section.</dd>
                    </div>
                    <div>
                        <dt>Resize a block</dt>
                        <dd>
                            Drag the bottom corner, or an edge for one
                            dimension. On its resize handle, use Left and Right
                            for width, Up and Down for minimum height. Escape
                            cancels a drag. Settings also has width and height
                            controls.
                        </dd>
                    </div>
                    <div>
                        <dt>Move with the keyboard</dt>
                        <dd>
                            Focus a grip, press Space, use the arrow keys, then
                            Enter to place. Escape cancels.
                        </dd>
                    </div>
                    <div>
                        <dt>Undo and redo</dt>
                        <dd>
                            Use the toolbar, or Ctrl / ⌘ Z and Shift Ctrl / ⌘ Z.
                            While typing, these keys undo your text normally.
                        </dd>
                    </div>
                </dl>
                <Button onClick={onClose}>Got it</Button>
            </DialogContent>
        </Dialog>
    );
}
