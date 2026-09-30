import './style.css'
import { api } from './api.js'
import { renderLanding } from './landing.js'

const app = document.querySelector('#app')
let currentUser = null

const adminNavigation = [
  ['Operations', ['Overview', 'Livestock', 'Animal health', 'Activities', 'Production', 'Inventory']],
  ['Commerce', ['Sales & orders', 'Feedback']],
  ['Insights', ['Reports', 'Assistant']],
  ['Administration', ['User accounts']],
]

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
  const demoButton = document.createElement('button')
  demoButton.type = 'button'
  demoButton.className = 'secondary-action'
  demoButton.textContent = 'Load demo data'
  demoButton.addEventListener('click', async () => {
    try { const result = await api.loadDemoData(); alert(result.message); await renderApp() } catch (error) { alert(error.message) }
  })
  app.querySelector('.content header').append(demoButton)
  const navigation = app.querySelector('.workspace-nav')
  navigation.innerHTML = adminNavigation.map(([group, items]) => `<div class="nav-group"><p>${group}</p>${items.map((item) => `<button class="workspace-nav-item ${item === 'Overview' ? 'active' : ''}" data-module="${item}">${item}</button>`).join('')}</div>`).join('')
  app.querySelectorAll('[data-module]').forEach((item) => item.addEventListener('click', () => {
    if (item.dataset.module === 'Overview') return renderApp()
    renderAdminModule(item.dataset.module)
  }))
  app.querySelector('#logout').addEventListener('click', async () => { await api.logout(); currentUser = null; renderAuth() })
  app.querySelector('#animal-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createAnimal(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
  app.querySelector('#health-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createHealthRecord(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
}

function renderAdminModule(module) {
  if (module === 'Activities') return renderActivitiesModule()
  if (module === 'Production') return renderProductionModule()
  if (module === 'Inventory') return renderInventoryModule()
  if (module === 'Sales & orders') return renderCommerceModule()
  if (module === 'Reports') return renderReportsModule()
  if (module === 'Assistant') return renderAssistantModule()
  if (module === 'Livestock') return renderLivestockModule()
  if (module === 'Animal health') return renderHealthModule()
  if (module === 'User accounts') return renderUsersModule()
  if (module === 'Feedback') return renderFeedbackModule()
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

async function renderActivitiesModule() {
  const content = app.querySelector('.content')
  try {
    const data = await api.activities()
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Activities</h1><p class="muted">Plan and complete daily farm work.</p></div><span class="health-pill">Live records</span></header><section class="module-grid"><section class="panel"><h2>Plan activity</h2><form id="activity-form" class="compact-form"><select name="activityType"><option value="feeding">Feeding</option><option value="milking">Milking</option><option value="cleaning">Cleaning</option><option value="health">Health follow-up</option><option value="maintenance">Maintenance</option><option value="other">Other</option></select><input name="description" placeholder="What needs to happen?" required><input name="activityAt" type="datetime-local" required><button>Save activity</button></form></section><section class="panel"><h2>Activity list</h2><div class="table-wrap"><table><thead><tr><th>When</th><th>Type</th><th>Description</th><th>Status</th><th></th></tr></thead><tbody>${data.activities.length ? data.activities.map((item) => `<tr><td>${escapeHtml(item.activity_at)}</td><td>${escapeHtml(item.activity_type)}</td><td>${escapeHtml(item.description)}</td><td>${escapeHtml(item.status)}</td><td>${item.status === 'planned' ? `<button class="small-action" data-complete="${item.id}">Complete</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="5" class="empty">No activities yet.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('#activity-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createActivity(Object.fromEntries(new FormData(event.target))); await renderActivitiesModule() })
    content.querySelectorAll('[data-complete]').forEach((button) => button.addEventListener('click', async () => { await api.completeActivity(button.dataset.complete); await renderActivitiesModule() }))
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderProductionModule() {
  const content = app.querySelector('.content')
  try {
    const [milk, eggs] = await Promise.all([api.milkProduction(), api.eggProduction()])
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Production</h1><p class="muted">Record milk and egg production as it happens.</p></div><span class="health-pill">Daily records</span></header><section class="module-grid"><section class="panel"><h2>Record milk</h2><form id="milk-form" class="compact-form"><input name="productionDate" type="date" required><select name="session"><option value="morning">Morning</option><option value="evening">Evening</option></select><input name="quantityLitres" type="number" min="0" step="0.01" placeholder="Litres" required><button>Save milk</button></form><h2>Recent milk</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Session</th><th>Litres</th></tr></thead><tbody>${milk.milk.length ? milk.milk.slice(0, 12).map((item) => `<tr><td>${item.production_date}</td><td>${item.milking_session}</td><td>${item.quantity_litres}</td></tr>`).join('') : '<tr><td colspan="3" class="empty">No milk records yet.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Record eggs</h2><form id="egg-form" class="compact-form"><input name="productionDate" type="date" required><input name="eggsCollected" type="number" min="0" placeholder="Collected" required><input name="eggsBroken" type="number" min="0" placeholder="Broken" value="0"><button>Save eggs</button></form><h2>Recent eggs</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Collected</th><th>Broken</th></tr></thead><tbody>${eggs.eggs.length ? eggs.eggs.slice(0, 12).map((item) => `<tr><td>${item.production_date}</td><td>${item.eggs_collected}</td><td>${item.eggs_broken}</td></tr>`).join('') : '<tr><td colspan="3" class="empty">No egg records yet.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('#milk-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.recordMilk(Object.fromEntries(new FormData(event.target))); await renderProductionModule() })
    content.querySelector('#egg-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.recordEggs(Object.fromEntries(new FormData(event.target))); await renderProductionModule() })
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderInventoryModule() {
  const content = app.querySelector('.content')
  try {
    const data = await api.inventory()
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Inventory</h1><p class="muted">Track supplies and see what needs replenishing.</p></div><span class="health-pill">Stock control</span></header><section class="module-grid"><section class="panel"><h2>Add stock item</h2><form id="inventory-form" class="compact-form"><input name="name" placeholder="Item name" required><select name="category"><option value="feed">Feed</option><option value="medicine">Medicine</option><option value="equipment">Equipment</option><option value="supplies">Supplies</option><option value="other">Other</option></select><input name="unit" placeholder="Unit" required><input name="quantityOnHand" type="number" min="0" step="0.01" placeholder="Quantity" required><input name="reorderLevel" type="number" min="0" step="0.01" placeholder="Reorder level" required><button>Add item</button></form></section><section class="panel"><h2>Stock list</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th>Category</th><th>On hand</th><th>Reorder at</th></tr></thead><tbody>${data.items.length ? data.items.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.category)}</td><td>${item.quantity_on_hand} ${escapeHtml(item.unit)}</td><td>${item.reorder_level}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No stock items yet.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('#inventory-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createInventoryItem(Object.fromEntries(new FormData(event.target))); await renderInventoryModule() })
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderCommerceModule() {
  const content = app.querySelector('.content')
  try {
    const [orders, sales, payments] = await Promise.all([api.orders(), api.sales(), api.payments()])
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Sales & orders</h1><p class="muted">Create orders, record sales and track payments.</p></div><span class="health-pill">Admin access</span></header><section class="module-grid"><section class="panel"><h2>Create order</h2><form id="order-form" class="compact-form"><input name="customerName" placeholder="Customer name" required><input name="customerPhone" placeholder="Phone"><input name="itemName" placeholder="Item" required><input name="quantity" type="number" min=".01" step=".01" placeholder="Quantity" required><input name="unit" placeholder="Unit" required><input name="unitPrice" type="number" min="0" step=".01" placeholder="Unit price" required><button>Create order</button></form><h2>Orders</h2><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Status</th><th>Total</th></tr></thead><tbody>${orders.orders.length ? orders.orders.map((item) => `<tr><td>${escapeHtml(item.customer)}</td><td>${escapeHtml(item.status)}</td><td>${item.total_amount}</td></tr>`).join('') : '<tr><td colspan="3" class="empty">No orders yet.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Record sale</h2><form id="sale-form" class="compact-form"><input name="description" placeholder="Description" required><input name="quantity" type="number" min=".01" step=".01" placeholder="Quantity" required><input name="unit" placeholder="Unit" required><input name="unitPrice" type="number" min="0" step=".01" placeholder="Unit price" required><input name="soldAt" type="datetime-local" required><button>Save sale</button></form><h2>Payments</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Method</th><th>Status</th><th>Amount</th></tr></thead><tbody>${payments.payments.length ? payments.payments.map((item) => `<tr><td>#${item.order_id}</td><td>${item.method}</td><td>${item.status}</td><td>${item.amount}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No payments yet.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('#order-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createOrder(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() })
    content.querySelector('#sale-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createSale(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() })
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderReportsModule() {
  const content = app.querySelector('.content')
  try {
    const finance = await api.financeReport()
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Reports</h1><p class="muted">Monthly financial performance from recorded sales and expenses.</p></div><span class="health-pill">Current month</span></header><section class="stats"><div><small>Sales</small><strong>KES ${Number(finance.salesThisMonth).toLocaleString()}</strong></div><div><small>Confirmed payments</small><strong>KES ${Number(finance.confirmedPaymentsThisMonth).toLocaleString()}</strong></div><div><small>Expenses</small><strong>KES ${Number(finance.expensesThisMonth).toLocaleString()}</strong></div><div><small>Estimated net</small><strong>KES ${Number(finance.estimatedNet).toLocaleString()}</strong></div></section><section class="panel"><h2>Finance summary</h2><p class="muted">These totals are calculated from the records entered in Sales, Payments and Expenses.</p></section>`
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderAssistantModule() {
  const content = app.querySelector('.content')
  content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Farm assistant</h1><p class="muted">Role-limited, rule-based decision support from your records.</p></div><span class="health-pill">Rule-based mode</span></header><section class="panel assistant-panel"><div id="assistant-messages" class="assistant-messages"><p class="message success">Ask about milk, animals, tasks, stock or health follow-ups.</p></div><form id="assistant-form" class="assistant-form"><input name="message" placeholder="Ask a farm question..." required><button>Ask assistant</button></form></section>`
  content.querySelector('#assistant-form').addEventListener('submit', async (event) => {
    event.preventDefault()
    const form = event.target
    const input = form.querySelector('input')
    const messages = content.querySelector('#assistant-messages')
    messages.insertAdjacentHTML('beforeend', `<p class="assistant-question">${escapeHtml(input.value)}</p>`)
    try { const result = await api.assistantChat(input.value); content.querySelector('.health-pill').textContent = result.mode; messages.insertAdjacentHTML('beforeend', `<p class="message success">${escapeHtml(result.answer)}</p>`) } catch (error) { messages.insertAdjacentHTML('beforeend', `<p class="message danger">${escapeHtml(error.message)}</p>`) }
    input.value = ''
  })
}

async function renderLivestockModule() {
  const content=app.querySelector('.content'); try { const data=await api.animals(); content.innerHTML=`<header><div><p class="kicker">FARM MODULE</p><h1>Livestock register</h1><p class="muted">Register and review the herd.</p></div><span class="health-pill">${data.animals.length} animals</span></header><section class="panel module-panel"><form id="animal-module-form" class="compact-form"><input name="tagNumber" placeholder="Tag number" required><select name="species"><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="sheep">Sheep</option></select><input name="breed" placeholder="Breed"><select name="sex"><option value="female">Female</option><option value="male">Male</option></select><button>Add animal</button></form><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Species</th><th>Breed</th><th>Status</th></tr></thead><tbody>${data.animals.map((a)=>`<tr><td>${escapeHtml(a.tag_number)}</td><td>${a.species}</td><td>${escapeHtml(a.breed||'—')}</td><td>${a.status}</td></tr>`).join('')||'<tr><td colspan="4" class="empty">No animals yet.</td></tr>'}</tbody></table></div></section>`; content.querySelector('form').addEventListener('submit',async(e)=>{e.preventDefault();await api.createAnimal(Object.fromEntries(new FormData(e.target)));await renderLivestockModule()}) } catch(error){content.innerHTML=message(error.message,true)}
}

async function renderHealthModule() {
  const content=app.querySelector('.content'); try { const [animals,data]=await Promise.all([api.animals(),api.healthRecords()]); content.innerHTML=`<header><div><p class="kicker">FARM MODULE</p><h1>Animal health</h1><p class="muted">Record treatment, vaccination and follow-ups.</p></div><span class="health-pill">${data.records.length} records</span></header><section class="panel module-panel"><form id="health-module-form" class="compact-form"><select name="animalId" required><option value="">Animal</option>${animals.animals.map((a)=>`<option value="${a.id}">${escapeHtml(a.tag_number)}</option>`).join('')}</select><select name="recordType"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option></select><input name="description" placeholder="Description" required><input name="recordDate" type="date" required><button>Save health record</button></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal</th><th>Type</th><th>Description</th></tr></thead><tbody>${data.records.map((r)=>`<tr><td>${r.record_date}</td><td>${escapeHtml(r.tag_number)}</td><td>${r.record_type}</td><td>${escapeHtml(r.description)}</td></tr>`).join('')||'<tr><td colspan="4" class="empty">No health records yet.</td></tr>'}</tbody></table></div></section>`; content.querySelector('form').addEventListener('submit',async(e)=>{e.preventDefault();await api.createHealthRecord(Object.fromEntries(new FormData(e.target)));await renderHealthModule()}) } catch(error){content.innerHTML=message(error.message,true)}
}

async function renderUsersModule() {
  const content=app.querySelector('.content'); try { const data=await api.users(); content.innerHTML=`<header><div><p class="kicker">FARM MODULE</p><h1>User accounts</h1><p class="muted">Create accounts and control access.</p></div><span class="health-pill">Admin access</span></header><section class="module-grid"><section class="panel"><h2>Create account</h2><form id="user-module-form" class="compact-form"><input name="fullName" placeholder="Full name" required><input name="username" placeholder="Username" required><input name="email" type="email" placeholder="Email" required><input name="password" type="password" minlength="12" placeholder="Temporary password" required><select name="role"><option value="worker">Worker</option><option value="veterinarian">Veterinarian</option><option value="buyer">Buyer</option><option value="customer">Customer</option></select><button>Create account</button></form></section><section class="panel"><h2>Accounts</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Role</th><th>Status</th></tr></thead><tbody>${data.users.map((u)=>`<tr><td>${escapeHtml(u.full_name)}</td><td>${u.role}</td><td>${u.is_active?'Active':'Inactive'}</td></tr>`).join('')}</tbody></table></div></section></section>`; content.querySelector('form').addEventListener('submit',async(e)=>{e.preventDefault();await api.createUser(Object.fromEntries(new FormData(e.target)));await renderUsersModule()}) } catch(error){content.innerHTML=message(error.message,true)}
}

async function renderFeedbackModule() {
  const content=app.querySelector('.content'); try { const data=await api.feedback(); content.innerHTML=`<header><div><p class="kicker">FARM MODULE</p><h1>Feedback</h1><p class="muted">Review customer feedback, complaints and suggestions.</p></div><span class="health-pill">${data.feedback.length} messages</span></header><section class="panel module-panel"><div class="table-wrap"><table><thead><tr><th>Date</th><th>From</th><th>Type</th><th>Message</th><th>Status</th><th></th></tr></thead><tbody>${data.feedback.map((f)=>`<tr><td>${f.created_at}</td><td>${escapeHtml(f.user_name||'Customer')}</td><td>${f.category}</td><td>${escapeHtml(f.message)}</td><td>${f.status}</td><td>${f.status==='open'?`<button class="small-action" data-resolve="${f.id}">Resolve</button>`:''}</td></tr>`).join('')||'<tr><td colspan="6" class="empty">No feedback yet.</td></tr>'}</tbody></table></div></section>`; content.querySelectorAll('[data-resolve]').forEach((b)=>b.addEventListener('click',async()=>{await api.resolveFeedback(b.dataset.resolve);await renderFeedbackModule()})) } catch(error){content.innerHTML=message(error.message,true)}
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
