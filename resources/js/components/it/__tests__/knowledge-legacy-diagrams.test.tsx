import {
    knowledgeDocumentHref,
    knowledgeFileHref,
} from '@/components/it/knowledge-navigation';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    KnowledgeDiagrams,
    type KnowledgeDiagram,
} from '../knowledge-legacy-diagrams';

afterEach(cleanup);

it('authors shapes and connections without submitting the document and reopens saved source', () => {
    const submit = vi.fn();
    let saved: KnowledgeDiagram[] = [];
    function Editor() {
        const [diagrams, setDiagrams] = useState<KnowledgeDiagram[]>([]);
        return (
            <form onSubmit={submit}>
                <KnowledgeDiagrams
                    diagrams={diagrams}
                    onChange={(next) => {
                        saved = next;
                        setDiagrams(next);
                    }}
                />
            </form>
        );
    }
    const view = render(<Editor />);
    fireEvent.click(screen.getByRole('button', { name: 'Create diagram' }));
    fireEvent.change(screen.getByLabelText('Diagram title'), {
        target: { value: 'Recovery topology' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add rectangle' }));
    fireEvent.change(screen.getByLabelText('Selected shape label'), {
        target: { value: 'Router' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add ellipse' }));
    fireEvent.change(screen.getByLabelText('Selected shape label'), {
        target: { value: 'Application' },
    });
    fireEvent.change(screen.getByLabelText('Connector start 1'), {
        target: { value: saved[0].nodes[0].id },
    });
    fireEvent.change(screen.getByLabelText('Connector end 1'), {
        target: { value: saved[0].nodes[1].id },
    });
    fireEvent.change(screen.getByLabelText('Connector label'), {
        target: { value: 'Approved connection' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add connector' }));
    expect(saved[0].edges).toHaveLength(1);
    expect(submit).not.toHaveBeenCalled();
    const source = JSON.parse(JSON.stringify(saved));
    view.unmount();
    render(<KnowledgeDiagrams diagrams={source} />);
    expect(
        screen.getByRole('img', { name: 'Recovery topology' }),
    ).toBeVisible();
    expect(screen.getByText('Approved connection')).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Add rectangle' }),
    ).not.toBeInTheDocument();
});

it('supports keyboard placement and removes connections with their shape', () => {
    const first = crypto.randomUUID(),
        second = crypto.randomUUID();
    let saved: KnowledgeDiagram[] = [
        {
            id: crypto.randomUUID(),
            title: 'Keyboard diagram',
            nodes: [
                { id: first, shape: 'rectangle', text: 'First', x: 20, y: 20 },
                { id: second, shape: 'ellipse', text: 'Second', x: 400, y: 20 },
            ],
            edges: [
                {
                    id: crypto.randomUUID(),
                    from: first,
                    to: second,
                    label: 'Route',
                },
            ],
        },
    ];
    function Editor() {
        const [diagrams, setDiagrams] = useState(saved);
        return (
            <KnowledgeDiagrams
                diagrams={diagrams}
                onChange={(next) => {
                    saved = next;
                    setDiagrams(next);
                }}
            />
        );
    }
    render(<Editor />);
    fireEvent.keyDown(screen.getByLabelText('rectangle: First'), {
        key: 'ArrowRight',
    });
    expect(saved[0].nodes[0].x).toBe(30);
    fireEvent.click(screen.getByRole('button', { name: 'Delete shape' }));
    expect(saved[0].nodes).toHaveLength(1);
    expect(saved[0].edges).toHaveLength(0);
});

it('native document links preserve bounded library filters and page in read and edit URLs', () => {
    const href = knowledgeDocumentHref(
        23,
        '/it/knowledge?q=routers&page=2&list_view=cards&category=network&article=9&secret=excluded',
        true,
    );
    const url = new URL(href, 'https://local.invalid');
    expect(url.pathname).toBe('/it/knowledge/23');
    expect(url.searchParams.get('edit')).toBe('1');
    const context = new URLSearchParams(url.searchParams.get('library')!);
    expect(context.get('page')).toBe('2');
    expect(context.get('list_view')).toBe('cards');
    expect(context.get('q')).toBe('routers');
    expect(context.has('secret')).toBe(false);
    expect(context.has('article')).toBe(false);
    const fileHref = knowledgeFileHref('/it/knowledge/23/files/4', href);
    const reopened = new URL(
        knowledgeDocumentHref(23, fileHref),
        'https://local.invalid',
    );
    expect(reopened.searchParams.get('library')).toBe(context.toString());
    expect(reopened.searchParams.has('edit')).toBe(false);
});
