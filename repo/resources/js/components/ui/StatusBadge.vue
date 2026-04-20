<script setup>
defineProps({
    status: { type: String, required: true },
});
const styles = {
    available: 'bg-green-100 text-green-800',
    rented: 'bg-blue-100 text-blue-800',
    active: 'bg-blue-100 text-blue-800',
    in_maintenance: 'bg-yellow-100 text-yellow-800',
    deactivated: 'bg-gray-200 text-gray-700',
    overdue: 'bg-red-100 text-red-800',
    returned: 'bg-gray-100 text-gray-700',
    cancelled: 'bg-gray-100 text-gray-700',
    draft: 'bg-gray-100 text-gray-700',
    in_review: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-indigo-100 text-indigo-800',
    published: 'bg-green-100 text-green-800',
    hidden: 'bg-gray-200 text-gray-700',
    pending: 'bg-yellow-100 text-yellow-800',
    open: 'bg-blue-100 text-blue-800',
    closed: 'bg-gray-100 text-gray-700',
};
function labelFor(s) {
    return String(s || '').replace(/_/g, ' ');
}
</script>

<template>
    <span :class="['inline-block px-2 py-0.5 rounded text-xs font-medium capitalize', styles[status] || 'bg-gray-100 text-gray-800']">
        {{ labelFor(status) }}
    </span>
</template>
