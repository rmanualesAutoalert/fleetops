<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, watch } from 'vue';
import Button from '@/components/ui/button/Button.vue';
import { useAppointmentsBoard } from '@/composables/useAppointmentsBoard';
import { useBoardBranches } from '@/composables/useBoardBranches';
import type { AppointmentStatus, BoardFilters } from '@/types/appointments';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Appointments', href: '/appointments' }] },
});

const today = new Date();
const date = (value: Date) =>
    `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;
const filters = reactive<BoardFilters>({
    branch_id: '',
    status: '',
    q: '',
    page: 1,
    from: date(new Date(today.getFullYear(), today.getMonth(), 1)),
    to: date(new Date(today.getFullYear(), today.getMonth() + 1, 0)),
});
const statuses: { value: AppointmentStatus; label: string }[] = [
    { value: 'booked', label: 'Booked' },
    { value: 'checked_in', label: 'Checked in' },
    { value: 'in_service', label: 'In service' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];
const { result, loading, error, schedule, cancel } = useAppointmentsBoard();
const {
    result: branchOptions,
    loading: branchesLoading,
    error: branchesError,
    initialize,
} = useBoardBranches();
async function initializeBoard() {
    await initialize();

    if (branchOptions.value && !filters.branch_id) {
        filters.branch_id = String(
            branchOptions.value.meta.default_branch_id ?? '',
        );
    }
}
onMounted(initializeBoard);
const validationError = computed(() =>
    !filters.from || !filters.to
        ? 'Choose both a start date and an end date.'
        : filters.to < filters.from
          ? 'End date must be on or after start date.'
          : null,
);

function reload(delay = 0) {
    if (validationError.value || !filters.branch_id) {
        cancel();

        return;
    }

    schedule(filters, delay);
}

// A single synchronous watcher invalidates requests before stale promises can commit.
watch(
    () => [
        filters.branch_id,
        filters.status,
        filters.from,
        filters.to,
        filters.q,
    ],
    (next, previous) => {
        filters.page = 1;
        //4 means the search keyword input
        reload(previous && next[4] !== previous[4] ? 300 : 0);
    },
    { immediate: true, flush: 'sync' },
);

function changePage(page: number) {
    filters.page = page;
    reload();
}

const statusLabel = (status: AppointmentStatus) =>
    statuses.find((item) => item.value === status)?.label ?? status;

const scheduledFormatter = new Intl.DateTimeFormat('en-US', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'UTC',
});

function formatScheduled(value: string): string {
    // Format the API's wall-clock timestamp without shifting it to browser time.
    const timestamp = new Date(`${value.replace(' ', 'T')}Z`);

    return Number.isNaN(timestamp.getTime())
        ? value
        : scheduledFormatter.format(timestamp);
}
</script>

<template>
    <Head title="Appointments Board" />
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Appointments Board
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Find appointments across branches and track their progress.
            </p>
        </div>
        <section
            aria-label="Appointment filters"
            class="grid gap-4 rounded-xl border bg-card p-4 md:grid-cols-2 xl:grid-cols-5"
        >
            <label class="grid min-w-0 gap-2 text-sm font-medium">
                Branch
                <select
                    :value="filters.branch_id"
                    :disabled="branchesLoading || !branchOptions?.data.length"
                    class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
                    @change="
                        filters.branch_id = (
                            $event.target as HTMLSelectElement
                        ).value
                    "
                >
                    <option v-if="!branchOptions?.data.length" value="">
                        {{
                            branchesLoading
                                ? 'Loading branches…'
                                : 'No branches available'
                        }}
                    </option>
                    <option
                        v-for="branch in branchOptions?.data"
                        :key="branch.id"
                        :value="String(branch.id)"
                    >
                        {{ branch.name }}
                    </option>
                </select>
            </label>
            <label class="grid min-w-0 gap-2 text-sm font-medium">
                Status
                <select
                    v-model="filters.status"
                    class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
                >
                    <option value="">All statuses</option>
                    <option
                        v-for="status in statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
            </label>
            <label class="grid min-w-0 gap-2 text-sm font-medium"
                >From<input
                    v-model="filters.from"
                    type="date"
                    class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
            /></label>
            <label class="grid min-w-0 gap-2 text-sm font-medium"
                >To<input
                    v-model="filters.to"
                    type="date"
                    :min="filters.from"
                    class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
            /></label>
            <label class="grid min-w-0 gap-2 text-sm font-medium"
                >Search<input
                    v-model="filters.q"
                    type="search"
                    maxlength="100"
                    placeholder="Customer or plate number"
                    class="h-10 w-full min-w-0 rounded-md border bg-background px-3"
            /></label>
        </section>
        <div
            v-if="branchesError"
            role="alert"
            class="flex items-center justify-between gap-4 rounded-lg border border-destructive p-4"
        >
            <p>{{ branchesError }}</p>
            <Button variant="outline" @click="initializeBoard"
                >Retry branches</Button
            >
        </div>
        <p v-if="validationError" role="alert" class="text-sm text-destructive">
            {{ validationError }}
        </p>
        <div
            v-else-if="error"
            role="alert"
            class="flex items-center justify-between gap-4 rounded-lg border border-destructive p-4"
        >
            <p>{{ error }}</p>
            <Button variant="outline" @click="reload()">Retry</Button>
        </div>
        <section
            :aria-busy="loading || branchesLoading"
            aria-label="Appointments"
            class="overflow-hidden rounded-xl border"
        >
            <div class="flex items-center justify-between border-b p-4 text-sm">
                <p role="status" aria-live="polite">
                    {{
                        loading || branchesLoading
                            ? 'Loading appointments…'
                            : `${result?.data.length ?? 0} appointments on this page`
                    }}
                </p>
                <span v-if="result" class="text-muted-foreground"
                    >Times: {{ result.meta.timezone }}</span
                >
            </div>
            <div class="overflow-x-auto">
                <table
                    class="w-full text-left text-sm"
                    :class="{ 'opacity-50': loading }"
                >
                    <caption class="sr-only">
                        Appointments for the selected branch and date range
                    </caption>
                    <thead class="bg-muted/50">
                        <tr>
                            <th scope="col" class="p-3">Scheduled</th>
                            <th scope="col" class="p-3">Customer</th>
                            <th scope="col" class="p-3">Plate</th>
                            <th scope="col" class="p-3">Advisor</th>
                            <th scope="col" class="p-3">Bay</th>
                            <th scope="col" class="p-3">Status</th>
                        </tr>
                    </thead>
                    <tbody v-if="!validationError && !error && !branchesError">
                        <tr
                            v-for="row in result?.data"
                            :key="row.id"
                            class="border-t"
                        >
                            <td class="p-3 whitespace-nowrap">
                                {{ formatScheduled(row.scheduled_at) }}
                            </td>
                            <td class="p-3">{{ row.customer_name ?? '—' }}</td>
                            <td class="p-3 font-medium">
                                {{ row.plate_number ?? '—' }}
                            </td>
                            <td class="p-3">{{ row.advisor_name ?? '—' }}</td>
                            <td class="p-3">{{ row.bay ?? '—' }}</td>
                            <td class="p-3">
                                <span
                                    class="rounded-full bg-muted px-2 py-1 text-xs whitespace-nowrap"
                                    >{{ statusLabel(row.status) }}</span
                                >
                            </td>
                        </tr>
                        <tr
                            v-if="
                                !loading &&
                                !branchesLoading &&
                                !result?.data.length
                            "
                        >
                            <td
                                colspan="6"
                                class="p-12 text-center text-muted-foreground"
                            >
                                No appointments match these filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                aria-label="Appointment pages"
                class="flex items-center justify-between border-t p-4"
            >
                <Button
                    variant="outline"
                    :disabled="
                        loading ||
                        !!error ||
                        !!validationError ||
                        filters.page <= 1
                    "
                    @click="changePage(filters.page - 1)"
                    >Previous</Button
                >
                <span class="text-sm">Page {{ filters.page }}</span>
                <Button
                    variant="outline"
                    :disabled="
                        loading ||
                        !!error ||
                        !!validationError ||
                        !result?.meta.has_more
                    "
                    @click="changePage(filters.page + 1)"
                    >Next</Button
                >
            </nav>
        </section>
    </div>
</template>
