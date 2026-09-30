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
  setupStatus: () => request('/api/setup-status'),
  setup: (data) => post('/api/setup', data),
  register: (data) => post('/api/register', data),
  verifyEmail: (token) => request(`/api/verify-email?token=${encodeURIComponent(token)}`),
  forgotPassword: (data) => post('/api/password/forgot', data),
  resetPassword: (data) => post('/api/password/reset', data),
  login: (data) => post('/api/login', data),
  logout: () => post('/api/logout', {}),
  session: () => request('/api/session'),
  animals: () => request('/api/animals'),
  createAnimal: (data) => post('/api/animals', data),
  healthRecords: () => request('/api/health-records'),
  createHealthRecord: (data) => post('/api/health-records', data),
  activities: () => request('/api/activities'),
  createActivity: (data) => post('/api/activities', data),
  completeActivity: (id) => request(`/api/activities/${id}/complete`, { method: 'PATCH', body: '{}' }),
  milkProduction: () => request('/api/production/milk'),
  recordMilk: (data) => post('/api/production/milk', data),
  eggProduction: () => request('/api/production/eggs'),
  recordEggs: (data) => post('/api/production/eggs', data),
  inventory: () => request('/api/inventory'),
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
  assistantChat: (message) => post('/api/assistant/chat', { message }),
  notifications: () => request('/api/notifications'),
  markNotificationRead: (id) => request(`/api/notifications/${id}`, { method: 'PATCH', body: '{}' }),
  feedback: () => request('/api/feedback'),
  createFeedback: (data) => post('/api/feedback', data),
  resolveFeedback: (id) => request(`/api/feedback/${id}/resolve`, { method: 'PATCH', body: '{}' }),
  users: () => request('/api/users'),
  createUser: (data) => post('/api/users', data),
}
