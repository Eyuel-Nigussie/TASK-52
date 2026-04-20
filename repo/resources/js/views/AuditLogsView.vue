<script setup>
import { ref, onMounted } from 'vue';
import { api } from '@/api';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';
import DataTable from '@/components/ui/DataTable.vue';
import { formatDateTime } from '@/utils/format';

const notes = useNotificationsStore();
const rows = ref([]);
const loading = ref(false);

const columns = [
    { key: 'created_at', label: 'When' },
    { key: 'event', label: 'Event' },
    { key: 'user_id', label: 'User' },
    { key: 'entity_type', label: 'Entity' },
    { key: 'entity_id', label: 'ID' },
    { key: 'ip_address', label: 'IP' },
];

async function load() {
    loading.value = true;
    try {
        const res = await api.auditLogs.list();
        rows.value = res?.data || res || [];
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Load failed.'));
    } finally {
        loading.value = false;
    }
}

onMounted(load);

defineExpose({ load });
</script>

<template>
    <section>
        <h1 class="text-2xl font-semibold mb-4">Audit Logs</h1>
        <DataTable :columns="columns" :rows="rows" :loading="loading">
            <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
        </DataTable>
    </section>
</template>
