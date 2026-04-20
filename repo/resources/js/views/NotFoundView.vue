<script setup>
import AppButton from '@/components/ui/AppButton.vue';
import { useRouter } from 'vue-router';
const router = useRouter();
</script>

<template>
    <div class="min-h-screen flex items-center justify-center">
        <div class="text-center">
            <div class="text-6xl font-bold text-gray-300">404</div>
            <p class="text-gray-600 mt-2 mb-4">Page not found.</p>
            <AppButton @click="router.push('/dashboard')">Go to Dashboard</AppButton>
        </div>
    </div>
</template>
