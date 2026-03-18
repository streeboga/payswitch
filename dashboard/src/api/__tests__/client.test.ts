import { describe, it, expect } from 'vitest'
import { ApiError } from '../client'

describe('ApiError', () => {
  it('creates error with all fields', () => {
    const error = new ApiError({
      status: 422,
      code: 'validation_error',
      title: 'Validation Failed',
      detail: 'The name field is required.',
      source: { pointer: '/data/attributes/name' },
      errors: [
        {
          status: 422,
          code: 'validation_error',
          title: 'Validation Failed',
          detail: 'The name field is required.',
          source: { pointer: '/data/attributes/name' },
        },
      ],
    })

    expect(error).toBeInstanceOf(Error)
    expect(error).toBeInstanceOf(ApiError)
    expect(error.name).toBe('ApiError')
    expect(error.status).toBe(422)
    expect(error.code).toBe('validation_error')
    expect(error.title).toBe('Validation Failed')
    expect(error.detail).toBe('The name field is required.')
    expect(error.source?.pointer).toBe('/data/attributes/name')
    expect(error.errors).toHaveLength(1)
    expect(error.message).toBe('The name field is required.')
  })

  it('uses title as message when detail is missing', () => {
    const error = new ApiError({
      status: 500,
      code: 'server_error',
      title: 'Internal Server Error',
      errors: [{ status: 500, code: 'server_error', title: 'Internal Server Error' }],
    })

    expect(error.message).toBe('Internal Server Error')
    expect(error.detail).toBeUndefined()
  })
})
