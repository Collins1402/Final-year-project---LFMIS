import './style.css'
import './app.css'
import { api } from './api.js'

const root = document.querySelector('#app')
const navSections = [
  ['⌂', 'Overview'], ['◉', 'Livestock'], ['♡', 'Animal health'],
  ['☷', 'Activities'], ['◌', 'Production'], ['▣', 'Inventory'], ['↗', 'Sales & orders'], ['▥', 'Reports'], ['◇', 'Feedback'], ['♙', 'User accounts'],
]

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[character]))

const formatMoney = (value) => new Intl.NumberFormat('en-KE', {
  style: 'currency', currency: 'KES', maximumFractionDigits: 0,
}).format(Number(value) || 0)

function showMessage(element, message, isError = false) {
  element.textContent = message
  element.classList.toggle('error', isError)
  element.classList.remove('success')
  element.hidden = !message
}

function renderAuth(mode, notice = '') {
  const setup = mode === 'setup'
  root.innerHTML = `
    <main class="auth-shell"><section class="auth-panel" aria-labelledby="auth-title">
      <div class="brand auth-brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>LFMIS workspace</small></div></div>
      <p class="eyebrow">${setup ? 'Initial setup' : 'Farm workspace'}</p>
      <h1 id="auth-title">${setup ? 'Create the farm owner account' : 'Welcome back'}</h1>
      <p class="intro">${setup ? 'This first account will have administrator access.' : 'Sign in to access farm records.'}</p>
      <form id="auth-form" class="form-stack">
        ${setup ? '<label>Full name<input name="fullName" autocomplete="name" required maxlength="120"></label>' : ''}
        <label>Email<input type="email" name="email" autocomplete="username" required maxlength="190"></label>
        <label>Password<input type="password" name="password" autocomplete="${setup ? 'new-password' : 'current-password'}" minlength="${setup ? '12' : '1'}" required></label>
        <p class="form-message ${notice ? 'success' : ''}" role="status" ${notice ? '' : 'hidden'}>${escapeHtml(notice)}</p>
        <button class="primary-button" type="submit">${setup ? 'Create account' : 'Sign in'}</button>
      </form>
      ${setup ? '' : '<button class="text-link" type="button" id="forgot-password">Forgot password?</button>'}
    </section></main>`

  const form = root.querySelector('#auth-form')
  root.querySelector('#forgot-password')?.addEventListener('click', renderForgotPassword)
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(form))
    const feedback = form.querySelector('.form-message')
    const button = form.querySelector('button')
    button.disabled = true
    button.textContent = setup ? 'Creating account…' : 'Signing in…'
    try {
      const result = setup ? await api.createAdmin(data) : await api.login(data)
      renderHome(result.user)
    } catch (error) {
      showMessage(feedback, error.message, true)
      button.disabled = false
      button.textContent = setup ? 'Create account' : 'Sign in'
    }
  })
}

function renderForgotPassword() {
  root.innerHTML = `
    <main class="auth-shell"><section class="auth-panel" aria-labelledby="forgot-title">
      <div class="brand auth-brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>LFMIS workspace</small></div></div>
      <p class="eyebrow">Account recovery</p><h1 id="forgot-title">Reset your password</h1>
      <p class="intro">Enter the email address on your account. If it matches an active account, reset instructions will be sent.</p>
      <form id="forgot-form" class="form-stack">
        <label>Email<input type="email" name="email" autocomplete="email" required maxlength="190"></label>
        <p class="form-message" role="status" hidden></p>
        <button class="primary-button" type="submit">Send reset link</button>
      </form>
      <button class="text-link" type="button" id="back-to-login">Back to sign in</button>
    </section></main>`
  root.querySelector('#back-to-login').addEventListener('click', () => renderAuth('login'))
  const form = root.querySelector('#forgot-form')
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const feedback = form.querySelector('.form-message')
    const button = form.querySelector('button')
    button.disabled = true
    button.textContent = 'Sending…'
    try {
      const result = await api.forgotPassword(Object.fromEntries(new FormData(form)))
      showMessage(feedback, result.message)
      button.textContent = 'Send another link'
      button.disabled = false
    } catch (error) {
      showMessage(feedback, error.message, true)
      button.textContent = 'Send reset link'
      button.disabled = false
    }
  })
}

function renderResetPassword(token) {
  root.innerHTML = `
    <main class="auth-shell"><section class="auth-panel" aria-labelledby="reset-title">
      <div class="brand auth-brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>LFMIS workspace</small></div></div>
      <p class="eyebrow">Account recovery</p><h1 id="reset-title">Choose a new password</h1>
      <p class="intro">Use at least 12 characters. The reset link can only be used once.</p>
      <form id="reset-form" class="form-stack">
        <label>New password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
        <label>Confirm password<input type="password" name="confirmPassword" autocomplete="new-password" minlength="12" required></label>
        <p class="form-message" role="alert" hidden></p>
        <button class="primary-button" type="submit">Update password</button>
      </form>
    </section></main>`
  const form = root.querySelector('#reset-form')
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const feedback = form.querySelector('.form-message')
    const button = form.querySelector('button')
    const data = Object.fromEntries(new FormData(form))
    if (data.password !== data.confirmPassword) {
      showMessage(feedback, 'The passwords do not match.', true)
      return
    }
    button.disabled = true
    button.textContent = 'Updating…'
    try {
      await api.resetPassword({ token, password: data.password })
      renderAuth('login', 'Password updated. Sign in with your new password.')
    } catch (error) {
      showMessage(feedback, error.message, true)
      button.disabled = false
      button.textContent = 'Update password'
    }
  })
}

function shell(user, active, content) {
  const initials = user.fullName.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase()
  const visibleSections = navSections.filter(([, label]) => {
    if (user.role === 'admin') return true
    if (user.role === 'customer') return ['Sales & orders', 'Feedback'].includes(label)
    if (user.role === 'veterinarian') return ['Overview', 'Livestock', 'Animal health', 'Activities'].includes(label)
    if (user.role === 'worker') return ['Overview', 'Livestock', 'Activities', 'Production', 'Inventory'].includes(label)
    return false
  })
  root.innerHTML = `
    <aside class="sidebar">
      <div class="brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>LFMIS workspace</small></div></div>
      <div class="farm-switcher"><span class="online-dot"></span><span>Farm operations</span></div>
      <nav aria-label="Main navigation"><p class="nav-label">Workspace</p>
        ${visibleSections.map(([icon, label]) => `<button class="nav-item ${label === active ? 'active' : ''}" data-section="${escapeHtml(label)}"><span class="nav-icon">${icon}</span>${escapeHtml(label)}</button>`).join('')}
      </nav>
      <div class="sidebar-bottom"><button class="nav-item" id="logout">↪ Sign out</button><div class="user"><span class="avatar">${escapeHtml(initials)}</span><div><strong>${escapeHtml(user.fullName)}</strong><small>${escapeHtml(user.role)}</small></div></div></div>
    </aside>
    <main class="main-content"><header class="topbar"><div class="mobile-brand"><span class="brand-mark">F</span> Flouri Farm</div><div class="top-actions"><span class="role-label">${escapeHtml(user.role)}</span></div></header>
      <div class="page-wrap">${content}<div id="toast" class="toast" role="status"></div></div>
    </main>`

  root.querySelectorAll('.nav-item[data-section]').forEach((item) => item.addEventListener('click', () => navigate(user, item.dataset.section)))
  root.querySelector('#logout').addEventListener('click', async () => {
    try { await api.logout() } finally { renderAuth('login') }
  })
}

function toast(message) {
  const element = root.querySelector('#toast')
  if (!element) return
  element.textContent = message
  element.classList.add('visible')
  window.setTimeout(() => element.classList.remove('visible'), 2400)
}

async function renderDashboard(user) {
  shell(user, 'Overview', '<section class="welcome-row"><div><p class="eyebrow">Farm overview</p><h1>Welcome, <span id="welcome-name"></span></h1><p class="intro">Live figures from your farm records.</p></div><button class="primary-button" id="add-animal"><span>＋</span> Register animal</button></section><p class="page-error" id="page-error" role="alert" hidden></p><section class="stat-grid" aria-label="Farm summary"><article class="stat-card"><div class="stat-top"><span class="stat-icon green">♧</span></div><p>Active livestock</p><strong id="stat-animals">—</strong><small>From registered animal records</small></article><article class="stat-card"><div class="stat-top"><span class="stat-icon amber">◌</span></div><p>Today\'s milk</p><strong><span id="stat-milk">—</span> <em>litres</em></strong><small>Recorded for today</small></article><article class="stat-card"><div class="stat-top"><span class="stat-icon blue">▣</span></div><p>Monthly sales</p><strong id="stat-sales">—</strong><small>Recorded sales</small></article><article class="stat-card"><div class="stat-top"><span class="stat-icon coral">!</span></div><p>Open tasks</p><strong id="stat-tasks">—</strong><small>Planned tasks due</small></article></section><section class="content-grid"><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Livestock inventory</p><h2>Herd overview</h2></div><button class="text-button" data-section="Livestock">View records →</button></div><div class="herd-list" id="herd-list"><p class="empty-state">Loading farm records…</p></div></article><article class="panel alerts-panel"><div class="panel-heading"><div><p class="eyebrow">Needs attention</p><h2>Stock alerts</h2></div><span class="alert-count" id="alert-count">0</span></div><div class="alert-list" id="stock-alerts"><p class="empty-state">Loading stock levels…</p></div></article></section>')
  root.querySelector('#welcome-name').textContent = user.fullName.split(/\s+/)[0]
  root.querySelector('#add-animal').addEventListener('click', () => navigate(user, 'Livestock'))
  root.querySelector('.text-button[data-section="Livestock"]').addEventListener('click', () => navigate(user, 'Livestock'))
  try {
    const data = await api.dashboard()
    root.querySelector('#stat-animals').textContent = data.activeAnimals
    root.querySelector('#stat-milk').textContent = Number(data.milkTodayLitres).toLocaleString()
    root.querySelector('#stat-sales').textContent = formatMoney(data.salesThisMonth)
    root.querySelector('#stat-tasks').textContent = data.openTasks
    const counts = Object.fromEntries(data.herdBySpecies.map(({ species, total }) => [species, total]))
    const speciesNames = { cattle: 'Cattle', goat: 'Goats', rabbit: 'Rabbits', chicken: 'Poultry', other: 'Other' }
    root.querySelector('#herd-list').innerHTML = Object.entries(speciesNames).map(([key, label]) => `<div class="herd-row"><span class="animal">${label[0]}</span><div><strong>${label}</strong><small>Active animals</small></div><b>${counts[key] || 0}</b></div>`).join('')
    root.querySelector('#alert-count').textContent = data.stockAlerts.length
    root.querySelector('#stock-alerts').innerHTML = data.stockAlerts.length
      ? data.stockAlerts.map((item) => `<div class="alert-item"><span class="alert-symbol red">!</span><div><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.quantity_on_hand)} ${escapeHtml(item.unit)} remaining</small></div></div>`).join('')
      : '<p class="empty-state">No items are below their reorder level.</p>'
  } catch (error) {
    showMessage(root.querySelector('#page-error'), error.message, true)
  }
}

async function renderLivestock(user) {
  shell(user, 'Livestock', '<section class="welcome-row"><div><p class="eyebrow">Farm records</p><h1>Livestock</h1><p class="intro">Registered animals and their current status.</p></div><button class="primary-button" id="open-animal-form"><span>＋</span> Register animal</button></section><form id="animal-form" class="entry-form" hidden><label>Tag number<input name="tagNumber" maxlength="80"></label><label>Species<select name="species" required><option value="">Choose species</option><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="other">Other</option></select></label><label>Name<input name="name" maxlength="100"></label><label>Breed<input name="breed" maxlength="100"></label><label>Sex<select name="sex"><option value="unknown">Unknown</option><option value="female">Female</option><option value="male">Male</option></select></label><label>Weight (kg)<input name="weightKg" type="number" min="0" step="0.01"></label><label>Date of birth<input name="dateOfBirth" type="date"></label><button class="primary-button" type="submit">Save animal</button><p class="form-message" role="alert" hidden></p></form><p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Animal</th><th>Breed</th><th>Sex</th><th>Weight</th><th>Status</th></tr></thead><tbody id="animal-rows"><tr><td colspan="6">Loading records…</td></tr></tbody></table></div>')
  const form = root.querySelector('#animal-form')
  root.querySelector('#open-animal-form').addEventListener('click', () => { form.hidden = !form.hidden })
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const feedback = form.querySelector('.form-message')
    const data = Object.fromEntries(new FormData(form))
    data.weightKg = data.weightKg || null
    data.dateOfBirth = data.dateOfBirth || null
    try {
      await api.createAnimal(data)
      toast('Animal registered')
      await renderLivestock(user)
    } catch (error) {
      showMessage(feedback, error.message, true)
    }
  })
  try {
    const { animals } = await api.animals()
    root.querySelector('#animal-rows').innerHTML = animals.length
      ? animals.map((animal) => `<tr><td>${escapeHtml(animal.tag_number || '—')}</td><td>${escapeHtml(animal.name || animal.species)}</td><td>${escapeHtml(animal.breed || '—')}</td><td>${escapeHtml(animal.sex)}</td><td>${escapeHtml(animal.weight_kg ?? '—')}</td><td>${escapeHtml(animal.status)}</td></tr>`).join('')
      : '<tr><td colspan="6">No animals recorded yet.</td></tr>'
  } catch (error) {
    showMessage(root.querySelector('#page-error'), error.message, true)
    root.querySelector('#animal-rows').innerHTML = '<tr><td colspan="6">Records unavailable.</td></tr>'
  }
}

async function renderProduction(user) {
  shell(user, 'Production', '<section class="welcome-row"><div><p class="eyebrow">Daily farm records</p><h1>Production</h1><p class="intro">Record and review milk and egg totals.</p></div></section><h2 class="section-title">Milk</h2><form id="milk-form" class="entry-form"><label>Date<input name="productionDate" type="date" required></label><label>Session<select name="session" required><option value="morning">Morning</option><option value="evening">Evening</option><option value="other">Other</option></select></label><label>Quantity (litres)<input name="quantityLitres" type="number" min="0" step="0.01" required></label><label>Notes<input name="notes" maxlength="500"></label><button class="primary-button" type="submit">Save milk</button><p class="form-message" role="alert" hidden></p></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Total milk (litres)</th></tr></thead><tbody id="milk-rows"><tr><td colspan="2">Loading records…</td></tr></tbody></table></div><h2 class="section-title">Eggs</h2><form id="egg-form" class="entry-form"><label>Date<input name="productionDate" type="date" required></label><label>Quantity (trays)<input name="quantityTrays" type="number" min="0" step="0.01" required></label><label>Notes<input name="notes" maxlength="500"></label><button class="primary-button" type="submit">Save eggs</button><p class="form-message" role="alert" hidden></p></form><p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>Date</th><th>Trays</th></tr></thead><tbody id="egg-rows"><tr><td colspan="2">Loading records…</td></tr></tbody></table></div>')
  const date = root.querySelector('[name="productionDate"]')
  date.value = new Date().toISOString().slice(0, 10)
  root.querySelector('#milk-form').addEventListener('submit', async (event) => {
    event.preventDefault()
    const form = event.currentTarget
    try {
      await api.recordMilk(Object.fromEntries(new FormData(form)))
      toast('Production recorded')
      await renderProduction(user)
    } catch (error) {
      showMessage(form.querySelector('.form-message'), error.message, true)
    }
  })
  const eggDate = root.querySelector('#egg-form [name="productionDate"]')
  eggDate.value = date.value
  root.querySelector('#egg-form').addEventListener('submit', async (event) => {
    event.preventDefault()
    const form = event.currentTarget
    try {
      await api.recordEggs(Object.fromEntries(new FormData(form)))
      toast('Egg production recorded')
      await renderProduction(user)
    } catch (error) {
      showMessage(form.querySelector('.form-message'), error.message, true)
    }
  })
  try {
    const { production } = await api.milkProduction()
    root.querySelector('#milk-rows').innerHTML = production.length
      ? production.map((record) => `<tr><td>${escapeHtml(record.production_date)}</td><td>${escapeHtml(record.quantity_litres)}</td></tr>`).join('')
      : '<tr><td colspan="2">No milk production recorded in the last seven days.</td></tr>'
    const eggs = await api.eggProduction()
    root.querySelector('#egg-rows').innerHTML = eggs.production.length
      ? eggs.production.map((record) => `<tr><td>${escapeHtml(record.production_date)}</td><td>${escapeHtml(record.quantity_trays)}</td></tr>`).join('')
      : '<tr><td colspan="2">No eggs recorded in the last 30 days.</td></tr>'
  } catch (error) {
    showMessage(root.querySelector('#page-error'), error.message, true)
    root.querySelector('#milk-rows').innerHTML = '<tr><td colspan="2">Records unavailable.</td></tr>'
  }
}

async function renderReports(user) {
  const financePanel = user.role === 'admin' ? '<section class="panel finance-panel"><div class="panel-heading"><div><p class="eyebrow">Current month</p><h2>Financial summary</h2></div></div><div class="finance-values"><div><span>Recorded sales</span><strong id="report-sales">—</strong></div><div><span>Expenses</span><strong id="report-expenses">—</strong></div><div><span>Confirmed payments</span><strong id="report-payments">—</strong></div><div><span>Estimated net</span><strong id="report-net">—</strong></div></div></section><h2 class="section-title">Record expense</h2><form id="expense-form" class="entry-form"><label>Category<input name="category" required maxlength="80"></label><label>Description<input name="description" required maxlength="255"></label><label>Amount (KES)<input name="amount" type="number" min="0" step="0.01" required></label><label>Date<input name="expenseDate" type="date" required></label><button class="primary-button" type="submit">Save expense</button><p class="form-message" role="alert" hidden></p></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr></thead><tbody id="expense-rows"><tr><td colspan="4">Loading expenses…</td></tr></tbody></table></div>' : ''
  shell(user, 'Reports', `<section class="welcome-row"><div><p class="eyebrow">Farm performance</p><h1>Reports</h1><p class="intro">Recorded financial results and production decision support.</p></div></section><p class="page-error" id="page-error" role="alert" hidden></p>${financePanel}<section class="panel forecast-panel"><div class="panel-heading"><div><p class="eyebrow">Machine learning</p><h2>Milk production outlook</h2></div><span class="alert-count">7 days</span></div><p id="forecast-status" class="intro">Evaluating recorded history…</p><div id="forecast-values" class="forecast-values"></div><p id="forecast-evaluation" class="forecast-evaluation"></p><p class="forecast-note">Decision support only. Forecasts require at least 21 consecutive recorded days and do not replace farm or veterinary judgment.</p></section>`)
  try {
    const data = await api.productionForecast()
    root.querySelector('#forecast-status').textContent = `${data.method} · trained on ${data.trainingDays} consecutive days`
    root.querySelector('#forecast-values').innerHTML = data.forecast.map((item) => `<div class="forecast-day"><span>${escapeHtml(item.date)}</span><strong>${escapeHtml(item.litres)} L</strong></div>`).join('')
    root.querySelector('#forecast-evaluation').textContent = `Seven-day backtest mean absolute error: ${data.backtestMaeLitres} litres per day.`
  } catch (error) {
    if (error.status === 422) {
      root.querySelector('#forecast-status').textContent = error.message
    } else {
      showMessage(root.querySelector('#page-error'), error.message, true)
    }
  }
  if (user.role === 'admin') {
    try {
      const [finance, expenseData] = await Promise.all([api.financeReport(), api.expenses()])
      root.querySelector('#report-sales').textContent = formatMoney(finance.salesThisMonth)
      root.querySelector('#report-expenses').textContent = formatMoney(finance.expensesThisMonth)
      root.querySelector('#report-payments').textContent = formatMoney(finance.confirmedPaymentsThisMonth)
      root.querySelector('#report-net').textContent = formatMoney(finance.estimatedNetFromRecordedSales)
      root.querySelector('#expense-rows').innerHTML = expenseData.expenses.length
        ? expenseData.expenses.map((expense) => `<tr><td>${escapeHtml(expense.expense_date)}</td><td>${escapeHtml(expense.category)}</td><td>${escapeHtml(expense.description)}</td><td>${formatMoney(expense.amount)}</td></tr>`).join('')
        : '<tr><td colspan="4">No expenses recorded.</td></tr>'
    } catch (error) {
      showMessage(root.querySelector('#page-error'), error.message, true)
    }
    const form = root.querySelector('#expense-form')
    form.querySelector('[name="expenseDate"]').value = new Date().toISOString().slice(0, 10)
    form.addEventListener('submit', async (event) => {
      event.preventDefault()
      try {
        await api.createExpense(Object.fromEntries(new FormData(form)))
        toast('Expense recorded')
        await renderReports(user)
      } catch (error) { showMessage(form.querySelector('.form-message'), error.message, true) }
    })
  }
}

async function renderHealth(user) {
  shell(user, 'Animal health', '<section class="welcome-row"><div><p class="eyebrow">Animal care</p><h1>Health records</h1><p class="intro">Treatments, vaccinations, incidents, and due dates.</p></div></section><form id="health-form" class="entry-form"><label>Animal<select name="animalId" required><option value="">Choose an animal</option></select></label><label>Record type<select name="recordType" required><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="diagnosis">Diagnosis</option><option value="medication">Medication</option><option value="incident">Incident</option><option value="other">Other</option></select></label><label>Recorded at<input name="recordedAt" type="datetime-local" required></label><label>Next due<input name="nextDueAt" type="datetime-local"></label><label>Description<input name="description" required maxlength="1000"></label><label>Veterinarian<input name="veterinarian" maxlength="120"></label><button class="primary-button" type="submit">Save health record</button><p class="form-message" role="alert" hidden></p></form><p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>Recorded</th><th>Animal</th><th>Type</th><th>Description</th><th>Next due</th></tr></thead><tbody id="health-rows"><tr><td colspan="5">Loading records…</td></tr></tbody></table></div>')
  const form = root.querySelector('#health-form')
  form.querySelector('[name="recordedAt"]').value = new Date().toISOString().slice(0, 16)
  try {
    const [{ animals }, { records }] = await Promise.all([api.animals(), api.healthRecords()])
    form.querySelector('[name="animalId"]').innerHTML = '<option value="">Choose an animal</option>' + animals.map((animal) => `<option value="${escapeHtml(animal.id)}">${escapeHtml(animal.tag_number || animal.name || animal.species)} · ${escapeHtml(animal.species)}</option>`).join('')
    root.querySelector('#health-rows').innerHTML = records.length
      ? records.map((record) => `<tr><td>${escapeHtml(record.recorded_at)}</td><td>${escapeHtml(record.tag_number || record.animal_name || record.animal_id)}</td><td>${escapeHtml(record.record_type)}</td><td>${escapeHtml(record.description)}</td><td>${escapeHtml(record.next_due_at || '—')}</td></tr>`).join('')
      : '<tr><td colspan="5">No health records have been recorded.</td></tr>'
  } catch (error) {
    showMessage(root.querySelector('#page-error'), error.message, true)
  }
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(form))
    data.nextDueAt = data.nextDueAt || null
    try {
      await api.createHealthRecord(data)
      toast('Health record saved')
      await renderHealth(user)
    } catch (error) {
      showMessage(form.querySelector('.form-message'), error.message, true)
    }
  })
}

async function renderActivities(user) {
  const canEdit = ['admin', 'worker'].includes(user.role)
  const formMarkup = canEdit ? '<form id="activity-form" class="entry-form"><label>Activity<input name="activityType" required maxlength="60" placeholder="Feeding, cleaning, observation"></label><label>Description<input name="description" required maxlength="1000"></label><label>Scheduled at<input name="activityAt" type="datetime-local" required></label><label>Animal<select name="animalId"><option value="">Farm-wide</option></select></label><label>Status<select name="status"><option value="planned">Planned</option><option value="completed">Completed</option></select></label><button class="primary-button" type="submit">Save activity</button><p class="form-message" role="alert" hidden></p></form>' : ''
  shell(user, 'Activities', `<section class="welcome-row"><div><p class="eyebrow">Daily operations</p><h1>Farm activities</h1><p class="intro">Planned work, observations, and completed tasks.</p></div></section>${formMarkup}<p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>When</th><th>Activity</th><th>Description</th><th>Animal</th><th>Status</th><th></th></tr></thead><tbody id="activity-rows"><tr><td colspan="6">Loading activities…</td></tr></tbody></table></div>`)
  const form = root.querySelector('#activity-form')
  if (form) form.querySelector('[name="activityAt"]').value = new Date().toISOString().slice(0, 16)
  async function refresh() {
    const [{ activities }, { animals }] = await Promise.all([api.activities(), api.animals()])
    if (form) form.querySelector('[name="animalId"]').innerHTML = '<option value="">Farm-wide</option>' + animals.map((animal) => `<option value="${escapeHtml(animal.id)}">${escapeHtml(animal.tag_number || animal.name || animal.species)}</option>`).join('')
    root.querySelector('#activity-rows').innerHTML = activities.length
      ? activities.map((activity) => `<tr><td>${escapeHtml(activity.activity_at)}</td><td>${escapeHtml(activity.activity_type)}</td><td>${escapeHtml(activity.description)}</td><td>${escapeHtml(activity.tag_number || activity.animal_name || '—')}</td><td>${escapeHtml(activity.status)}</td><td>${canEdit && activity.status === 'planned' ? `<button class="small-action" data-complete="${escapeHtml(activity.id)}">Complete</button>` : ''}</td></tr>`).join('')
      : '<tr><td colspan="6">No activities recorded.</td></tr>'
  }
  try { await refresh() } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  form?.addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(form))
    data.activityAt = data.activityAt.replace('T', ' ')
    data.animalId = data.animalId || null
    try { await api.createActivity(data); toast('Activity saved'); form.reset(); await refresh() }
    catch (error) { showMessage(form.querySelector('.form-message'), error.message, true) }
  })
  root.querySelector('#activity-rows').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-complete]')
    if (!button) return
    try { await api.completeActivity(button.dataset.complete); toast('Activity completed'); await refresh() }
    catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  })
}

async function renderInventory(user) {
  const createItem = user.role === 'admin' ? '<form id="inventory-item-form" class="entry-form"><label>Item name<input name="name" required maxlength="120"></label><label>Unit<input name="unit" required maxlength="30" placeholder="bales, kg, bags"></label><label>Opening quantity<input name="quantity" type="number" min="0" step="0.01" required value="0"></label><label>Reorder level<input name="reorderLevel" type="number" min="0" step="0.01" required value="0"></label><button class="primary-button" type="submit">Add inventory item</button><p class="form-message" role="alert" hidden></p></form>' : ''
  shell(user, 'Inventory', `<section class="welcome-row"><div><p class="eyebrow">Materials and stock</p><h1>Inventory</h1><p class="intro">Stock on hand, reorder levels, and recorded usage.</p></div></section>${createItem}<form id="stock-form" class="entry-form"><label>Item<select name="itemId" required><option value="">Choose item</option></select></label><label>Transaction<select name="type"><option value="received">Received</option><option value="used">Used</option><option value="waste">Waste</option></select></label><label>Quantity<input name="quantity" type="number" min="0.01" step="0.01" required></label><label>Notes<input name="notes" maxlength="500"></label><button class="primary-button" type="submit">Save stock movement</button><p class="form-message" role="alert" hidden></p></form><p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>Item</th><th>On hand</th><th>Reorder at</th><th>Status</th></tr></thead><tbody id="inventory-rows"><tr><td colspan="4">Loading stock…</td></tr></tbody></table></div>`)
  async function refresh() {
    const { items } = await api.inventory()
    root.querySelector('#stock-form [name="itemId"]').innerHTML = '<option value="">Choose item</option>' + items.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('')
    root.querySelector('#inventory-rows').innerHTML = items.length
      ? items.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.quantity_on_hand)} ${escapeHtml(item.unit)}</td><td>${escapeHtml(item.reorder_level)} ${escapeHtml(item.unit)}</td><td>${Number(item.quantity_on_hand) <= Number(item.reorder_level) ? 'Reorder' : 'In stock'}</td></tr>`).join('')
      : '<tr><td colspan="4">No inventory items have been added.</td></tr>'
  }
  try { await refresh() } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  root.querySelector('#stock-form').addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      await api.inventoryTransaction(Object.fromEntries(new FormData(event.currentTarget)))
      toast('Stock updated')
      await refresh()
    } catch (error) { showMessage(event.currentTarget.querySelector('.form-message'), error.message, true) }
  })
  const itemForm = root.querySelector('#inventory-item-form')
  itemForm?.addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      await api.createInventoryItem(Object.fromEntries(new FormData(itemForm)))
      toast('Inventory item added')
      itemForm.reset()
      await refresh()
    } catch (error) { showMessage(itemForm.querySelector('.form-message'), error.message, true) }
  })
}

async function renderSalesOrders(user) {
  const admin = user.role === 'admin'
  const saleFormMarkup = admin ? '<h2 class="section-title">Record sale</h2><form id="sale-form" class="entry-form"><label>Description<input name="description" required maxlength="255"></label><label>Quantity<input name="quantity" type="number" min="0.01" step="0.01" required></label><label>Unit<input name="unit" required maxlength="30"></label><label>Unit price (KES)<input name="unitPrice" type="number" min="0" step="0.01" required></label><label>Sale date<input name="soldAt" type="datetime-local" required></label><button class="primary-button" type="submit">Save sale</button><p class="form-message" role="alert" hidden></p></form>' : ''
  const orderFormMarkup = admin
    ? '<form id="order-form" class="entry-form"><label>Customer name<input name="customerName" required maxlength="120"></label><label>Customer phone<input name="customerPhone" maxlength="40"></label><label>Item<input name="itemName" required maxlength="120"></label><label>Quantity<input name="quantity" type="number" min="0.01" step="0.01" required></label><label>Unit<input name="unit" required maxlength="30"></label><label>Unit price (KES)<input name="unitPrice" type="number" min="0" step="0.01" required></label><button class="primary-button" type="submit">Create order</button><p class="form-message" role="alert" hidden></p></form>'
    : '<form id="order-form" class="entry-form"><label>Item requested<input name="itemName" required maxlength="120"></label><label>Quantity<input name="quantity" type="number" min="0.01" step="0.01" required></label><label>Unit<input name="unit" required maxlength="30" placeholder="litres, trays, animals"></label><button class="primary-button" type="submit">Request an order</button><p class="form-message" role="alert" hidden></p></form>'
  const salesTable = admin ? '<h2 class="section-title">Sales</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Description</th><th>Quantity</th><th>Total</th></tr></thead><tbody id="sales-rows"><tr><td colspan="4">Loading sales…</td></tr></tbody></table></div>' : ''
  const paymentSection = admin ? '<h2 class="section-title">Record payment</h2><form id="payment-form" class="entry-form"><label>Order<select name="orderId" required><option value="">Choose order</option></select></label><label>Method<select name="method"><option value="cash">Cash received</option><option value="bank">Bank transfer received</option><option value="mpesa">M-Pesa (awaiting confirmation)</option><option value="other">Other</option></select></label><label>Transaction reference<input name="transactionReference" maxlength="120"></label><button class="primary-button" type="submit">Record payment</button><p class="form-message" role="alert" hidden></p></form><h2 class="section-title">Payments</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Method</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead><tbody id="payment-rows"><tr><td colspan="5">Loading payments…</td></tr></tbody></table></div>' : ''
  shell(user, 'Sales & orders', `<section class="welcome-row"><div><p class="eyebrow">Farm transactions</p><h1>${admin ? 'Sales &amp; orders' : 'Order requests'}</h1><p class="intro">${admin ? 'Record sales, manage customer requests, and review totals.' : 'Send the farm an order request for confirmation.'}</p></div></section>${saleFormMarkup}${admin ? '<h2 class="section-title">Create customer order</h2>' : '<h2 class="section-title">New request</h2>'}${orderFormMarkup}<p class="page-error" id="page-error" role="alert" hidden></p>${salesTable}<h2 class="section-title">${admin ? 'Orders' : 'Your orders'}</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Customer</th><th>Status</th><th>Total</th>${admin ? '<th>Quote/status</th>' : ''}</tr></thead><tbody id="order-rows"><tr><td colspan="${admin ? 5 : 4}">Loading orders…</td></tr></tbody></table></div>${paymentSection}`)
  const refresh = async () => {
    const orders = await api.orders()
    if (admin) {
      const sales = await api.sales()
      root.querySelector('#sales-rows').innerHTML = sales.sales.length
        ? sales.sales.map((sale) => `<tr><td>${escapeHtml(sale.sold_at)}</td><td>${escapeHtml(sale.description)}</td><td>${escapeHtml(sale.quantity)} ${escapeHtml(sale.unit)}</td><td>${formatMoney(sale.total_amount)}</td></tr>`).join('')
        : '<tr><td colspan="4">No sales recorded.</td></tr>'
    }
    root.querySelector('#order-rows').innerHTML = orders.orders.length
      ? orders.orders.map((order) => `<tr><td>${escapeHtml(order.ordered_at)}</td><td>${escapeHtml(order.customer)}</td><td>${escapeHtml(order.status)}</td><td>${formatMoney(order.total_amount)}</td>${admin ? `<td><form class="order-update" data-order-id="${escapeHtml(order.id)}"><select name="status"><option value="pending" ${order.status === 'pending' ? 'selected' : ''}>Pending</option><option value="accepted" ${order.status === 'accepted' ? 'selected' : ''}>Accepted</option><option value="fulfilled" ${order.status === 'fulfilled' ? 'selected' : ''}>Fulfilled</option><option value="cancelled" ${order.status === 'cancelled' ? 'selected' : ''}>Cancelled</option></select><input name="totalAmount" type="number" min="0" step="0.01" value="${escapeHtml(order.total_amount)}" aria-label="Quoted total in KES"><button class="small-action" type="submit">Save</button></form></td>` : ''}</tr>`).join('')
      : `<tr><td colspan="${admin ? 5 : 4}">No orders recorded.</td></tr>`
    if (admin) {
      const orderSelect = root.querySelector('#payment-form [name="orderId"]')
      orderSelect.innerHTML = '<option value="">Choose order</option>' + orders.orders.map((order) => `<option value="${escapeHtml(order.id)}">#${escapeHtml(order.id)} · ${escapeHtml(order.customer)} · ${formatMoney(order.total_amount)}</option>`).join('')
      const { payments } = await api.payments()
      root.querySelector('#payment-rows').innerHTML = payments.length
        ? payments.map((payment) => `<tr><td>#${escapeHtml(payment.order_id)}</td><td>${escapeHtml(payment.method)}</td><td>${formatMoney(payment.amount)}</td><td>${escapeHtml(payment.status)}</td><td>${escapeHtml(payment.transaction_reference || '—')}</td></tr>`).join('')
        : '<tr><td colspan="5">No payments recorded.</td></tr>'
    }
  }
  try { await refresh() } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  root.querySelector('#order-rows').addEventListener('submit', async (event) => {
    const form = event.target.closest('.order-update')
    if (!form) return
    event.preventDefault()
    const data = Object.fromEntries(new FormData(form))
    try { await api.updateOrder(form.dataset.orderId, data); toast('Order updated'); await refresh() }
    catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  })
  const saleEntryForm = root.querySelector('#sale-form')
  if (saleEntryForm) {
    saleEntryForm.querySelector('[name="soldAt"]').value = new Date().toISOString().slice(0, 16)
    saleEntryForm.addEventListener('submit', async (event) => {
      event.preventDefault()
      const data = Object.fromEntries(new FormData(saleEntryForm))
      data.soldAt = data.soldAt.replace('T', ' ')
      try { await api.createSale(data); toast('Sale recorded'); saleEntryForm.reset(); await refresh() }
      catch (error) { showMessage(saleEntryForm.querySelector('.form-message'), error.message, true) }
    })
  }
  const orderForm = root.querySelector('#order-form')
  orderForm.addEventListener('submit', async (event) => {
    event.preventDefault()
    const { customerName, customerPhone, itemName, quantity, unit, unitPrice } = Object.fromEntries(new FormData(orderForm))
    try {
      const order = await api.createOrder({ customerName, customerPhone, items: [{ itemName, quantity, unit, unitPrice: admin ? unitPrice : 0 }] })
      toast(admin ? `Order created · ${formatMoney(order.totalAmount)}` : 'Order request sent to the farm')
      orderForm.reset()
      await refresh()
    } catch (error) { showMessage(orderForm.querySelector('.form-message'), error.message, true) }
  })
  const paymentForm = root.querySelector('#payment-form')
  paymentForm?.addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      const result = await api.createPayment(Object.fromEntries(new FormData(paymentForm)))
      toast(result.status === 'pending' ? 'M-Pesa recorded as awaiting gateway confirmation' : 'Payment recorded')
      paymentForm.reset()
      await refresh()
    } catch (error) { showMessage(paymentForm.querySelector('.form-message'), error.message, true) }
  })
}

async function renderUsers(user) {
  shell(user, 'User accounts', '<section class="welcome-row"><div><p class="eyebrow">Access control</p><h1>User accounts</h1><p class="intro">Create staff or customer logins for farm operations.</p></div></section><form id="user-form" class="entry-form"><label>Full name<input name="fullName" required maxlength="120"></label><label>Email<input name="email" type="email" required maxlength="190"></label><label>Password<input name="password" type="password" required minlength="12"></label><label>Role<select name="role"><option value="worker">Farm worker</option><option value="veterinarian">Veterinarian</option><option value="customer">Customer</option><option value="admin">Administrator</option></select></label><button class="primary-button" type="submit">Create account</button><p class="form-message" role="alert" hidden></p></form><p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr></thead><tbody id="user-rows"><tr><td colspan="4">Loading accounts…</td></tr></tbody></table></div>')
  const form = root.querySelector('#user-form')
  async function refresh() {
    const { users } = await api.users()
    root.querySelector('#user-rows').innerHTML = users.map((account) => `<tr><td>${escapeHtml(account.full_name)}</td><td>${escapeHtml(account.email)}</td><td>${escapeHtml(account.role)}</td><td>${account.is_active ? 'Active' : 'Disabled'}</td></tr>`).join('')
  }
  try { await refresh() } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      await api.createUser(Object.fromEntries(new FormData(form)))
      toast('Staff account created')
      form.reset()
      await refresh()
    } catch (error) { showMessage(form.querySelector('.form-message'), error.message, true) }
  })
}

async function renderFeedback(user) {
  const customer = user.role === 'customer'
  const formMarkup = customer ? '<form id="feedback-form" class="entry-form"><label>Type<select name="category"><option value="feedback">Feedback</option><option value="complaint">Complaint</option><option value="suggestion">Suggestion</option></select></label><label class="wide-field">Message<textarea name="message" required maxlength="5000" rows="4"></textarea></label><button class="primary-button" type="submit">Send feedback</button><p class="form-message" role="alert" hidden></p></form>' : ''
  shell(user, 'Feedback', `<section class="welcome-row"><div><p class="eyebrow">Customer service</p><h1>${customer ? 'Your feedback' : 'Feedback inbox'}</h1><p class="intro">${customer ? 'Send a message to the farm and track its status.' : 'Review and resolve customer messages.'}</p></div></section>${formMarkup}<p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr>${customer ? '' : '<th>Customer</th>'}<th>Submitted</th><th>Type</th><th>Message</th><th>Status</th>${customer ? '' : '<th></th>'}</tr></thead><tbody id="feedback-rows"><tr><td colspan="6">Loading feedback…</td></tr></tbody></table></div>`)
  async function refresh() {
    const { feedback } = await api.feedback()
    root.querySelector('#feedback-rows').innerHTML = feedback.length
      ? feedback.map((record) => `<tr>${customer ? '' : `<td>${escapeHtml(record.customer || 'Farm')}</td>`}<td>${escapeHtml(record.submitted_at)}</td><td>${escapeHtml(record.category)}</td><td>${escapeHtml(record.message)}</td><td>${escapeHtml(record.status)}</td>${customer ? '' : `<td>${record.status !== 'resolved' ? `<button class="small-action" data-resolve="${escapeHtml(record.id)}">Resolve</button>` : ''}</td>`}</tr>`).join('')
      : `<tr><td colspan="${customer ? 4 : 6}">No feedback submitted.</td></tr>`
  }
  try { await refresh() } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  const form = root.querySelector('#feedback-form')
  form?.addEventListener('submit', async (event) => {
    event.preventDefault()
    try { await api.createFeedback(Object.fromEntries(new FormData(form))); toast('Feedback sent'); form.reset(); await refresh() }
    catch (error) { showMessage(form.querySelector('.form-message'), error.message, true) }
  })
  root.querySelector('#feedback-rows').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-resolve]')
    if (!button) return
    try { await api.resolveFeedback(button.dataset.resolve); toast('Feedback resolved'); await refresh() }
    catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  })
}

function renderNotReady(user, section) {
  shell(user, section, `<section class="welcome-row"><div><p class="eyebrow">Farm operations</p><h1>${escapeHtml(section)}</h1><p class="intro">This module is not connected yet.</p></div></section>`)
}

function navigate(user, section) {
  if (section === 'Overview') return renderDashboard(user)
  if (section === 'Livestock') return renderLivestock(user)
  if (section === 'Production') return renderProduction(user)
  if (section === 'Animal health') return renderHealth(user)
  if (section === 'Activities') return renderActivities(user)
  if (section === 'Inventory') return renderInventory(user)
  if (section === 'Sales & orders') return renderSalesOrders(user)
  if (section === 'User accounts' && user.role === 'admin') return renderUsers(user)
  if (section === 'Feedback' && ['admin', 'customer'].includes(user.role)) return renderFeedback(user)
  if (section === 'Reports') return renderReports(user)
  renderNotReady(user, section)
}

function renderHome(user) {
  if (user.role === 'customer') return renderSalesOrders(user)
  return renderDashboard(user)
}

async function start() {
  root.innerHTML = '<main class="auth-shell"><p class="intro">Connecting to the farm database…</p></main>'
  const resetToken = new URLSearchParams(window.location.hash.slice(1)).get('reset_token')
  if (resetToken) {
    window.history.replaceState(null, '', window.location.pathname + window.location.search)
    renderResetPassword(resetToken)
    return
  }
  try {
    const status = await api.setupStatus()
    if (status.setupRequired) {
      renderAuth('setup')
      return
    }
    const { user } = await api.session()
    renderHome(user)
  } catch (error) {
    if (error.status === 401) {
      renderAuth('login')
      return
    }
    root.innerHTML = `<main class="auth-shell"><section class="auth-panel"><h1>Can't connect to LFMIS</h1><p class="intro">${escapeHtml(error.message)} Start the PHP API and confirm the MySQL service is running.</p></section></main>`
  }
}

start()