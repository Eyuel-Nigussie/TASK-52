<script setup>
import { ref, onMounted, computed } from 'vue';
import DataTable from '@/components/ui/DataTable.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Modal from '@/components/ui/Modal.vue';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';

const props = defineProps({
    title: { type: String, required: true },
    resource: { type: Object, required: true }, // { list, create, update, remove }
    columns: { type: Array, required: true },
    fields: { type: Array, default: () => [] }, // form fields
    canCreate: { type: Boolean, default: true },
    canEdit: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: false },
    initialForm: { type: Object, default: () => ({}) },
    listKey: { type: String, default: 'data' }, // paginator has `data`
});

const notes = useNotificationsStore();
const rows = ref([]);
const loading = ref(false);
const saving = ref(false);
const showForm = ref(false);
const editing = ref(null);
const form = ref({ ...props.initialForm });
const errorText = ref('');

const formTitle = computed(() => (editing.value ? `Edit ${props.title.replace(/s$/, '')}` : `New ${props.title.replace(/s$/, '')}`));

async function load() {
    loading.value = true;
    try {
        const res = await props.resource.list();
        rows.value = Array.isArray(res) ? res : (res?.[props.listKey] || res?.data || []);
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Failed to load records.'));
    } finally {
        loading.value = false;
    }
}

function openNew() {
    editing.value = null;
    form.value = { ...props.initialForm };
    errorText.value = '';
    showForm.value = true;
}

function openEdit(row) {
    if (!props.canEdit) return;
    editing.value = row;
    form.value = { ...props.initialForm, ...row };
    errorText.value = '';
    showForm.value = true;
}

async function save() {
    saving.value = true;
    errorText.value = '';
    try {
        if (editing.value) {
            await props.resource.update(editing.value.id, form.value);
            notes.success('Saved.');
        } else {
            await props.resource.create(form.value);
            notes.success('Created.');
        }
        showForm.value = false;
        await load();
    } catch (e) {
        errorText.value = extractErrorMessage(e, 'Save failed.');
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    if (!props.canDelete) return;
    try {
        await props.resource.remove(row.id);
        notes.success('Deleted.');
        await load();
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Delete failed.'));
    }
}

onMounted(load);

defineExpose({ load, openNew, openEdit, save, remove, rows, form });
</script>

<template>
    <section>
        <header class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-semibold">{{ title }}</h1>
            <AppButton v-if="canCreate" @click="openNew">+ New</AppButton>
        </header>

        <DataTable
            :columns="columns"
            :rows="rows"
            :loading="loading"
            @rowClick="openEdit"
        >
            <template v-for="col in columns" #[`cell-${col.key}`]="slotProps" :key="col.key">
                <slot :name="`cell-${col.key}`" v-bind="slotProps" />
            </template>
            <template v-if="canDelete" #[`cell-__actions`]="{ row }">
                <button class="text-red-600 text-xs underline" @click.stop="remove(row)">Delete</button>
            </template>
        </DataTable>

        <Modal :open="showForm" :title="formTitle" @close="showForm = false">
            <form class="space-y-3" @submit.prevent="save">
                <div v-for="f in fields" :key="f.key">
                    <label class="block text-sm font-medium text-gray-700">{{ f.label }}</label>
                    <select
                        v-if="f.type === 'select'"
                        v-model="form[f.key]"
                        class="mt-1 block w-full border rounded px-3 py-2 text-sm"
                    >
                        <option v-for="opt in f.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <textarea
                        v-else-if="f.type === 'textarea'"
                        v-model="form[f.key]"
                        rows="3"
                        class="mt-1 block w-full border rounded px-3 py-2 text-sm"
                    />
                    <input
                        v-else
                        v-model="form[f.key]"
                        :type="f.type || 'text'"
                        class="mt-1 block w-full border rounded px-3 py-2 text-sm"
                        :required="f.required"
                    />
                </div>
                <div v-if="errorText" class="text-sm text-red-600" data-test="form-error">{{ errorText }}</div>
                <div class="flex justify-end gap-2 pt-2">
                    <AppButton type="button" variant="secondary" @click="showForm = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="saving">Save</AppButton>
                </div>
            </form>
        </Modal>
    </section>
</template>
