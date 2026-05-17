/**
 * Format a number as a currency string (e.g. 1234567 → "$1,234,567")
 */
export function formatCurrency(amount, symbol = '$') {
    if (amount == null || isNaN(amount)) return `${symbol}0`
    return `${symbol}${Math.floor(amount).toLocaleString('en-US')}`
}

/**
 * Format a large number with K/M suffix (e.g. 1500 → "1.5K")
 */
export function formatCompact(n) {
    if (n == null || isNaN(n)) return '0'
    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
    if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`
    return String(n)
}

/**
 * Truncate a string to maxLength chars, appending "…" if truncated.
 */
export function truncate(str, maxLength = 50) {
    if (!str) return ''
    return str.length <= maxLength ? str : str.slice(0, maxLength - 1) + '…'
}

/**
 * Convert a snake_case or kebab-case key into a human-readable label.
 * e.g. "max_health" → "Max Health"
 */
export function labelFromKey(key) {
    return key
        .replace(/[-_]/g, ' ')
        .replace(/\b\w/g, c => c.toUpperCase())
}
