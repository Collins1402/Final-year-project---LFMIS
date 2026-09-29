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

function post(path, data = {}) {
  return request(path, { method: 'POST', body: JSON.stringify(data) })
}

async function download(path, filename) {
  const response = await fetch(path, { credentials: 'include' })
  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    const error = new Error(body.error || `Download failed (${response.status})`)
    error.status = response.status
    throw error
  }
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.append(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export const api = {
  setupStatus: () => request('/api/setup-status'),
  createAdmin: (data) => post('/api/setup', data),
  login: (data) => post('/api/login', data),
  register: (data) => post('/api/register', data),
  forgotPassword: (data) => post('/api/password/forgot', data),
  resetPassword: (data) => post('/api/password/reset', data),
  logout: () => post('/api/logout'),
  session: () => request('/api/session'),
  dashboard: () => request('/api/dashboard'),
  animals: () => request('/api/animals'),
  createAnimal: (data) => post('/api/animals', data),
  milkProduction: () => request('/api/production/milk'),
  recordMilk: (data) => post('/api/production/milk', data),
  eggProduction: () => request('/api/production/eggs'),
  recordEggs: (data) => post('/api/production/eggs', data),
  recordWool: (data) => post('/api/production/wool', data),
  productionLog: () => request('/api/production/log'),
  productionSummary: () => request('/api/production/summary'),
  healthRecords: () => request('/api/health-records'),
  createHealthRecord: (data) => post('/api/health-records', data),
  inventory: () => request('/api/inventory'),
  createInventoryItem: (data) => post('/api/inventory/items', data),
  inventoryTransaction: (data) => post('/api/inventory/transactions', data),
  storeProducts: () => request('/api/store/products'),
  downloadLivestockSelection: (productId, quantity) => download(
    `/api/store/livestock.csv?productId=${encodeURIComponent(productId)}&quantity=${encodeURIComponent(quantity)}`,
    'flouri-farm-livestock-selection.csv',
  ),
  downloadOrders: () => download('/api/orders/export.csv', 'flouri-farm-orders.csv'),
  products: () => request('/api/products'),
  createProduct: (data) => post('/api/products', data),
  updateProduct: (id, data) => request(`/api/products/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  sales: () => request('/api/sales'),
  createSale: (data) => post('/api/sales', data),
  orders: () => request('/api/orders'),
  createOrder: (data) => post('/api/orders', data),
  updateOrder: (id, data) => request(`/api/orders/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  payments: () => request('/api/payments'),
  createPayment: (data) => post('/api/payments', data),
  activities: () => request('/api/activities'),
  createActivity: (data) => post('/api/activities', data),
  completeActivity: (id) => request(`/api/activities/${id}`, { method: 'PATCH', body: JSON.stringify({ status: 'completed' }) }),
  expenses: () => request('/api/expenses'),
  feedback: () => request('/api/feedback'),
  createFeedback: (data) => post('/api/feedback', data),
  resolveFeedback: (id) => request(`/api/feedback/${id}`, { method: 'PATCH', body: JSON.stringify({ status: 'resolved' }) }),
  createExpense: (data) => post('/api/expenses', data),
  users: () => request('/api/users'),
  createUser: (data) => post('/api/users', data),
  updateUser: (id, data) => request(`/api/users/${id}`, { method: 'PATCH', body: JSON.stringify(data) }),
  transferAdmin: (data) => post('/api/admin/transfer', data),
  setUsername: (data) => post('/api/account/username', data),
  productionForecast: () => request('/api/production/forecast'),
  financeReport: () => request('/api/reports/finance'),
}