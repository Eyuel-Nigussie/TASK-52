<script setup>
import CrudView from '@/components/CrudView.vue';
import { api } from '@/api';

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'external_key', label: 'Code' },
    { key: 'city', label: 'City' },
    { key: 'state', label: 'State' },
    { key: 'business_hours', label: 'Hours' },
];
const fields = [
    { key: 'name', label: 'Name', required: true },
    { key: 'external_key', label: 'External code', required: true },
    { key: 'address', label: 'Address', required: true },
    { key: 'city', label: 'City', required: true },
    { key: 'state', label: 'State (2-letter)', required: true },
    { key: 'zip', label: 'ZIP code', required: true },
    { key: 'email', label: 'Email', type: 'email' },
    { key: 'phone', label: 'Phone' },
];
</script>

<template>
    <CrudView title="Facilities" :resource="api.facilities" :columns="columns" :fields="fields" />
</template>
