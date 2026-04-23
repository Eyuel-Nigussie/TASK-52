<script setup>
import { ref, onMounted } from 'vue';
import { api } from '@/api';
import { getClient } from '@/api';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';
import DataTable from '@/components/ui/DataTable.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { formatDateTime } from '@/utils/format';

const notes = useNotificationsStore();
const rows = ref([]);
const loading = ref(false);
const createOpen = ref(false);
const busy = ref(false);
const error = ref('');

function emptyForm() {
    return {
        patient_id: '',
        doctor_id: '',
        facility_id: '',
        reservation_strategy: 'lock_at_creation',
        items: [],
    };
}

const form = ref(emptyForm());

function addItem() {
    form.value.items.push({ item_id: '', storeroom_id: '', quantity: 1 });
}

function removeItem(index) {
    form.value.items.splice(index, 1);
}

const columns = [
    { key: 'id', label: 'ID' },
    { key: 'patient_id', label: 'Patient' },
    { key: 'status', label: 'Status' },
    { key: 'reservation_strategy', label: 'Strategy' },
    { key: 'created_at', label: 'Created' },
    { key: '__actions', label: 'Actions' },
];

async function load() {
    loading.value = true;
    try {
        const res = await api.serviceOrders.list();
        rows.value = res?.data || res || [];
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Load failed.'));
    } finally {
        loading.value = false;
    }
}

async function save() {
    busy.value = true;
    error.value = '';
    try {
        const payload = { ...form.value };
        if (payload.items.length === 0) {
            delete payload.items;
        }
        await api.serviceOrders.create(payload);
        notes.success('Order created.');
        createOpen.value = false;
        form.value = emptyForm();
        await load();
    } catch (e) {
        error.value = extractErrorMessage(e, 'Create failed.');
    } finally {
        busy.value = false;
    }
}

async function closeOrder(row) {
    try {
        await getClient().post(`/service-orders/${row.id}/close`);
        notes.success('Closed.');
        await load();
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Close failed.'));
    }
}

function openCreate() {
    form.value = emptyForm();
    error.value = '';
    createOpen.value = true;
}

onMounted(load);

defineExpose({ load, save, closeOrder });
</script>

<template>
    <section>
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-semibold">Service Orders</h1>
            <AppButton @click="openCreate">+ New order</AppButton>
        </div>
        <DataTable :columns="columns" :rows="rows" :loading="loading">
            <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
            <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
            <template #cell-__actions="{ row }">
                <button v-if="row.status === 'open'" class="text-xs underline" @click.stop="closeOrder(row)">Close</button>
            </template>
        </DataTable>

        <Modal :open="createOpen" title="New service order" @close="createOpen = false">
            <form class="space-y-3" @submit.prevent="save">
                <div>
                    <label class="block text-sm font-medium">Patient ID</label>
                    <input v-model.number="form.patient_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Doctor ID</label>
                    <input v-model.number="form.doctor_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Facility ID</label>
                    <input v-model.number="form.facility_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Reservation strategy</label>
                    <select v-model="form.reservation_strategy" class="mt-1 block w-full border rounded px-3 py-2 text-sm" data-test="reservation-strategy">
                        <option value="lock_at_creation">Lock inventory at creation</option>
                        <option value="deduct_at_close">Deduct on close</option>
                    </select>
                </div>

                <!-- Line items -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-sm font-medium">Inventory line items</span>
                        <button type="button" class="text-xs text-blue-600 underline" data-test="add-item" @click="addItem">+ Add item</button>
                    </div>
                    <div v-if="form.items.length === 0" class="text-xs text-gray-400 italic">No items added — order will be created with no reservations.</div>
                    <div
                        v-for="(line, i) in form.items"
                        :key="i"
                        class="flex gap-2 items-center mt-1"
                        :data-test="`line-item-${i}`"
                    >
                        <div class="flex-1">
                            <label class="sr-only">Item ID</label>
                            <input
                                v-model.number="line.item_id"
                                type="number"
                                placeholder="Item ID"
                                class="block w-full border rounded px-2 py-1 text-sm"
                                required
                                :data-test="`item-id-${i}`"
                            />
                        </div>
                        <div class="flex-1">
                            <label class="sr-only">Storeroom ID</label>
                            <input
                                v-model.number="line.storeroom_id"
                                type="number"
                                placeholder="Storeroom ID"
                                class="block w-full border rounded px-2 py-1 text-sm"
                                required
                                :data-test="`storeroom-id-${i}`"
                            />
                        </div>
                        <div class="w-20">
                            <label class="sr-only">Quantity</label>
                            <input
                                v-model.number="line.quantity"
                                type="number"
                                min="0.001"
                                step="any"
                                placeholder="Qty"
                                class="block w-full border rounded px-2 py-1 text-sm"
                                required
                                :data-test="`quantity-${i}`"
                            />
                        </div>
                        <button
                            type="button"
                            class="text-red-500 text-xs"
                            :data-test="`remove-item-${i}`"
                            @click="removeItem(i)"
                        >&times;</button>
                    </div>
                </div>

                <div v-if="error" class="text-sm text-red-600" data-test="so-error">{{ error }}</div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="createOpen = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="busy">Create</AppButton>
                </div>
            </form>
        </Modal>
    </section>
</template>
