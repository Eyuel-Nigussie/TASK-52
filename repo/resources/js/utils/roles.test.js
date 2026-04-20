import { describe, it, expect } from 'vitest';
import {
    ROLES, ROLE_LABEL, hasRole, isAdmin, isManager,
    canReceiveInventory, canIssueInventory, canCheckoutRental,
    canAuthorContent, canApproveContent,
} from './roles.js';

describe('roles', () => {
    it('exposes role constants', () => {
        expect(ROLES.SYSTEM_ADMIN).toBe('system_admin');
        expect(Object.isFrozen(ROLES)).toBe(true);
    });

    it('provides role labels', () => {
        expect(ROLE_LABEL.system_admin).toBe('System Administrator');
        expect(ROLE_LABEL.content_approver).toBe('Content Approver');
    });

    describe('hasRole', () => {
        it('returns false when user is null', () => {
            expect(hasRole(null, 'system_admin')).toBe(false);
            expect(hasRole({}, 'system_admin')).toBe(false);
        });
        it('matches a known role', () => {
            expect(hasRole({ role: 'clinic_manager' }, 'clinic_manager', 'system_admin')).toBe(true);
        });
        it('rejects unknown role', () => {
            expect(hasRole({ role: 'technician_doctor' }, 'system_admin')).toBe(false);
        });
    });

    it('isAdmin matches only system_admin', () => {
        expect(isAdmin({ role: 'system_admin' })).toBe(true);
        expect(isAdmin({ role: 'clinic_manager' })).toBe(false);
    });

    it('isManager matches admin or clinic_manager', () => {
        expect(isManager({ role: 'system_admin' })).toBe(true);
        expect(isManager({ role: 'clinic_manager' })).toBe(true);
        expect(isManager({ role: 'inventory_clerk' })).toBe(false);
    });

    it('inventory helpers enforce matrix', () => {
        expect(canReceiveInventory({ role: 'inventory_clerk' })).toBe(true);
        expect(canReceiveInventory({ role: 'technician_doctor' })).toBe(false);
        expect(canIssueInventory({ role: 'technician_doctor' })).toBe(true);
        expect(canIssueInventory({ role: 'content_editor' })).toBe(false);
        expect(canCheckoutRental({ role: 'clinic_manager' })).toBe(true);
    });

    it('content helpers enforce matrix', () => {
        expect(canAuthorContent({ role: 'content_editor' })).toBe(true);
        expect(canAuthorContent({ role: 'technician_doctor' })).toBe(false);
        expect(canApproveContent({ role: 'content_approver' })).toBe(true);
        expect(canApproveContent({ role: 'content_editor' })).toBe(false);
    });
});
