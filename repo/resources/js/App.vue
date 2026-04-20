<script setup>
import { onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

onMounted(async () => {
    if (!auth.user) {
        // Attempt a silent session restore via the HttpOnly vetops_session cookie.
        // refreshSession() clears auth state on failure so the router guard can
        // redirect to /login normally.
        await auth.refreshSession();
    }
});
</script>

<template>
    <router-view />
</template>
