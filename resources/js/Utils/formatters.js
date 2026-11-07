/**
 * Formats product and production task quantities as clean integers without decimals (e.g., 3 instead of 3.00).
 */
export function formatQuantity(val) {
    if (val === null || val === undefined || val === '') return '0';
    const num = Number(val);
    if (isNaN(num)) return '0';
    return String(Math.round(num));
}

