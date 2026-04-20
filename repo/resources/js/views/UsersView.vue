<script setup>
import CrudView from '@/components/CrudView.vue';
import { api } from '@/api';

const columns = [
    { key: 'username', label: 'Username' },
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'role', label: 'Role' },
    { key: 'active', label: 'Active' },
];
const fields = [
    { key: 'username', label: 'Username', required: true },
    { key: 'name', label: 'Name', required: true },
    { key: 'email', label: 'Email', type: 'email', required: true },
    { key: 'password', label: 'Password', type: 'password' },
    { key: 'role', label: 'Role', type: 'select', options: [
        { value: 'system_admin', label: 'System Admin' },
        { value: 'clinic_manager', label: 'Clinic Manager' },
        { value: 'inventory_clerk', label: 'Inventory Clerk' },
        { value: 'technician_doctor', label: 'Technician / Doctor' },
        { value: 'content_editor', label: 'Content Editor' },
        { value: 'content_approver', label: 'Content Approver' },
    ] },
    { key: 'facility_id', label: 'Facility ID', type: 'number' },
];
</script>

<template>
    <CrudView title="Users" :resource="api.users" :columns="columns" :fields="fields" :can-delete="true" />
</template>
