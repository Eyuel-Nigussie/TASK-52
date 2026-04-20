<script setup>
import CrudView from '@/components/CrudView.vue';
import { api } from '@/api';

const columns = [
    { key: 'id', label: 'ID' },
    { key: 'patient_id', label: 'Patient' },
    { key: 'doctor_id', label: 'Doctor' },
    { key: 'status', label: 'Status' },
    { key: 'visit_date', label: 'Visit date' },
];
const fields = [
    { key: 'patient_id', label: 'Patient ID', type: 'number', required: true },
    { key: 'doctor_id', label: 'Doctor ID', type: 'number', required: true },
    { key: 'facility_id', label: 'Facility ID', type: 'number', required: true },
    { key: 'visit_date', label: 'Visit date', type: 'datetime-local', required: true },
    { key: 'service_order_id', label: 'Service order ID (optional)', type: 'number' },
    { key: 'status', label: 'Status', type: 'select', options: [
        { value: 'scheduled', label: 'Scheduled' },
        { value: 'completed', label: 'Completed' },
        { value: 'cancelled', label: 'Cancelled' },
    ] },
];
</script>

<template>
    <CrudView title="Visits" :resource="api.visits" :columns="columns" :fields="fields" />
</template>
