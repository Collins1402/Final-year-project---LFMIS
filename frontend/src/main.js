import './style.css'
import { api } from './api.js'
import { renderLanding } from './landing.js'

const app = document.querySelector('#app')
let currentUser = null

const adminNavigation = [
  ['Operations', ['Overview', 'Livestock', 'Animal health', 'Activities', 'Production', 'Inventory']],
  ['Commerce', ['Sales & orders', 'Feedback']],
  ['Insights', ['Reports', 'Assistant']],
  ['Administration', ['User accounts', 'Events']],
]

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]))
const message = (text, danger = false) => `<p class="message ${danger ? 'danger' : 'success'}">${escapeHtml(text)}</p>`

function renderAuth(mode = 'login', notice = '') {
  const setup = mode === 'setup'
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">FLOURi FARM · LFMIS</p><h1>${setup ? 'Set up the farm workspace' : 'Welcome back'}</h1><p class="muted">${setup ? 'Create the first farm owner account.' : 'Sign in to manage the farm.'}</p>${notice}${setup ? `<form id="setup-form"><label>Full name<input name="fullName" required></label><label>Username<input name="username" required pattern="[A-Za-z0-9_.]{3,30}"></label><label>Email<input name="email" type="email" required></label><label>Password<span class="password-field"><input name="password" type="password" minlength="12" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Create owner account</button></form>` : `<form id="login-form"><label>Username or email<input name="login" required></label><label>Password<span class="password-field"><input name="password" type="password" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Sign in</button></form><button id="forgot-link" class="text-link" type="button">Forgot password?</button><button id="register-link" class="text-link" type="button">Create a farm account</button>`}</section></main>`
  const form = app.querySelector('form')
  app.querySelector('#register-link')?.addEventListener('click', () => renderRegister())
  app.querySelector('#forgot-link')?.addEventListener('click', () => renderForgotPassword())
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
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">FLOURi FARM · LFMIS</p><h1>Create an account</h1><p class="muted">Create your farm customer or livestock buyer account.</p>${notice}<form id="register-form"><label>Full name<input name="fullName" required></label><label>Username<input name="username" required pattern="[A-Za-z0-9_.]{3,30}"></label><label>Email<input name="email" type="email" required></label><label>Phone<input name="phone" type="tel"></label><label>Account type<select name="role"><option value="customer">Farm customer</option><option value="buyer">Livestock buyer</option></select></label><label>Password<span class="password-field"><input name="password" type="password" minlength="12" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Create account</button></form><button id="back-login" class="text-link" type="button">Back to sign in</button></section></main>`
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
    await renderRoleWorkspace()
    return
  }
  let animals = []
  let healthRecords = []
  let overviewData = {}
  try {
    const [animalData, healthData, overview] = await Promise.all([api.animals(), api.healthRecords(), api.overview()])
    animals = animalData.animals
    healthRecords = healthData.records
    overviewData = overview
  } catch (error) {
    app.innerHTML = message(error.message, true)
    return
  }
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>Farm workspace</h2><nav class="workspace-nav" aria-label="Farm modules">${['Overview', 'Livestock', 'Animal health', 'Activities', 'Production', 'Inventory', 'Sales & orders', 'Reports', 'Feedback', 'User accounts', 'Assistant'].map((item) => `<button class="workspace-nav-item ${item === 'Overview' ? 'active' : ''}" data-module="${item}">${item}</button>`).join('')}</nav><div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="logout" class="quiet">Sign out</button></aside><main class="content"><header><div><p class="kicker">OVERVIEW</p><h1>Good day, ${escapeHtml(currentUser.fullName.split(' ')[0])}</h1><p class="muted">Keep the herd records complete and easy to trust.</p></div><span class="health-pill">API connected</span></header><section class="stats"><div><small>Animals</small><strong>${animals.length}</strong></div><div><small>Health records</small><strong>${healthRecords.length}</strong></div><div><small>Role</small><strong>${escapeHtml(currentUser.role)}</strong></div></section><div class="grid"><section class="panel"><div class="panel-head"><h2>Livestock register</h2><span>${animals.length} records</span></div><form id="animal-form" class="compact-form"><input name="tagNumber" placeholder="Tag number" required><select name="species"><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="sheep">Sheep</option></select><input name="breed" placeholder="Breed"><select name="sex"><option value="female">Female</option><option value="male">Male</option></select><button>Add animal</button></form><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Species</th><th>Breed</th><th>Status</th></tr></thead><tbody>${animals.length ? animals.map((animal) => `<tr><td>${escapeHtml(animal.tag_number)}</td><td>${escapeHtml(animal.species)}</td><td>${escapeHtml(animal.breed || '—')}</td><td><span class="status">${escapeHtml(animal.status)}</span></td></tr>`).join('') : '<tr><td colspan="4" class="empty">No animals yet.</td></tr>'}</tbody></table></div></section><section class="panel"><div class="panel-head"><h2>Animal health</h2><span>${healthRecords.length} records</span></div><form id="health-form" class="compact-form"><select name="animalId" required><option value="">Animal</option>${animals.map((animal) => `<option value="${animal.id}">${escapeHtml(animal.tag_number)}</option>`).join('')}</select><select name="recordType"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option></select><input name="description" placeholder="Description" required><input name="recordDate" type="date" required><button>Save record</button></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal</th><th>Type</th><th>Description</th></tr></thead><tbody>${healthRecords.length ? healthRecords.map((record) => `<tr><td>${escapeHtml(record.record_date)}</td><td>${escapeHtml(record.tag_number)}</td><td>${escapeHtml(record.record_type)}</td><td>${escapeHtml(record.description)}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No health records yet.</td></tr>'}</tbody></table></div></section></div></main></div>`
  const stats = app.querySelector('.stats')
  stats.classList.add('overview-stats')
  const milkTotal = Number(overviewData.milkToday?.morning || 0) + Number(overviewData.milkToday?.evening || 0)
  stats.innerHTML = `<div><small>Active animals</small><strong>${Number(overviewData.activeAnimals || 0)}</strong></div><div><small>Milk today</small><strong>${milkTotal.toFixed(1)} L</strong></div><div><small>Eggs today</small><strong>${Number(overviewData.eggsToday || 0).toLocaleString()} trays</strong></div><div><small>Open tasks</small><strong>${Number(overviewData.openTasks || 0)}</strong></div><div><small>Low-stock items</small><strong>${Number(overviewData.lowStockCount || 0)}</strong></div><div><small>Follow-ups due</small><strong>${Number(overviewData.healthFollowUpsDue || 0)}</strong></div>`
  const overviewPanels = document.createElement('section')
  overviewPanels.className = 'overview-grid'
  overviewPanels.innerHTML = `<section class="panel"><h2>Active animals by species</h2><dl class="species-list">${Object.entries(overviewData.activeAnimalsBySpecies || {}).map(([species, count]) => `<div><dt>${escapeHtml(species)}</dt><dd>${Number(count)}</dd></div>`).join('')}</dl></section><section class="panel"><h2>Needs attention</h2><ul class="attention-list"><li><span>Orders awaiting review</span><strong>${Number(overviewData.pendingOrders || 0)}</strong></li><li><span>Sales this month</span><strong>KES ${Math.round(Number(overviewData.salesThisMonth || 0)).toLocaleString()}</strong></li><li><span>Expenses this month</span><strong>KES ${Math.round(Number(overviewData.expensesThisMonth || 0)).toLocaleString()}</strong></li></ul></section>`
  app.querySelector('.content .grid').before(overviewPanels)

  const notificationControl = document.createElement('section')
  notificationControl.className = 'notification-control'
  notificationControl.innerHTML = '<button type="button" class="notification-toggle" aria-expanded="false" aria-controls="notification-popover">Notifications <span class="notification-count" hidden></span></button><div id="notification-popover" class="notification-popover" hidden><h2>Notifications</h2><ul class="notification-list"></ul></div>'
  app.querySelector('aside .brand').after(notificationControl)
  const notificationToggle = notificationControl.querySelector('.notification-toggle')
  const notificationPopover = notificationControl.querySelector('.notification-popover')
  const refreshNotifications = async () => {
    const data = await api.notifications()
    const notifications = data.notifications || []
    const unread = notifications.filter((item) => Number(item.is_read) === 0).length
    const badge = notificationToggle.querySelector('.notification-count')
    badge.hidden = unread === 0
    badge.textContent = unread > 99 ? '99+' : String(unread)
    notificationControl.querySelector('.notification-list').innerHTML = notifications.length
      ? notifications.map((item) => `<li class="notification-item ${Number(item.is_read) === 0 ? 'unread' : ''}"><strong>${escapeHtml(item.title)}</strong><p>${escapeHtml(item.message)}</p><small>${escapeHtml(item.created_at)}</small>${Number(item.is_read) === 0 ? `<button type="button" class="small-action" data-notification-read="${Number(item.id)}">Mark read</button>` : ''}</li>`).join('')
      : '<li class="empty">No notifications.</li>'
    notificationControl.querySelectorAll('[data-notification-read]').forEach((button) => button.addEventListener('click', async () => {
      await api.markNotificationRead(button.dataset.notificationRead)
      await refreshNotifications()
    }))
  }
  notificationToggle.addEventListener('click', async () => {
    const open = notificationPopover.hidden
    notificationPopover.hidden = !open
    notificationToggle.setAttribute('aria-expanded', String(open))
    if (open) {
      try { await refreshNotifications() } catch (error) { notificationControl.querySelector('.notification-list').innerHTML = `<li>${escapeHtml(error.message)}</li>` }
    }
  })
  refreshNotifications().catch(() => {})

  const demoButton = document.createElement('button')
  demoButton.type = 'button'
  demoButton.className = 'secondary-action'
  demoButton.textContent = 'Load demo data'
  demoButton.addEventListener('click', async () => {
    try { const result = await api.loadDemoData(); alert(result.message); await renderApp() } catch (error) { alert(error.message) }
  })
  app.querySelector('.content header').append(demoButton)
  const navigation = app.querySelector('.workspace-nav')
  navigation.innerHTML = adminNavigation.map(([group, items], index) => `<details class="nav-group" ${index === 0 ? 'open' : ''}><summary>${group}</summary>${items.map((item) => `<button class="workspace-nav-item ${item === 'Overview' ? 'active' : ''}" data-module="${item}">${item}</button>`).join('')}</details>`).join('')
  app.querySelectorAll('[data-module]').forEach((item) => item.addEventListener('click', () => {
    if (item.dataset.module === 'Overview') return renderApp()
    renderAdminModule(item.dataset.module)
  }))
  app.querySelector('#logout').addEventListener('click', async () => { await api.logout(); currentUser = null; renderAuth() })
  app.querySelector('#animal-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createAnimal(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
  app.querySelector('#health-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createHealthRecord(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
}

function renderAdminModule(module) {
  if (module === 'Events') return renderEventsModule()
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
    Production: ['Farm produce', 'Record produced quantities, units sold and remaining stock.'],
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

async function renderEventsModule() {
  const content = app.querySelector('.content')
  try {
    const data = await api.adminEvents()
    const events = data.events
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Events</h1><p class="muted">Publish daytime farm visits, tours and workshops.</p></div><span class="health-pill">Day visits only · Ends by 4:00 p.m.</span></header><section class="module-grid"><section class="panel"><h2 id="event-form-title">Create event</h2><p class="muted">Published events appear on the public website. Overnight stays are not available.</p><div id="events-feedback"></div><form id="event-form" class="compact-form event-form"><input type="hidden" name="id"><label>Event title<input name="title" maxlength="150" required></label><label>Location<input name="location" maxlength="200" value="Flouri Farm" required></label><label>Date<input name="eventDate" type="date" required></label><label>Status<select name="status"><option value="draft">Draft</option><option value="published">Published</option><option value="cancelled">Cancelled</option></select></label><label>Starts at<input name="startTime" type="time" max="16:00" required></label><label>Ends at<input name="endTime" type="time" max="16:00" required></label><label class="event-description">Description<textarea name="description" rows="4" maxlength="2000" required></textarea></label><button id="event-save-button">Save event</button><button type="button" id="event-reset-button" class="secondary-action">Clear form</button></form></section><section class="panel"><div class="panel-head"><h2>Farm events</h2><span>${events.length} records</span></div><div class="event-admin-list">${events.length ? events.map((event) => {
      const nextStatus = event.status === 'published' ? 'cancelled' : 'published'
      const statusAction = nextStatus === 'published' ? (event.status === 'cancelled' ? 'Publish again' : 'Publish') : 'Cancel'
      return `<article class="event-admin-item"><div class="event-admin-copy"><span class="event-status status-${event.status}">${escapeHtml(event.status)}</span><h3>${escapeHtml(event.title)}</h3><p>${formatEventDate(event.event_date)} · ${escapeHtml(event.start_time.slice(0, 5))}–${escapeHtml(event.end_time.slice(0, 5))}</p><p>${escapeHtml(event.location)}</p></div><div class="event-admin-actions"><button type="button" class="small-action" data-event-edit="${event.id}">Edit</button><button type="button" class="small-action" data-event-status="${event.id}" data-status="${nextStatus}">${statusAction}</button></div></article>`
    }).join('') : '<p class="empty">No events yet. Create a draft or publish your first event.</p>'}</div></section></section>`

    const form = content.querySelector('#event-form')
    const resetForm = () => {
      form.reset()
      form.elements.namedItem('id').value = ''
      form.elements.namedItem('location').value = 'Flouri Farm'
      content.querySelector('#event-form-title').textContent = 'Create event'
      content.querySelector('#event-save-button').textContent = 'Save event'
    }
    form.addEventListener('submit', async (event) => {
      event.preventDefault()
      const values = Object.fromEntries(new FormData(form))
      const id = values.id
      delete values.id
      try {
        if (id) await api.updateEvent(id, values)
        else await api.createEvent(values)
        await renderEventsModule()
      } catch (error) {
        content.querySelector('#events-feedback').innerHTML = message(error.message, true)
      }
    })
    content.querySelector('#event-reset-button').addEventListener('click', resetForm)
    content.querySelectorAll('[data-event-edit]').forEach((button) => button.addEventListener('click', () => {
      const selected = events.find((event) => String(event.id) === button.dataset.eventEdit)
      if (!selected) return
      form.elements.namedItem('id').value = selected.id
      form.elements.namedItem('title').value = selected.title
      form.elements.namedItem('description').value = selected.description
      form.elements.namedItem('eventDate').value = selected.event_date
      form.elements.namedItem('startTime').value = selected.start_time.slice(0, 5)
      form.elements.namedItem('endTime').value = selected.end_time.slice(0, 5)
      form.elements.namedItem('location').value = selected.location
      form.elements.namedItem('status').value = selected.status
      content.querySelector('#event-form-title').textContent = 'Edit event'
      content.querySelector('#event-save-button').textContent = 'Save changes'
      form.scrollIntoView({ behavior: 'smooth', block: 'start' })
    }))
    content.querySelectorAll('[data-event-status]').forEach((button) => button.addEventListener('click', async () => {
      try {
        await api.setEventStatus(button.dataset.eventStatus, button.dataset.status)
        await renderEventsModule()
      } catch (error) {
        content.querySelector('#events-feedback').innerHTML = message(error.message, true)
      }
    }))
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderProductionModule() {
  const content = app.querySelector('.content')
  try {
    const [data, summary] = await Promise.all([api.production(), api.productionSummary()])
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Farm produce</h1><p class="muted">Record any produce with its quantity produced, quantity sold and unit.</p></div><span class="health-pill">${data.records.length} records</span></header><section class="module-grid"><section class="panel"><h2>Record produce</h2><form id="produce-form" class="compact-form"><input name="produceName" list="produce-types" placeholder="Produce name" maxlength="120" required><datalist id="produce-types"><option value="Milk"><option value="Eggs"><option value="Wool"></datalist><input name="productionDate" type="date" required><select name="session"><option value="">No session</option><option value="morning">Morning</option><option value="evening">Evening</option><option value="other">Other</option></select><input name="quantityProduced" type="number" min="0" step="0.01" placeholder="Quantity produced" required><input name="quantitySold" type="number" min="0" step="0.01" placeholder="Quantity sold" value="0" required><input name="quantityDamaged" type="number" min="0" step="0.01" placeholder="Damaged or unusable" value="0" required><input name="unit" list="produce-units" placeholder="Unit, e.g. litres, trays, kg" maxlength="30" required><datalist id="produce-units"><option value="litres"><option value="eggs"><option value="trays"><option value="kg"></datalist><input name="notes" placeholder="Notes (optional)"><button>Save produce record</button></form></section><section class="panel"><h2>Recent produce</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Produce</th><th>Session</th><th>Produced</th><th>Sold</th><th>Damaged</th><th>Unit</th></tr></thead><tbody>${data.records.length ? data.records.map((item) => `<tr><td>${escapeHtml(item.production_date)}</td><td>${escapeHtml(item.produce_name)}</td><td>${escapeHtml(item.production_session || '—')}</td><td>${item.quantity_produced}</td><td>${item.quantity_sold}</td><td>${item.quantity_damaged}</td><td>${escapeHtml(item.unit)}</td></tr>`).join('') : '<tr><td colspan="7" class="empty">No produce records yet.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('.module-grid').before(renderProductionSummary(summary))
    content.querySelector('#produce-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.recordProduction(Object.fromEntries(new FormData(event.target))); await renderProductionModule() })
  } catch (error) { content.innerHTML = message(error.message, true) }
}

function renderProductionSummary(summary) {
  const format = (value) => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })
  const totalsRows = (totals) => Object.entries(totals).map(([product, amounts]) => `<tr><td>${escapeHtml(product)}</td><td>${format(amounts.produced)}</td><td>${format(amounts.sold)}</td><td>${format(amounts.damaged)}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">No records for this period.</td></tr>'
  const firstDay = new Date(`${summary.weekStart}T00:00:00Z`)
  const dates = Array.from({ length: 7 }, (_, index) => new Date(firstDay.getTime() + index * 86400000).toISOString().slice(0, 10))
  const dailyRows = dates.map((day) => {
    const products = Object.entries(summary.dailyTotals[day] || {})
    return products.length ? products.map(([product, amounts]) => `<tr><td>${escapeHtml(day)}</td><td>${escapeHtml(product)}</td><td>${format(amounts.produced)}</td></tr>`).join('') : `<tr><td>${escapeHtml(day)}</td><td colspan="2" class="muted">No entries</td></tr>`
  }).join('')
  const shareLines = Object.entries(summary.weeklyTotals).map(([product, values]) => `${product}: ${format(values.produced)} produced, ${format(values.sold)} sold`)
  const shareText = `Flouri Farm production summary (${summary.weekStart} to ${summary.today})\n${shareLines.join('\n') || 'No production records.'}`
  return `<section class="panel production-summary"><div class="panel-head"><div><h2>Production summary</h2><p class="muted">Recorded totals through ${escapeHtml(summary.today)}.</p></div><a class="share-link" href="https://wa.me/?text=${encodeURIComponent(shareText)}" target="_blank" rel="noopener noreferrer">Share on WhatsApp</a></div><div class="production-summary-grid"><section><h3>Last seven days</h3><div class="table-wrap"><table><thead><tr><th>Produce</th><th>Produced</th><th>Sold</th><th>Damaged</th></tr></thead><tbody>${totalsRows(summary.weeklyTotals)}</tbody></table></div></section><section><h3>This month</h3><div class="table-wrap"><table><thead><tr><th>Produce</th><th>Produced</th><th>Sold</th><th>Damaged</th></tr></thead><tbody>${totalsRows(summary.monthlyTotals)}</tbody></table></div></section></div><h3>Daily figures</h3><div class="table-wrap"><table><thead><tr><th>Date</th><th>Produce</th><th>Produced</th></tr></thead><tbody>${dailyRows}</tbody></table></div></section>`
}

async function renderInventoryModule() {
  const content = app.querySelector('.content')
  try {
    const [data, transactionData] = await Promise.all([api.inventory(), api.inventoryTransactions()])
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Inventory</h1><p class="muted">Track supplies and see what needs replenishing.</p></div><span class="health-pill">Stock control</span></header><section class="module-grid"><section class="panel"><h2>Add stock item</h2><form id="inventory-form" class="compact-form"><input name="name" placeholder="Item name" required><select name="category"><option value="feed">Feed</option><option value="medicine">Medicine</option><option value="equipment">Equipment</option><option value="supplies">Supplies</option><option value="other">Other</option></select><input name="unit" placeholder="Unit" required><input name="quantityOnHand" type="number" min="0" step="0.01" placeholder="Quantity" required><input name="reorderLevel" type="number" min="0" step="0.01" placeholder="Reorder level" required><button>Add item</button></form></section><section class="panel"><h2>Stock list</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th>Category</th><th>On hand</th><th>Reorder at</th></tr></thead><tbody>${data.items.length ? data.items.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.category)}</td><td>${item.quantity_on_hand} ${escapeHtml(item.unit)}</td><td>${item.reorder_level}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No stock items yet.</td></tr>'}</tbody></table></div></section></section>`
    const rows = content.querySelectorAll('.module-grid .panel:nth-child(2) tbody tr')
    data.items.forEach((item, index) => {
      if (Number(item.quantity_on_hand) <= Number(item.reorder_level) && rows[index]) {
        rows[index].classList.add('low-stock-row')
        rows[index].querySelector('td:last-child')?.insertAdjacentHTML('beforeend', '<small class="low-stock-label">Low stock</small>')
      }
    })
    const transactionPanel = document.createElement('section')
    transactionPanel.className = 'panel inventory-history'
    transactionPanel.innerHTML = `<h2>Record stock movement</h2><p class="muted">Use a positive quantity for stock in and a negative quantity for stock out. Adjustments may be positive or negative.</p><form id="inventory-transaction-form" class="compact-form"><select name="itemId" required><option value="">Choose item</option>${data.items.map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.name)} · ${Number(item.quantity_on_hand)} ${escapeHtml(item.unit)}</option>`).join('')}</select><select name="transactionType"><option value="in">Stock in</option><option value="out">Stock out</option><option value="adjustment">Adjustment</option></select><input name="quantityChange" type="number" step="0.01" placeholder="Signed quantity" required><input name="reason" maxlength="255" placeholder="Reason" required><button>Save movement</button></form><h2>Transaction history</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Item</th><th>Type</th><th>Change</th><th>Reason</th></tr></thead><tbody>${transactionData.transactions.length ? transactionData.transactions.map((item) => `<tr><td>${escapeHtml(item.transaction_date)}</td><td>${escapeHtml(item.item_name)}</td><td>${escapeHtml(item.transaction_type)}</td><td>${Number(item.quantity_change).toLocaleString()}</td><td>${escapeHtml(item.reason || '—')}</td></tr>`).join('') : '<tr><td colspan="5" class="empty">No stock movements recorded.</td></tr>'}</tbody></table></div>`
    content.append(transactionPanel)
    content.querySelector('#inventory-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createInventoryItem(Object.fromEntries(new FormData(event.target))); await renderInventoryModule() })
    transactionPanel.querySelector('#inventory-transaction-form').addEventListener('submit', async (event) => {
      event.preventDefault()
      const values = Object.fromEntries(new FormData(event.target))
      const itemId = values.itemId
      delete values.itemId
      try { await api.inventoryTransaction(itemId, values); await renderInventoryModule() } catch (error) { transactionPanel.prepend(Object.assign(document.createElement('div'), { innerHTML: message(error.message, true) })) }
    })
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderCommerceModule() {
  const content = app.querySelector('.content')
  try {
    const [orders, sales, payments, expenses, products] = await Promise.all([api.orders(), api.sales(), api.payments(), api.expenses(), api.products()])
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Sales & orders</h1><p class="muted">Create orders, record sales and track payments.</p></div><span class="health-pill">Admin access</span></header><section class="module-grid"><section class="panel"><h2>Create order</h2><form id="order-form" class="compact-form"><input name="customerName" placeholder="Customer name" required><input name="customerPhone" placeholder="Phone"><select name="productId" required><option value="">Choose a listed product</option>${products.products.filter((item) => Number(item.available_quantity) > 0).map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.name)} · ${Number(item.available_quantity)} ${escapeHtml(item.unit)}</option>`).join('')}</select><input name="quantity" type="number" min=".01" step=".01" placeholder="Quantity" required><input name="unitPrice" type="number" min="0" step=".01" placeholder="Quoted unit price (optional)"><button>Create quote</button></form><h2>Orders</h2><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Status</th><th>Total</th></tr></thead><tbody>${orders.orders.length ? orders.orders.map((item) => `<tr><td>${escapeHtml(item.customer)}</td><td>${escapeHtml(item.status)}</td><td>${item.total_amount}</td></tr>`).join('') : '<tr><td colspan="3" class="empty">No orders yet.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Record sale</h2><form id="sale-form" class="compact-form"><input name="description" placeholder="Description" required><input name="quantity" type="number" min=".01" step=".01" placeholder="Quantity" required><input name="unit" placeholder="Unit" required><input name="unitPrice" type="number" min="0" step=".01" placeholder="Unit price" required><input name="soldAt" type="datetime-local" required><button>Save sale</button></form><h2>Payments</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Method</th><th>Status</th><th>Amount</th></tr></thead><tbody>${payments.payments.length ? payments.payments.map((item) => `<tr><td>#${item.order_id}</td><td>${item.method}</td><td>${item.status}</td><td>${item.amount}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No payments yet.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('#order-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createOrder(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() })
    content.querySelector('#sale-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createSale(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() })
    const commerceTools = document.createElement('section')
    commerceTools.className = 'module-grid commerce-tools'
    commerceTools.innerHTML = `<section class="panel"><h2>Product catalogue</h2><form id="product-form" class="compact-form"><input name="name" placeholder="Listing name" maxlength="140" required><select name="productType"><option value="produce">Produce</option><option value="livestock">Livestock</option><option value="other">Other</option></select><select name="productionKind"><option value="">Unlinked produce</option><option value="milk">Milk</option><option value="eggs">Eggs</option><option value="wool">Wool</option></select><select name="species"><option value="">Species, for livestock</option><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="sheep">Sheep</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="other">Other</option></select><input name="unit" placeholder="Unit, e.g. litres or head" required><input name="availableQuantity" type="number" min="0" step="0.01" placeholder="Opening quantity" value="0"><input name="unitPrice" type="number" min="0" step="0.01" placeholder="Unit price (KES)" required><button>Add listing</button></form><div class="table-wrap"><table><thead><tr><th>Listing</th><th>Type</th><th>Available</th><th>Price</th></tr></thead><tbody>${products.products.length ? products.products.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.product_type)}</td><td>${Number(item.available_quantity).toLocaleString()} ${escapeHtml(item.unit)}</td><td>KES ${Math.round(Number(item.unit_price)).toLocaleString()}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No active listings.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Expenses</h2><form id="expense-form" class="compact-form"><select name="category"><option value="feed">Feed</option><option value="veterinary">Veterinary</option><option value="labour">Labour</option><option value="equipment">Equipment</option><option value="utilities">Utilities</option><option value="transport">Transport</option><option value="maintenance">Maintenance</option><option value="other">Other</option></select><input name="description" placeholder="Expense description" required><input name="amount" type="number" min="0.01" step="0.01" placeholder="Amount (KES)" required><input name="expenseDate" type="date" value="${new Date().toISOString().slice(0, 10)}" required><button>Record expense</button></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr></thead><tbody>${expenses.expenses.length ? expenses.expenses.map((item) => `<tr><td>${escapeHtml(item.expense_date)}</td><td>${escapeHtml(item.category)}</td><td>${escapeHtml(item.description)}</td><td>KES ${Math.round(Number(item.amount)).toLocaleString()}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No expenses recorded.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Record payment</h2><form id="payment-form" class="compact-form"><select name="orderId" required><option value="">Choose order</option>${orders.orders.filter((item) => !['cancelled', 'fulfilled'].includes(item.status)).map((item) => `<option value="${Number(item.id)}">#${Number(item.id)} · ${escapeHtml(item.customer)} · KES ${Math.round(Number(item.total_amount)).toLocaleString()}</option>`).join('')}</select><select name="method"><option value="cash">Cash</option><option value="mpesa">M-Pesa (pending confirmation)</option><option value="bank">Bank</option><option value="other">Other</option></select><input name="transactionReference" placeholder="Reference (optional)"><button>Record payment</button></form><p class="muted">M-Pesa entries remain pending until a signed Daraja callback is configured.</p></section></section>`
    content.append(commerceTools)
    const orderTable = content.querySelector('.module-grid .panel table')
    const orderBody = orderTable?.querySelector('tbody')
    if (orderTable && orderBody && orders.orders.length) {
      orderTable.querySelector('thead tr').insertAdjacentHTML('beforeend', '<th>Action</th>')
      orders.orders.forEach((order, index) => {
        const row = orderBody.rows[index]
        if (!row) return
        const actionCell = row.insertCell()
        const actions = order.status === 'pending' ? [['Quote', 'quoted'], ['Cancel', 'cancelled']]
          : order.status === 'quoted' ? [['Accept', 'accepted'], ['Cancel', 'cancelled']]
            : order.status === 'accepted' ? [['Fulfill', 'fulfilled'], ['Cancel', 'cancelled']] : []
        actions.forEach(([label, status]) => {
          const button = document.createElement('button')
          button.type = 'button'
          button.className = 'small-action order-action'
          button.textContent = label
          button.dataset.orderId = String(order.id)
          button.dataset.orderStatus = status
          actionCell.append(button)
        })
      })
    }
    const showCommerceError = (error) => {
      let feedback = content.querySelector('#commerce-feedback')
      if (!feedback) {
        feedback = document.createElement('div')
        feedback.id = 'commerce-feedback'
        content.querySelector('header').after(feedback)
      }
      feedback.innerHTML = message(error.message, true)
    }
    content.querySelectorAll('.order-action').forEach((button) => button.addEventListener('click', async () => {
      let totalAmount
      if (button.dataset.orderStatus === 'quoted') {
        const order = orders.orders.find((item) => String(item.id) === button.dataset.orderId)
        totalAmount = window.prompt('Enter the quoted total in KES.', String(order?.total_amount || ''))
        if (totalAmount === null) return
      }
      try {
        await api.updateOrder(button.dataset.orderId, { status: button.dataset.orderStatus, ...(totalAmount === undefined ? {} : { totalAmount }) })
        await renderCommerceModule()
      } catch (error) { showCommerceError(error) }
    }))
    content.querySelector('#product-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createProduct(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() } catch (error) { showCommerceError(error) } })
    content.querySelector('#expense-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createExpense(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() } catch (error) { showCommerceError(error) } })
    content.querySelector('#payment-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createPayment(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() } catch (error) { showCommerceError(error) } })
  } catch (error) { content.innerHTML = message(error.message, true) }
}

function renderForecastChart(observations, predictions) {
  const actual = observations.slice(-28)
  const values = [...actual, ...predictions]
  if (values.length === 0) return '<p class="empty">No production history is available.</p>'

  const width = 760
  const height = 250
  const left = 44
  const right = 12
  const top = 14
  const bottom = 32
  const quantities = values.map((item) => Number(item.litres))
  const minimum = Math.min(...quantities)
  const maximum = Math.max(...quantities)
  const range = Math.max(maximum - minimum, 1)
  const x = (index) => left + index * (width - left - right) / Math.max(values.length - 1, 1)
  const y = (quantity) => top + (maximum - Number(quantity)) * (height - top - bottom) / range
  const path = (items, offset = 0) => items.map((item, index) => `${index === 0 ? 'M' : 'L'} ${x(index + offset).toFixed(1)} ${y(item.litres).toFixed(1)}`).join(' ')
  const actualPath = path(actual)
  const forecastPath = actual.length ? path([actual[actual.length - 1], ...predictions], actual.length - 1) : path(predictions)
  const midY = y((minimum + maximum) / 2)

  return `<svg class="forecast-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Milk production history and seven-day forecast in litres">
    <line x1="${left}" y1="${midY}" x2="${width - right}" y2="${midY}" class="forecast-gridline"></line>
    <path d="${actualPath}" class="forecast-actual-line"></path>
    <path d="${forecastPath}" class="forecast-prediction-line"></path>
    <circle cx="${x(values.length - 1)}" cy="${y(values[values.length - 1].litres)}" r="4" class="forecast-endpoint"></circle>
    <text x="${left}" y="${height - 8}">${escapeHtml(actual[0]?.date || values[0].date)}</text>
    <text x="${width - right}" y="${height - 8}" text-anchor="end">${escapeHtml(values[values.length - 1].date)}</text>
    <text x="4" y="${Math.max(top + 8, midY - 5)}">${Number((minimum + maximum) / 2).toFixed(0)} L</text>
  </svg><div class="forecast-legend"><span><i class="forecast-legend-actual"></i>Recorded</span><span><i class="forecast-legend-prediction"></i>Forecast</span></div>`
}

function renderForecastPanel(data, error) {
  if (error) {
    return `<section class="forecast-panel"><header><div><p class="kicker">DECISION SUPPORT</p><h2>Milk forecast</h2></div></header><p class="message danger">${escapeHtml(error.message)}</p><p class="muted">Forecasting requires at least 21 consecutive recorded milk-production days and the local Python service.</p></section>`
  }

  const result = data.forecast
  const backtest = result.backtest
  const predictions = result.forecast || []
  const actual = data.observations || []
  const checks = data.insights?.checks || []
  const formatLitres = (value) => `${Number(value).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })} L`
  const formatMae = (value) => `${Number(value).toFixed(2)} L`
  const improvement = Number(backtest.improvementPct)
  const verdict = improvement > 0
    ? `The model reduced one-day-ahead backtest MAE by ${improvement.toFixed(1)}% against the ${escapeHtml(backtest.baselineName)} baseline. This does not guarantee seven-day forecast accuracy.`
    : `The model did not outperform the ${escapeHtml(backtest.baselineName)} baseline in the one-day-ahead backtest. Treat the seven-day forecast as a reference, not a proven improvement.`
  const evaluationRows = (result.evaluation || []).map((item) => `<tr><td>${escapeHtml(item.model)}</td><td>${escapeHtml(item.features)}</td><td>${formatMae(item.maeLitres)}</td><td>${formatMae(item.rmseLitres)}</td><td>${item.improvementPct === undefined ? '—' : `${Number(item.improvementPct).toFixed(1)}%`}</td></tr>`).join('')
  const checkItems = checks.map((item) => {
    const priority = ['high', 'medium', 'low'].includes(item.priority) ? item.priority : 'low'
    return `<li class="insight-item insight-${priority}"><strong>${escapeHtml(item.title)}</strong><p>${escapeHtml(item.detail)}</p><small>${escapeHtml(item.action)}</small></li>`
  }).join('')

  return `<section class="forecast-panel"><header><div><p class="kicker">DECISION SUPPORT</p><h2>Milk forecast</h2><p class="muted">Seven-day estimate from the latest ${result.trainingDays} consecutive recorded days.</p></div></header>
    ${data.includesSimulatedData ? '<p class="message forecast-warning"><strong>Simulated data:</strong> this history includes labelled demo records. Do not report it as farm results.</p>' : ''}
    <div class="stats forecast-stats"><div><small>Training history</small><strong>${result.trainingDays} days</strong></div><div><small>Next seven days</small><strong>${formatLitres(predictions.reduce((sum, item) => sum + Number(item.litres), 0))}</strong></div><div><small>Backtest MAE</small><strong>${formatMae(backtest.maeLitres)}</strong><small>Baseline: ${formatMae(backtest.baselineMaeLitres)}</small></div></div>
    <p class="forecast-verdict">${verdict}</p>
    <figure class="forecast-figure"><figcaption>Recorded daily milk and seven-day estimate</figcaption>${renderForecastChart(actual, predictions)}</figure>
    <div class="forecast-columns"><section><h3>Seven-day forecast</h3><div class="table-wrap"><table><thead><tr><th>Date</th><th>Expected milk</th></tr></thead><tbody>${predictions.map((item) => `<tr><td>${escapeHtml(item.date)}</td><td>${formatLitres(item.litres)}</td></tr>`).join('')}</tbody></table></div></section><section><h3>Checks for the farmer</h3><ul class="insight-list">${checkItems || '<li class="empty">No immediate checks detected.</li>'}</ul></section></div>
    <h3>Model and baseline comparison</h3><div class="table-wrap"><table><thead><tr><th>Model</th><th>Features</th><th>MAE</th><th>RMSE</th><th>Improvement</th></tr></thead><tbody>${evaluationRows}</tbody></table></div>
    <p class="muted forecast-limitation">${escapeHtml(result.limitations)} Backtest results are one-day-ahead; the forecast horizon is seven days.</p>
  </section>`
}

async function renderReportsModule() {
  const content = app.querySelector('.content')
  try {
    const finance = await api.financeReport()
    let forecastData = null
    let forecastError = null
    try { forecastData = await api.forecastReport() } catch (error) { forecastError = error }
    let operations = null
    let operationsError = null
    try { operations = await api.operationalReports() } catch (error) { operationsError = error }
    const money = (value) => Math.round(Number(value)).toLocaleString()
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Reports</h1><p class="muted">Monthly financial performance and milk-production decision support.</p></div><span class="health-pill">Current month</span></header><section class="stats"><div><small>Sales</small><strong>KES ${money(finance.salesThisMonth)}</strong></div><div><small>Confirmed payments</small><strong>KES ${money(finance.confirmedPaymentsThisMonth)}</strong></div><div><small>Expenses</small><strong>KES ${money(finance.expensesThisMonth)}</strong></div><div><small>Estimated net</small><strong>KES ${money(finance.estimatedNet)}</strong></div></section><section class="panel"><h2>Finance summary</h2><p class="muted">These totals are calculated from the records entered in Sales, Payments and Expenses.</p></section>${renderForecastPanel(forecastData, forecastError)}`
    const annualReport = document.createElement('section')
    annualReport.className = 'panel annual-report'
    if (operationsError) {
      annualReport.innerHTML = `<h2>12-month operations</h2>${message(operationsError.message, true)}`
    } else {
      const milkKey = 'Milk (litres)'
      const eggsKey = 'Eggs (trays)'
      annualReport.innerHTML = `<h2>12-month operations</h2><p class="muted">Monthly production, recorded sales, expenses and estimated net.</p><div class="annual-health-summary"><div><small>Health records this month</small><strong>${Number(operations.health.healthRecordsThisMonth)}</strong></div><div><small>Open follow-ups due</small><strong>${Number(operations.health.followUpsDue)}</strong></div><div><small>Overdue follow-ups</small><strong>${Number(operations.health.overdueFollowUps)}</strong></div></div><div class="table-wrap"><table><thead><tr><th>Month</th><th>Milk (L)</th><th>Eggs (trays)</th><th>Sales</th><th>Expenses</th><th>Estimated net</th></tr></thead><tbody>${operations.months.map((month) => `<tr><td>${escapeHtml(month.month)}</td><td>${Number(month.production[milkKey] || 0).toLocaleString()}</td><td>${Number(month.production[eggsKey] || 0).toLocaleString()}</td><td>KES ${money(month.sales)}</td><td>KES ${money(month.expenses)}</td><td>KES ${money(month.net)}</td></tr>`).join('')}</tbody></table></div>`
    }
    content.querySelector('.forecast-panel').before(annualReport)
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
  const content = app.querySelector('.content')
  const speciesNames = { cattle: 'Cattle', goat: 'Goat', sheep: 'Sheep', rabbit: 'Rabbit', chicken: 'Chicken', other: 'Other' }
  try {
    const [animals, data] = await Promise.all([api.animals(), api.healthRecords()])
    const animalTypes = [...new Set(animals.animals.map((animal) => animal.species))].sort()
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Animal health</h1><p class="muted">Record treatment, vaccination and follow-ups.</p></div><span class="health-pill" id="health-record-count">${data.records.length} records</span></header><section class="panel module-panel"><form id="health-module-form" class="compact-form"><select name="animalId" required><option value="">Animal</option>${animals.animals.map((animal) => `<option value="${Number(animal.id)}">${escapeHtml(speciesNames[animal.species] || animal.species)} · ${escapeHtml(animal.tag_number)}</option>`).join('')}</select><select name="recordType"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option></select><input name="description" placeholder="Description" required><input name="recordDate" type="date" required><button>Save health record</button></form><div class="health-record-toolbar"><label for="health-species-filter">Animal type<select id="health-species-filter"><option value="">All animal types</option>${animalTypes.map((species) => `<option value="${escapeHtml(species)}">${escapeHtml(speciesNames[species] || species)}</option>`).join('')}</select></label></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal type</th><th>Animal</th><th>Record type</th><th>Description</th></tr></thead><tbody id="health-record-rows"></tbody></table></div></section>`

    const rows = content.querySelector('#health-record-rows')
    const filter = content.querySelector('#health-species-filter')
    const renderRows = () => {
      const visibleRecords = data.records.filter((record) => !filter.value || record.species === filter.value)
      content.querySelector('#health-record-count').textContent = `${visibleRecords.length} records`
      rows.innerHTML = visibleRecords.length
        ? visibleRecords.map((record) => `<tr><td>${escapeHtml(record.record_date)}</td><td>${escapeHtml(speciesNames[record.species] || record.species)}</td><td>${escapeHtml(record.tag_number)}</td><td>${escapeHtml(record.record_type)}</td><td>${escapeHtml(record.description)}</td></tr>`).join('')
        : '<tr><td colspan="5" class="empty">No health records for this animal type.</td></tr>'
    }
    filter.addEventListener('change', renderRows)
    renderRows()
    content.querySelector('#health-module-form').addEventListener('submit', async (event) => {
      event.preventDefault()
      try {
        await api.createHealthRecord(Object.fromEntries(new FormData(event.target)))
        await renderHealthModule()
      } catch (error) { content.prepend(Object.assign(document.createElement('div'), { innerHTML: message(error.message, true) })) }
    })
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderUsersModule() {
  const content=app.querySelector('.content'); try { const data=await api.users(); content.innerHTML=`<header><div><p class="kicker">FARM MODULE</p><h1>User accounts</h1><p class="muted">Create accounts and control access.</p></div><span class="health-pill">Admin access</span></header><section class="module-grid"><section class="panel"><h2>Create account</h2><form id="user-module-form" class="compact-form"><input name="fullName" placeholder="Full name" required><input name="username" placeholder="Username" required><input name="email" type="email" placeholder="Email" required><input name="password" type="password" minlength="12" placeholder="Temporary password" required><select name="role"><option value="worker">Worker</option><option value="veterinarian">Veterinarian</option><option value="buyer">Buyer</option><option value="customer">Customer</option></select><button>Create account</button></form></section><section class="panel"><h2>Accounts</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Role</th><th>Status</th></tr></thead><tbody>${data.users.map((u)=>`<tr><td>${escapeHtml(u.full_name)}</td><td>${u.role}</td><td>${u.is_active?'Active':'Inactive'}</td></tr>`).join('')}</tbody></table></div></section></section>`; content.querySelector('form').addEventListener('submit',async(e)=>{e.preventDefault();await api.createUser(Object.fromEntries(new FormData(e.target)));await renderUsersModule()}) } catch(error){content.innerHTML=message(error.message,true)}
}

async function renderFeedbackModule() {
  const content=app.querySelector('.content'); try { const data=await api.feedback(); content.innerHTML=`<header><div><p class="kicker">FARM MODULE</p><h1>Feedback</h1><p class="muted">Review customer feedback, complaints and suggestions.</p></div><span class="health-pill">${data.feedback.length} messages</span></header><section class="panel module-panel"><div class="table-wrap"><table><thead><tr><th>Date</th><th>From</th><th>Type</th><th>Message</th><th>Status</th><th></th></tr></thead><tbody>${data.feedback.map((f)=>`<tr><td>${f.created_at}</td><td>${escapeHtml(f.user_name||'Customer')}</td><td>${f.category}</td><td>${escapeHtml(f.message)}</td><td>${f.status}</td><td>${f.status==='open'?`<button class="small-action" data-resolve="${f.id}">Resolve</button>`:''}</td></tr>`).join('')||'<tr><td colspan="6" class="empty">No feedback yet.</td></tr>'}</tbody></table></div></section>`; content.querySelectorAll('[data-resolve]').forEach((b)=>b.addEventListener('click',async()=>{await api.resolveFeedback(b.dataset.resolve);await renderFeedbackModule()})) } catch(error){content.innerHTML=message(error.message,true)}
}

async function renderRoleWorkspace(initialModule) {
  const workspaces = {
    worker: { title: 'Daily operations', description: 'Production, farm tasks and stock movements.', modules: ['Production', 'Activities', 'Inventory'] },
    veterinarian: { title: 'Animal health', description: 'Livestock records, treatment history and follow-up care.', modules: ['Livestock', 'Health records', 'Due follow-ups'] },
    customer: { title: 'Farm shop', description: 'Browse farm produce, place orders and follow up on payments.', modules: ['Farm shop', 'My orders', 'Feedback'] },
    buyer: { title: 'Livestock buying', description: 'Browse available livestock and track your requests.', modules: ['Livestock catalogue', 'My orders', 'Feedback'] },
  }
  const workspace = workspaces[currentUser.role]
  const activeModule = initialModule || workspace.modules[0]
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>${escapeHtml(workspace.title)}</h2><nav class="workspace-nav" aria-label="Workspace modules">${workspace.modules.map((item) => `<button class="workspace-nav-item ${item === activeModule ? 'active' : ''}" data-role-module="${escapeHtml(item)}">${escapeHtml(item)}</button>`).join('')}</nav><div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="logout" class="quiet">Sign out</button></aside><main class="content"><header><div><p class="kicker">YOUR WORKSPACE</p><h1>${escapeHtml(activeModule)}</h1><p class="muted">${escapeHtml(workspace.description)}</p></div><span class="health-pill">Role protected</span></header><div id="role-content"></div></main></div>`
  app.querySelector('#logout').addEventListener('click', async () => { await api.logout(); currentUser = null; renderLanding(app, () => renderAuth('login'), () => renderAuth('login')) })
  app.querySelectorAll('[data-role-module]').forEach((button) => button.addEventListener('click', () => renderRoleWorkspace(button.dataset.roleModule)))
  await renderRoleModule(activeModule)
}

async function renderRoleModule(module) {
  const content = app.querySelector('#role-content')
  const showError = (error) => { content.innerHTML = message(error.message, true) }
  try {
    if (module === 'Production') {
      const [data, summary] = await Promise.all([api.production(), api.productionSummary()])
      content.innerHTML = `<section class="module-grid"><section class="panel"><h2>Record daily produce</h2><form id="role-production-form" class="compact-form"><select name="produceName"><option value="Milk">Milk</option><option value="Eggs">Eggs</option></select><input name="productionDate" type="date" value="${new Date().toISOString().slice(0, 10)}" required><select name="session"><option value="morning">Morning</option><option value="evening">Evening</option><option value="">No session</option></select><input name="quantityProduced" type="number" min="0" step="0.01" placeholder="Quantity produced" required><select name="unit"><option value="litres">Litres</option><option value="trays">Trays</option><option value="eggs">Eggs</option></select><input name="quantitySold" type="number" min="0" step="0.01" value="0" required><input name="quantityDamaged" type="number" min="0" step="0.01" value="0" required><button>Save production</button></form></section><section class="panel"><h2>Recent records</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Produce</th><th>Session</th><th>Quantity</th><th>Unit</th></tr></thead><tbody>${data.records.map((item) => `<tr><td>${escapeHtml(item.production_date)}</td><td>${escapeHtml(item.produce_name)}</td><td>${escapeHtml(item.production_session || '—')}</td><td>${Number(item.quantity_produced).toLocaleString()}</td><td>${escapeHtml(item.unit)}</td></tr>`).join('') || '<tr><td colspan="5" class="empty">No production records yet.</td></tr>'}</tbody></table></div></section></section>`
      content.insertAdjacentHTML('afterbegin', renderProductionSummary(summary))
      content.querySelector('#role-production-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.recordProduction(Object.fromEntries(new FormData(event.target))); await renderRoleModule(module) } catch (error) { showError(error) } })
      return
    }
    if (module === 'Activities') {
      const data = await api.activities()
      content.innerHTML = `<section class="panel"><h2>Assigned and shared activities</h2><div class="table-wrap"><table><thead><tr><th>When</th><th>Task</th><th>Description</th><th>Status</th><th></th></tr></thead><tbody>${data.activities.map((item) => `<tr><td>${escapeHtml(item.activity_at)}</td><td>${escapeHtml(item.activity_type)}</td><td>${escapeHtml(item.description)}</td><td>${escapeHtml(item.status)}</td><td>${item.status === 'planned' ? `<button class="small-action" data-complete-activity="${Number(item.id)}">Complete</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="5" class="empty">No assigned activities.</td></tr>'}</tbody></table></div></section>`
      content.querySelectorAll('[data-complete-activity]').forEach((button) => button.addEventListener('click', async () => { try { await api.completeActivity(button.dataset.completeActivity); await renderRoleModule(module) } catch (error) { showError(error) } }))
      return
    }
    if (module === 'Inventory') {
      const [data, history] = await Promise.all([api.inventory(), api.inventoryTransactions()])
      content.innerHTML = `<section class="module-grid"><section class="panel"><h2>Stock on hand</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th>Balance</th><th>Reorder at</th></tr></thead><tbody>${data.items.map((item) => `<tr class="${Number(item.quantity_on_hand) <= Number(item.reorder_level) ? 'low-stock-row' : ''}"><td>${escapeHtml(item.name)}</td><td>${Number(item.quantity_on_hand).toLocaleString()} ${escapeHtml(item.unit)}</td><td>${Number(item.reorder_level).toLocaleString()}</td></tr>`).join('')}</tbody></table></div></section><section class="panel"><h2>Record stock use</h2><form id="role-stock-form" class="compact-form"><select name="itemId" required><option value="">Choose item</option>${data.items.map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.name)} · ${Number(item.quantity_on_hand)} ${escapeHtml(item.unit)}</option>`).join('')}</select><select name="transactionType"><option value="out">Stock out</option><option value="in">Stock in</option><option value="adjustment">Adjustment</option></select><input name="quantityChange" type="number" step="0.01" placeholder="Signed quantity" required><input name="reason" placeholder="Reason" required><button>Save movement</button></form></section></section><section class="panel"><h2>Recent stock movements</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Item</th><th>Change</th><th>Reason</th></tr></thead><tbody>${history.transactions.map((item) => `<tr><td>${escapeHtml(item.transaction_date)}</td><td>${escapeHtml(item.item_name)}</td><td>${Number(item.quantity_change).toLocaleString()}</td><td>${escapeHtml(item.reason || '—')}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">No stock movements.</td></tr>'}</tbody></table></div></section>`
      content.querySelector('#role-stock-form').addEventListener('submit', async (event) => { event.preventDefault(); const values = Object.fromEntries(new FormData(event.target)); const itemId = values.itemId; delete values.itemId; try { await api.inventoryTransaction(itemId, values); await renderRoleModule(module) } catch (error) { showError(error) } })
      return
    }
    if (module === 'Livestock' || module === 'Due follow-ups' || module === 'Health records') {
      const [animals, health] = await Promise.all([api.animals(), api.healthRecords()])
      if (module === 'Livestock') {
        content.innerHTML = `<section class="panel"><h2>Animal register</h2><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Name</th><th>Species</th><th>Status</th></tr></thead><tbody>${animals.animals.map((item) => `<tr><td>${escapeHtml(item.tag_number)}</td><td>${escapeHtml(item.name || '—')}</td><td>${escapeHtml(item.species)}</td><td>${escapeHtml(item.status)}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">No animals registered.</td></tr>'}</tbody></table></div></section>`
        return
      }
      const dueRows = health.dueSoon.map((item) => `<tr><td>${escapeHtml(item.next_due_date)}</td><td>${escapeHtml(item.tag_number)}</td><td>${escapeHtml(item.record_type)}</td><td>${escapeHtml(item.description)}</td></tr>`).join('')
      const recordRows = health.records.map((item) => `<tr><td>${escapeHtml(item.record_date)}</td><td>${escapeHtml(item.tag_number)}</td><td>${escapeHtml(item.record_type)}</td><td>${escapeHtml(item.description)}</td></tr>`).join('')
      content.innerHTML = `<section class="module-grid"><section class="panel"><h2>Record treatment or care</h2><form id="role-health-form" class="compact-form"><select name="animalId" required><option value="">Choose animal</option>${animals.animals.map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.tag_number)} · ${escapeHtml(item.species)}</option>`).join('')}</select><select name="recordType"><option value="treatment">Treatment</option><option value="vaccination">Vaccination</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option><option value="illness">Illness</option></select><input name="description" placeholder="Clinical note or action" required><input name="medication" placeholder="Medication, if applicable"><input name="recordDate" type="date" value="${new Date().toISOString().slice(0, 10)}" required><input name="nextDueDate" type="date"><button>Save health record</button></form></section><section class="panel"><h2>Due follow-ups</h2><div class="table-wrap"><table><thead><tr><th>Due</th><th>Animal</th><th>Type</th><th>Description</th></tr></thead><tbody>${dueRows || '<tr><td colspan="4" class="empty">No open follow-ups due.</td></tr>'}</tbody></table></div></section></section><section class="panel"><h2>Health history</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal</th><th>Type</th><th>Description</th></tr></thead><tbody>${recordRows || '<tr><td colspan="4" class="empty">No health records.</td></tr>'}</tbody></table></div></section>`
      content.querySelector('#role-health-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createHealthRecord(Object.fromEntries(new FormData(event.target))); await renderRoleModule(module) } catch (error) { showError(error) } })
      return
    }
    if (module === 'Farm shop' || module === 'Livestock catalogue') {
      const data = await api.products()
      const products = data.products.filter((item) => Number(item.available_quantity) > 0)
      content.innerHTML = `<section class="panel"><h2>Available listings</h2><form id="role-order-form" class="compact-form"><select name="productId" required><option value="">Choose listing</option>${products.map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.name)} · ${Number(item.available_quantity).toLocaleString()} ${escapeHtml(item.unit)} · KES ${Math.round(Number(item.unit_price)).toLocaleString()}</option>`).join('')}</select><input name="quantity" type="number" min="0.01" step="0.01" placeholder="Quantity" required><button>Request order</button></form><div class="table-wrap"><table><thead><tr><th>Listing</th><th>Type</th><th>Available</th><th>Unit price</th></tr></thead><tbody>${products.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.product_type)}</td><td>${Number(item.available_quantity).toLocaleString()} ${escapeHtml(item.unit)}</td><td>KES ${Math.round(Number(item.unit_price)).toLocaleString()}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">No available listings.</td></tr>'}</tbody></table></div></section>`
      content.querySelector('#role-order-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createOrder(Object.fromEntries(new FormData(event.target))); await renderRoleWorkspace('My orders') } catch (error) { showError(error) } })
      return
    }
    if (module === 'My orders' || module === 'Payments') {
      const [orderData, paymentData] = await Promise.all([api.orders(), api.payments()])
      content.innerHTML = `<section class="module-grid"><section class="panel"><h2>My orders</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody>${orderData.orders.map((item) => `<tr><td>#${Number(item.id)}</td><td>${escapeHtml(item.ordered_at)}</td><td>${escapeHtml(item.status)}</td><td>KES ${Math.round(Number(item.total_amount)).toLocaleString()}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">No orders yet.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Payments</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Method</th><th>Status</th><th>Amount</th></tr></thead><tbody>${paymentData.payments.map((item) => `<tr><td>#${Number(item.order_id)}</td><td>${escapeHtml(item.method)}</td><td>${escapeHtml(item.status)}</td><td>KES ${Math.round(Number(item.amount)).toLocaleString()}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">No payments recorded.</td></tr>'}</tbody></table></div></section></section>`
      return
    }
    if (module === 'Feedback') {
      const data = await api.feedback()
      content.innerHTML = `<section class="module-grid"><section class="panel"><h2>Send feedback</h2><form id="role-feedback-form" class="compact-form"><select name="category"><option value="feedback">Feedback</option><option value="complaint">Complaint</option><option value="suggestion">Suggestion</option></select><textarea name="message" rows="5" placeholder="Write your message" required></textarea><button>Send message</button></form></section><section class="panel"><h2>My messages</h2><div class="feedback-list">${data.feedback.map((item) => `<article class="feedback-item"><p class="kicker">${escapeHtml(item.category)} · ${escapeHtml(item.status)}</p><p>${escapeHtml(item.message)}</p><small>${escapeHtml(item.created_at)}</small></article>`).join('') || '<p class="empty">No messages sent.</p>'}</div></section></section>`
      content.querySelector('#role-feedback-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createFeedback(Object.fromEntries(new FormData(event.target))); await renderRoleWorkspace('Feedback') } catch (error) { showError(error) } })
      return
    }
  } catch (error) { showError(error) }
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
