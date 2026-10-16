import { act, render } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { DocumentReadReceipt } from './_document-read-receipt';
import type { HubDocument } from './document';

const request = vi.hoisted(() => vi.fn());
vi.mock('./_api', () => ({ hubRequest: request }));
const file = {
    id: 41,
    actor_id: 11,
    media_id: 52,
    revision_id: 7,
    edition: 'published',
} as HubDocument;
beforeEach(() => {
    request.mockReset().mockResolvedValue({ saved: true });
});
it('records the exact published edition and actor without acknowledging anything', () => {
    const view = render(<DocumentReadReceipt file={file} />);
    expect(request).toHaveBeenCalledExactlyOnceWith(
        '/company-hub/documents/41/read',
        'POST',
        { actor_id: 11, media_id: 52, revision_id: 7 },
    );
    view.rerender(<DocumentReadReceipt file={{ ...file }} />);
    expect(request).toHaveBeenCalledTimes(1);
});
it('does not record private drafts or a missing published revision', () => {
    const view = render(
        <DocumentReadReceipt file={{ ...file, edition: 'draft' }} />,
    );
    view.rerender(
        <DocumentReadReceipt file={{ ...file, revision_id: null }} />,
    );
    expect(request).not.toHaveBeenCalled();
});
it('leaves reading available if recording history fails', async () => {
    request.mockRejectedValue(new Error('Unavailable'));
    render(<DocumentReadReceipt file={file} />);
    await act(async () => {});
    expect(request).toHaveBeenCalledTimes(1);
});
