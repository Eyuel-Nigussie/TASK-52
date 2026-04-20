<script setup>
import { ref, onMounted } from 'vue';
import { api } from '@/api';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';
import DataTable from '@/components/ui/DataTable.vue';
import AppButton from '@/components/ui/AppButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';

const notes = useNotificationsStore();
const rows = ref([]);
const loading = ref(false);

const columns = [
    { key: 'id', label: 'ID' },
    { key: 'entity_type', label: 'Entity' },
    { key: 'source_id', label: 'Source' },
    { key: 'target_id', label: 'Target' },
    { key: 'status', label: 'Status' },
    { key: '__actions', label: 'Actions' },
];

async function load() {
    loading.value = true;
    try {
        const res = await api.mergeRequests.list();
        rows.value = res?.data || res || [];
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Load failed.'));
    } finally {
        loading.value = false;
    }
}

async function approve(row) {
    try {
        await api.mergeApprove(row.id);
        notes.success('Approved.');
        await load();
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Approve failed.'));
    }
}

async function reject(row) {
    try {
        await api.mergeReject(row.id);
        notes.success('Rejected.');
        await load();
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Reject failed.'));
    }
}

onMounted(load);

defineExpose({ load, approve, reject });
</script>

<template>
    <section>
        <h1 class="text-2xl font-semibold mb-4">Merge Requests</h1>
        <DataTable :columns="columns" :rows="rows" :loading="loading">
            <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
            <template #cell-__actions="{ row }">
                <button v-if="row.status === 'pending'" class="text-xs underline text-green-700 mr-2" @click.stop="approve(row)">Approve</button>
                <button v-if="row.status === 'pending'" class="text-xs underline text-red-700" @click.stop="reject(row)">Reject</button>
            </template>
        </DataTable>
    </section>
</template>
