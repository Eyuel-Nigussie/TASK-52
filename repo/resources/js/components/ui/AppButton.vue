<script setup>
const props = defineProps({
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});
defineEmits(['click']);
const variants = {
    primary: 'bg-blue-600 hover:bg-blue-700 text-white',
    secondary: 'bg-gray-200 hover:bg-gray-300 text-gray-900',
    danger: 'bg-red-600 hover:bg-red-700 text-white',
    ghost: 'bg-transparent hover:bg-gray-100 text-gray-900',
};
const sizes = { sm: 'px-2 py-1 text-xs', md: 'px-4 py-2 text-sm', lg: 'px-6 py-3 text-base' };
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'rounded font-medium transition disabled:opacity-60 disabled:cursor-not-allowed',
            variants[props.variant] || variants.primary,
            sizes[props.size] || sizes.md,
        ]"
        @click="$emit('click', $event)"
    >
        <span v-if="loading" data-test="spinner" class="inline-block mr-2 animate-spin">⟳</span>
        <slot />
    </button>
</template>
