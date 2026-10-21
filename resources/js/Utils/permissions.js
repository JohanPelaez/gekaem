import { page } from '@inertiajs/svelte';

/**
 * Determines whether a user has permission to view prices, costs, totals, and financial/payment information.
 * Returns true if super admin, owner or admin; false if worker or unauthenticated.
 *
 * @param {Object|null} [user] - Optional user object. If omitted, reads from page.props.auth.user.
 * @returns {boolean}
 */
export function canViewPrices(user = null) {
    const u = user || page?.props?.auth?.user;
    if (!u) return false;
    if (u.is_super_admin || u.role === 'owner' || u.role === 'admin') {
        return true;
    }
    return false;
}
