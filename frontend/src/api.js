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
    throw error
  }
  return body
}

const post = (path, data) => request(path, { method: 'POST', body: JSON.stringify(data) })

export const api = {
  health: () => request('/api/health'),
  trends: () => request('/api/trends'),
  setupStatus: () => request('/api/setup-status'),
  setup: (data) => post('/api/setup', data),
  register: (data) => post('/api/register', data),
  verifyEmail: (token) => request(`/api/verify-email?token=${encodeURIComponent(token)}`),
  forgotPassword: (data) => post('/api/password/forgot', data),
  resetPassword: (data) => post('/api/password/reset', data),
  login: (data) => post('/api/login', data),
  sendLoginTwoFactorCode: (method) => post('/api/login/2fa/send', { method }),
  verifyLoginTwoFactorCode: (data) => post('/api/login/2fa/verify', data),
  cancelLoginTwoFactor: () => post('/api/login/2fa/cancel', {}),
  twoFactorStatus: () => request('/api/account/2fa'),
  startTwoFactorEnrollment: (data) => post('/api/account/2fa/enroll', data),
  verifyTwoFactorEnrollment: (data) => post('/api/account/2fa/enroll/verify', data),
  removeTwoFactorMethod: (data) => post('/api/account/2fa/remove', data),
  logout: () => post('/api/logout', {}),
  session: () => request('/api/session'),
  overview: () => request('/api/overview'),
  animals: () => request('/api/animals'),
  createAnimal: (data) => post('/api/animals', data),
  healthRecords: () => request('/api/health-records'),
  createHealthRecord: (data) => post('/api/health-records', data),
  activities: () => request('/api/activities'),
  createActivity: (data) => post('/api/activities', data),
  completeActivity: (id) => request(`/api/activities/${id}/complete`, { method: 'PATCH', body: '{}' }),
  events: () => request('/api/events'),
  adminEvents: () => request('/api/events/admin'),
  createEvent: (data) => post('/api/events', data),
  updateEvent: (id, data) => request(`/api/events/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  setEventStatus: (id, status) => request(`/api/events/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),
  production: () => request('/api/production'),
  productionSummary: () => request('/api/production/summary'),
  recordProduction: (data) => post('/api/production', data),
  inventory: () => request('/api/inventory'),
  inventoryTransactions: () => request('/api/inventory/transactions'),
  createInventoryItem: (data) => post('/api/inventory/items', data),
  inventoryTransaction: (id, data) => post(`/api/inventory/${id}/transactions`, data),
  products: () => request('/api/products'),
  createProduct: (data) => post('/api/products', data),
  orders: () => request('/api/orders'),
  createOrder: (data) => post('/api/orders', data),
  updateOrder: (id, data) => request(`/api/orders/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  sales: () => request('/api/sales'),
  createSale: (data) => post('/api/sales', data),
  expenses: () => request('/api/expenses'),
  createExpense: (data) => post('/api/expenses', data),
  payments: () => request('/api/payments'),
  createPayment: (data) => post('/api/payments', data),
  financeReport: () => request('/api/reports/finance'),
  operationalReports: () => request('/api/reports/operations'),
  forecastReport: () => request('/api/reports/forecast'),
  forecastHistory: () => request('/api/reports/forecast/history'),
  assistantChat: (message) => post('/api/assistant/chat', { message }),
  assistantScenario: (data) => post('/api/assistant/scenario', data),
  notifications: () => request('/api/notifications'),
  markNotificationRead: (id) => request(`/api/notifications/${id}`, { method: 'PATCH', body: '{}' }),
  loadDemoData: () => post('/api/demo-data', {}),
  feedback: () => request('/api/feedback'),
  createFeedback: (data) => post('/api/feedback', data),
  resolveFeedback: (id) => request(`/api/feedback/${id}/resolve`, { method: 'PATCH', body: '{}' }),
  users: () => request('/api/users'),
  createUser: (data) => post('/api/users', data),
  permissions: () => request('/api/permissions'),
  updateRolePermissions: (role, permissions) => post(`/api/permissions/${encodeURIComponent(role)}`, { permissions }),
}
