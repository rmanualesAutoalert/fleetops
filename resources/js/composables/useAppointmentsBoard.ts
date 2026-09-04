import { onScopeDispose, ref } from 'vue';
import type { BoardFilters, BoardResponse } from '@/types/appointments';

export async function fetchBoard(
    filters: BoardFilters,
    signal: AbortSignal,
): Promise<BoardResponse> {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(filters)) {
        if (value !== '') {
            params.set(key, String(value));
        }
    }

    const response = await fetch(`/api/appointments?${params}`, {
        signal,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        if (response.status === 401) {
            throw new Error(
                'Your session has expired. Sign in again to view appointments.',
            );
        }

        if (response.status === 403) {
            throw new Error(
                'A service advisor account is required to view appointments.',
            );
        }

        if (response.status === 422) {
            const body = await response.json();
            const messages = Object.values(body.errors ?? {})
                .flat()
                .join(' ');

            throw new Error(
                messages || 'Check the board filters and try again.',
            );
        }

        throw new Error('Appointments could not be loaded. Please retry.');
    }

    return response.json();
}

export function useAppointmentsBoard(load: typeof fetchBoard = fetchBoard) {
    const result = ref<BoardResponse | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    let generation = 0;
    let controller: AbortController | undefined;
    let timer: ReturnType<typeof setTimeout> | undefined;

    function cancel() {
        //api call key
        ++generation;
        clearTimeout(timer);
        controller?.abort();
        loading.value = false;
    }

    function schedule(filters: BoardFilters, delay = 300) {
        // Invalidate immediately, including during the next request's debounce window.
        cancel();
        const requestId = generation;
        const snapshot = { ...filters };
        error.value = null;
        loading.value = true;

        timer = setTimeout(async () => {
            const current = new AbortController();
            controller = current;
            const isCurrent = () =>
                requestId === generation && !current.signal.aborted;

            try {
                const data = await load(snapshot, current.signal);

                if (isCurrent()) {
                    result.value = data;
                }
            } catch (cause) {
                if (isCurrent()) {
                    error.value =
                        cause instanceof Error
                            ? cause.message
                            : 'Appointments could not be loaded.';
                }
            } finally {
                if (isCurrent()) {
                    loading.value = false;
                }
            }
        }, delay);
    }

    onScopeDispose(cancel);

    return { result, loading, error, schedule, cancel };
}
