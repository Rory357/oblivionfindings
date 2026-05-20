import { describe, expect, it } from 'vitest';
import {
    resolveAttachedOpening,
    snapOpeningToNearestWall,
    wallSegmentsWithOpenings,
    type AttachedOpening,
} from './_geometry';
import type { PlanWall } from './_types';

const canvas = { width: 1000, height: 700 };

describe('site plan geometry', () => {
    it('snaps a door opening onto the nearest wall and stores an attachment', () => {
        const wall: PlanWall = {
            id: 'front-wall',
            points: [
                { x: 0.1, y: 0.5 },
                { x: 0.9, y: 0.5 },
            ],
            thickness: 4,
        };

        const snapped = snapOpeningToNearestWall(
            { x: 0.49, y: 0.54 },
            [wall],
            canvas,
            { width: 0.08, maxDistancePx: 50 },
        );

        expect(snapped).not.toBeNull();
        expect(snapped?.wall_id).toBe('front-wall');
        expect(snapped?.wall_segment_index).toBe(0);
        expect(snapped?.wall_t).toBeCloseTo(0.4875, 3);
        expect(snapped?.rotation_deg).toBeCloseTo(0, 3);
        expect(snapped?.x).toBeCloseTo(0.45, 3);
        expect(snapped?.y).toBeCloseTo(0.5, 3);
    });

    it('does not snap openings beyond the placement threshold', () => {
        const wall: PlanWall = {
            id: 'front-wall',
            points: [
                { x: 0.1, y: 0.5 },
                { x: 0.9, y: 0.5 },
            ],
        };

        expect(
            snapOpeningToNearestWall({ x: 0.5, y: 0.72 }, [wall], canvas, {
                width: 0.08,
                maxDistancePx: 40,
            }),
        ).toBeNull();
    });

    it('breaks wall rendering behind attached doors and windows', () => {
        const wall: PlanWall = {
            id: 'front-wall',
            points: [
                { x: 0.1, y: 0.5 },
                { x: 0.9, y: 0.5 },
            ],
            thickness: 4,
        };

        const openings: AttachedOpening[] = [
            {
                id: 'door-1',
                wall_id: 'front-wall',
                wall_segment_index: 0,
                wall_t: 0.5,
                width: 0.1,
            },
        ];

        const segments = wallSegmentsWithOpenings(wall, openings, canvas);

        expect(segments).toHaveLength(2);
        expect(segments[0].a).toEqual({ x: 0.1, y: 0.5 });
        expect(segments[0].b.x).toBeCloseTo(0.45, 3);
        expect(segments[1].a.x).toBeCloseTo(0.55, 3);
        expect(segments[1].b).toEqual({ x: 0.9, y: 0.5 });
    });

    it('resolves an attached vertical window from wall id and wall offset after reload', () => {
        const wall: PlanWall = {
            id: 'side-wall',
            points: [
                { x: 0.2, y: 0.2 },
                { x: 0.2, y: 0.8 },
            ],
        };

        const resolved = resolveAttachedOpening(
            {
                id: 'window-1',
                wall_id: 'side-wall',
                wall_segment_index: 0,
                wall_t: 0.25,
                width: 0.12,
                x: 0,
                y: 0,
            },
            [wall],
            canvas,
        );

        expect(resolved.attached).toBe(true);
        expect(resolved.x).toBeCloseTo(0.14, 3);
        expect(resolved.y).toBeCloseTo(0.35, 3);
        expect(resolved.rotation_deg).toBeCloseTo(90, 3);
    });
});
