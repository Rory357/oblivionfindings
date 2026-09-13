import { Button } from '@/components/ui/button';
import type {
    DiagramRendererProps,
    DiagramStudioProps,
} from '@/lib/diagram-studio/host-api';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    KnowledgeDiagrams,
    type KnowledgeDiagram,
} from '../knowledge-diagrams';

const studio = vi.hoisted(() => ({ props: null as DiagramStudioProps | null }));
vi.mock('@/components/diagram-studio/diagram-studio', () => ({
    DiagramStudio: (props: DiagramStudioProps) => {
        studio.props = props;
        return (
            <div>
                <Button
                    type="button"
                    onClick={() =>
                        props.onChange?.(
                            { ...props.value, title: 'Edited drawing' },
                            {
                                recordKey: props.recordKey,
                                kind: 'edit',
                                label: 'Rename drawing',
                                imageFileIds: [],
                            },
                        )
                    }
                >
                    Make a drawing edit
                </Button>
                {props.hostActions}
            </div>
        );
    },
}));
vi.mock('@/components/diagram-studio/diagram-renderer', () => ({
    DiagramRenderer: (props: DiagramRendererProps) => (
        <div role="img" aria-label={props.value.title}>
            {props.pageId}
        </div>
    ),
}));
afterEach(() => {
    cleanup();
    studio.props = null;
});

function legacy(): KnowledgeDiagram {
    return {
        id: crypto.randomUUID(),
        title: 'Legacy recovery diagram',
        nodes: [
            {
                id: crypto.randomUUID(),
                shape: 'rectangle',
                text: 'Router',
                x: 20,
                y: 20,
            },
        ],
        edges: [],
    };
}

it('opens and closes a legacy drawing without persisting an implicit format upgrade', () => {
    const source = legacy();
    const before = JSON.stringify(source);
    const changed = vi.fn();
    render(
        <KnowledgeDiagrams
            diagrams={[source]}
            onChange={changed}
            recordKey="7:12:editor"
        />,
    );
    expect(screen.getByRole('img', { name: source.title })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Edit diagram' }));
    expect(studio.props?.value.schema_version).toBe(2);
    fireEvent.click(screen.getByRole('button', { name: 'Done editing' }));
    expect(changed).not.toHaveBeenCalled();
    expect(JSON.stringify(source)).toBe(before);
});

it('routes explicit drawing edits into the document buffer without submitting its form', () => {
    const submit = vi.fn();
    const original = legacy();
    let saved: KnowledgeDiagram[] = [];
    function Document() {
        const [value, setValue] = useState([original]);
        return (
            <form onSubmit={submit}>
                <KnowledgeDiagrams
                    diagrams={value}
                    recordKey="7:12:editor"
                    onChange={(next) => {
                        saved = next;
                        setValue(next);
                    }}
                />
            </form>
        );
    }
    render(<Document />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit diagram' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Make a drawing edit' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Done editing' }));
    expect(saved[0]).toMatchObject({
        schema_version: 2,
        id: original.id,
        title: 'Edited drawing',
    });
    expect(submit).not.toHaveBeenCalled();
    expect(original).not.toHaveProperty('schema_version');
});

it('discards a delayed editor callback after the actor or canonical document changes', () => {
    const changed = vi.fn();
    const source = legacy();
    const view = render(
        <KnowledgeDiagrams
            diagrams={[source]}
            onChange={changed}
            recordKey="7:12:editor"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit diagram' }));
    const old = studio.props!;
    view.rerender(
        <KnowledgeDiagrams
            diagrams={[legacy()]}
            onChange={changed}
            recordKey="8:29:editor"
        />,
    );
    act(() =>
        old.onChange?.(
            { ...old.value, title: 'Late result' },
            {
                recordKey: old.recordKey,
                kind: 'edit',
                label: 'Late edit',
                imageFileIds: [],
            },
        ),
    );
    expect(changed).not.toHaveBeenCalled();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('creates a versioned drawing and lets readers choose its pages without editing', () => {
    let saved: KnowledgeDiagram[] = [];
    const view = render(
        <KnowledgeDiagrams
            diagrams={[]}
            onChange={(next) => {
                saved = next;
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Create diagram' }));
    expect(saved[0]).toHaveProperty('schema_version', 2);
    const value = studio.props!.value;
    const second = {
        ...structuredClone(value.pages[0]),
        id: crypto.randomUUID(),
        name: 'Recovery steps',
        layers: [{ ...value.pages[0].layers[0], id: crypto.randomUUID() }],
    };
    view.unmount();
    render(
        <KnowledgeDiagrams
            diagrams={[{ ...value, pages: [...value.pages, second] }]}
        />,
    );
    fireEvent.change(screen.getByLabelText('Diagram page'), {
        target: { value: second.id },
    });
    expect(screen.getByRole('img', { name: value.title })).toHaveTextContent(
        second.id,
    );
    expect(
        screen.queryByRole('button', { name: 'Edit diagram' }),
    ).not.toBeInTheDocument();
});
