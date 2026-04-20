import { defineStore } from 'pinia';

let nextId = 1;

export const useNotificationsStore = defineStore('notifications', {
    state: () => ({ items: [] }),
    actions: {
        push(kind, message, ttl = 5000) {
            const id = nextId++;
            this.items.push({ id, kind, message });
            if (ttl > 0) {
                setTimeout(() => this.dismiss(id), ttl);
            }
            return id;
        },
        success(msg, ttl) { return this.push('success', msg, ttl); },
        error(msg, ttl) { return this.push('error', msg, ttl); },
        info(msg, ttl) { return this.push('info', msg, ttl); },
        dismiss(id) {
            this.items = this.items.filter((i) => i.id !== id);
        },
        clear() {
            this.items = [];
        },
    },
});
