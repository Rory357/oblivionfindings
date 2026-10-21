import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';

class UploadRequest {
    static current: UploadRequest;
    upload = {
        onprogress: null as
            | null
            | ((event: {
                  loaded: number;
                  total: number;
                  lengthComputable: boolean;
              }) => void),
    };
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    ontimeout: (() => void) | null = null;
    onabort: (() => void) | null = null;
    responseType = '';
    timeout = 0;
    status = 200;
    response: unknown = { message: { id: 301 } };
    open = vi.fn();
    setRequestHeader = vi.fn();
    send = vi.fn();
    abort = vi.fn(() => this.onabort?.());
    constructor() {
        UploadRequest.current = this;
    }
}
describe('private Chat upload transport', () => {
    beforeEach(() => {
        vi.stubGlobal('XMLHttpRequest', UploadRequest);
    });
    afterEach(() => {
        vi.unstubAllGlobals();
    });
    it('reports real bytes but waits for the server response before confirming a send', async () => {
        const body = new FormData();
        body.append('files[]', new File(['photo'], 'visit.txt'));
        const progress = vi.fn();
        const confirmed = vi.fn();
        const request = chatRequest('/chat/api/conversations/71/messages', {
            method: 'POST',
            body,
            onUploadProgress: progress,
        }).then(confirmed);
        const xhr = UploadRequest.current;
        expect(xhr.send).toHaveBeenCalledWith(body);
        expect(xhr.setRequestHeader).not.toHaveBeenCalledWith(
            'content-type',
            expect.anything(),
        );
        expect(xhr.setRequestHeader).toHaveBeenCalledWith(
            'accept',
            'application/json',
        );
        xhr.upload.onprogress?.({
            loaded: 10,
            total: 10,
            lengthComputable: true,
        });
        await Promise.resolve();
        expect(confirmed).not.toHaveBeenCalled();
        expect(progress).toHaveBeenCalledWith({ loaded: 10, total: 10 });
        xhr.onload?.();
        await request;
        expect(confirmed).toHaveBeenCalledWith({ message: { id: 301 } });
        expect(xhr.upload.onprogress).toBeNull();
    });
    it('preserves uncertainty after timeout, network failure or a non-JSON success page', async () => {
        for (const kind of ['timeout', 'network', 'html']) {
            const promise = chatRequest('/chat/api/conversations/71/messages', {
                body: new FormData(),
                onUploadProgress: vi.fn(),
            });
            const rejection = expect(promise).rejects.toMatchObject({
                status: 0,
                code: 'chat_send_uncertain',
            });
            const xhr = UploadRequest.current;
            if (kind === 'timeout') xhr.ontimeout?.();
            else if (kind === 'network') xhr.onerror?.();
            else {
                xhr.response = null;
                xhr.onload?.();
            }
            await rejection;
        }
    });
    it('keeps server validation and audience-conflict codes for correction and safe recovery', async () => {
        const promise = chatRequest('/chat/api/conversations/71/messages', {
            body: new FormData(),
            onUploadProgress: vi.fn(),
        });
        const rejection = expect(promise).rejects.toMatchObject({
            status: 409,
            code: 'chat_audience_review_required',
            message: 'Review changed audience',
        });
        UploadRequest.current.status = 409;
        UploadRequest.current.response = {
            message: 'Review changed audience',
            code: 'chat_audience_review_required',
        };
        UploadRequest.current.onload?.();
        await rejection;
    });
    it('aborts abandoned uploads and cannot report later progress to a different conversation', async () => {
        const controller = new AbortController();
        const progress = vi.fn();
        const promise = chatRequest('/chat/api/conversations/71/messages', {
            body: new FormData(),
            signal: controller.signal,
            onUploadProgress: progress,
        });
        const rejection = expect(promise).rejects.toMatchObject({
            code: 'chat_send_uncertain',
        });
        const callback = UploadRequest.current.upload.onprogress;
        controller.abort();
        await rejection;
        callback?.({ loaded: 10, total: 10, lengthComputable: true });
        expect(progress).not.toHaveBeenCalled();
        expect(UploadRequest.current.abort).toHaveBeenCalledTimes(1);
    });
});
