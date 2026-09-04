import { effectScope } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { useBoardBranches } from './useBoardBranches';
import { useAppointmentsBoard } from './useAppointmentsBoard';

const options = {
    data: [{ id: 2, name: 'Main' }],
    meta: { default_branch_id: 2 },
};

describe('branch bootstrap', () => {
    it('deduplicates bootstrap and keeps branches through appointment filter changes', async () => {
        vi.useFakeTimers();
        const scope = effectScope();

        try {
            const loadBranches = vi.fn().mockResolvedValue(options);
            const loadRows = vi.fn().mockResolvedValue({ data: [], meta: {} });
            const { branches, board } = scope.run(() => ({
                branches: useBoardBranches(loadBranches),
                board: useAppointmentsBoard(loadRows),
            }))!;
            await Promise.all([branches.initialize(), branches.initialize()]);

            for (const q of ['', 'Bertrand', 'ABC']) {
                board.schedule({
                    branch_id: '2',
                    status: '',
                    from: '2026-09-01',
                    to: '2026-09-30',
                    page: 1,
                    q,
                });
                await vi.advanceTimersByTimeAsync(300);
                await branches.initialize();
            }

            expect(loadBranches).toHaveBeenCalledTimes(1);
            expect(loadRows).toHaveBeenCalledTimes(3);
            expect(branches.result.value).toEqual(options);
        } finally {
            scope.stop();
            vi.useRealTimers();
        }
    });

    it('allows retry after failure and ignores a response after disposal', async () => {
        const scope = effectScope();
        let resolve!: (value: typeof options) => void;
        const load = vi
            .fn()
            .mockRejectedValueOnce(new Error('Offline'))
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
        const branches = scope.run(() => useBoardBranches(load))!;
        await branches.initialize();
        expect(branches.error.value).toBe('Offline');
        const pending = branches.initialize();
        scope.stop();
        expect(load.mock.calls[1][0].aborted).toBe(true);
        resolve(options);
        await pending;
        expect(branches.result.value).toBeNull();
    });
});
