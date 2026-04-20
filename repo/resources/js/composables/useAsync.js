import { ref } from 'vue';
import { extractErrorMessage } from '@/api/client';

export function useAsync(fn) {
    const loading = ref(false);
    const error = ref(null);
    const data = ref(null);

    async function run(...args) {
        loading.value = true;
        error.value = null;
        try {
            const result = await fn(...args);
            data.value = result;
            return result;
        } catch (e) {
            error.value = extractErrorMessage(e);
            throw e;
        } finally {
            loading.value = false;
        }
    }

    return { loading, error, data, run };
}
