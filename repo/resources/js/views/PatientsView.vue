<script setup>
import CrudView from '@/components/CrudView.vue';
import { api } from '@/api';

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'species', label: 'Species' },
    { key: 'breed', label: 'Breed' },
    { key: 'owner_name', label: 'Owner' },
    { key: 'owner_phone_masked', label: 'Phone' },
];
const fields = [
    { key: 'facility_id', label: 'Facility ID', type: 'number', required: true },
    { key: 'external_key', label: 'External key', required: true },
    { key: 'name', label: 'Patient name', required: true },
    { key: 'species', label: 'Species' },
    { key: 'breed', label: 'Breed' },
    { key: 'owner_name', label: 'Owner name' },
    { key: 'owner_phone', label: 'Owner phone' },
    { key: 'owner_email', label: 'Owner email', type: 'email' },
];
</script>

<template>
    <CrudView title="Patients" :resource="api.patients" :columns="columns" :fields="fields" />
</template>
