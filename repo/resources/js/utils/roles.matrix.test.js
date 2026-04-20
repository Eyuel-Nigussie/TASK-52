import { describe, it, expect } from 'vitest';
import {
    ROLES,
    hasRole,
    isAdmin,
    isManager,
    canReceiveInventory,
    canIssueInventory,
    canCheckoutRental,
    canAuthorContent,
    canApproveContent,
} from './roles.js';

describe('roles.matrix permissions', () => {
    const allRoles = [
        ROLES.SYSTEM_ADMIN,
        ROLES.CLINIC_MANAGER,
        ROLES.INVENTORY_CLERK,
        ROLES.TECHNICIAN_DOCTOR,
        ROLES.CONTENT_EDITOR,
        ROLES.CONTENT_APPROVER,
    ];

    allRoles.forEach((role) => {
        it(`hasRole matches same role ${role}`, () => {
            expect(hasRole({ role }, role)).toBe(true);
        });

        it(`checkout and issue parity for ${role}`, () => {
            expect(canCheckoutRental({ role })).toBe(canIssueInventory({ role }));
        });
    });

    [null, undefined, {}, { role: null }].forEach((user, idx) => {
        it(`returns false for malformed user #${idx + 1}`, () => {
            expect(hasRole(user, ROLES.SYSTEM_ADMIN)).toBe(false);
            expect(isAdmin(user)).toBe(false);
            expect(isManager(user)).toBe(false);
            expect(canReceiveInventory(user)).toBe(false);
            expect(canIssueInventory(user)).toBe(false);
            expect(canAuthorContent(user)).toBe(false);
            expect(canApproveContent(user)).toBe(false);
        });
    });

    const matrix = [
        [ROLES.SYSTEM_ADMIN, true, true, true, true, true, true],
        [ROLES.CLINIC_MANAGER, false, true, true, true, false, false],
        [ROLES.INVENTORY_CLERK, false, false, true, true, false, false],
        [ROLES.TECHNICIAN_DOCTOR, false, false, false, true, false, false],
        [ROLES.CONTENT_EDITOR, false, false, false, false, true, false],
        [ROLES.CONTENT_APPROVER, false, false, false, false, true, true],
    ];

    matrix.forEach(([role, admin, manager, recv, issue, author, approve]) => {
        it(`permission matrix for ${role}`, () => {
            const user = { role };
            expect(isAdmin(user)).toBe(admin);
            expect(isManager(user)).toBe(manager);
            expect(canReceiveInventory(user)).toBe(recv);
            expect(canIssueInventory(user)).toBe(issue);
            expect(canAuthorContent(user)).toBe(author);
            expect(canApproveContent(user)).toBe(approve);
        });
    });
});
