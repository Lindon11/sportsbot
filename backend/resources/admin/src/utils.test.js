import { describe, it, expect } from 'vitest'
import { formatCurrency, formatCompact, truncate, labelFromKey } from './utils.js'

describe('formatCurrency', () => {
    it('formats zero', () => expect(formatCurrency(0)).toBe('$0'))
    it('formats thousands', () => expect(formatCurrency(1234567)).toBe('$1,234,567'))
    it('supports custom symbol', () => expect(formatCurrency(500, '£')).toBe('£500'))
    it('handles null', () => expect(formatCurrency(null)).toBe('$0'))
    it('floors decimals', () => expect(formatCurrency(99.9)).toBe('$99'))
})

describe('formatCompact', () => {
    it('returns plain number below 1k', () => expect(formatCompact(500)).toBe('500'))
    it('formats thousands with K', () => expect(formatCompact(1500)).toBe('1.5K'))
    it('formats millions with M', () => expect(formatCompact(2_500_000)).toBe('2.5M'))
    it('handles null', () => expect(formatCompact(null)).toBe('0'))
})

describe('truncate', () => {
    it('leaves short strings unchanged', () => expect(truncate('hello', 10)).toBe('hello'))
    it('truncates long strings', () => expect(truncate('abcdefghij', 5)).toBe('abcd…'))
    it('handles empty string', () => expect(truncate('')).toBe(''))
    it('handles null', () => expect(truncate(null)).toBe(''))
})

describe('labelFromKey', () => {
    it('converts snake_case', () => expect(labelFromKey('max_health')).toBe('Max Health'))
    it('converts kebab-case', () => expect(labelFromKey('last-login')).toBe('Last Login'))
    it('handles single word', () => expect(labelFromKey('level')).toBe('Level'))
})
