import type {
    DiagramRasterAdapter,
    DiagramRasterResult,
} from '@/lib/diagram-studio/host-api';
import { assertResolvedRaster } from '@/lib/diagram-studio/host-api';
import axios from 'axios';
import { useCallback, useLayoutEffect, useMemo, useRef } from 'react';

export const KNOWLEDGE_RASTER_POLICY = {
    maxBytes: 20 * 1024 * 1024,
    maxWidth: 8192,
    maxHeight: 8192,
    maxPixels: 16000000,
    mimeTypes: ['image/png', 'image/jpeg'] as const,
};

type UploadResult = {
    actor_user_id: number;
    article_id: number;
    lock_version: number;
    file_ids: number[];
    file: DiagramRasterResult;
};
type Host = {
    actorId: number;
    articleId: number;
    revisionId?: number;
    version?: number;
    onUploaded?: (result: UploadResult) => void;
    onBusy?: (busy: boolean) => void;
};

/** Canonical URLs exist only here. Drawing source stores positive file identities. */
export function useKnowledgeRaster(host: Host): DiagramRasterAdapter {
    const current = useRef(host);
    const busy = useRef(false);
    const requests = useRef(new WeakMap<File, string>());
    useLayoutEffect(() => {
        current.current = host;
        return () => {
            current.current = { actorId: -1, articleId: -1 };
        };
    }, [host]);
    const sameScope = useCallback(
        (scope: Host) =>
            current.current.actorId === scope.actorId &&
            current.current.articleId === scope.articleId &&
            current.current.revisionId === scope.revisionId,
        [],
    );
    const canUpload = Boolean(
        host.onUploaded && host.version && !host.revisionId,
    );
    return useMemo(
        () => ({
            policy: KNOWLEDGE_RASTER_POLICY,
            resolveRaster: async (fileId, { signal }) => {
                const scope = current.current;
                if (!Number.isSafeInteger(fileId) || fileId < 1)
                    throw new Error('This image reference is invalid.');
                const response = await axios.get<Blob>(
                    `/it/knowledge/${scope.articleId}/files/${fileId}`,
                    {
                        params: {
                            raster: 1,
                            actor_user_id: scope.actorId,
                            ...(scope.revisionId
                                ? { revision: scope.revisionId }
                                : {}),
                        },
                        signal,
                        responseType: 'blob',
                        timeout: 30000,
                        headers: {
                            Accept: 'image/png, image/jpeg',
                            'Cache-Control': 'no-cache',
                        },
                    },
                );
                if (
                    signal.aborted ||
                    !sameScope(scope) ||
                    Number(response.headers['x-knowledge-actor-id']) !==
                        scope.actorId ||
                    Number(response.headers['x-knowledge-article-id']) !==
                        scope.articleId
                )
                    throw new Error(
                        'Image access changed. Reopen the document.',
                    );
                const result = {
                    fileId: Number(response.headers['x-knowledge-file-id']),
                    mime: String(response.headers['content-type']).split(
                        ';',
                    )[0] as DiagramRasterResult['mime'],
                    width: Number(response.headers['x-image-width']),
                    height: Number(response.headers['x-image-height']),
                    bytes: response.data.size,
                    blob: response.data,
                };
                assertResolvedRaster(fileId, result, KNOWLEDGE_RASTER_POLICY);
                return result;
            },
            ...(canUpload
                ? {
                      uploadRaster: async (
                          file: File,
                          { signal }: { signal: AbortSignal },
                      ) => {
                          const scope = current.current;
                          if (busy.current)
                              throw new Error(
                                  'Wait for the current image upload to finish.',
                              );
                          if (
                              !scope.onUploaded ||
                              !scope.version ||
                              scope.revisionId
                          )
                              throw new Error(
                                  'Reopen the document editor before adding an image.',
                              );
                          if (
                              !KNOWLEDGE_RASTER_POLICY.mimeTypes.includes(
                                  file.type as 'image/png' | 'image/jpeg',
                              ) ||
                              file.size < 1 ||
                              file.size > KNOWLEDGE_RASTER_POLICY.maxBytes
                          )
                              throw new Error(
                                  'Choose a PNG or JPEG image up to 20 MB.',
                              );
                          busy.current = true;
                          scope.onBusy?.(true);
                          try {
                              let requestId = requests.current.get(file);
                              if (!requestId) {
                                  requestId = crypto.randomUUID();
                                  requests.current.set(file, requestId);
                              }
                              const body = new FormData();
                              body.append(
                                  'actor_user_id',
                                  String(scope.actorId),
                              );
                              body.append(
                                  'lock_version',
                                  String(scope.version),
                              );
                              body.append('request_uuid', requestId);
                              body.append('diagram_image', '1');
                              body.append('file', file);
                              const { data } = await axios.post<UploadResult>(
                                  `/it/knowledge/${scope.articleId}/files`,
                                  body,
                                  {
                                      signal,
                                      timeout: 180000,
                                      headers: { Accept: 'application/json' },
                                  },
                              );
                              if (
                                  signal.aborted ||
                                  !sameScope(scope) ||
                                  current.current.version !== scope.version ||
                                  data.actor_user_id !== scope.actorId ||
                                  data.article_id !== scope.articleId ||
                                  !Number.isSafeInteger(data.lock_version) ||
                                  data.lock_version <= scope.version ||
                                  !Array.isArray(data.file_ids) ||
                                  data.file_ids.length > 30 ||
                                  !data.file_ids.every(
                                      (id) =>
                                          Number.isSafeInteger(id) && id > 0,
                                  ) ||
                                  !data.file_ids.includes(data.file?.fileId)
                              )
                                  throw new Error(
                                      'The document changed during upload. Your drawing is retained; reopen the saved files before retrying.',
                                  );
                              assertResolvedRaster(
                                  data.file.fileId,
                                  { ...data.file, blob: file },
                                  KNOWLEDGE_RASTER_POLICY,
                              );
                              current.current.onUploaded?.(data);
                              return data.file;
                          } catch (error) {
                              if (axios.isAxiosError(error)) {
                                  const message =
                                      error.response?.data?.errors?.file?.[0];
                                  if (typeof message === 'string')
                                      throw new Error(message);
                              }
                              throw error;
                          } finally {
                              busy.current = false;
                              if (sameScope(scope))
                                  current.current.onBusy?.(false);
                          }
                      },
                  }
                : {}),
        }),
        [canUpload, sameScope],
    );
}
