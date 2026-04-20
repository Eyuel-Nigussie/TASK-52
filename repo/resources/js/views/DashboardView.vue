<script setup>
import { ref, onMounted, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/api';
import { ROLE_LABEL } from '@/utils/roles';

const auth = useAuthStore();
const overdue = ref([]);
const lowStock = ref([]);
const loading = ref(false);

const greeting = computed(() => `Welcome back, ${auth.user?.name || 'User'}`);
const roleLabel = computed(() => ROLE_LABEL[auth.user?.role] || '');

onMounted(async () => {
    loading.value = true;
    try {
        const [o, ls] = await Promise.all([
            api.rentalOverdue().catch(() => []),
            api.inventoryLowStock().catch(() => []),
        ]);
        overdue.value = Array.isArray(o) ? o : (o?.data || []);
        lowStock.value = Array.isArray(ls) ? ls : (ls?.data || []);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <section>
        <h1 class="text-2xl font-semibold mb-1">{{ greeting }}</h1>
        <p class="text-sm text-gray-500 mb-6">{{ roleLabel }}</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold mb-2">Overdue Rentals</h2>
                <p v-if="!overdue.length && !loading" class="text-sm text-gray-500">No overdue rentals.</p>
                <ul v-else class="text-sm divide-y">
                    <li v-for="r in overdue" :key="r.id" class="py-2" data-test="overdue-item">
                        #{{ r.id }} — {{ r.asset?.name || 'Asset' }}
                    </li>
                </ul>
            </div>
            <div class="bg-white rounded shadow p-4">
                <h2 class="font-semibold mb-2">Low Stock Alerts</h2>
                <p v-if="!lowStock.length && !loading" class="text-sm text-gray-500">Stock levels are healthy.</p>
                <ul v-else class="text-sm divide-y">
                    <li v-for="l in lowStock" :key="`${l.item_id}-${l.storeroom_id}`" class="py-2" data-test="low-item">
                        {{ l.item?.name || `Item #${l.item_id}` }} — {{ l.on_hand }} on hand
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
