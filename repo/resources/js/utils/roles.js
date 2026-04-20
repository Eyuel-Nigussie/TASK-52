export const ROLES = Object.freeze({
    SYSTEM_ADMIN: 'system_admin',
    CLINIC_MANAGER: 'clinic_manager',
    INVENTORY_CLERK: 'inventory_clerk',
    TECHNICIAN_DOCTOR: 'technician_doctor',
    CONTENT_EDITOR: 'content_editor',
    CONTENT_APPROVER: 'content_approver',
});

export const ROLE_LABEL = {
    system_admin: 'System Administrator',
    clinic_manager: 'Clinic Manager',
    inventory_clerk: 'Inventory Clerk',
    technician_doctor: 'Technician / Doctor',
    content_editor: 'Content Editor',
    content_approver: 'Content Approver',
};

export function hasRole(user, ...allowed) {
    if (!user || !user.role) return false;
    return allowed.includes(user.role);
}

export function isAdmin(user) {
    return hasRole(user, ROLES.SYSTEM_ADMIN);
}

export function isManager(user) {
    return hasRole(user, ROLES.SYSTEM_ADMIN, ROLES.CLINIC_MANAGER);
}

export function canReceiveInventory(user) {
    return hasRole(user, ROLES.SYSTEM_ADMIN, ROLES.CLINIC_MANAGER, ROLES.INVENTORY_CLERK);
}

export function canIssueInventory(user) {
    return hasRole(user, ROLES.SYSTEM_ADMIN, ROLES.CLINIC_MANAGER, ROLES.INVENTORY_CLERK, ROLES.TECHNICIAN_DOCTOR);
}

export function canCheckoutRental(user) {
    return canIssueInventory(user);
}

export function canAuthorContent(user) {
    return hasRole(user, ROLES.SYSTEM_ADMIN, ROLES.CONTENT_EDITOR, ROLES.CONTENT_APPROVER);
}

export function canApproveContent(user) {
    return hasRole(user, ROLES.SYSTEM_ADMIN, ROLES.CONTENT_APPROVER);
}
