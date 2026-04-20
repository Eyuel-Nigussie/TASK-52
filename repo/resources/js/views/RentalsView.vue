<script setup>
import { ref, onMounted, computed } from 'vue';
import { api } from '@/api';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';
import { formatCurrency, formatDateTime, countdown } from '@/utils/format';
import AppButton from '@/components/ui/AppButton.vue';
import DataTable from '@/components/ui/DataTable.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import Modal from '@/components/ui/Modal.vue';

const notes = useNotificationsStore();
const tab = ref('assets');

const assets = ref([]);
const transactions = ref([]);
const loadingAssets = ref(false);
const loadingTx = ref(false);

const scanCode = ref('');
const scanned = ref(null);

const checkoutOpen = ref(false);
const checkoutForm = ref({ asset_id: null, renter_type: 'department', renter_id: '', facility_id: '', expected_return_at: '', notes: '', fee_terms: '' });
const checkoutBusy = ref(false);
const checkoutError = ref('');

const returnOpen = ref(false);
const returningTx = ref(null);
const returnNotes = ref('');
const returnBusy = ref(false);

const assetColumns = [
    { key: 'photo', label: 'Photo' },
    { key: 'name', label: 'Name' },
    { key: 'category', label: 'Category' },
    { key: 'external_key', label: 'Asset #' },
    { key: 'status', label: 'Status' },
    { key: 'specs', label: 'Specs' },
    { key: 'daily_rate', label: 'Daily' },
    { key: 'deposit_amount', label: 'Deposit' },
];

function photoUrl(asset) {
    if (!asset?.photo_path) return null;
    // Files are served from /storage/{path} via Laravel's public disk link.
    const path = String(asset.photo_path).replace(/^\/?(public\/)?/, '');
    return `/storage/${path}`;
}

function specsSummary(specs) {
    if (!specs) return '';
    if (typeof specs === 'string') return specs;
    if (Array.isArray(specs)) return specs.join(', ');
    if (typeof specs === 'object') {
        return Object.entries(specs).map(([k, v]) => `${k}: ${v}`).join(', ');
    }
    return '';
}

const txColumns = [
    { key: 'id', label: 'ID' },
    { key: 'asset.name', label: 'Asset' },
    { key: 'status', label: 'Status' },
    { key: 'checked_out_at', label: 'Out' },
    { key: 'expected_return_at', label: 'Due' },
    { key: 'countdown', label: 'Countdown' },
    { key: '__actions', label: 'Actions' },
];

const overdueOnly = computed(() => transactions.value.filter((t) => ['active', 'overdue'].includes(t.status)));

async function loadAssets() {
    loadingAssets.value = true;
    try {
        const res = await api.rentalAssets.list();
        assets.value = res?.data || res || [];
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Failed to load assets.'));
    } finally {
        loadingAssets.value = false;
    }
}

async function loadTx() {
    loadingTx.value = true;
    try {
        const res = await api.rentalTransactions.list();
        transactions.value = res?.data || res || [];
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Failed to load transactions.'));
    } finally {
        loadingTx.value = false;
    }
}

async function doScan() {
    if (!scanCode.value) return;
    try {
        scanned.value = await api.rentalScan(scanCode.value);
        notes.success(`Found: ${scanned.value.name}`);
    } catch (e) {
        scanned.value = null;
        notes.error(extractErrorMessage(e, 'Asset not found.'));
    }
}

function openCheckout(asset) {
    if (asset.status !== 'available') {
        notes.error('Only available assets can be checked out.');
        return;
    }
    checkoutForm.value = {
        asset_id: asset.id,
        renter_type: 'department',
        renter_id: '',
        facility_id: asset.facility_id,
        expected_return_at: '',
        notes: '',
        fee_terms: '',
    };
    checkoutError.value = '';
    checkoutOpen.value = true;
}

async function submitCheckout() {
    checkoutBusy.value = true;
    checkoutError.value = '';
    try {
        await api.rentalCheckout(checkoutForm.value);
        notes.success('Checked out.');
        checkoutOpen.value = false;
        await Promise.all([loadAssets(), loadTx()]);
    } catch (e) {
        checkoutError.value = extractErrorMessage(e, 'Checkout failed.');
    } finally {
        checkoutBusy.value = false;
    }
}

function openReturn(tx) {
    returningTx.value = tx;
    returnNotes.value = '';
    returnOpen.value = true;
}

async function submitReturn() {
    returnBusy.value = true;
    try {
        await api.rentalReturn(returningTx.value.id, { notes: returnNotes.value });
        notes.success('Returned.');
        returnOpen.value = false;
        await Promise.all([loadAssets(), loadTx()]);
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Return failed.'));
    } finally {
        returnBusy.value = false;
    }
}

async function cancel(tx) {
    try {
        await api.rentalCancel(tx.id);
        notes.success('Cancelled.');
        await loadTx();
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Cancel failed.'));
    }
}

onMounted(async () => {
    await Promise.all([loadAssets(), loadTx()]);
});

defineExpose({ loadAssets, loadTx, doScan, openCheckout, submitCheckout, openReturn, submitReturn, cancel });
</script>

<template>
    <section>
        <h1 class="text-2xl font-semibold mb-4">Rental Equipment</h1>

        <div class="flex gap-2 border-b mb-4">
            <button
                v-for="t in ['assets','transactions','overdue','scan']"
                :key="t"
                class="px-4 py-2 text-sm font-medium border-b-2"
                :class="tab === t ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-600'"
                :data-test="`tab-${t}`"
                @click="tab = t"
            >{{ t }}</button>
        </div>

        <div v-if="tab === 'assets'">
            <DataTable :columns="assetColumns" :rows="assets" :loading="loadingAssets" @rowClick="openCheckout">
                <template #cell-photo="{ row }">
                    <img
                        v-if="photoUrl(row)"
                        :src="photoUrl(row)"
                        :alt="row.name + ' photo'"
                        class="h-10 w-14 object-cover rounded border"
                        data-test="asset-photo"
                    />
                    <span v-else class="text-xs text-gray-400" data-test="asset-photo-missing">(no photo)</span>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-specs="{ row }">
                    <span class="text-xs text-gray-600" data-test="asset-specs">{{ specsSummary(row.specs) }}</span>
                </template>
                <template #cell-daily_rate="{ row }">{{ formatCurrency(row.daily_rate) }}</template>
                <template #cell-deposit_amount="{ row }">{{ formatCurrency(row.deposit_amount) }}</template>
            </DataTable>
        </div>

        <div v-else-if="tab === 'transactions'">
            <DataTable :columns="txColumns" :rows="transactions" :loading="loadingTx">
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-checked_out_at="{ row }">{{ formatDateTime(row.checked_out_at) }}</template>
                <template #cell-expected_return_at="{ row }">{{ formatDateTime(row.expected_return_at) }}</template>
                <template #cell-countdown="{ row }">
                    <span :class="countdown(row.expected_return_at).expired ? 'text-red-600 font-semibold' : 'text-gray-700'">
                        {{ countdown(row.expected_return_at).expired ? '− ' + countdown(row.expected_return_at).label : countdown(row.expected_return_at).label }}
                    </span>
                </template>
                <template #cell-__actions="{ row }">
                    <button v-if="['active','overdue'].includes(row.status)" class="text-blue-600 text-xs underline" @click.stop="openReturn(row)">Return</button>
                    <button v-if="row.status === 'active'" class="ml-3 text-red-600 text-xs underline" @click.stop="cancel(row)">Cancel</button>
                </template>
            </DataTable>
        </div>

        <div v-else-if="tab === 'overdue'">
            <DataTable :columns="txColumns" :rows="overdueOnly">
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-checked_out_at="{ row }">{{ formatDateTime(row.checked_out_at) }}</template>
                <template #cell-expected_return_at="{ row }">{{ formatDateTime(row.expected_return_at) }}</template>
                <template #cell-countdown="{ row }">{{ countdown(row.expected_return_at).label }}</template>
                <template #cell-__actions="{ row }">
                    <button class="text-blue-600 text-xs underline" @click.stop="openReturn(row)">Return</button>
                </template>
            </DataTable>
        </div>

        <div v-else-if="tab === 'scan'">
            <div class="max-w-md bg-white p-4 rounded shadow">
                <label class="block text-sm font-medium mb-1">Scan or enter barcode / QR</label>
                <div class="flex gap-2">
                    <input v-model="scanCode" class="flex-1 border rounded px-3 py-2 text-sm" @keyup.enter="doScan" />
                    <AppButton @click="doScan">Lookup</AppButton>
                </div>
                <div v-if="scanned" class="mt-4 border-t pt-4 text-sm" data-test="scan-result">
                    <div class="flex gap-4">
                        <img
                            v-if="photoUrl(scanned)"
                            :src="photoUrl(scanned)"
                            :alt="scanned.name + ' photo'"
                            class="h-24 w-32 object-cover rounded border"
                            data-test="scan-photo"
                        />
                        <div class="flex-1">
                            <div class="font-semibold">{{ scanned.name }}</div>
                            <div class="text-gray-500">{{ scanned.category }} · {{ scanned.external_key }}</div>
                            <StatusBadge :status="scanned.status" class="mt-2" />
                            <div v-if="specsSummary(scanned.specs)" class="mt-2 text-xs text-gray-700" data-test="scan-specs">
                                <span class="font-medium">Specs:</span> {{ specsSummary(scanned.specs) }}
                            </div>
                            <AppButton v-if="scanned.status === 'available'" class="mt-3" @click="openCheckout(scanned)">Check out</AppButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :open="checkoutOpen" title="Check out asset" @close="checkoutOpen = false">
            <form class="space-y-3" @submit.prevent="submitCheckout">
                <div>
                    <label class="block text-sm font-medium">Renter type</label>
                    <select v-model="checkoutForm.renter_type" class="mt-1 block w-full border rounded px-3 py-2 text-sm">
                        <option value="department">Department</option>
                        <option value="clinician">Clinician</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Renter ID</label>
                    <input v-model="checkoutForm.renter_id" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Expected return</label>
                    <input v-model="checkoutForm.expected_return_at" type="datetime-local" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Fee terms (optional)</label>
                    <input v-model="checkoutForm.fee_terms" type="text" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div v-if="checkoutError" class="text-sm text-red-600" data-test="checkout-error">{{ checkoutError }}</div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="checkoutOpen = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="checkoutBusy">Check out</AppButton>
                </div>
            </form>
        </Modal>

        <Modal :open="returnOpen" title="Return rental" @close="returnOpen = false">
            <form class="space-y-3" @submit.prevent="submitReturn">
                <div>
                    <label class="block text-sm font-medium">Notes (optional)</label>
                    <textarea v-model="returnNotes" rows="3" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="returnOpen = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="returnBusy">Confirm return</AppButton>
                </div>
            </form>
        </Modal>
    </section>
</template>
