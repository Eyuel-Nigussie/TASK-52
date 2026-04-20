<script setup>
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
    loading: { type: Boolean, default: false },
    empty: { type: String, default: 'No records found.' },
});
defineEmits(['rowClick']);
function valueFor(row, col) {
    if (typeof col.value === 'function') return col.value(row);
    const path = col.key || col.value;
    if (!path) return '';
    return String(path).split('.').reduce((acc, k) => (acc == null ? acc : acc[k]), row);
}
</script>

<template>
    <div class="overflow-x-auto border border-gray-200 rounded">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-700">
                <tr>
                    <th
                        v-for="col in props.columns"
                        :key="col.key || col.label"
                        class="text-left px-3 py-2 font-semibold"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td :colspan="props.columns.length" class="px-3 py-6 text-center text-gray-500">Loading…</td>
                </tr>
                <tr v-else-if="!rows.length">
                    <td :colspan="props.columns.length" class="px-3 py-6 text-center text-gray-500">{{ empty }}</td>
                </tr>
                <tr
                    v-for="row in rows"
                    :key="row[rowKey]"
                    class="border-t hover:bg-gray-50 cursor-pointer"
                    @click="$emit('rowClick', row)"
                >
                    <td v-for="col in props.columns" :key="col.key || col.label" class="px-3 py-2 align-top">
                        <slot :name="`cell-${col.key}`" :row="row" :value="valueFor(row, col)">
                            {{ valueFor(row, col) }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
