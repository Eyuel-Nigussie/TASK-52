<script setup>
import { ref, onMounted } from 'vue';
import { api } from '@/api';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';
import DataTable from '@/components/ui/DataTable.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Modal from '@/components/ui/Modal.vue';

const notes = useNotificationsStore();
const tab = ref('items');
const items = ref([]);
const stockLevels = ref([]);
const lowStock = ref([]);
const ledger = ref([]);
const storerooms = ref([]);
const loading = ref(false);

const itemForm = ref({ open: false, busy: false, error: '', data: { external_key: '', name: '', sku: '', category: '', unit_of_measure: 'ea' } });

const txForm = ref({ open: false, mode: 'receive', busy: false, error: '', data: {} });

async function loadAll() {
    loading.value = true;
    try {
        const [i, sl, ls, l, sr] = await Promise.all([
            api.inventoryItems().catch(() => ({ data: [] })),
            api.inventoryStockLevels().catch(() => ({ data: [] })),
            api.inventoryLowStock().catch(() => []),
            api.inventoryLedger().catch(() => ({ data: [] })),
            api.storerooms.list().catch(() => ({ data: [] })),
        ]);
        items.value = i?.data || i || [];
        stockLevels.value = sl?.data || sl || [];
        lowStock.value = Array.isArray(ls) ? ls : (ls?.data || []);
        ledger.value = l?.data || l || [];
        storerooms.value = sr?.data || sr || [];
    } finally {
        loading.value = false;
    }
}

function openItemForm() {
    itemForm.value = { open: true, busy: false, error: '', data: { external_key: '', name: '', sku: '', category: '', unit_of_measure: 'ea' } };
}

async function saveItem() {
    itemForm.value.busy = true;
    itemForm.value.error = '';
    try {
        await api.inventoryCreateItem(itemForm.value.data);
        notes.success('Item created.');
        itemForm.value.open = false;
        await loadAll();
    } catch (e) {
        itemForm.value.error = extractErrorMessage(e, 'Create failed.');
    } finally {
        itemForm.value.busy = false;
    }
}

function openTx(mode) {
    const defaults = {
        receive: { item_id: '', storeroom_id: '', quantity: 1, unit_cost: 0, reference: '' },
        issue: { item_id: '', storeroom_id: '', quantity: 1, department_id: '', reference: '' },
        transfer: { item_id: '', from_storeroom_id: '', to_storeroom_id: '', quantity: 1, reference: '' },
    };
    txForm.value = { open: true, mode, busy: false, error: '', data: { ...defaults[mode] } };
}

async function submitTx() {
    txForm.value.busy = true;
    txForm.value.error = '';
    try {
        if (txForm.value.mode === 'receive') await api.inventoryReceive(txForm.value.data);
        else if (txForm.value.mode === 'issue') await api.inventoryIssue(txForm.value.data);
        else if (txForm.value.mode === 'transfer') await api.inventoryTransfer(txForm.value.data);
        notes.success('Recorded.');
        txForm.value.open = false;
        await loadAll();
    } catch (e) {
        txForm.value.error = extractErrorMessage(e, 'Transaction failed.');
    } finally {
        txForm.value.busy = false;
    }
}

const itemColumns = [
    { key: 'external_key', label: 'Key' },
    { key: 'name', label: 'Item' },
    { key: 'sku', label: 'SKU' },
    { key: 'category', label: 'Category' },
    { key: 'unit_of_measure', label: 'Unit' },
];
const stockColumns = [
    { key: 'item.name', label: 'Item' },
    { key: 'storeroom.name', label: 'Storeroom' },
    { key: 'on_hand', label: 'On hand' },
    { key: 'reserved', label: 'Reserved' },
    { key: 'available_to_promise', label: 'ATP' },
];
const lowColumns = [
    { key: 'item.name', label: 'Item' },
    { key: 'storeroom.name', label: 'Storeroom' },
    { key: 'on_hand', label: 'On hand' },
    { key: 'safety_stock', label: 'Safety stock' },
];
const ledgerColumns = [
    { key: 'created_at', label: 'When' },
    { key: 'kind', label: 'Kind' },
    { key: 'item.name', label: 'Item' },
    { key: 'quantity', label: 'Qty' },
    { key: 'storeroom.name', label: 'Storeroom' },
    { key: 'reference', label: 'Ref' },
];

onMounted(loadAll);

defineExpose({ loadAll, openItemForm, saveItem, openTx, submitTx });
</script>

<template>
    <section>
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-semibold">Inventory</h1>
            <div class="space-x-2">
                <AppButton variant="secondary" @click="openTx('receive')">Receive</AppButton>
                <AppButton variant="secondary" @click="openTx('issue')">Issue</AppButton>
                <AppButton variant="secondary" @click="openTx('transfer')">Transfer</AppButton>
                <AppButton @click="openItemForm">+ Item</AppButton>
            </div>
        </div>

        <div class="flex gap-2 border-b mb-4">
            <button
                v-for="t in ['items','stock','low','ledger']"
                :key="t"
                class="px-4 py-2 text-sm font-medium border-b-2"
                :class="tab === t ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-600'"
                :data-test="`tab-${t}`"
                @click="tab = t"
            >{{ t }}</button>
        </div>

        <DataTable v-if="tab === 'items'" :columns="itemColumns" :rows="items" :loading="loading" />
        <DataTable v-else-if="tab === 'stock'" :columns="stockColumns" :rows="stockLevels" :loading="loading" />
        <DataTable v-else-if="tab === 'low'" :columns="lowColumns" :rows="lowStock" :loading="loading" />
        <DataTable v-else-if="tab === 'ledger'" :columns="ledgerColumns" :rows="ledger" :loading="loading" />

        <Modal :open="itemForm.open" title="New inventory item" @close="itemForm.open = false">
            <form class="space-y-3" @submit.prevent="saveItem">
                <div>
                    <label class="block text-sm font-medium">External key</label>
                    <input v-model="itemForm.data.external_key" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Name</label>
                    <input v-model="itemForm.data.name" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">SKU</label>
                    <input v-model="itemForm.data.sku" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Category</label>
                    <input v-model="itemForm.data.category" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Unit of measure</label>
                    <input v-model="itemForm.data.unit_of_measure" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div v-if="itemForm.error" class="text-sm text-red-600" data-test="item-error">{{ itemForm.error }}</div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="itemForm.open = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="itemForm.busy">Save</AppButton>
                </div>
            </form>
        </Modal>

        <Modal :open="txForm.open" :title="'Inventory ' + txForm.mode" @close="txForm.open = false">
            <form class="space-y-3" @submit.prevent="submitTx">
                <div>
                    <label class="block text-sm font-medium">Item ID</label>
                    <input v-model.number="txForm.data.item_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div v-if="txForm.mode !== 'transfer'">
                    <label class="block text-sm font-medium">Storeroom ID</label>
                    <input v-model.number="txForm.data.storeroom_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <template v-else>
                    <div>
                        <label class="block text-sm font-medium">From storeroom</label>
                        <input v-model.number="txForm.data.from_storeroom_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                    </div>
                    <div>
                        <label class="block text-sm font-medium">To storeroom</label>
                        <input v-model.number="txForm.data.to_storeroom_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                    </div>
                </template>
                <div>
                    <label class="block text-sm font-medium">Quantity</label>
                    <input v-model.number="txForm.data.quantity" type="number" min="1" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Reference (optional)</label>
                    <input v-model="txForm.data.reference" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div v-if="txForm.error" class="text-sm text-red-600" data-test="tx-error">{{ txForm.error }}</div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="txForm.open = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="txForm.busy">Submit</AppButton>
                </div>
            </form>
        </Modal>
    </section>
</template>
