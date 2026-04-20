import { ROLES } from '@/utils/roles';

export const NAV = [
    { to: '/dashboard',  label: 'Dashboard',  icon: 'home',    roles: null },
    { to: '/rentals',    label: 'Rentals',    icon: 'truck',   roles: null },
    { to: '/inventory',  label: 'Inventory',  icon: 'box',     roles: null },
    { to: '/stocktake',  label: 'Stocktake',  icon: 'clip',    roles: [ROLES.SYSTEM_ADMIN, ROLES.CLINIC_MANAGER, ROLES.INVENTORY_CLERK] },
    { to: '/service-orders', label: 'Service Orders', icon: 'order', roles: null },
    { to: '/content',    label: 'Content',    icon: 'pen',     roles: [ROLES.SYSTEM_ADMIN, ROLES.CONTENT_EDITOR, ROLES.CONTENT_APPROVER] },
    { to: '/reviews',    label: 'Reviews',    icon: 'star',    roles: null },
    { to: '/patients',   label: 'Patients',   icon: 'paw',     roles: null },
    { to: '/visits',     label: 'Visits',     icon: 'cal',     roles: null },
    { to: '/doctors',    label: 'Doctors',    icon: 'md',      roles: null },
    { to: '/facilities', label: 'Facilities', icon: 'house',   roles: [ROLES.SYSTEM_ADMIN, ROLES.CLINIC_MANAGER] },
    { to: '/departments',label: 'Departments',icon: 'grid',    roles: [ROLES.SYSTEM_ADMIN, ROLES.CLINIC_MANAGER] },
    { to: '/users',      label: 'Users',      icon: 'users',   roles: [ROLES.SYSTEM_ADMIN] },
    { to: '/merge-requests', label: 'Merges', icon: 'merge',   roles: [ROLES.SYSTEM_ADMIN, ROLES.CLINIC_MANAGER] },
    { to: '/audit-logs', label: 'Audit Logs', icon: 'log',     roles: [ROLES.SYSTEM_ADMIN, ROLES.CLINIC_MANAGER] },
];

export function navFor(user) {
    if (!user) return [];
    return NAV.filter((item) => item.roles === null || item.roles.includes(user.role));
}
