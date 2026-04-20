<script setup>
import { ref, onMounted } from 'vue';
import { api } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';
import DataTable from '@/components/ui/DataTable.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';

const auth = useAuthStore();
const notes = useNotificationsStore();
const tab = ref('list');
const reviews = ref([]);
const dashboard = ref(null);
const breakdown = ref(null);
const loading = ref(false);
const modal = ref({ open: false, kind: null, review: null, text: '', busy: false, error: '' });

async function load() {
    loading.value = true;
    try {
        // Dashboard requires facility_id — take it from the authenticated user.
        const facilityId = auth.user?.facility_id ?? auth.user?.facility?.id ?? null;
        const dashParams = facilityId ? { facility_id: facilityId } : undefined;

        const [rv, dash, brk] = await Promise.all([
            api.reviews.list().catch(() => ({ data: [] })),
            dashParams ? api.reviewDashboard(dashParams).catch(() => null) : Promise.resolve(null),
            api.reviewDashboardBreakdown(dashParams).catch(() => null),
        ]);
        reviews.value = rv?.data || rv || [];
        dashboard.value = dash;
        breakdown.value = brk;
    } finally {
        loading.value = false;
    }
}

function openAction(kind, review) {
    modal.value = { open: true, kind, review, text: '', busy: false, error: '' };
}

async function submitAction() {
    modal.value.busy = true;
    modal.value.error = '';
    try {
        const r = modal.value.review;
        if (modal.value.kind === 'respond') await api.reviewRespond(r.id, modal.value.text);
        else if (modal.value.kind === 'hide') await api.reviewHide(r.id, modal.value.text);
        else if (modal.value.kind === 'appeal') await api.reviewAppeal(r.id, modal.value.text);
        else if (modal.value.kind === 'publish') await api.reviewPublish(r.id);
        notes.success('Done.');
        modal.value.open = false;
        await load();
    } catch (e) {
        modal.value.error = extractErrorMessage(e, 'Action failed.');
    } finally {
        modal.value.busy = false;
    }
}

const columns = [
    { key: 'id', label: 'ID' },
    { key: 'rating', label: 'Rating' },
    { key: 'status', label: 'Status' },
    { key: 'body', label: 'Comment' },
    { key: '__actions', label: 'Actions' },
];

onMounted(load);

defineExpose({ load, openAction, submitAction });
</script>

<template>
    <section>
        <h1 class="text-2xl font-semibold mb-4">Reviews</h1>
        <div class="flex gap-2 border-b mb-4">
            <button v-for="t in ['list','dashboard']" :key="t"
                class="px-4 py-2 text-sm font-medium border-b-2"
                :class="tab === t ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-600'"
                :data-test="`tab-${t}`"
                @click="tab = t"
            >{{ t }}</button>
        </div>

        <div v-if="tab === 'list'">
            <DataTable :columns="columns" :rows="reviews" :loading="loading">
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-rating="{ row }">{{ '★'.repeat(row.rating) }}{{ '☆'.repeat(5 - row.rating) }}</template>
                <template #cell-__actions="{ row }">
                    <button v-if="row.status === 'pending'" class="text-xs underline mr-2" @click.stop="openAction('publish', row)">Publish</button>
                    <button class="text-xs underline mr-2" @click.stop="openAction('respond', row)">Respond</button>
                    <button class="text-xs underline mr-2" @click.stop="openAction('hide', row)">Hide</button>
                    <button class="text-xs underline" @click.stop="openAction('appeal', row)">Appeal</button>
                </template>
            </DataTable>
        </div>

        <div v-else-if="tab === 'dashboard'" class="space-y-6">
            <div class="grid grid-cols-3 gap-4" data-test="dashboard-cards">
                <div class="bg-white rounded shadow p-4" data-test="card-average-rating">
                    <div class="text-xs text-gray-500">Average rating</div>
                    <div class="text-3xl font-semibold">{{ dashboard?.average_rating != null ? Number(dashboard.average_rating).toFixed(2) : '—' }}</div>
                </div>
                <div class="bg-white rounded shadow p-4" data-test="card-negative-rate">
                    <div class="text-xs text-gray-500">Negative-review rate</div>
                    <div class="text-3xl font-semibold">{{ dashboard?.negative_review_rate != null ? Number(dashboard.negative_review_rate).toFixed(1) + '%' : '—' }}</div>
                </div>
                <div class="bg-white rounded shadow p-4" data-test="card-median-response">
                    <div class="text-xs text-gray-500">Median response time</div>
                    <div class="text-3xl font-semibold">{{ dashboard?.median_response_time_hours != null ? Number(dashboard.median_response_time_hours).toFixed(1) + 'h' : '—' }}</div>
                </div>
            </div>

            <div class="bg-white rounded shadow p-4" data-test="breakdown-by-facility">
                <h2 class="text-sm font-semibold mb-2">By clinic</h2>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase">
                            <th class="py-1 pr-4">Facility</th>
                            <th class="py-1 pr-4">Reviews</th>
                            <th class="py-1 pr-4">Avg</th>
                            <th class="py-1 pr-4">Negative %</th>
                            <th class="py-1">Median Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in breakdown?.by_facility || []" :key="'f-' + row.facility_id" class="border-t">
                            <td class="py-1 pr-4">#{{ row.facility_id }}</td>
                            <td class="py-1 pr-4">{{ row.total }}</td>
                            <td class="py-1 pr-4">{{ Number(row.average_rating).toFixed(2) }}</td>
                            <td class="py-1 pr-4">{{ Number(row.negative_review_rate).toFixed(1) }}%</td>
                            <td class="py-1">{{ row.median_response_time_hours != null ? Number(row.median_response_time_hours).toFixed(1) + 'h' : '—' }}</td>
                        </tr>
                        <tr v-if="!(breakdown?.by_facility?.length)"><td colspan="5" class="py-2 text-center text-gray-400">No facility data</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded shadow p-4" data-test="breakdown-by-provider">
                <h2 class="text-sm font-semibold mb-2">By provider</h2>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase">
                            <th class="py-1 pr-4">Doctor</th>
                            <th class="py-1 pr-4">Reviews</th>
                            <th class="py-1 pr-4">Avg</th>
                            <th class="py-1 pr-4">Negative %</th>
                            <th class="py-1">Median Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in breakdown?.by_provider || []" :key="'d-' + row.doctor_id" class="border-t">
                            <td class="py-1 pr-4">#{{ row.doctor_id }}</td>
                            <td class="py-1 pr-4">{{ row.total }}</td>
                            <td class="py-1 pr-4">{{ Number(row.average_rating).toFixed(2) }}</td>
                            <td class="py-1 pr-4">{{ Number(row.negative_review_rate).toFixed(1) }}%</td>
                            <td class="py-1">{{ row.median_response_time_hours != null ? Number(row.median_response_time_hours).toFixed(1) + 'h' : '—' }}</td>
                        </tr>
                        <tr v-if="!(breakdown?.by_provider?.length)"><td colspan="5" class="py-2 text-center text-gray-400">No provider data</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal :open="modal.open" :title="'Review — ' + modal.kind" @close="modal.open = false">
            <form class="space-y-3" @submit.prevent="submitAction">
                <div v-if="modal.kind !== 'publish'">
                    <label class="block text-sm font-medium">{{ modal.kind === 'respond' ? 'Public response' : 'Reason' }}</label>
                    <textarea v-model="modal.text" rows="4" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div v-if="modal.error" class="text-sm text-red-600" data-test="review-error">{{ modal.error }}</div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="modal.open = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="modal.busy">Submit</AppButton>
                </div>
            </form>
        </Modal>
    </section>
</template>
