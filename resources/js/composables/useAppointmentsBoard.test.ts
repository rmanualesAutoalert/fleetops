import { effectScope } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { BoardFilters, BoardResponse } from '@/types/appointments';
import { fetchBoard, useAppointmentsBoard } from './useAppointmentsBoard';

const filters: BoardFilters = {
    branch_id: '1',
    status: '',
    from: '2026-09-01',
    to: '2026-09-30',
    q: '',
    page: 1,
};
const response = (id: number): BoardResponse => ({
    data: [
        {
            id,
            scheduled_at: '2026-09-04 10:00:00',
            status: 'booked',
            bay: null,
            customer_name: 'Customer',
            plate_number: 'ABC',
            advisor_name: 'Advisor',
        },
    ],
    meta: {
        branch_id: 1,
        page: 1,
        per_page: 50,
        has_more: false,
        timezone: 'UTC',
    },
});

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (error: Error) => void;
    const promise = new Promise<T>((yes, no) => {
        resolve = yes;
        reject = no;
    });

    return { promise, resolve, reject };
}

describe('appointments request lifecycle', () => {
    let scope: ReturnType<typeof effectScope>;
    beforeEach(() => {
        vi.useFakeTimers();
        scope = effectScope();
    });
    afterEach(() => {
        scope.stop();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('debounces typing for exactly 300ms and snapshots the filters', async () => {
        const load = vi.fn().mockResolvedValue(response(1));
        const board = scope.run(() => useAppointmentsBoard(load))!;
        board.schedule({ ...filters, q: 'a' });
        await vi.advanceTimersByTimeAsync(200);
        const latest = { ...filters, q: 'abc' };
        board.schedule(latest);
        latest.q = 'mutated';
        await vi.advanceTimersByTimeAsync(299);
        expect(load).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(load).toHaveBeenCalledTimes(1);
        expect(load.mock.calls[0][0].q).toBe('abc');
    });

    it('aborts A immediately and never lets its late response overwrite fresh B', async () => {
        const a = deferred<BoardResponse>();
        const b = deferred<BoardResponse>();
        // Deliberately ignore AbortSignal: correctness must survive a late completion.
        const load = vi
            .fn()
            .mockReturnValueOnce(a.promise)
            .mockReturnValueOnce(b.promise);
        const board = scope.run(() => useAppointmentsBoard(load))!;
        board.schedule({ ...filters, q: 'old' });
        await vi.advanceTimersByTimeAsync(300);
        const signal = load.mock.calls[0][1] as AbortSignal;
        board.schedule({ ...filters, q: 'fresh' });
        expect(signal.aborted).toBe(true);
        await vi.advanceTimersByTimeAsync(299);
        expect(load).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        b.resolve(response(2));
        await vi.advanceTimersByTimeAsync(0);
        expect(board.result.value?.data[0].id).toBe(2);
        a.resolve(response(1));
        await vi.advanceTimersByTimeAsync(0);
        expect(board.result.value?.data[0].id).toBe(2);
        expect(board.error.value).toBeNull();
        expect(board.loading.value).toBe(false);
    });

    it('ignores A completing inside the debounce window and preserves B loading state', async () => {
        const a = deferred<BoardResponse>();
        const load = vi
            .fn()
            .mockReturnValueOnce(a.promise)
            .mockResolvedValueOnce(response(2));
        const board = scope.run(() => useAppointmentsBoard(load))!;
        board.schedule(filters, 0);
        await vi.advanceTimersByTimeAsync(0);
        board.schedule({ ...filters, q: 'new' });
        a.resolve(response(1));
        await vi.advanceTimersByTimeAsync(100);
        expect(board.result.value).toBeNull();
        expect(board.loading.value).toBe(true);
        await vi.advanceTimersByTimeAsync(200);
        expect(board.result.value?.data[0].id).toBe(2);
    });

    it('ignores a stale error after a branch change', async () => {
        const a = deferred<BoardResponse>();
        const load = vi
            .fn()
            .mockReturnValueOnce(a.promise)
            .mockResolvedValueOnce(response(2));
        const board = scope.run(() => useAppointmentsBoard(load))!;
        board.schedule(filters, 0);
        await vi.advanceTimersByTimeAsync(0);
        board.schedule({ ...filters, branch_id: '2' }, 0);
        await vi.advanceTimersByTimeAsync(0);
        a.reject(new Error('old failure'));
        await vi.advanceTimersByTimeAsync(0);
        expect(board.error.value).toBeNull();
        expect(board.result.value?.data[0].id).toBe(2);
    });

    it('disposal clears timers and aborts in-flight work without committing it', async () => {
        const pending = deferred<BoardResponse>();
        const load = vi.fn().mockReturnValue(pending.promise);
        const board = scope.run(() => useAppointmentsBoard(load))!;
        board.schedule(filters, 0);
        await vi.advanceTimersByTimeAsync(0);
        const signal = load.mock.calls[0][1] as AbortSignal;
        board.schedule({ ...filters, q: 'pending timer' });
        scope.stop();
        expect(signal.aborted).toBe(true);
        pending.resolve(response(1));
        await vi.advanceTimersByTimeAsync(300);
        expect(load).toHaveBeenCalledTimes(1);
        expect(board.result.value).toBeNull();
    });

    it('surfaces a current failure and supports retry', async () => {
        const load = vi
            .fn()
            .mockRejectedValueOnce(new Error('Network error'))
            .mockResolvedValueOnce(response(1));
        const board = scope.run(() => useAppointmentsBoard(load))!;
        board.schedule(filters, 0);
        await vi.advanceTimersByTimeAsync(0);
        expect(board.error.value).toBe('Network error');
        expect(board.loading.value).toBe(false);
        board.schedule(filters, 0);
        await vi.advanceTimersByTimeAsync(0);
        expect(board.error.value).toBeNull();
        expect(board.result.value?.data[0].id).toBe(1);
    });

    it('passes cancellation to fetch and URL-encodes search text', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(new Response(JSON.stringify(response(1))));
        vi.stubGlobal('fetch', fetch);
        const controller = new AbortController();
        await fetchBoard({ ...filters, q: 'A&B + 10%' }, controller.signal);
        expect(
            new URL(
                fetch.mock.calls[0][0],
                'http://localhost',
            ).searchParams.get('q'),
        ).toBe('A&B + 10%');
        expect(fetch.mock.calls[0][1].signal).toBe(controller.signal);
    });
});
