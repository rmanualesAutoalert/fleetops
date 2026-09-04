import { onScopeDispose, ref } from 'vue';
import type { BranchResponse } from '@/types/appointments';

export async function fetchBranches(
    signal: AbortSignal,
): Promise<BranchResponse> {
    const response = await fetch('/api/branches', {
        signal,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(
            response.status === 401
                ? 'Your session has expired. Sign in again to load branches.'
                : 'Branches could not be loaded. Please retry.',
        );
    }

    return response.json();
}

// One successful load per board instance; failures may be explicitly retried.
export function useBoardBranches(load: typeof fetchBranches = fetchBranches) {
    const result = ref<BranchResponse | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const controller = new AbortController();
    let pending: Promise<void> | undefined;

    function initialize(): Promise<void> {
        if (result.value || controller.signal.aborted) {
return Promise.resolve();
}

        if (pending) {
return pending;
}

        loading.value = true;
        error.value = null;
        pending = (async () => {
            try {
                const data = await load(controller.signal);

                if (!controller.signal.aborted) {
result.value = data;
}
            } catch (cause) {
                if (!controller.signal.aborted) {
                    error.value =
                        cause instanceof Error
                            ? cause.message
                            : 'Branches could not be loaded.';
                }
            } finally {
                if (!controller.signal.aborted) {
loading.value = false;
}

                pending = undefined;
            }
        })();

        return pending;
    }

    onScopeDispose(() => controller.abort());

    return { result, loading, error, initialize };
}
