import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BlockResizeHandle, type BlockSize } from './_designer-resize';

function Harness({ onCommit }: { onCommit: (size: BlockSize) => void }) {
    const [preview, setPreview] = useState<BlockSize | null>(null);
    return (
        <div data-testid="grid">
            <article>
                <div className="hub-designer-render">Story</div>
                <output>
                    {preview
                        ? `${preview.width}/${preview.min_height}`
                        : 'Unchanged'}
                </output>
                <BlockResizeHandle
                    label="Story"
                    size={{ width: 6, min_height: 0 }}
                    onPreview={setPreview}
                    onCommit={onCommit}
                    onSelect={() => {}}
                />
            </article>
        </div>
    );
}

function start() {
    class TestPointerEvent extends MouseEvent {
        pointerId: number;
        constructor(type: string, init: PointerEventInit = {}) {
            super(type, init);
            this.pointerId = init.pointerId ?? 1;
        }
    }
    vi.stubGlobal('PointerEvent', TestPointerEvent);
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(720);
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(
        function (this: Element) {
            return {
                x: 0,
                y: 0,
                top: 0,
                left: 0,
                bottom: 240,
                right: 720,
                width: this.getAttribute('data-testid') === 'grid' ? 720 : 360,
                height: 240,
                toJSON: () => ({}),
            };
        },
    );
    const commit = vi.fn();
    render(<Harness onCommit={commit} />);
    return {
        commit,
        handle: screen.getByRole('button', { name: 'Resize Story' }),
    };
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('corner resize transaction', () => {
    it('previews both dimensions and commits only once after release', () => {
        const { commit, handle } = start();
        fireEvent.pointerDown(handle, {
            button: 0,
            pointerId: 1,
            clientX: 200,
            clientY: 200,
        });
        fireEvent.pointerMove(handle, {
            pointerId: 1,
            clientX: 270,
            clientY: 224,
        });
        fireEvent.pointerMove(handle, {
            pointerId: 1,
            clientX: 320,
            clientY: 248,
        });
        expect(screen.getByText('8/288')).toBeVisible();
        expect(commit).not.toHaveBeenCalled();
        fireEvent.pointerUp(handle, {
            pointerId: 1,
            clientX: 320,
            clientY: 248,
        });
        expect(commit).toHaveBeenCalledExactlyOnceWith({
            width: 8,
            min_height: 288,
        });
        expect(screen.getByText('Unchanged')).toBeVisible();
    });
    it('cancels pointer changes with Escape and ignores a click without dragging', () => {
        const { commit, handle } = start();
        fireEvent.pointerDown(handle, {
            button: 0,
            pointerId: 1,
            clientX: 200,
            clientY: 200,
        });
        fireEvent.pointerMove(handle, {
            pointerId: 1,
            clientX: 900,
            clientY: 900,
        });
        expect(screen.getByText('12/800')).toBeVisible();
        fireEvent.keyDown(handle, { key: 'Escape' });
        expect(screen.getByText('Unchanged')).toBeVisible();
        expect(commit).not.toHaveBeenCalled();
        fireEvent.pointerDown(handle, {
            button: 0,
            pointerId: 2,
            clientX: 200,
            clientY: 200,
        });
        fireEvent.pointerUp(handle, {
            pointerId: 2,
            clientX: 200,
            clientY: 200,
        });
        expect(commit).not.toHaveBeenCalled();
    });
    it.each([
        ['width', { width: 8, min_height: 0 }, '8/0'],
        ['height', { width: 6, min_height: 288 }, '6/288'],
    ])('resizes only the %s from its edge', (axis, size, preview) => {
        const { commit } = start();
        const edge = screen.getByRole('button', {
            name: `Resize ${axis} of Story`,
        });
        fireEvent.pointerDown(edge, {
            button: 0,
            pointerId: 1,
            clientX: 200,
            clientY: 200,
        });
        fireEvent.pointerMove(edge, {
            pointerId: 1,
            clientX: 320,
            clientY: 248,
        });
        expect(screen.getByText(preview)).toBeVisible();
        expect(commit).not.toHaveBeenCalled();
        fireEvent.pointerUp(edge, { pointerId: 1 });
        expect(commit).toHaveBeenCalledExactlyOnceWith(size);
    });
    it('rolls back an interrupted drag and ignores other pointers', () => {
        const { commit, handle } = start();
        fireEvent.pointerDown(handle, {
            button: 0,
            pointerId: 1,
            clientX: 200,
            clientY: 200,
        });
        fireEvent.pointerMove(handle, {
            pointerId: 1,
            clientX: 320,
            clientY: 248,
        });
        fireEvent.pointerUp(handle, { pointerId: 2 });
        expect(screen.getByText('8/288')).toBeVisible();
        expect(commit).not.toHaveBeenCalled();
        fireEvent.lostPointerCapture(handle, { pointerId: 1 });
        expect(screen.getByText('Unchanged')).toBeVisible();
        expect(commit).not.toHaveBeenCalled();
    });
    it('uses the usable grid width when the section has inset padding', () => {
        const { commit, handle } = start();
        screen.getByTestId('grid').style.cssText =
            'padding: 0 110px; column-gap: 20px';
        fireEvent.pointerDown(handle, {
            button: 0,
            pointerId: 1,
            clientX: 200,
            clientY: 200,
        });
        fireEvent.pointerMove(handle, {
            pointerId: 1,
            clientX: 250,
            clientY: 200,
        });
        fireEvent.pointerUp(handle, { pointerId: 1 });
        expect(commit).toHaveBeenCalledExactlyOnceWith({
            width: 8,
            min_height: 0,
        });
    });
});
