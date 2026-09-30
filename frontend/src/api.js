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
}
