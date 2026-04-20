<script setup>
import CrudView from '@/components/CrudView.vue';
import { api } from '@/api';

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'facility_id', label: 'Facility' },
    { key: 'code', label: 'Code' },
];
const fields = [
    { key: 'name', label: 'Name', required: true },
    { key: 'code', label: 'Code', required: true },
    { key: 'facility_id', label: 'Facility ID', type: 'number', required: true },
];
</script>

<template>
    <CrudView title="Departments" :resource="api.departments" :columns="columns" :fields="fields" />
</template>
