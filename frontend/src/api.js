/**
 * File purpose: api.js contains the browser API client calls.
 * The frontend entry point imports this module to serve farm users and livestock buyers.
 * It depends on the browser DOM, the application API, and shared frontend state.
 */
/**
 * Sends a same-origin JSON request and turns unsuccessful responses into useful errors.
 * @param {string} path API route to call.
 * @param {object} options Fetch options such as the method, body, and headers.
 * @returns {Promise<object>} The decoded response body.
 */
async function request(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', ...options.headers },
    ...options,
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    const error = new Error(body.error || `Request failed (${response.status})`)
    error.status = response.status
    error.existingRecord = body.existingRecord || null
    error.warning = body.warning === true
    throw error
  }
  return body
}

/**
 * Handles the post operation and returns its result.
 * @param {*} path Value supplied to this operation.
 * @param {*} data Value supplied to this operation.
 * @returns {*} Result of this operation.
 */
const post = (path, data) => request(path, { method: 'POST', body: JSON.stringify(data) })

export const api = {
  /** Sends the matching application API request and returns its response. */
  health: () => request('/api/health'),
  /** Sends the matching application API request and returns its response. */
  trends: () => request('/api/trends'),
  /** Sends the matching application API request and returns its response. */
  trendsHistory: (params = {}) => request('/api/trends/history?' + new URLSearchParams(params)),
  /** Sends the matching application API request and returns its response. */
  trendsMetrics: (params = {}) => request('/api/trends/metrics?' + new URLSearchParams(params)),
  /** Sends the matching application API request and returns its response. */
  trendsStatus: () => request('/api/trends/status'),
  /** Sends the matching application API request and returns its response. */
  refreshTrends: () => post('/api/trends/refresh', {}),
  /** Sends the matching application API request and returns its response. */
  setupStatus: () => request('/api/setup-status'),
  /** Sends the matching application API request and returns its response. */
  setup: (data) => post('/api/setup', data),
  /** Sends the matching application API request and returns its response. */
  register: (data) => post('/api/register', data),
  /** Sends the matching application API request and returns its response. */
  verifyEmail: (token) => request(`/api/verify-email?token=${encodeURIComponent(token)}`),
  /** Sends the matching application API request and returns its response. */
  forgotPassword: (data) => post('/api/password/forgot', data),
  /** Sends the matching application API request and returns its response. */
  resetPassword: (data) => post('/api/password/reset', data),
  /** Sends the matching application API request and returns its response. */
  login: (data) => post('/api/login', data),
  /** Sends the matching application API request and returns its response. */
  sendLoginTwoFactorCode: (method) => post('/api/login/2fa/send', { method }),
  /** Sends the matching application API request and returns its response. */
  verifyLoginTwoFactorCode: (data) => post('/api/login/2fa/verify', data),
  /** Sends the matching application API request and returns its response. */
  verifyTotpLogin: (data) => post('/api/login/2fa', data),
  /** Sends the matching application API request and returns its response. */
  cancelLoginTwoFactor: () => post('/api/login/2fa/cancel', {}),
  /** Sends the matching application API request and returns its response. */
  twoFactorStatus: () => request('/api/account/2fa'),
  /** Sends the matching application API request and returns its response. */
  setupTotp: () => post('/api/account/2fa/setup', {}),
  /** Sends the matching application API request and returns its response. */
  enableTotp: (code) => post('/api/account/2fa/enable', { code }),
  /** Sends the matching application API request and returns its response. */
  disableTotp: (data) => post('/api/account/2fa/disable', data),
  /** Sends the matching application API request and returns its response. */
  regenerateRecoveryCodes: (data) => post('/api/account/2fa/recovery-codes', data),
  /** Sends the matching application API request and returns its response. */
  revokeTrustedDevices: () => post('/api/account/2fa/trusted-devices/revoke', {}),
  /** Sends the matching application API request and returns its response. */
  startTwoFactorEnrollment: (data) => post('/api/account/2fa/enroll', data),
  /** Sends the matching application API request and returns its response. */
  verifyTwoFactorEnrollment: (data) => post('/api/account/2fa/enroll/verify', data),
  /** Sends the matching application API request and returns its response. */
  removeTwoFactorMethod: (data) => post('/api/account/2fa/remove', data),
  /** Sends the matching application API request and returns its response. */
  logout: () => post('/api/logout', {}),
  /** Sends the matching application API request and returns its response. */
  session: () => request('/api/session'),
  /** Sends the matching application API request and returns its response. */
  overview: () => request('/api/overview'),
  /** Sends the matching application API request and returns its response. */
  animals: () => request('/api/animals'),
  /** Sends the matching application API request and returns its response. */
  animalProfile: (id) => request(`/api/animals/${Number(id)}`),
  /** Sends the matching application API request and returns its response. */
  updateAnimal: (id, data) => request(`/api/animals/${Number(id)}`, { method: 'PATCH', body: JSON.stringify(data) }),
  /** Sends the matching application API request and returns its response. */
  updateAnimalStatus: (id, status) => request(`/api/animals/${Number(id)}/status`, { method: 'PATCH', body: JSON.stringify({ status }) }),
  /** Sends the matching application API request and returns its response. */
  createAnimal: (data) => post('/api/animals', data),
  /** Sends the matching application API request and returns its response. */
  healthRecords: () => request('/api/health-records'),
  /** Sends the matching application API request and returns its response. */
  createHealthRecord: (data) => post('/api/health-records', data),
  /** Sends the matching application API request and returns its response. */
  activities: () => request('/api/activities'),
  /** Sends the matching application API request and returns its response. */
  createActivity: (data) => post('/api/activities', data),
  /** Sends the matching application API request and returns its response. */
  completeActivity: (id) => request(`/api/activities/${id}/complete`, { method: 'PATCH', body: '{}' }),
  /** Sends the matching application API request and returns its response. */
  events: () => request('/api/events'),
  /** Sends the matching application API request and returns its response. */
  adminEvents: () => request('/api/events/admin'),
  /** Sends the matching application API request and returns its response. */
  createEvent: (data) => post('/api/events', data),
  /** Sends the matching application API request and returns its response. */
  updateEvent: (id, data) => request(`/api/events/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  /** Sends the matching application API request and returns its response. */
  setEventStatus: (id, status) => request(`/api/events/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),
  /** Sends the matching application API request and returns its response. */
  production: () => request('/api/production'),
  /** Sends the matching application API request and returns its response. */
  productionSummary: () => request('/api/production/summary'),
  /** Sends the matching application API request and returns its response. */
  recordProduction: (data) => post('/api/production', data),
  /** Sends the matching application API request and returns its response. */
  updateProduction: (id, data) => request(`/api/production/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  /** Sends the matching application API request and returns its response. */
  inventory: () => request('/api/inventory'),
  /** Sends the matching application API request and returns its response. */
  inventoryTransactions: () => request('/api/inventory/transactions'),
  /** Sends the matching application API request and returns its response. */
  createInventoryItem: (data) => post('/api/inventory/items', data),
  /** Sends the matching application API request and returns its response. */
  inventoryTransaction: (id, data) => post(`/api/inventory/${id}/transactions`, data),
  /** Sends the matching application API request and returns its response. */
  products: () => request('/api/products'),
  /** Sends the matching application API request and returns its response. */
  createProduct: (data) => post('/api/products', data),
  /** Sends the matching application API request and returns its response. */
  orders: () => request('/api/orders'),
  /** Sends the matching application API request and returns its response. */
  createOrder: (data) => post('/api/orders', data),
  /** Sends the matching application API request and returns its response. */
  updateOrder: (id, data) => request(`/api/orders/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  /** Sends the matching application API request and returns its response. */
  linkOrderProduct: (id, data) => request(`/api/orders/${id}/product`, { method: 'PATCH', body: JSON.stringify(data) }),
  /** Sends the matching application API request and returns its response. */
  updateOrdersBulk: (data) => request('/api/orders/bulk', { method: 'PATCH', body: JSON.stringify(data) }),
  /** Sends the matching application API request and returns its response. */
  sales: () => request('/api/sales'),
  /** Sends the matching application API request and returns its response. */
  createSale: (data) => post('/api/sales', data),
  /** Sends the matching application API request and returns its response. */
  expenses: () => request('/api/expenses'),
  /** Sends the matching application API request and returns its response. */
  createExpense: (data) => post('/api/expenses', data),
  /** Sends the matching application API request and returns its response. */
  payments: () => request('/api/payments'),
  /** Sends the matching application API request and returns its response. */
  createPayment: (data) => post('/api/payments', data),
  /** Sends the matching application API request and returns its response. */
  initiateMpesaPayment: (data) => post('/api/payments/mpesa/initiate', data),
  /** Sends the matching application API request and returns its response. */
  financeReport: () => request('/api/reports/finance'),
  /** Sends the matching application API request and returns its response. */
  operationalReports: () => request('/api/reports/operations'),
  /** Sends the matching application API request and returns its response. */
  forecastReport: (product = 'milk') => request(`/api/reports/forecast?product=${encodeURIComponent(product)}`),
  /** Sends the matching application API request and returns its response. */
  forecastProducts: () => request('/api/reports/forecast/products'),
  /** Sends the matching application API request and returns its response. */
  forecastHistory: () => request('/api/reports/forecast/history'),
  /** Sends the matching application API request and returns its response. */
  assistantChat: (message) => post('/api/assistant/chat', { message }),
  /** Sends the matching application API request and returns its response. */
  analytics: (metric, params = {}) => request(`/api/analytics/${encodeURIComponent(metric)}?${new URLSearchParams(params)}`),
  /** Sends the matching application API request and returns its response. */
  assistantScenario: (data) => post('/api/assistant/scenario', data),
  /** Sends the matching application API request and returns its response. */
  notifications: () => request('/api/notifications'),
  /** Sends the matching application API request and returns its response. */
  markNotificationRead: (id) => request(`/api/notifications/${id}`, { method: 'PATCH', body: '{}' }),
  /** Sends the matching application API request and returns its response. */
  loadDemoData: () => post('/api/demo-data', {}),
  /** Sends the matching application API request and returns its response. */
  feedback: () => request('/api/feedback'),
  /** Sends the matching application API request and returns its response. */
  createFeedback: (data) => post('/api/feedback', data),
  /** Sends the matching application API request and returns its response. */
  resolveFeedback: (id) => request(`/api/feedback/${id}/resolve`, { method: 'PATCH', body: '{}' }),
  /** Sends the matching application API request and returns its response. */
  resolveAllFeedback: () => request('/api/feedback/resolve-all', { method: 'PATCH', body: '{}' }),
  /** Sends the matching application API request and returns its response. */
  users: () => request('/api/users'),
  /** Sends the matching application API request and returns its response. */
  createUser: (data) => post('/api/users', data),
  /** Sends the matching application API request and returns its response. */
  deleteUser: (id) => request(`/api/users/${id}`, { method: 'DELETE' }),
  /** Sends the matching application API request and returns its response. */
  permissions: () => request('/api/permissions'),
  /** Sends the matching application API request and returns its response. */
  updateRolePermissions: (role, permissions) => post(`/api/permissions/${encodeURIComponent(role)}`, { permissions }),
  /** Sends the matching application API request and returns its response. */
  auditLogs: (filters = {}) => {
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''))
    return request(`/api/audit-logs${query.size ? `?${query}` : ''}`)
  },
  /** Sends the matching application API request and returns its response. */
  exportSections: () => request('/api/data/export/options'),
  /** Sends the matching application API request and returns its response. */
  exportData: (data) => post('/api/data/export', data),
  /** Sends the matching application API request and returns its response. */
  createBackup: () => post('/api/admin/backup', {}),
}
