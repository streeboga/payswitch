export const TEST_CARDS = {
  visa_success: {
    card_number: '4242424242424242',
    card_exp_month: '12',
    card_exp_year: '2030',
    card_cvc: '123',
  },
  visa_3ds: {
    card_number: '4000000000003220',
    card_exp_month: '12',
    card_exp_year: '2030',
    card_cvc: '123',
  },
  visa_decline: {
    card_number: '4000000000000002',
    card_exp_month: '12',
    card_exp_year: '2030',
    card_cvc: '123',
  },
  mastercard: {
    card_number: '5555555555554444',
    card_exp_month: '12',
    card_exp_year: '2030',
    card_cvc: '123',
  },
} as const

export const TEST_USER = {
  email: 'test@example.com',
  password: 'password',
} as const
