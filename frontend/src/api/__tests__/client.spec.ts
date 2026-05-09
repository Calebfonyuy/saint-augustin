// Tests for the API client helpers (token storage, error extraction).
// We stub axios's isAxiosError for the error extractor paths.
import { describe, expect, it, beforeEach } from 'vitest'
import axios from 'axios'
import {
  clearToken,
  extractErrorMessage,
  getStoredToken,
  storeToken,
} from '@/api/client'

describe('token storage', () => {
  beforeEach(() => localStorage.clear())

  it('returns null when no token is stored', () => {
    expect(getStoredToken()).toBeNull()
  })

  it('persists and clears the token via localStorage', () => {
    storeToken('abc.def')
    expect(getStoredToken()).toBe('abc.def')
    clearToken()
    expect(getStoredToken()).toBeNull()
  })
})

describe('extractErrorMessage', () => {
  it('pulls the first validation error when present', () => {
    // Construct a plausible AxiosError so isAxiosError returns true.
    const err = new axios.AxiosError(
      'Request failed',
      '422',
      undefined,
      undefined,
      {
        status: 422,
        data: { message: 'Validation failed', errors: { email: ['Must be an email.'] } },
      } as never,
    )
    expect(extractErrorMessage(err)).toBe('Must be an email.')
  })

  it('falls back to the top-level message', () => {
    const err = new axios.AxiosError(
      'Request failed',
      '401',
      undefined,
      undefined,
      { status: 401, data: { message: 'Invalid credentials.' } } as never,
    )
    expect(extractErrorMessage(err)).toBe('Invalid credentials.')
  })

  it('returns the fallback for unknown errors', () => {
    expect(extractErrorMessage({ boom: true }, 'Oops.')).toBe('Oops.')
  })

  it('uses Error.message for plain errors', () => {
    expect(extractErrorMessage(new Error('kaboom'))).toBe('kaboom')
  })
})
