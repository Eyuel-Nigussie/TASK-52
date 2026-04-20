<script setup>
import { ref, onMounted } from 'vue';
import { api } from '@/api';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';
import DataTable from '@/components/ui/DataTable.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { formatDateTime } from '@/utils/format';

const notes = useNotificationsStore();
const sessions = ref([]);
const loading = ref(false);
const selected = ref(null);

const startOpen = ref(false);
const startData = ref({ storeroom_id: '' });
const startBusy = ref(false);
const startError = ref('');

const entryForm = ref({ item_id: '', counted_quantity: 0 });
const entryBusy = ref(false);
const entryError = ref('');

async function load() {
    loading.value = true;
    try {
        const res = await api.stocktakes();
        sessions.value = res?.data || res || [];
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Failed to load sessions.'));
    } finally {
        loading.value = false;
    }
}

async function openSession(s) {
    try {
        selected.value = await api.stocktakeShow(s.id);
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Failed to load session.'));
    }
}

async function startSession() {
    startBusy.value = true;
    startError.value = '';
    try {
        const created = await api.stocktakeStart({ storeroom_id: startData.value.storeroom_id });
        notes.success('Session started.');
        startOpen.value = false;
        await load();
        await openSession(created);
    } catch (e) {
        startError.value = extractErrorMessage(e, 'Could not start session.');
    } finally {
        startBusy.value = false;
    }
}

async function addEntry() {
    if (!selected.value) return;
    entryBusy.value = true;
    entryError.value = '';
    try {
        await api.stocktakeAddEntry(selected.value.id, entryForm.value);
        notes.success('Entry added.');
        entryForm.value = { item_id: '', counted_quantity: 0 };
        await openSession(selected.value);
    } catch (e) {
        entryError.value = extractErrorMessage(e, 'Entry failed.');
    } finally {
        entryBusy.value = false;
    }
}

async function approveEntry(entry) {
    // Backend requires a free-text reason when approving variance entries.
    // Prompt the manager rather than silently submitting nothing — the
    // previous no-reason behavior blocked the ±5% approval flow entirely.
    const reason = typeof window !== 'undefined' && typeof window.prompt === 'function'
        ? window.prompt('Reason for approving this variance entry:')
        : '';
    if (reason === null || reason === '' || (typeof reason === 'string' && reason.trim() === '')) {
        notes.error('Approval reason is required.');
        return;
    }
    try {
        await api.stocktakeApproveEntry(selected.value.id, entry.id, reason.trim());
        notes.success('Approved.');
        await openSession(selected.value);
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Approve failed.'));
    }
}

async function closeSession() {
    try {
        await api.stocktakeClose(selected.value.id);
        notes.success('Session closed.');
        selected.value = null;
        await load();
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Close failed.'));
    }
}

const sessionColumns = [
    { key: 'id', label: 'ID' },
    { key: 'storeroom.name', label: 'Storeroom' },
    { key: 'status', label: 'Status' },
    { key: 'started_at', label: 'Started' },
];
const entryColumns = [
    { key: 'item.name', label: 'Item' },
    { key: 'counted_quantity', label: 'Counted' },
    { key: 'expected_quantity', label: 'Expected' },
    { key: 'variance_pct', label: 'Variance %' },
    { key: 'status', label: 'Status' },
    { key: '__actions', label: 'Actions' },
];

onMounted(load);

defineExpose({ load, startSession, openSession, addEntry, approveEntry, closeSession });
</script>

<template>
    <section>
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-semibold">Stocktake</h1>
            <AppButton @click="startOpen = true">+ Start session</AppButton>
        </div>

        <div v-if="!selected">
            <DataTable :columns="sessionColumns" :rows="sessions" :loading="loading" @rowClick="openSession">
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-started_at="{ row }">{{ formatDateTime(row.started_at) }}</template>
            </DataTable>
        </div>

        <div v-else class="space-y-4">
            <div class="flex items-center gap-3">
                <button class="text-sm underline" @click="selected = null">← back</button>
                <h2 class="text-lg font-semibold">Session #{{ selected.id }}</h2>
                <StatusBadge :status="selected.status" />
                <div class="flex-1"></div>
                <AppButton v-if="selected.status === 'open'" variant="danger" @click="closeSession">Close session</AppButton>
            </div>

            <form v-if="selected.status === 'open'" class="bg-white p-4 rounded shadow grid grid-cols-3 gap-3 items-end" @submit.prevent="addEntry">
                <div>
                    <label class="block text-sm font-medium">Item ID</label>
                    <input v-model.number="entryForm.item_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Counted quantity</label>
                    <input v-model.number="entryForm.counted_quantity" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <AppButton type="submit" :loading="entryBusy">Add entry</AppButton>
                <div v-if="entryError" class="col-span-3 text-sm text-red-600" data-test="entry-error">{{ entryError }}</div>
            </form>

            <DataTable :columns="entryColumns" :rows="selected.entries || []">
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-__actions="{ row }">
                    <button v-if="row.status === 'pending'" class="text-blue-600 text-xs underline" @click.stop="approveEntry(row)">Approve</button>
                </template>
            </DataTable>
        </div>

        <Modal :open="startOpen" title="Start stocktake" @close="startOpen = false">
            <form class="space-y-3" @submit.prevent="startSession">
                <div>
                    <label class="block text-sm font-medium">Storeroom ID</label>
                    <input v-model.number="startData.storeroom_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div v-if="startError" class="text-sm text-red-600" data-test="start-error">{{ startError }}</div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="startOpen = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="startBusy">Start</AppButton>
                </div>
            </form>
        </Modal>
    </section>
</template>
