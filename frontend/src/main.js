import './style.css'
import { api } from './api.js'
import { renderLanding } from './landing.js'

const app = document.querySelector('#app')
let currentUser = null

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]))
const message = (text, danger = false) => `<p class="message ${danger ? 'danger' : 'success'}">${escapeHtml(text)}</p>`

function renderAuth(mode = 'login', notice = '') {
  const setup = mode === 'setup'
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">FLOURi FARM · LFMIS</p><h1>${setup ? 'Set up the farm workspace' : 'Welcome back'}</h1><p class="muted">${setup ? 'Create the first farm owner account.' : 'Sign in to manage the farm.'}</p>${notice}${setup ? `<form id="setup-form"><label>Full name<input name="fullName" required></label><label>Username<input name="username" required pattern="[A-Za-z0-9_.]{3,30}"></label><label>Email<input name="email" type="email" required></label><label>Password<span class="password-field"><input name="password" type="password" minlength="12" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Create owner account</button></form>` : `<form id="login-form"><label>Username or email<input name="login" required></label><label>Password<span class="password-field"><input name="password" type="password" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Sign in</button></form><button id="forgot-link" class="text-link" type="button">Forgot password?</button><button id="register-link" class="text-link" type="button">Create a farm account</button>`}</section></main>`
  const form = app.querySelector('form')
  app.querySelector('#register-link')?.addEventListener('click', renderRegister)
  app.querySelector('#forgot-link')?.addEventListener('click', renderForgotPassword)
  app.querySelectorAll('.password-toggle').forEach((toggle) => toggle.addEventListener('click', () => {
    const input = toggle.parentElement.querySelector('input')
    const visible = input.type === 'text'
    input.type = visible ? 'password' : 'text'
    toggle.setAttribute('aria-label', visible ? 'Show password' : 'Hide password')
    toggle.textContent = visible ? '👁' : '🙈'
  }))
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(form))
    try {
      const result = setup ? await api.setup(data) : await api.login(data)
      if (setup && result.verificationRequired) {
        const devLink = result.devVerificationLink ? `<p class="message success">${escapeHtml(result.message)} <a href="${escapeHtml(result.devVerificationLink)}">Open development confirmation link</a></p>` : message(result.message, false)
        renderAuth('login', devLink)
        return
      }
      currentUser = result.user
      renderApp()
    } catch (error) { renderAuth(mode, message(error.message, true)) }
  })
}

function renderForgotPassword(notice = '') {
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">ACCOUNT RECOVERY</p><h1>Reset your password</h1><p class="muted">Enter your account email. We will send a one-time reset link if it matches an active account.</p>${notice}<form id="forgot-form"><label>Email<input name="email" type="email" required autocomplete="email"></label><button>Send reset link</button></form><button id="back-login" class="text-link" type="button">Back to sign in</button></section></main>`
  app.querySelector('#back-login').addEventListener('click', () => renderAuth('login'))
  app.querySelector('#forgot-form').addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      const result = await api.forgotPassword(Object.fromEntries(new FormData(event.target)))
      const link = result.devResetLink ? ` <a href="${escapeHtml(result.devResetLink)}">Open development reset link</a>` : ''
      renderForgotPassword(`<p class="message success">${escapeHtml(result.message)}${link}</p>`)
    } catch (error) { renderForgotPassword(`<p class="message danger">${escapeHtml(error.message)}</p>`) }
  })
}

function renderResetPassword(token) {
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">ACCOUNT RECOVERY</p><h1>Choose a new password</h1><p class="muted">Use at least 12 characters. This link works once and expires after one hour.</p><form id="reset-form"><label>New password<span class="password-field"><input name="password" type="password" minlength="12" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><label>Confirm password<span class="password-field"><input name="confirmPassword" type="password" minlength="12" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Update password</button></form></section></main>`
  app.querySelectorAll('.password-toggle').forEach((toggle) => toggle.addEventListener('click', () => {
    const input = toggle.parentElement.querySelector('input')
    input.type = input.type === 'password' ? 'text' : 'password'
  }))
  app.querySelector('#reset-form').addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(event.target))
    if (data.password !== data.confirmPassword) {
      renderResetPasswordWithNotice(token, 'The passwords do not match.')
      return
    }
    try {
      await api.resetPassword({ token, password: data.password })
      history.replaceState({}, '', '/')
      renderAuth('login', 'Password updated. You can now sign in.')
    } catch (error) { renderResetPasswordWithNotice(token, error.message) }
  })
}

function renderResetPasswordWithNotice(token, text) {
  renderResetPassword(token)
  const notice = document.createElement('p')
  notice.className = 'message danger'
  notice.textContent = text
  app.querySelector('#reset-form').before(notice)
}

function renderRegister(notice = '') {
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">FLOURi FARM · LFMIS</p><h1>Create an account</h1><p class="muted">Create your user account. The farm administrator assigns access where needed.</p>${notice}<form id="register-form"><label>Full name<input name="fullName" required></label><label>Username<input name="username" required pattern="[A-Za-z0-9_.]{3,30}"></label><label>Email<input name="email" type="email" required></label><label>Password<span class="password-field"><input name="password" type="password" minlength="12" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Create account</button></form><button id="back-login" class="text-link" type="button">Back to sign in</button></section></main>`
  app.querySelectorAll('.password-toggle').forEach((toggle) => toggle.addEventListener('click', () => {
    const input = toggle.parentElement.querySelector('input')
    input.type = input.type === 'password' ? 'text' : 'password'
    toggle.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password')
  }))
  app.querySelector('#back-login').addEventListener('click', () => renderAuth('login'))
  app.querySelector('#register-form').addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      const result = await api.register(Object.fromEntries(new FormData(event.target)))
      const approval = result.approvalRequired ? ' The farm administrator must approve your staff account after email confirmation.' : ''
      renderAuth('login', `${result.message}${approval}${result.devVerificationLink ? ` Open the development confirmation link: ${result.devVerificationLink}` : ''}`)
    } catch (error) { renderRegister(`<p class="message danger">${escapeHtml(error.message)}</p>`) }
  })
}

async function renderApp() {
  if (['customer', 'buyer', 'worker', 'veterinarian'].includes(currentUser.role)) {
    renderRoleWorkspace()
    return
  }
  let animals = []
  let healthRecords = []
  try {
    const [animalData, healthData] = await Promise.all([api.animals(), api.healthRecords()])
    animals = animalData.animals
    healthRecords = healthData.records
  } catch (error) {
    app.innerHTML = message(error.message, true)
    return
  }
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>Farm workspace</h2><nav class="workspace-nav" aria-label="Farm modules">${['Overview', 'Livestock', 'Animal health', 'Activities', 'Production', 'Inventory', 'Sales & orders', 'Reports', 'Feedback', 'User accounts', 'Assistant'].map((item) => `<button class="workspace-nav-item ${item === 'Overview' ? 'active' : ''}" data-module="${item}">${item}</button>`).join('')}</nav><div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="logout" class="quiet">Sign out</button></aside><main class="content"><header><div><p class="kicker">OVERVIEW</p><h1>Good day, ${escapeHtml(currentUser.fullName.split(' ')[0])}</h1><p class="muted">Keep the herd records complete and easy to trust.</p></div><span class="health-pill">API connected</span></header><section class="stats"><div><small>Animals</small><strong>${animals.length}</strong></div><div><small>Health records</small><strong>${healthRecords.length}</strong></div><div><small>Role</small><strong>${escapeHtml(currentUser.role)}</strong></div></section><div class="grid"><section class="panel"><div class="panel-head"><h2>Livestock register</h2><span>${animals.length} records</span></div><form id="animal-form" class="compact-form"><input name="tagNumber" placeholder="Tag number" required><select name="species"><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="sheep">Sheep</option></select><input name="breed" placeholder="Breed"><select name="sex"><option value="female">Female</option><option value="male">Male</option></select><button>Add animal</button></form><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Species</th><th>Breed</th><th>Status</th></tr></thead><tbody>${animals.length ? animals.map((animal) => `<tr><td>${escapeHtml(animal.tag_number)}</td><td>${escapeHtml(animal.species)}</td><td>${escapeHtml(animal.breed || '—')}</td><td><span class="status">${escapeHtml(animal.status)}</span></td></tr>`).join('') : '<tr><td colspan="4" class="empty">No animals yet.</td></tr>'}</tbody></table></div></section><section class="panel"><div class="panel-head"><h2>Animal health</h2><span>${healthRecords.length} records</span></div><form id="health-form" class="compact-form"><select name="animalId" required><option value="">Animal</option>${animals.map((animal) => `<option value="${animal.id}">${escapeHtml(animal.tag_number)}</option>`).join('')}</select><select name="recordType"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option></select><input name="description" placeholder="Description" required><input name="recordDate" type="date" required><button>Save record</button></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal</th><th>Type</th><th>Description</th></tr></thead><tbody>${healthRecords.length ? healthRecords.map((record) => `<tr><td>${escapeHtml(record.record_date)}</td><td>${escapeHtml(record.tag_number)}</td><td>${escapeHtml(record.record_type)}</td><td>${escapeHtml(record.description)}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No health records yet.</td></tr>'}</tbody></table></div></section></div></main></div>`
  app.querySelectorAll('[data-module]').forEach((item) => item.addEventListener('click', () => {
    if (item.dataset.module === 'Overview') return renderApp()
    renderAdminModule(item.dataset.module)
  }))
  app.querySelector('#logout').addEventListener('click', async () => { await api.logout(); currentUser = null; renderAuth() })
  app.querySelector('#animal-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createAnimal(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
  app.querySelector('#health-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createHealthRecord(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
}

function renderAdminModule(module) {
  const details = {
    Livestock: ['Livestock register', 'Register animals, update their status and review the herd.'],
    'Animal health': ['Animal health', 'Record vaccinations, treatments and follow-up dates.'],
    Activities: ['Farm activities', 'Plan feeding, cleaning, milking, egg collection and follow-ups.'],
    Production: ['Production', 'Record milk, eggs and wool, then review production trends.'],
    Inventory: ['Inventory', 'Track feed, medicine, equipment and low-stock alerts.'],
    'Sales & orders': ['Sales & orders', 'Manage customers, products, quotes, orders and payments.'],
    Reports: ['Reports', 'Review production, health compliance, finance and forecast results.'],
    Feedback: ['Feedback', 'Review customer feedback, complaints and suggestions.'],
    'User accounts': ['User accounts', 'Create staff and buyer accounts, assign roles and deactivate access.'],
    Assistant: ['Farm assistant', 'Ask questions about records and receive role-limited decision support.'],
  }[module]
  app.querySelectorAll('[data-module]').forEach((item) => item.classList.toggle('active', item.dataset.module === module))
  const [title, description] = details
  app.querySelector('.content').innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>${escapeHtml(title)}</h1><p class="muted">${escapeHtml(description)}</p></div><span class="health-pill">Admin access</span></header><section class="module-grid"><section class="panel module-intro"><p class="kicker">WORKSPACE</p><h2>${escapeHtml(title)}</h2><p class="muted">This module is now part of the admin workspace. Its records and actions will appear here as the backend slice is connected.</p><button class="primary-action" data-module="Overview">Back to overview</button></section><section class="panel"><p class="kicker">NEXT ACTION</p><h2>Keep records complete</h2><p class="muted">Use the overview for the currently connected livestock and animal-health records.</p></section></section>`
  app.querySelector('[data-module="Overview"]').addEventListener('click', renderApp)
}

function renderRoleWorkspace() {
  const roleContent = {
    worker: ['Daily operations', 'Record milk and eggs, complete activities, check stock and notify the farm owner about shortages.', ['Production', 'Activities', 'Inventory']],
    veterinarian: ['Animal health', 'Review animal records, record treatments and follow up vaccinations and due care.', ['Livestock', 'Health records', 'Due follow-ups']],
    customer: ['Farm shop', 'Browse produce, place orders, follow your payment status and send feedback.', ['Farm shop', 'My orders', 'Payments']],
    buyer: ['Livestock buying', 'Review available livestock, request animals and follow your order status.', ['Livestock catalogue', 'My orders', 'Payments']],
  }[currentUser.role]
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>${escapeHtml(roleContent[0])}</h2><div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="logout" class="quiet">Sign out</button></aside><main class="content"><header><div><p class="kicker">YOUR WORKSPACE</p><h1>${escapeHtml(roleContent[0])}</h1><p class="muted">${escapeHtml(roleContent[1])}</p></div><span class="health-pill">Role protected</span></header><div class="role-grid">${roleContent[2].map((item) => `<section class="panel"><p class="kicker">MODULE</p><h2>${escapeHtml(item)}</h2><p class="muted">This workspace is limited to information relevant to your role.</p></section>`).join('')}</div></main></div>`
  app.querySelector('#logout').addEventListener('click', async () => { await api.logout(); currentUser = null; renderLanding(app, () => renderAuth('login'), () => renderAuth('login')) })
}

async function start() {
  const resetToken = new URLSearchParams(window.location.search).get('reset')
  if (resetToken) {
    renderResetPassword(resetToken)
    return
  }
  const verificationToken = new URLSearchParams(window.location.search).get('verify')
  if (verificationToken) {
    try {
      await api.verifyEmail(verificationToken)
      history.replaceState({}, '', '/')
      renderAuth('login', message('Email confirmed. You can now sign in.', false))
    } catch (error) { renderAuth('login', message(error.message, true)) }
    return
  }
  try {
    const session = await api.session()
    currentUser = session.user
    await renderApp()
  } catch {
    try {
      const status = await api.setupStatus()
      renderLanding(app, () => renderAuth(status.setupRequired ? 'setup' : 'login'), () => renderAuth('login'))
    } catch (error) { app.innerHTML = message(`Backend unavailable: ${error.message}`, true) }
  }
}

start()
