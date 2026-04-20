<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/api';
import { extractErrorMessage } from '@/api/client';
import AppButton from '@/components/ui/AppButton.vue';

const router = useRouter();
const auth = useAuthStore();

const current = ref('');
const password = ref('');
const confirmation = ref('');
const error = ref('');
const loading = ref(false);
const done = ref(false);

async function submit() {
    error.value = '';
    if (password.value !== confirmation.value) {
        error.value = 'Passwords do not match.';
        return;
    }
    loading.value = true;
    try {
        await api.changePassword({
            current_password: current.value,
            password: password.value,
            password_confirmation: confirmation.value,
        });
        done.value = true;
        auth.requiresPasswordChange = false;
        setTimeout(() => router.push('/dashboard'), 1500);
    } catch (e) {
        error.value = extractErrorMessage(e, 'Password change failed.');
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-100 p-4">
        <div class="bg-white p-8 rounded shadow w-full max-w-md">
            <h1 class="text-2xl font-bold mb-1">Change Password</h1>
            <p class="text-sm text-gray-600 mb-6">
                Your account was provisioned with a temporary password.
                Please set a new password before continuing.
            </p>
            <div v-if="done" class="text-green-600 font-medium text-center py-4" role="status">
                Password changed successfully. Redirecting…
            </div>
            <form v-else class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium">Current (temporary) password</label>
                    <input
                        v-model="current"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="mt-1 block w-full border rounded px-3 py-2"
                        data-test="current-password"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium">New password</label>
                    <input
                        v-model="password"
                        type="password"
                        required
                        autocomplete="new-password"
                        minlength="12"
                        class="mt-1 block w-full border rounded px-3 py-2"
                        data-test="new-password"
                    />
                    <p class="text-xs text-gray-400 mt-1">Minimum 12 characters.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Confirm new password</label>
                    <input
                        v-model="confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-1 block w-full border rounded px-3 py-2"
                        data-test="confirm-password"
                    />
                </div>
                <div v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</div>
                <AppButton type="submit" :loading="loading" class="w-full">
                    Set new password
                </AppButton>
            </form>
        </div>
    </div>
</template>
