<script setup>
import { ref, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { extractErrorMessage } from '@/api/client';
import AppButton from '@/components/ui/AppButton.vue';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const username = ref('');
const password = ref('');
const captchaToken = ref('');
const error = ref('');
const checking = ref(false);

watch(username, async (v) => {
    if (!v) {
        auth.captchaRequired = false;
        auth.captchaChallenge = '';
        return;
    }
    checking.value = true;
    try {
        await auth.checkCaptcha(v);
    } catch {
        // non-fatal
    } finally {
        checking.value = false;
    }
});

async function submit() {
    error.value = '';
    try {
        const res = await auth.login({
            username: username.value,
            password: password.value,
            captcha_token: auth.captchaRequired ? captchaToken.value : undefined,
        });
        if (res.requires_password_change) {
            router.push('/change-password');
        } else {
            const redirect = route.query.redirect;
            router.push(typeof redirect === 'string' ? redirect : '/dashboard');
        }
    } catch (e) {
        error.value = extractErrorMessage(e, 'Invalid credentials.');
    }
}
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-100 p-4">
        <div class="bg-white p-8 rounded shadow w-full max-w-md">
            <h1 class="text-2xl font-bold mb-1">VetOps</h1>
            <p class="text-sm text-gray-600 mb-6">Sign in to continue</p>
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium">Username</label>
                    <input
                        v-model="username"
                        type="text"
                        required
                        autocomplete="username"
                        class="mt-1 block w-full border rounded px-3 py-2"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium">Password</label>
                    <input
                        v-model="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="mt-1 block w-full border rounded px-3 py-2"
                    />
                </div>
                <div v-if="auth.captchaRequired" data-test="captcha">
                    <label class="block text-sm font-medium">CAPTCHA</label>
                    <p
                        v-if="auth.captchaChallenge"
                        class="mt-1 text-sm text-gray-800 font-mono bg-gray-100 border rounded px-3 py-2"
                        data-test="captcha-challenge"
                    >
                        What is <span class="font-semibold">{{ auth.captchaChallenge }}</span>?
                    </p>
                    <input
                        v-model="captchaToken"
                        type="text"
                        class="mt-1 block w-full border rounded px-3 py-2"
                        placeholder="Type the answer"
                        data-test="captcha-input"
                        required
                    />
                    <p class="text-xs text-gray-500 mt-1">
                        Too many failed attempts — please solve the challenge above.
                    </p>
                </div>
                <div v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</div>
                <AppButton type="submit" :loading="auth.loading" class="w-full">
                    Sign in
                </AppButton>
                <p v-if="checking" class="text-xs text-gray-400 text-center">Checking captcha status…</p>
            </form>
        </div>
    </div>
</template>
