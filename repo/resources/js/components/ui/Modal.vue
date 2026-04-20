<script setup>
defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, default: '' },
});
defineEmits(['close']);
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center" role="dialog">
        <div class="absolute inset-0 bg-black/40" @click="$emit('close')"></div>
        <div class="relative bg-white rounded shadow-lg max-w-lg w-full m-4 p-6">
            <div v-if="title" class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold">{{ title }}</h3>
                <button class="text-gray-500 hover:text-gray-800" aria-label="Close" @click="$emit('close')">✕</button>
            </div>
            <slot />
        </div>
    </div>
</template>
