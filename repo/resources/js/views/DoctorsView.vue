<script setup>
import CrudView from '@/components/CrudView.vue';
import { api } from '@/api';

const columns = [
    { key: 'first_name', label: 'First name' },
    { key: 'last_name', label: 'Last name' },
    { key: 'specialty', label: 'Specialty' },
    { key: 'license_number', label: 'License' },
    { key: 'facility_id', label: 'Facility' },
];
const fields = [
    { key: 'facility_id', label: 'Facility ID', type: 'number', required: true },
    { key: 'external_key', label: 'External key', required: true },
    { key: 'first_name', label: 'First name', required: true },
    { key: 'last_name', label: 'Last name', required: true },
    { key: 'specialty', label: 'Specialty' },
    { key: 'license_number', label: 'License number' },
    { key: 'email', label: 'Email', type: 'email' },
];
</script>

<template>
    <CrudView title="Doctors" :resource="api.doctors" :columns="columns" :fields="fields" />
</template>
