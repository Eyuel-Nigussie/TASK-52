<script setup>
import { ref, onMounted, computed } from 'vue';
import { api } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';
import { extractErrorMessage } from '@/api/client';
import { canApproveContent } from '@/utils/roles';
import DataTable from '@/components/ui/DataTable.vue';
import AppButton from '@/components/ui/AppButton.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';

const auth = useAuthStore();
const notes = useNotificationsStore();
const items = ref([]);
const loading = ref(false);
const selected = ref(null);
const versions = ref([]);

const editorOpen = ref(false);
const editing = ref(null);
const form = ref({
    type: 'announcement',
    title: '',
    body: '',
    excerpt: '',
    priority: 0,
    // Visibility targeting — server stores each as JSON array columns.
    facility_ids_csv: '',
    department_ids_csv: '',
    role_targets_csv: '',
    tags_csv: '',
});
const saving = ref(false);
const error = ref('');

function parseIdCsv(csv) {
    if (!csv) return null;
    const ids = csv.split(',').map(s => parseInt(s.trim(), 10)).filter(n => Number.isFinite(n));
    return ids.length ? ids : null;
}
function parseCsv(csv) {
    if (!csv) return null;
    const parts = csv.split(',').map(s => s.trim()).filter(Boolean);
    return parts.length ? parts : null;
}
function serializeTargetingToForm(row) {
    return {
        facility_ids_csv: (row.facility_ids || []).join(','),
        department_ids_csv: (row.department_ids || []).join(','),
        role_targets_csv: (row.role_targets || []).join(','),
        tags_csv: (row.tags || []).join(','),
    };
}

const canApprove = computed(() => canApproveContent(auth.user));

async function load() {
    loading.value = true;
    try {
        const res = await api.content.list();
        items.value = res?.data || res || [];
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Load failed.'));
    } finally {
        loading.value = false;
    }
}

function openNew() {
    editing.value = null;
    form.value = {
        type: 'announcement',
        title: '',
        body: '',
        excerpt: '',
        priority: 0,
        facility_ids_csv: '',
        department_ids_csv: '',
        role_targets_csv: '',
        tags_csv: '',
    };
    error.value = '';
    editorOpen.value = true;
}

function openEdit(row) {
    editing.value = row;
    form.value = {
        type: row.type,
        title: row.title,
        body: row.body,
        excerpt: row.excerpt || '',
        priority: row.priority ?? 0,
        ...serializeTargetingToForm(row),
    };
    error.value = '';
    editorOpen.value = true;
}

async function save() {
    saving.value = true;
    error.value = '';
    const payload = {
        type: form.value.type,
        title: form.value.title,
        body: form.value.body,
        excerpt: form.value.excerpt,
        priority: form.value.priority,
        facility_ids: parseIdCsv(form.value.facility_ids_csv),
        department_ids: parseIdCsv(form.value.department_ids_csv),
        role_targets: parseCsv(form.value.role_targets_csv),
        tags: parseCsv(form.value.tags_csv),
    };
    try {
        if (editing.value) await api.content.update(editing.value.id, payload);
        else await api.content.create(payload);
        notes.success('Saved.');
        editorOpen.value = false;
        await load();
    } catch (e) {
        error.value = extractErrorMessage(e, 'Save failed.');
    } finally {
        saving.value = false;
    }
}

async function doAction(fn, ok, row) {
    try {
        await fn(row.id);
        notes.success(ok);
        await load();
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Action failed.'));
    }
}

async function showVersions(row) {
    selected.value = row;
    try {
        versions.value = await api.contentVersions(row.id);
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Failed to load versions.'));
    }
}

async function rollback(v) {
    try {
        await api.contentRollback(selected.value.id, v.version);
        notes.success(`Rolled back to v${v.version}`);
        selected.value = null;
        await load();
    } catch (e) {
        notes.error(extractErrorMessage(e, 'Rollback failed.'));
    }
}

const columns = [
    { key: 'title', label: 'Title' },
    { key: 'type', label: 'Type' },
    { key: 'status', label: 'Status' },
    { key: 'version', label: 'v' },
    { key: '__actions', label: 'Actions' },
];

onMounted(load);

defineExpose({ load, openNew, openEdit, save, doAction, showVersions, rollback });
</script>

<template>
    <section>
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-semibold">Content</h1>
            <AppButton @click="openNew">+ New draft</AppButton>
        </div>

        <DataTable :columns="columns" :rows="items" :loading="loading">
            <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
            <template #cell-__actions="{ row }">
                <button class="text-xs underline mr-2" @click.stop="openEdit(row)">Edit</button>
                <button v-if="row.status === 'draft'" class="text-xs underline mr-2" @click.stop="doAction(api.contentSubmit, 'Submitted', row)">Submit</button>
                <button v-if="canApprove && row.status === 'in_review'" class="text-xs underline mr-2" @click.stop="doAction(api.contentApprove, 'Approved', row)">Approve</button>
                <button v-if="canApprove && row.status === 'approved'" class="text-xs underline mr-2" @click.stop="doAction(api.contentPublish, 'Published', row)">Publish</button>
                <button class="text-xs underline" @click.stop="showVersions(row)">History</button>
            </template>
        </DataTable>

        <Modal :open="editorOpen" :title="editing ? 'Edit content' : 'New content'" @close="editorOpen = false">
            <form class="space-y-3" @submit.prevent="save">
                <div>
                    <label class="block text-sm font-medium">Type</label>
                    <select v-model="form.type" class="mt-1 block w-full border rounded px-3 py-2 text-sm">
                        <option value="announcement">Announcement</option>
                        <option value="carousel">Carousel</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Title</label>
                    <input v-model="form.title" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Excerpt</label>
                    <input v-model="form.excerpt" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Body</label>
                    <textarea v-model="form.body" rows="6" class="mt-1 block w-full border rounded px-3 py-2 text-sm" required />
                </div>
                <div>
                    <label class="block text-sm font-medium">Priority</label>
                    <input v-model.number="form.priority" type="number" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                </div>

                <fieldset class="border rounded p-3 space-y-3">
                    <legend class="text-sm font-medium px-1">Visibility targeting (leave blank for everyone)</legend>
                    <div>
                        <label class="block text-xs text-gray-600">Facility IDs (comma-separated)</label>
                        <input v-model="form.facility_ids_csv" data-test="targeting-facility" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600">Department IDs (comma-separated)</label>
                        <input v-model="form.department_ids_csv" data-test="targeting-department" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600">Role targets (e.g. clinic_manager,technician_doctor)</label>
                        <input v-model="form.role_targets_csv" data-test="targeting-roles" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600">Tags (comma-separated)</label>
                        <input v-model="form.tags_csv" data-test="targeting-tags" class="mt-1 block w-full border rounded px-3 py-2 text-sm" />
                    </div>
                </fieldset>

                <div v-if="error" class="text-sm text-red-600" data-test="content-error">{{ error }}</div>
                <div class="flex justify-end gap-2">
                    <AppButton type="button" variant="secondary" @click="editorOpen = false">Cancel</AppButton>
                    <AppButton type="submit" :loading="saving">Save</AppButton>
                </div>
            </form>
        </Modal>

        <Modal :open="!!selected" :title="`Versions — ${selected?.title}`" @close="selected = null">
            <ul class="divide-y max-h-96 overflow-auto">
                <li v-for="v in versions" :key="v.version" class="py-2 flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium">v{{ v.version }}</div>
                        <div class="text-xs text-gray-500">{{ v.note || v.created_at }}</div>
                    </div>
                    <button class="text-xs underline text-blue-600" @click="rollback(v)">Roll back</button>
                </li>
            </ul>
        </Modal>
    </section>
</template>
