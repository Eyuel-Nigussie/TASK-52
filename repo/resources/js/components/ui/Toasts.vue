<script setup>
import { useNotificationsStore } from '@/stores/notifications';
const notes = useNotificationsStore();
const kindStyle = {
    success: 'bg-green-600',
    error: 'bg-red-600',
    info: 'bg-blue-600',
};
</script>

<template>
    <div class="fixed top-4 right-4 space-y-2 z-50">
        <div
            v-for="n in notes.items"
            :key="n.id"
            :class="['text-white px-4 py-2 rounded shadow', kindStyle[n.kind] || 'bg-gray-800']"
            role="status"
            @click="notes.dismiss(n.id)"
        >
            {{ n.message }}
        </div>
    </div>
</template>
