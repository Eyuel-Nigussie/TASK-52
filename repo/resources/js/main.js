import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { createAppRouter } from './router';
import { initApi } from './api';
import { useAuthStore } from './stores/auth';

const pinia = createPinia();
const app = createApp(App);
app.use(pinia);

initApi({
    getToken: () => useAuthStore().token,
    onUnauthorized: () => {
        const auth = useAuthStore();
        auth.clear();
        if (router.currentRoute.value.name !== 'login') {
            router.push({ name: 'login' });
        }
    },
});

const router = createAppRouter();
app.use(router);
app.mount('#app');
