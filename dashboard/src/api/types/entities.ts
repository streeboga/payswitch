/** Entity attribute types — matches JSON:API resource attributes from backend */

import type {
  PaymentStatus,
  RefundStatus,
  CaptureMethod,
  AuthenticationType,
  ConnectorName,
  ConnectorType,
  ApiKeyType,
  RoutingRuleType,
  WebhookEventType,
} from './enums'

export interface OrganizationAttributes {
  name: string
  merchants_count: number
  created_at: string
}

export interface MerchantAccountAttributes {
  name: string
  publishable_key: string
  organization_id: string
  profiles_count: number
  connectors_count: number
  created_at: string
}

export interface BusinessProfileAttributes {
  merchant_id: string
  webhook_url: string | null
  payment_response_hash_key: string
  connectors_count: number
  routing_rules_count: number
  created_at: string
}

export interface ApiKeyAttributes {
  name: string
  type: ApiKeyType
  key_prefix: string
  expires_at: string | null
  revoked_at: string | null
  created_at: string
  /** Only present in creation response */
  api_key?: string
}

export interface ConnectorAttributes {
  connector_name: ConnectorName
  connector_type: ConnectorType
  connector_account_details: Record<string, string> | null
  payment_methods_enabled: (
    | string
    | { payment_method: string; payment_method_types?: unknown[] }
  )[]
  test_mode: boolean
  disabled: boolean
  webhook_url: string
  created_at: string
}

export interface RoutingRuleAttributes {
  type: RoutingRuleType
  name: string
  rules: unknown[]
  active: boolean
  priority: number
  business_profile_id: string | null
  created_at: string
}

export interface PaymentIntentAttributes {
  status: PaymentStatus
  amount: number
  net_amount: number
  amount_capturable: number
  amount_received: number
  currency: string
  client_secret: string
  capture_method: CaptureMethod
  authentication_type: AuthenticationType
  customer_id: string | null
  description: string | null
  return_url: string | null
  metadata: Record<string, unknown> | null
  connector: string | null
  attempt_count: number
  error_code: string | null
  error_message: string | null
  cancellation_reason: string | null
  session_expiry: string | null
  created_at: string
  expires_on: string | null
}

export interface RefundAttributes {
  payment_id: string
  amount: number
  currency: string
  status: RefundStatus
  reason: string | null
  connector: string | null
  error_code: string | null
  error_message: string | null
  metadata: Record<string, unknown> | null
  created_at: string
}

export interface CustomerAttributes {
  name: string
  email: string | null
  phone: string | null
  phone_country_code: string | null
  description: string | null
  metadata: Record<string, unknown> | null
  default_payment_method_id: string | null
  created_at: string
}

export interface PaymentMethodAttributes {
  type: string
  card_last4: string | null
  card_brand: string | null
  card_exp_month: number | null
  card_exp_year: number | null
  card_holder_name: string | null
  connector_name: ConnectorName
  is_default: boolean
  metadata: Record<string, unknown> | null
  created_at: string
}

export interface WebhookEventAttributes {
  event_type: WebhookEventType
  delivered: boolean
  delivery_attempts: number
  next_retry_at: string | null
  last_error: string | null
  created_at: string
}
