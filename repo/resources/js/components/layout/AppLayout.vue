<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useRouter } from 'vue-router';
import { navFor } from '@/router/nav';
import { ROLE_LABEL } from '@/utils/roles';
import Toasts from '@/components/ui/Toasts.vue';

const auth = useAuthStore();
const router = useRouter();

const navItems = computed(() => navFor(auth.user));

async function handleLogout() {
    await auth.logout();
    router.push('/login');
}
</script>

<template>
    <div class="min-h-screen flex bg-gray-50 text-gray-900">
        <aside class="w-56 bg-gray-900 text-white flex flex-col">
            <div class="px-4 py-5 border-b border-gray-800">
                <div class="text-lg font-bold tracking-tight">VetOps</div>
                <div class="text-xs text-gray-400">Operations Portal</div>
            </div>
            <nav class="flex-1 px-2 py-3 space-y-0.5 overflow-y-auto">
                <router-link
                    v-for="item in navItems"
                    :key="item.to"
                    :to="item.to"
                    class="block px-3 py-2 rounded text-sm hover:bg-gray-800"
                    active-class="bg-gray-800 text-white"
                >
                    {{ item.label }}
                </router-link>
            </nav>
            <div class="px-4 py-3 border-t border-gray-800 text-xs">
                <div class="font-medium">{{ auth.user?.name }}</div>
                <div class="text-gray-400">{{ ROLE_LABEL[auth.user?.role] || auth.user?.role }}</div>
                <button
                    class="mt-2 text-xs underline text-gray-300 hover:text-white"
                    @click="handleLogout"
                >
                    Sign out
                </button>
            </div>
        </aside>
        <main class="flex-1 overflow-auto">
            <div class="p-6">
                <router-view />
            </div>
        </main>
        <Toasts />
    </div>
</template>
