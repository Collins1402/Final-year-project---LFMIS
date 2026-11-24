import './style.css'
import { api } from './api.js'
import { renderLanding, formatEventDate } from './landing.js'
import QRCode from 'qrcode'
import Chart from 'chart.js/auto'

const app = document.querySelector('#app')
let currentUser = null
let currentPermissions = {}
let trendsRefreshTimer = null
let roleLanguage = 'en'

const adminNavigation = [
  ['Operations', ['Overview', 'Livestock', 'Animal health', 'Activities', 'Production', 'Inventory']],
  ['Commerce', ['Sales & orders', 'Feedback']],
  ['Insights', ['Reports', 'Farm trends', 'Assistant']],
  ['Administration', ['User accounts', 'Role permissions', 'Audit log', 'Your rights', 'Events']],
]

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]))
const nairobiDate = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Nairobi', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
const message = (text, danger = false) => `<p class="message ${danger ? 'danger' : 'success'}">${escapeHtml(text)}</p>`
const roleCopy = (english, swahili) => roleLanguage === 'sw' && ['worker', 'veterinarian'].includes(currentUser?.role) ? swahili : english
function installFixedSidebar() {
  const sidebar = app.querySelector('.shell > aside')
  if (!sidebar || sidebar.dataset.fixedSidebar === 'true') return
  sidebar.dataset.fixedSidebar = 'true'
  const toggle = document.createElement('button')
  toggle.type = 'button'
  toggle.className = 'sidebar-toggle'
  toggle.setAttribute('aria-expanded', 'false')
  toggle.textContent = 'Menu'
  sidebar.querySelector('.brand')?.after(toggle)
  toggle.addEventListener('click', () => {
    const open = sidebar.classList.toggle('sidebar-open')
    toggle.setAttribute('aria-expanded', String(open))
    toggle.textContent = open ? 'Close menu' : 'Menu'
  })
  sidebar.querySelectorAll('nav button, #logout, #security-back, #account-security').forEach((button) => button.addEventListener('click', () => {
    if (window.matchMedia('(max-width: 900px)').matches) sidebar.classList.remove('sidebar-open')
  }))
}

function enableSortableTable(table, columnTypes = []) {
  const headers = table?.tHead?.rows[0]?.cells
  if (!headers || table.dataset.sortReady === 'true') return
  table.dataset.sortReady = 'true'
  const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
  Array.from(headers).forEach((header, index) => {
    const label = header.textContent.trim()
    if (!label || /^action$/i.test(label)) return
    const normalizedLabel = label.toLowerCase()
    const type = columnTypes[index] || (/order|amount|total|quantity|price|balance|count|stock/i.test(normalizedLabel) ? 'number' : 'text')
    header.dataset.sortLabel = label
    header.innerHTML = `<button type="button" class="table-sort" aria-label="Sort by ${escapeHtml(label)}">${escapeHtml(label)} <span aria-hidden="true">&harr;</span></button>`
    header.querySelector('button').addEventListener('click', () => {
      const sameColumn = table.dataset.sortColumn === String(index)
      const direction = sameColumn && table.dataset.sortDirection === 'asc' ? 'desc' : 'asc'
      table.dataset.sortColumn = String(index)
      table.dataset.sortDirection = direction
      header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending')
      const rows = [...table.tBodies[0].rows]
      rows.sort((left, right) => {
        const leftEmpty = left.cells.length === 1 && left.cells[0].colSpan > 1
        const rightEmpty = right.cells.length === 1 && right.cells[0].colSpan > 1
        if (leftEmpty || rightEmpty) return leftEmpty ? 1 : -1
        const a = left.cells[index]?.textContent.trim() || ''
        const b = right.cells[index]?.textContent.trim() || ''
        const comparison = type === 'number'
          ? (Number(a.replace(/[^\d.-]/g, '')) || 0) - (Number(b.replace(/[^\d.-]/g, '')) || 0)
          : collator.compare(a, b)
        return comparison * (direction === 'asc' ? 1 : -1)
      })
      rows.forEach((row) => table.tBodies[0].append(row))
    })
  })
}

function enhanceDataTable(table) {
  if (table.dataset.tableToolsReady === 'true' || !table.tHead || !table.tBodies.length) return
  const wrapper = table.closest('.table-wrap')
  if (!wrapper) return
  table.dataset.tableToolsReady = 'true'
  enableSortableTable(table)

  const headers = Array.from(table.tHead.rows[0].cells)
  const statusIndex = headers.findIndex((header) => (header.dataset.sortLabel || header.textContent).trim().toLowerCase() === 'status')
  const toolbar = document.createElement('div')
  toolbar.className = 'table-tools'
  const search = document.createElement('input')
  search.type = 'search'
  search.className = 'table-search'
  search.placeholder = 'Search records'
  search.setAttribute('aria-label', 'Search records in this table')
  toolbar.append(search)

  let statusFilter = null
  if (statusIndex >= 0) {
    const values = [...new Set(Array.from(table.tBodies[0].rows)
      .filter((row) => !(row.cells.length === 1 && row.cells[0].colSpan > 1))
      .map((row) => row.cells[statusIndex]?.textContent.trim())
      .filter(Boolean))].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }))
    statusFilter = document.createElement('select')
    statusFilter.className = 'table-status-filter'
    statusFilter.setAttribute('aria-label', 'Filter records by status')
    statusFilter.innerHTML = '<option value="">All statuses</option>' + values.map((value) => `<option value="${escapeHtml(value.toLowerCase())}">${escapeHtml(value)}</option>`).join('')
    toolbar.append(statusFilter)
  }

  let updateBulkButtons = () => {}
  if (table.dataset.bulkOrders === 'true') {
    const actions = document.createElement('div')
    actions.className = 'table-bulk-actions'
    const fulfill = document.createElement('button')
    fulfill.type = 'button'
    fulfill.className = 'small-action'
    const cancel = document.createElement('button')
    cancel.type = 'button'
    cancel.className = 'small-action danger-action'
    const feedback = document.createElement('span')
    feedback.className = 'table-bulk-feedback'
    feedback.setAttribute('aria-live', 'polite')
    actions.append(fulfill, cancel, feedback)
    toolbar.append(actions)

    const visibleOrders = () => [...table.tBodies[0].rows].filter((row) => !row.hidden && row.dataset.orderId)
    const fulfillIds = () => visibleOrders().filter((row) => row.dataset.orderStatus === 'accepted').map((row) => Number(row.dataset.orderId))
    const cancelIds = () => visibleOrders().filter((row) => ['pending', 'quoted', 'accepted'].includes(row.dataset.orderStatus)).map((row) => Number(row.dataset.orderId))
    updateBulkButtons = () => {
      const fulfillCount = fulfillIds().length
      const cancelCount = cancelIds().length
      fulfill.textContent = `Fulfill visible (${fulfillCount})`
      cancel.textContent = `Cancel visible (${cancelCount})`
      fulfill.disabled = fulfillCount === 0
      cancel.disabled = cancelCount === 0
    }

    const submitBulkAction = async (status, ids) => {
      if (ids.length === 0) return
      const confirmText = status === 'fulfilled'
        ? `Fulfill ${ids.length} accepted order(s) matching the current filters? This records their sales.`
        : `Cancel ${ids.length} open order(s) matching the current filters? Reserved stock will be released.`
      if (!window.confirm(confirmText)) return
      fulfill.disabled = true
      cancel.disabled = true
      feedback.textContent = 'Updating orders…'
      try {
        await api.updateOrdersBulk({ orderIds: ids, status })
        await renderCommerceModule()
      } catch (error) {
        feedback.textContent = error.message
        updateBulkButtons()
      }
    }
    fulfill.addEventListener('click', () => submitBulkAction('fulfilled', fulfillIds()))
    cancel.addEventListener('click', () => submitBulkAction('cancelled', cancelIds()))
  }

  const resultCount = document.createElement('span')
  resultCount.className = 'table-result-count'
  resultCount.setAttribute('aria-live', 'polite')
  toolbar.append(resultCount)
  wrapper.parentElement.insertBefore(toolbar, wrapper)

  const filterRows = () => {
    const query = search.value.trim().toLocaleLowerCase()
    const wantedStatus = statusFilter?.value || ''
    let visible = 0
    let total = 0
    Array.from(table.tBodies[0].rows).forEach((row) => {
      if (row.cells.length === 1 && row.cells[0].colSpan > 1) {
        row.hidden = false
        return
      }
      total += 1
      const matchesSearch = !query || row.textContent.toLocaleLowerCase().includes(query)
      const rowStatus = statusIndex >= 0 ? row.cells[statusIndex]?.textContent.trim().toLowerCase() : ''
      const matchesStatus = !wantedStatus || rowStatus === wantedStatus
      row.hidden = !(matchesSearch && matchesStatus)
      if (!row.hidden) visible += 1
    })
    resultCount.textContent = visible === total ? `${total} records` : `${visible} of ${total} records`
    updateBulkButtons()
  }
  search.addEventListener('input', filterRows)
  statusFilter?.addEventListener('change', filterRows)
  filterRows()
}

const tableObserver = new MutationObserver(() => {
  app.querySelectorAll('.content .table-wrap table').forEach(enhanceDataTable)
})
tableObserver.observe(app, { childList: true, subtree: true })
const roleModuleLabel = (module) => {
  if (!['worker', 'veterinarian'].includes(currentUser?.role)) return module
  const labels = {
    Home: ['Home', 'Nyumbani'],
    Production: ['🥛 Milk & eggs', '🥛 Maziwa na mayai'],
    Activities: ['📝 Tasks', '📝 Kazi'],
    Inventory: ['📦 Stock', '📦 Stoo'],
    Livestock: ['🐄 Animals', '🐄 Wanyama'],
    'Health records': ['🩺 Animal care', '🩺 Matibabu'],
    'Due follow-ups': ['⏰ Follow-ups', '⏰ Miadi'],
    Assistant: ['Help', 'Msaada'],
    'Your rights': ['Your rights', 'Haki zako'],
  }
  const labelsForModule = labels[module]
  return labelsForModule ? labelsForModule[roleLanguage === 'sw' ? 1 : 0] : module
}

function renderAuth(mode = 'login', notice = '') {
  const setup = mode === 'setup'
  clearInterval(trendsRefreshTimer)
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">FLOURi FARM · LFMIS</p><h1>${setup ? 'Set up the farm workspace' : 'Welcome back'}</h1><p class="muted">${setup ? 'Create the first farm owner account.' : 'Sign in to manage the farm.'}</p>${notice}${setup ? `<form id="setup-form"><label>Full name<input name="fullName" placeholder="e.g. Alex Kamau" required></label><label>Username<input name="username" placeholder="e.g. alex.kamau" required pattern="[A-Za-z0-9_.]{3,30}"></label><label>Email<input name="email" type="email" placeholder="name@example.com" required></label><label>Password<span class="password-field"><input name="password" type="password" minlength="12" placeholder="At least 12 characters" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Create owner account</button></form>` : `<nav class="auth-tabs" aria-label="Account information"><button type="button" class="auth-tab active" data-auth-view="login" aria-selected="true">Sign in</button><button type="button" class="auth-tab" data-auth-view="trends" aria-selected="false">Farm trends</button><button type="button" class="auth-tab" data-auth-view="rights" aria-selected="false">Your rights</button></nav><section id="auth-login-panel"><form id="login-form"><label>Username or email<input name="login" placeholder="Username or name@example.com" required autocomplete="username"></label><label>Password<span class="password-field"><input name="password" type="password" placeholder="Enter your password" required autocomplete="current-password"><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Sign in</button></form><button id="forgot-link" class="text-link" type="button">Forgot password?</button><button id="register-link" class="text-link" type="button">Create a farm account</button></section><section id="auth-trends-panel" class="auth-info-panel" hidden><p class="kicker">AGRICULTURE NEWS SIGNALS</p><h2>Farm trends</h2><p class="muted">Public Kenya and worldwide agriculture coverage. These are news signals, not measured nearby-farm yields.</p><div id="public-trends-content" aria-live="polite">Loading current reports...</div></section><section id="auth-rights-panel" class="auth-info-panel" hidden><p class="kicker">ACCOUNT & DATA</p><h2>Your rights</h2><div class="rights-copy"><p>You can access the records allowed by your assigned role. Farm records are shared only with roles that need them to operate the service.</p><p>You may ask the farm administrator to correct your account details or a record, provide a copy of your personal account data, or delete/deactivate your account.</p><p>Some transaction and audit records may need to be retained for farm operations or legal obligations. The administrator can explain what applies to a specific request.</p><p>Sign-in alerts are sent to the email address registered to your account after each successful login.</p></div></section>`}</section></main>`
  const form = app.querySelector('form')
  app.querySelectorAll('[data-auth-view]').forEach((tab) => tab.addEventListener('click', () => {
    const view = tab.dataset.authView
    app.querySelectorAll('[data-auth-view]').forEach((item) => {
      const active = item === tab
      item.classList.toggle('active', active)
      item.setAttribute('aria-selected', String(active))
    })
    app.querySelector('#auth-login-panel').hidden = view !== 'login'
    app.querySelector('#auth-trends-panel').hidden = view !== 'trends'
    app.querySelector('#auth-rights-panel').hidden = view !== 'rights'
    clearInterval(trendsRefreshTimer)
    if (view === 'trends') {
      loadPublicTrends()
      trendsRefreshTimer = setInterval(loadPublicTrends, 6 * 60 * 60 * 1000)
    }
  }))
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
      if (!setup && result.twoFactorRequired) {
        renderTwoFactorChallenge(result.methods)
        return
      }
      currentUser = result.user
      if (result.mustEnable2fa || currentUser.mustEnable2fa) {
        renderAccountSecurity(true)
        return
      }
      renderApp()
    } catch (error) { renderAuth(mode, message(error.message, true)) }
  })
}

function renderTwoFactorChallenge(methods, notice = '') {
  if (methods.includes('totp')) {
    app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">TWO-STEP VERIFICATION</p><h1>Verify it’s you</h1><p class="muted">Enter the 6-digit code from your authenticator app.</p><div id="two-factor-notice" aria-live="polite">${notice}</div><form id="totp-login-form"><label>6-digit code<input name="code" placeholder="123456 or recovery code" inputmode="text" autocomplete="one-time-code" required></label><label class="checkbox-label"><input type="checkbox" name="rememberDevice"> Remember this device for 30 days</label><button>Verify and sign in</button></form><button id="use-recovery-code" class="text-link" type="button">Use a recovery code instead</button><button id="two-factor-back" class="text-link" type="button">Back to sign in</button></section></main>`
    const code = app.querySelector('#totp-login-form').elements.namedItem('code')
    app.querySelector('#use-recovery-code').addEventListener('click', () => { code.placeholder = 'Enter a recovery code'; code.focus() })
    app.querySelector('#totp-login-form').addEventListener('submit', async (event) => {
      event.preventDefault()
      const form = event.currentTarget
      try {
        const result = await api.verifyTotpLogin({ code: code.value.trim(), rememberDevice: form.elements.namedItem('rememberDevice').checked })
        currentUser = result.user
        if (result.mustEnable2fa || currentUser.mustEnable2fa) renderAccountSecurity(true)
        else renderApp()
      } catch (error) { app.querySelector('#two-factor-notice').innerHTML = message(error.message, true) }
    })
    app.querySelector('#two-factor-back').addEventListener('click', async () => { try { await api.cancelLoginTwoFactor() } catch {} renderAuth('login') })
    return
  }
  const methodButtons = methods.includes('email') ? '<button type="button" class="secondary-action" data-two-factor-method="email">Send code to email</button>' : ''
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">TWO-FACTOR AUTHENTICATION</p><h1>Verify it’s you</h1><p class="muted">Enter the code sent to your verified account email. Codes expire after 10 minutes.</p><div id="two-factor-notice" aria-live="polite">${notice}</div><div class="button-row">${methodButtons}</div><form id="two-factor-code-form" hidden><label>6-digit code<input name="code" placeholder="e.g. 123456" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button>Verify and sign in</button><button id="two-factor-resend" class="text-link" type="button">Send another code</button></form><button id="two-factor-back" class="text-link" type="button">Back to sign in</button></section></main>`
  const codeForm = app.querySelector('#two-factor-code-form')
  let selectedMethod = ''
  const showError = (error) => { app.querySelector('#two-factor-notice').innerHTML = message(error.message, true) }
  const sendCode = async (method) => {
    selectedMethod = method
    try {
      await api.sendLoginTwoFactorCode(method)
      app.querySelector('#two-factor-notice').innerHTML = message('A code was sent by email.')
      codeForm.hidden = false
      codeForm.elements.namedItem('code').focus()
    } catch (error) { showError(error) }
  }
  app.querySelectorAll('[data-two-factor-method]').forEach((button) => button.addEventListener('click', () => sendCode(button.dataset.twoFactorMethod)))
  codeForm.addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      const result = await api.verifyLoginTwoFactorCode({ method: selectedMethod, code: codeForm.elements.namedItem('code').value })
      currentUser = result.user
      renderApp()
    } catch (error) { showError(error) }
  })
  app.querySelector('#two-factor-resend').addEventListener('click', () => sendCode(selectedMethod))
  app.querySelector('#two-factor-back').addEventListener('click', async () => {
    try {
      await api.cancelLoginTwoFactor()
      renderAuth('login')
    } catch (error) {
      showError(error)
    }
  })
}

async function loadPublicTrends() {
  const content = app.querySelector('#public-trends-content')
  if (!content) return
  try {
    const data = await api.trends()
    const renderGroup = (title, group) => {
      const articles = group.articles || []
      const rows = articles.map((article) => {
        let url = '#'
        try {
          const parsed = new URL(article.url)
          if (parsed.protocol === 'https:') url = parsed.href
        } catch { return '' }
        return `<li><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(article.title)}</a><small>${escapeHtml(article.domain)}${article.publishedAt ? ` · ${escapeHtml(article.publishedAt)}` : ''}</small></li>`
      }).join('')
      const updated = group.updatedAt ? new Date(`${group.updatedAt.replace(' ', 'T')}Z`).toLocaleString('en-KE', { timeZone: 'Africa/Nairobi' }) : 'Not available'
      return `<section class="trend-group"><div class="trend-heading"><h3>${escapeHtml(title)}</h3><small>${group.stale ? 'Cached · ' : ''}Updated ${escapeHtml(updated)}</small></div><ul>${rows || `<li class="empty">${group.unavailable ? 'Trend feed is temporarily unavailable.' : 'No current reports found.'}</li>`}</ul></section>`
    }
    content.innerHTML = `<p class="trend-source">Source: ${escapeHtml(data.source)} · refreshes every ${Number(data.refreshHours)} hours</p>${renderGroup(`Kenya · ${data.region}`, data.kenya || data.regional)}${renderGroup('East Africa', data.east_africa)}${renderGroup('Global', data.global)}`
  } catch (error) {
    content.innerHTML = `<p class="message danger">${escapeHtml(error.message)}</p>`
  }
}

function renderForgotPassword(notice = '') {
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">ACCOUNT RECOVERY</p><h1>Reset your password</h1><p class="muted">Enter your account email. We will send a one-time reset link if it matches an active account.</p>${notice}<form id="forgot-form"><label>Email<input name="email" type="email" placeholder="name@example.com" required autocomplete="email"></label><button>Send reset link</button></form><button id="back-login" class="text-link" type="button">Back to sign in</button></section></main>`
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
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">ACCOUNT RECOVERY</p><h1>Choose a new password</h1><p class="muted">Use at least 12 characters. This link works once and expires after one hour.</p><form id="reset-form"><label>New password<span class="password-field"><input name="password" type="password" minlength="12" placeholder="At least 12 characters" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><label>Confirm password<span class="password-field"><input name="confirmPassword" type="password" minlength="12" placeholder="Enter the password again" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Update password</button></form></section></main>`
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
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">FLOURi FARM · LFMIS</p><h1>Create an account</h1><p class="muted">Create your farm customer or livestock buyer account.</p>${notice}<form id="register-form"><label>Full name<input name="fullName" placeholder="e.g. Alex Kamau" required></label><label>Username<input name="username" placeholder="e.g. alex.kamau" required pattern="[A-Za-z0-9_.]{3,30}"></label><label>Email<input name="email" type="email" placeholder="name@example.com" required></label><label>Phone<input name="phone" type="tel" placeholder="e.g. 0712 345 678"></label><label>Account type<select name="role"><option value="customer">Farm customer</option><option value="buyer">Livestock buyer</option></select></label><label>Password<span class="password-field"><input name="password" type="password" minlength="12" placeholder="At least 12 characters" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Create account</button></form><button id="back-login" class="text-link" type="button">Back to sign in</button></section></main>`
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

async function renderAccountSecurity(forced = false) {
  let status
  try {
    status = await api.twoFactorStatus()
  } catch (error) {
    app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><h1>Account security</h1>${message(error.message, true)}<button id="security-back">Back to workspace</button></section></main>`
    app.querySelector('#security-back').addEventListener('click', renderApp)
    return
  }

  const enabledPanel = status.enabled ? `<p>Two-step verification is on.</p><form id="totp-disable-form"><label>Current password<input name="password" type="password" required autocomplete="current-password"></label><label>Current 6-digit code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label><button class="danger-action">Disable</button></form><hr><h3>New recovery codes</h3><form id="recovery-form"><label>Password<input name="password" type="password" required autocomplete="current-password"></label><label>6-digit code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label><button class="secondary-action">Generate new recovery codes</button></form><p>Unused recovery codes: ${status.recoveryCodesRemaining}</p><h3>Remembered devices</h3><ul>${status.trustedDevices.map((device) => `<li>${escapeHtml(device.label)} · expires ${escapeHtml(device.expires_at)}</li>`).join('') || '<li>No remembered devices.</li>'}</ul><button id="revoke-devices" class="secondary-action">Sign out of all remembered devices</button>` : `<p>Use Google Authenticator, Microsoft Authenticator, or another TOTP app.</p><button id="start-totp" class="secondary-action">Turn on 2-step verification</button>`
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>Account security</h2>${forced ? '' : '<button id="security-back" class="quiet">Back to workspace</button>'}<div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="security-logout" class="quiet">Sign out</button></aside><main class="content"><header><div><p class="kicker">ACCOUNT SECURITY</p><h1>Two-step verification</h1><p class="muted">Use an authenticator app each time you sign in.</p></div><span class="health-pill">Two-factor ${status.enabled ? 'On' : 'Off'}</span></header><div id="security-notice" aria-live="polite">${forced ? message(status.enabled ? 'Two-step verification is on. Continue to your workspace below.' : 'Administrator accounts must turn on two-step verification before continuing.') : ''}</div><section class="module-grid"><section class="panel"><h2>Authenticator app</h2>${enabledPanel}<div id="totp-setup" hidden><p>Scan this QR code in your authenticator app, or enter the secret manually.</p><canvas id="totp-qr"></canvas><p><strong id="totp-secret"></strong></p><form id="totp-enable-form"><label>First 6-digit code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button>Verify and turn on</button></form></div><div id="recovery-once" hidden><h3>Save these recovery codes</h3><p>Each code works once. They will not be shown again.</p><pre id="recovery-values"></pre><button id="copy-recovery" class="secondary-action">Copy</button><button id="saved-recovery">I saved them</button></div>${forced && status.enabled ? '<button id="continue-after-2fa">Continue to workspace</button>' : ''}</section></section></main></div>`
  installFixedSidebar()

  app.querySelector('#security-back')?.addEventListener('click', renderApp)
  app.querySelector('#continue-after-2fa')?.addEventListener('click', () => { currentUser.mustEnable2fa = false; renderApp() })
  app.querySelector('#security-logout').addEventListener('click', async () => {
    await api.logout()
    currentUser = null
    renderAuth('login')
  })
  const notice = app.querySelector('#security-notice')
  const showCodes = (codes) => { app.querySelector('#recovery-values').textContent = codes.join('\n'); app.querySelector('#recovery-once').hidden = false }
  app.querySelector('#start-totp')?.addEventListener('click', async () => {
    try { const setup = await api.setupTotp(); app.querySelector('#totp-setup').hidden = false; app.querySelector('#totp-secret').textContent = setup.secret; await QRCode.toCanvas(app.querySelector('#totp-qr'), setup.otpauthUri, { width: 220 }); }
    catch (error) { notice.innerHTML = message(error.message, true) }
  })
  app.querySelector('#totp-enable-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { const result = await api.enableTotp(event.currentTarget.elements.namedItem('code').value); showCodes(result.recoveryCodes); app.querySelector('#totp-setup').hidden = true; } catch (error) { notice.innerHTML = message(error.message, true) } })
  app.querySelector('#copy-recovery')?.addEventListener('click', async () => { await navigator.clipboard.writeText(app.querySelector('#recovery-values').textContent); notice.innerHTML = message('Recovery codes copied.') })
  app.querySelector('#saved-recovery')?.addEventListener('click', () => { if (forced) renderAccountSecurity(true); else renderAccountSecurity() })
  app.querySelector('#totp-disable-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { const data = Object.fromEntries(new FormData(event.currentTarget)); await api.disableTotp(data); currentUser.mustEnable2fa = currentUser.role === 'admin'; if (currentUser.mustEnable2fa) renderAccountSecurity(true); else renderAccountSecurity(); } catch (error) { notice.innerHTML = message(error.message, true) } })
  app.querySelector('#recovery-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { showCodes((await api.regenerateRecoveryCodes(Object.fromEntries(new FormData(event.currentTarget)))).recoveryCodes) } catch (error) { notice.innerHTML = message(error.message, true) } })
  app.querySelector('#revoke-devices')?.addEventListener('click', async () => { try { await api.revokeTrustedDevices(); await renderAccountSecurity() } catch (error) { notice.innerHTML = message(error.message, true) } })
  app.querySelectorAll('.remove-two-factor-form').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(form))
    data.method = form.dataset.method
    try {
      await api.removeTwoFactorMethod(data)
      await renderAccountSecurity()
    } catch (error) {
      notice.innerHTML = message(error.message, true)
    }
  }))
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
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>Farm workspace</h2><nav class="workspace-nav" aria-label="Farm modules">${['Overview', 'Livestock', 'Animal health', 'Activities', 'Production', 'Inventory', 'Sales & orders', 'Reports', 'Feedback', 'User accounts', 'Assistant'].map((item) => `<button class="workspace-nav-item ${item === 'Overview' ? 'active' : ''}" data-module="${item}">${item}</button>`).join('')}</nav><div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="logout" class="quiet">Sign out</button></aside><main class="content"><header><div><p class="kicker">OVERVIEW</p><h1>Good day, ${escapeHtml(currentUser.fullName.split(' ')[0])}</h1><p class="muted">Keep the herd records complete and easy to trust.</p></div><span class="health-pill">API connected</span></header><section class="stats"><div><small>Animals</small><strong>${animals.length}</strong></div><div><small>Health records</small><strong>${healthRecords.length}</strong></div><div><small>Role</small><strong>${escapeHtml(currentUser.role)}</strong></div></section><div class="grid"><section class="panel"><div class="panel-head"><h2>Livestock register</h2><span>${animals.length} records</span></div><form id="animal-form" class="compact-form">  <input name="tagNumber" placeholder="e.g. CAT-001" required><select name="species"><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="sheep">Sheep</option></select><input name="breed" placeholder="Breed"><select name="sex"><option value="female">Female</option><option value="male">Male</option></select><button>Add animal</button></form><div id="animal-module-feedback" aria-live="polite"></div><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Species</th><th>Breed</th><th>Status</th></tr></thead><tbody>${animals.length ? animals.map((animal) => `<tr><td>${escapeHtml(animal.tag_number)}</td><td>${escapeHtml(animal.species)}</td><td>${escapeHtml(animal.breed || '—')}</td><td><span class="status">${escapeHtml(animal.status)}</span></td></tr>`).join('') : '<tr><td colspan="4" class="empty">No animals yet.</td></tr>'}</tbody></table></div></section><section class="panel"><div class="panel-head"><h2>Animal health</h2><span>${healthRecords.length} records</span></div><form id="health-form" class="compact-form"><select name="animalId" required><option value="">Animal</option>${animals.map((animal) => `<option value="${animal.id}">${escapeHtml(animal.tag_number)}</option>`).join('')}</select><select name="recordType"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option></select>  <input name="description" placeholder="e.g. Vaccinated against..." required><input name="recordDate" type="date" required><button>Save record</button></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal</th><th>Type</th><th>Description</th></tr></thead><tbody>${healthRecords.length ? healthRecords.map((record) => `<tr><td>${escapeHtml(record.record_date)}</td><td>${escapeHtml(record.tag_number)}</td><td>${escapeHtml(record.record_type)}</td><td>${escapeHtml(record.description)}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No health records yet.</td></tr>'}</tbody></table></div></section></div></main></div>`
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>Farm workspace</h2><nav class="workspace-nav" aria-label="Farm modules">${['Overview', 'Livestock', 'Animal health', 'Activities', 'Production', 'Inventory', 'Sales & orders', 'Reports', 'Feedback', 'User accounts', 'Assistant'].map((item) => `<button class="workspace-nav-item ${item === 'Overview' ? 'active' : ''}" data-module="${item}">${item}</button>`).join('')}</nav><div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="logout" class="quiet">Sign out</button></aside><main class="content"><header><div><p class="kicker">OVERVIEW</p><h1>Good day, ${escapeHtml(currentUser.fullName.split(' ')[0])}</h1><p class="muted">Keep the herd records complete and easy to trust.</p></div><span class="health-pill">API connected</span></header><section class="stats"><div><small>Animals</small><strong>${animals.length}</strong></div><div><small>Health records</small><strong>${healthRecords.length}</strong></div><div><small>Role</small><strong>${escapeHtml(currentUser.role)}</strong></div></section><div class="grid"><section class="panel"><div class="panel-head"><h2>Livestock register</h2><span>${animals.length} records</span></div><form id="animal-form" class="compact-form"><input name="tagNumber" placeholder="e.g. CAT-001" required><select name="species"><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="sheep">Sheep</option></select><input name="breed" placeholder="e.g. Friesian or Kienyeji"><select name="sex"><option value="female">Female</option><option value="male">Male</option></select><button>Add animal</button></form><div id="animal-module-feedback" aria-live="polite"></div><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Species</th><th>Breed</th><th>Status</th></tr></thead><tbody>${animals.length ? animals.map((animal) => `<tr><td>${escapeHtml(animal.tag_number)}</td><td>${escapeHtml(animal.species)}</td><td>${escapeHtml(animal.breed || '—')}</td><td><span class="status">${escapeHtml(animal.status)}</span></td></tr>`).join('') : '<tr><td colspan="4" class="empty">No animals yet.</td></tr>'}</tbody></table></div></section><section class="panel"><div class="panel-head"><h2>Animal health</h2><span>${healthRecords.length} records</span></div><form id="health-form" class="compact-form"><select name="animalId" required><option value="">Select an animal</option>${animals.map((animal) => `<option value="${animal.id}">${escapeHtml(animal.tag_number)}</option>`).join('')}</select><select name="recordType"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option></select><input name="description" placeholder="e.g. Vaccinated against..." required><input name="recordDate" type="date" required><button>Save record</button></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal</th><th>Type</th><th>Description</th></tr></thead><tbody>${healthRecords.length ? healthRecords.map((record) => `<tr><td>${escapeHtml(record.record_date)}</td><td>${escapeHtml(record.tag_number)}</td><td>${escapeHtml(record.record_type)}</td><td>${escapeHtml(record.description)}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No health records yet.</td></tr>'}</tbody></table></div></section></div></main></div>`
  const accountSecurityButton = document.createElement('button')
  accountSecurityButton.id = 'account-security'
  accountSecurityButton.className = 'quiet'
  accountSecurityButton.textContent = 'Account security'
  app.querySelector('aside .user-chip').before(accountSecurityButton)
  accountSecurityButton.addEventListener('click', renderAccountSecurity)
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
  installFixedSidebar()
  app.querySelector('#logout').addEventListener('click', async () => { await api.logout(); currentUser = null; renderAuth() })
  app.querySelector('#animal-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createAnimal(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { const form = event.target; let feedback = form.nextElementSibling; if (!feedback?.classList.contains('form-feedback')) { feedback = document.createElement('div'); feedback.className = 'form-feedback'; form.after(feedback) } feedback.innerHTML = message(error.message, true) } })
  app.querySelector('#health-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createHealthRecord(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
}

function renderAdminModule(module) {
  if (module === 'Events') return renderEventsModule()
  if (module === 'Activities') return renderActivitiesModule()
  if (module === 'Production') return renderProductionModule()
  if (module === 'Inventory') return renderInventoryModule()
  if (module === 'Sales & orders') return renderCommerceModule()
  if (module === 'Reports') return renderReportsModule()
  if (module === 'Farm trends') return renderFarmTrendsModule()
  if (module === 'Assistant') return renderAssistantModule()
  if (module === 'Livestock') return renderLivestockModule()
  if (module === 'Animal health') return renderHealthModule()
  if (module === 'User accounts') return renderUsersModule()
  if (module === 'Role permissions') return renderPermissionsModule()
  if (module === 'Audit log') return renderAuditModule()
  if (module === 'Your rights') return renderRightsModule()
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
    'Role permissions': ['Role permissions', 'Control which farm modules each role can access.'],
    'Your rights': ['Your rights', 'Review the access granted to your account and learn how to manage your data.'],
    Assistant: ['Farm assistant', 'Ask questions about records and receive role-limited decision support.'],
  }[module]
  app.querySelectorAll('[data-module]').forEach((item) => item.classList.toggle('active', item.dataset.module === module))
  const [title, description] = details
  app.querySelector('.content').innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>${escapeHtml(title)}</h1><p class="muted">${escapeHtml(description)}</p></div><span class="health-pill">Admin access</span></header><section class="module-grid"><section class="panel module-intro"><p class="kicker">WORKSPACE</p><h2>${escapeHtml(title)}</h2><p class="muted">This module is now part of the admin workspace. Its records and actions will appear here as the backend slice is connected.</p><button class="primary-action" data-module="Overview">Back to overview</button></section><section class="panel"><p class="kicker">NEXT ACTION</p><h2>Keep records complete</h2><p class="muted">Use the overview for the currently connected livestock and animal-health records.</p></section></section>`
  app.querySelector('[data-module="Overview"]').addEventListener('click', renderApp)
}

function activityHealthFields(animals) {
  const options = animals.filter((animal) => animal.status === 'active')
    .map((animal) => `<option value="${Number(animal.id)}">${escapeHtml(animal.tag_number)} · ${escapeHtml(animal.species)}</option>`)
    .join('')
  return `<label class="checkbox-field"><input type="checkbox" name="healthConcern" value="1"> I noticed a health problem</label><fieldset data-health-concern-fields disabled hidden><label>Animal<select name="animalId" required><option value="">Choose animal</option>${options}</select></label><label>Symptoms<textarea name="symptoms" rows="3" maxlength="220" required placeholder="What did you notice?"></textarea></label></fieldset>`
}

function wireActivityHealthFields(form) {
  const checkbox = form.querySelector('[name="healthConcern"]')
  const fields = form.querySelector('[data-health-concern-fields]')
  checkbox.addEventListener('change', () => {
    fields.hidden = !checkbox.checked
    fields.disabled = !checkbox.checked
  })
}

async function renderActivitiesModule() {
  const content = app.querySelector('.content')
  try {
    const [data, animalData] = await Promise.all([api.activities(), api.animals()])
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Activities</h1><p class="muted">Plan and complete daily farm work.</p></div><span class="health-pill">Live records</span></header><section class="module-grid"><section class="panel"><h2>Plan activity</h2><form id="activity-form" class="compact-form"><select name="activityType"><option value="feeding">Feeding</option><option value="milking">Milking</option><option value="cleaning">Cleaning</option><option value="health">Health follow-up</option><option value="maintenance">Maintenance</option><option value="other">Other</option></select><input name="description" placeholder="e.g. Feed cattle in the west pen" required><input name="activityAt" type="datetime-local" required>${activityHealthFields(animalData.animals)}<button>Save activity</button></form></section><section class="panel"><h2>Activity list</h2><div class="table-wrap"><table><thead><tr><th>When</th><th>Type</th><th>Description</th><th>Status</th><th></th></tr></thead><tbody>${data.activities.length ? data.activities.map((item) => `<tr><td>${escapeHtml(item.activity_at)}</td><td>${escapeHtml(item.activity_type)}</td><td>${escapeHtml(item.description)}</td><td>${escapeHtml(item.status)}</td><td>${item.status === 'planned' ? `<button class="small-action" data-complete="${item.id}">Complete</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="5" class="empty">No activities yet.</td></tr>'}</tbody></table></div></section></section>`
    const form = content.querySelector('#activity-form')
    wireActivityHealthFields(form)
    form.addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createActivity(Object.fromEntries(new FormData(form))); await renderActivitiesModule() } catch (error) { content.insertAdjacentHTML('afterbegin', message(error.message, true)) } })
    content.querySelectorAll('[data-complete]').forEach((button) => button.addEventListener('click', async () => { await api.completeActivity(button.dataset.complete); await renderActivitiesModule() }))
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderEventsModule() {
  const content = app.querySelector('.content')
  try {
    const data = await api.adminEvents()
    const events = data.events
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Events</h1><p class="muted">Publish daytime farm visits, tours and workshops.</p></div><span class="health-pill">Day visits only · Ends by 4:00 p.m.</span></header><section class="module-grid"><section class="panel"><h2 id="event-form-title">Create event</h2><p class="muted">Published events appear on the public website. Overnight stays are not available.</p><div id="events-feedback"></div><form id="event-form" class="compact-form event-form"><input type="hidden" name="id"><label>Event title<input name="title" placeholder="e.g. Farm opening day" maxlength="150" required></label><label>Location<input name="location" placeholder="e.g. Flouri Farm main gate" maxlength="200" value="Flouri Farm" required></label><label>Date<input name="eventDate" type="date" required></label><label>Status<select name="status"><option value="draft">Draft</option><option value="published">Published</option><option value="cancelled">Cancelled</option></select></label><label>Starts at<input name="startTime" type="time" max="16:00" required></label><label>Ends at<input name="endTime" type="time" max="16:00" required></label><label class="event-description">Description    <textarea name="description" rows="4" maxlength="2000" placeholder="e.g. Farm tour, animal displays and safety briefing" required></textarea></label><button id="event-save-button">Save event</button><button type="button" id="event-reset-button" class="secondary-action">Clear form</button></form></section><section class="panel"><div class="panel-head"><h2>Farm events</h2><span>${events.length} records</span></div><div class="event-admin-list">${events.length ? events.map((event) => {
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

function bindProductionEditor(root, records, refresh) {
  const form = root.querySelector('#produce-form, #role-production-form')
  if (!form) return
  let feedback = root.querySelector('#production-feedback')
  if (!feedback) { feedback = document.createElement('div'); feedback.id = 'production-feedback'; feedback.setAttribute('aria-live', 'polite'); form.after(feedback) }
  const loadRecord = (record) => {
    form.dataset.recordId = record.id
    for (const [field, value] of Object.entries({ produceName: record.produce_name, productionDate: record.production_date, session: record.production_session || '', animalId: record.animal_id || '', quantityProduced: record.quantity_produced, quantitySold: record.quantity_sold, quantityDamaged: record.quantity_damaged, unit: record.unit, notes: record.notes || '' })) {
      if (form.elements[field]) form.elements[field].value = value
    }
    form.querySelector('button[type="submit"], button:not([type])').textContent = 'Update record'
    const cancel = form.querySelector('[data-cancel-production]')
    if (cancel) cancel.hidden = false
    feedback.innerHTML = ''
    form.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
  root.querySelectorAll('[data-production-edit]').forEach((button) => button.addEventListener('click', () => {
    const record = records.find((item) => Number(item.id) === Number(button.dataset.productionEdit))
    if (record) loadRecord(record)
  }))
  form.querySelector('[data-cancel-production]')?.addEventListener('click', () => refresh())
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const recordId = form.dataset.recordId
    const data = Object.fromEntries(new FormData(form))
    try {
      if (recordId) await api.updateProduction(recordId, data)
      else await api.recordProduction(data)
      await refresh()
    } catch (error) {
      feedback.innerHTML = message(error.message, true)
      if (error.existingRecord) {
        feedback.insertAdjacentHTML('beforeend', '<button type="button" class="small-action" data-edit-existing-record>Edit existing record</button>')
        feedback.querySelector('[data-edit-existing-record]').addEventListener('click', () => loadRecord(error.existingRecord))
      }
    }
  })
}

async function renderProductionModule() {
  const content = app.querySelector('.content')
  try {
    const [data, summary, animalData] = await Promise.all([api.production(), api.productionSummary(), api.animals()])
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Farm produce</h1><p class="muted">Record any produce with its quantity produced, quantity sold and unit.</p></div><span class="health-pill">${data.records.length} records</span></header><section class="module-grid"><section class="panel"><h2>Record produce</h2><form id="produce-form" class="compact-form"><input name="produceName" list="produce-types" placeholder="Produce name" maxlength="120" required><datalist id="produce-types"><option value="Milk"><option value="Eggs"><option value="Wool"></datalist><input name="productionDate" type="date" required><select name="session"><option value="">No session</option><option value="morning">Morning</option><option value="evening">Evening</option><option value="other">Other</option></select><select name="animalId"><option value="">Animal (milk only, optional)</option>${animalData.animals.filter((animal) => ['cattle', 'goat'].includes(animal.species) && animal.status === 'active').map((animal) => `<option value="${animal.id}">${escapeHtml(animal.tag_number)}</option>`).join('')}</select><input name="quantityProduced" type="number" min="0" step="0.01" placeholder="Quantity produced" required><input name="quantitySold" type="number" min="0" step="0.01" placeholder="Quantity sold" value="0" required><input name="quantityDamaged" type="number" min="0" step="0.01" placeholder="Damaged or unusable" value="0" required><input name="unit" list="produce-units" placeholder="Unit, e.g. litres, trays, kg" maxlength="30" required><datalist id="produce-units"><option value="litres"><option value="eggs"><option value="trays"><option value="kg"></datalist><input name="notes" placeholder="Notes (optional)"><button>Save produce record</button><button type="button" class="quiet" data-cancel-production hidden>Cancel edit</button></form><div id="production-feedback" aria-live="polite"></div></section><section class="panel"><h2>Recent produce</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Produce</th><th>Session</th><th>Produced</th><th>Sold</th><th>Damaged</th><th>Unit</th><th>Action</th></tr></thead><tbody>${data.records.length ? data.records.map((item) => `<tr><td>${escapeHtml(item.production_date)}</td><td>${escapeHtml(item.produce_name)}</td><td>${escapeHtml(item.production_session || '—')}</td><td>${item.quantity_produced}</td><td>${item.quantity_sold}</td><td>${item.quantity_damaged}</td><td>${escapeHtml(item.unit)}</td><td><button type="button" class="small-action" data-production-edit="${item.id}">Edit</button></td></tr>`).join('') : '<tr><td colspan="8" class="empty">No produce records yet.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('.module-grid').insertAdjacentHTML('beforebegin', renderProductionSummary(summary))
    bindProductionEditor(content, data.records, () => renderProductionModule())
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
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Inventory</h1><p class="muted">Track supplies and see what needs replenishing.</p></div><span class="health-pill">Stock control</span></header><section class="module-grid"><section class="panel"><h2>Add stock item</h2><form id="inventory-form" class="compact-form"><input name="name" placeholder="e.g. Dairy meal" required><select name="category"><option value="feed">Feed</option><option value="medicine">Medicine</option><option value="equipment">Equipment</option><option value="supplies">Supplies</option><option value="other">Other</option></select><input name="unit" placeholder="e.g. kg, litres or boxes" required><input name="quantityOnHand" type="number" min="0" step="0.01" placeholder="Quantity currently available" required><input name="reorderLevel" type="number" min="0" step="0.01" placeholder="Alert when stock reaches" required><button>Add item</button></form></section><section class="panel"><h2>Stock list</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th>Category</th><th>On hand</th><th>Reorder at</th></tr></thead><tbody>${data.items.length ? data.items.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.category)}</td><td>${item.quantity_on_hand} ${escapeHtml(item.unit)}</td><td>${item.reorder_level}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No stock items yet.</td></tr>'}</tbody></table></div></section></section>`
    const rows = content.querySelectorAll('.module-grid .panel:nth-child(2) tbody tr')
    data.items.forEach((item, index) => {
      if (Number(item.quantity_on_hand) <= Number(item.reorder_level) && rows[index]) {
        rows[index].classList.add('low-stock-row')
        rows[index].querySelector('td:last-child')?.insertAdjacentHTML('beforeend', '<small class="low-stock-label">Low stock</small>')
      }
    })
    const transactionPanel = document.createElement('section')
    transactionPanel.className = 'panel inventory-history'
    transactionPanel.innerHTML = `<h2>Record stock movement</h2><p class="muted">Use a positive quantity for stock in and a negative quantity for stock out. Adjustments may be positive or negative.</p><form id="inventory-transaction-form" class="compact-form"><select name="itemId" required><option value="">Choose item</option>${data.items.map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.name)} · ${Number(item.quantity_on_hand)} ${escapeHtml(item.unit)}</option>`).join('')}</select><select name="transactionType"><option value="in">Stock in</option><option value="out">Stock out</option><option value="adjustment">Adjustment</option></select><input name="quantityChange" type="number" step="0.01" placeholder="e.g. 20 or -5" required><input name="reason" maxlength="255" placeholder="e.g. Feed given to cattle" required><button>Save movement</button></form><h2>Transaction history</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Item</th><th>Type</th><th>Change</th><th>Reason</th></tr></thead><tbody>${transactionData.transactions.length ? transactionData.transactions.map((item) => `<tr><td>${escapeHtml(item.transaction_date)}</td><td>${escapeHtml(item.item_name)}</td><td>${escapeHtml(item.transaction_type)}</td><td>${Number(item.quantity_change).toLocaleString()}</td><td>${escapeHtml(item.reason || '—')}</td></tr>`).join('') : '<tr><td colspan="5" class="empty">No stock movements recorded.</td></tr>'}</tbody></table></div>`
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
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Sales & orders</h1><p class="muted">Create orders, record sales and track payments.</p></div><span class="health-pill">Admin access</span></header><section class="module-grid"><section class="panel"><h2>Create order</h2><form id="order-form" class="compact-form"><input name="customerName" placeholder="e.g. Mary Wanjiku" required><input name="customerPhone" placeholder="e.g. 0712 345 678"><select name="productId" required><option value="">Choose a listed product</option>${products.products.filter((item) => Number(item.available_quantity) > 0).map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.name)} · ${Number(item.available_quantity)} ${escapeHtml(item.unit)}</option>`).join('')}</select><input name="quantity" type="number" min=".01" step=".01" placeholder="e.g. 5 litres" required><input name="unitPrice" type="number" min="0" step=".01" placeholder="Price per unit, e.g. 75"><button>Create quote</button></form><h2>Orders</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Total</th></tr></thead><tbody>${orders.orders.length ? orders.orders.map((item) => `<tr><td>#${Number(item.id)}</td><td>${escapeHtml(item.ordered_at)}</td><td>${escapeHtml(item.customer)}</td><td>${escapeHtml(item.status)}</td><td>${item.total_amount}</td></tr>`).join('') : '<tr><td colspan="5" class="empty">No orders yet.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Record sale</h2><form id="sale-form" class="compact-form"><input name="description"     placeholder="e.g. 10 litres sold at market"><input name="quantity" type="number" min=".01" step=".01"     placeholder="e.g. 10"><input name="unit"     placeholder="e.g. litres or trays"><input name="unitPrice" type="number" min="0" step=".01"     placeholder="KES per unit"><input name="soldAt" type="datetime-local" required><button>Save sale</button></form><h2>Payments</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Method</th><th>Status</th><th>Amount</th></tr></thead><tbody>${payments.payments.length ? payments.payments.map((item) => `<tr><td>#${item.order_id}</td><td>${item.method}</td><td>${item.status}</td><td>${item.amount}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No payments yet.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('#order-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createOrder(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() })
    content.querySelector('#sale-form').addEventListener('submit', async (event) => { event.preventDefault(); await api.createSale(Object.fromEntries(new FormData(event.target))); await renderCommerceModule() })
    const commerceTools = document.createElement('section')
    commerceTools.className = 'module-grid commerce-tools'
    commerceTools.innerHTML = `<section class="panel"><h2>Product catalogue</h2><form id="product-form" class="compact-form"><input name="name"     placeholder="e.g. Fresh milk" maxlength="140" required><select name="productType"><option value="produce">Produce</option><option value="livestock">Livestock</option><option value="other">Other</option></select><select name="productionKind"><option value="">Unlinked produce</option><option value="milk">Milk</option><option value="eggs">Eggs</option><option value="wool">Wool</option></select><select name="species"><option value="">Species, for livestock</option><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="sheep">Sheep</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="other">Other</option></select><input name="unit"     placeholder="e.g. litres or head" required><input name="availableQuantity" type="number" min="0" step="0.01"     placeholder="e.g. 100" value="0"><input name="unitPrice" type="number" min="0" step="0.01"     placeholder="Price per unit (KES)" required><button>Add listing</button></form><div class="table-wrap"><table><thead><tr><th>Listing</th><th>Type</th><th>Available</th><th>Price</th></tr></thead><tbody>${products.products.length ? products.products.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.product_type)}</td><td>${Number(item.available_quantity).toLocaleString()} ${escapeHtml(item.unit)}</td><td>KES ${Math.round(Number(item.unit_price)).toLocaleString()}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No active listings.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Expenses</h2><form id="expense-form" class="compact-form"><select name="category"><option value="feed">Feed</option><option value="veterinary">Veterinary</option><option value="labour">Labour</option><option value="equipment">Equipment</option><option value="utilities">Utilities</option><option value="transport">Transport</option><option value="maintenance">Maintenance</option><option value="other">Other</option></select><input name="description"     placeholder="e.g. Dairy meal delivered" required><input name="amount" type="number" min="0.01" step="0.01"     placeholder="Amount in KES" required><input name="expenseDate" type="date" value="${nairobiDate()}" required><button>Record expense</button></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr></thead><tbody>${expenses.expenses.length ? expenses.expenses.map((item) => `<tr><td>${escapeHtml(item.expense_date)}</td><td>${escapeHtml(item.category)}</td><td>${escapeHtml(item.description)}</td><td>KES ${Math.round(Number(item.amount)).toLocaleString()}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No expenses recorded.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Record payment</h2><form id="payment-form" class="compact-form"><select name="orderId" required><option value="">Choose order</option>${orders.orders.filter((item) => !['cancelled', 'fulfilled'].includes(item.status)).map((item) => `<option value="${Number(item.id)}">#${Number(item.id)} · ${escapeHtml(item.customer)} · KES ${Math.round(Number(item.total_amount)).toLocaleString()}</option>`).join('')}</select><select name="method"><option value="cash">Cash</option><option value="mpesa">M-Pesa (simulate locally)</option><option value="bank">Bank</option><option value="other">Other</option></select><input name="transactionReference" placeholder="Receipt or reference (cash/bank/other)"><button>Record payment</button></form><p class="muted">M-PESA is simulated locally. No Daraja request or real charge is made.</p></section></section>`
    content.append(commerceTools)
    const commerceTables = content.querySelectorAll('.module-grid > .panel table')
    commerceTables[0].dataset.bulkOrders = 'true'
    orders.orders.forEach((order, index) => {
      const row = commerceTables[0].tBodies[0].rows[index]
      if (!row) return
      row.dataset.orderId = String(order.id)
      row.dataset.orderStatus = String(order.status)
    })
    enableSortableTable(commerceTables[0], ['number', 'text', 'text', 'text', 'number'])
    enableSortableTable(commerceTables[1], ['number', 'text', 'text', 'number'])
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

async function renderFarmTrendsModule() {
  const content = app.querySelector('.content')
  content.innerHTML = `<header><div><p class="kicker">AGRICULTURAL INTELLIGENCE</p><h1>Farm trends</h1><p class="muted">Separate live, synthetic and actual farm information.</p></div><button id="trends-refresh" class="secondary-action" type="button">Refresh now</button></header><div id="trends-content" aria-live="polite"><p class="muted">Loading agricultural intelligence...</p></div>`

  let scope = 'kenya'
  let historyRange = '7d'
  let data = null

  const money = (value) => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })
  const formatDate = (value) => value ? new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z')).toLocaleString() : 'Not available'

  const renderLive = () => {
    const group = data?.[scope] || data?.regional || {}
    const articles = group.articles || []
    const rows = articles.map((article) => {
      let url = '#'
      try {
        const parsed = new URL(article.url)
        if (parsed.protocol === 'https:') url = parsed.href
      } catch {}
      return `<article class="trend-article"><div><strong>${escapeHtml(article.headline || article.title)}</strong><small>${escapeHtml(article.sourceDomain || article.domain || 'Source not available')} · ${article.category ? escapeHtml(article.category) : 'general'} · ${escapeHtml(article.countryCode || '')}</small></div><div><small>Published: ${escapeHtml(article.publishedAt ? formatDate(article.publishedAt) : 'Date unavailable')}</small><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">Open original</a></div></article>`
    }).join('')
    return `<section class="panel trend-section"><h2>Live trends</h2><p class="muted">EXTERNAL LIVE DATA: GDELT agriculture and food-security reporting. Publication and retrieval times are shown separately.</p>${rows || `<p class="empty">${group.unavailable ? 'Live feed is temporarily unavailable.' : 'No current agricultural reports found.'}</p>`}</section>`
  }

  const renderSynthetic = () => {
    const scopeData = data?.synthetic?.scopes?.[scope] || { categories: {} }
    const cards = Object.entries(scopeData.categories || {}).flatMap(([category, metrics]) =>
      Object.values(metrics).map((metric) => `<div class="synthetic-metric"><div><small>${escapeHtml(category.replace('_', ' '))}</small><strong>${escapeHtml(metric.label)}</strong></div><div><strong>${money(metric.metricValue)} ${escapeHtml(metric.unit)}</strong><small class="${Number(metric.changePercent) >= 0 ? 'trend-up' : 'trend-down'}">${Number(metric.changePercent) >= 0 ? '+' : ''}${Number(metric.changePercent).toFixed(2)}%</small><span class="simulated-badge">SIMULATED</span></div></div>`)).join('')
    const events = (data?.synthetic?.events || []).filter((event) => event.geographicScope === scope || event.geographicScope === 'east_africa' && scope === 'kenya').map((event) => `<li><strong>${escapeHtml(event.name)}</strong><p>${escapeHtml(event.description)}</p><small>SIMULATED EVENT — NOT REAL · ${escapeHtml(event.geographicScope)} · ${escapeHtml(event.startPeriod)} · ${escapeHtml(event.durationHours)} hours</small></li>`).join('')
    return `<section class="panel trend-section"><h2>Simulated trends</h2><p class="forecast-warning"><strong>SIMULATED DATA — NOT REAL MARKET INFORMATION</strong></p><div class="synthetic-grid">${cards || '<p class="empty">No synthetic indicators are available yet.</p>'}</div><h3>Active simulated events</h3><ul class="simulated-events">${events || '<li class="empty">No active simulated events.</li>'}</ul></section>`
  }

  const renderFarm = () => {
    const farm = data?.actualFarm?.metrics
    if (!farm) return `<section class="panel trend-section"><h2>Actual farm performance</h2><p class="empty">No actual farm records are available.</p></section>`
    const weather = farm.weather
    return `<section class="panel trend-section"><h2>Actual farm performance</h2><p class="muted">ACTUAL FARM DATA: measured operational records. These values are not market forecasts.</p><div class="stats trends-farm-stats"><div><small>Milk today</small><strong>${money(farm.milk.todayTotal)} L</strong><small>Morning ${money(farm.milk.morning)} · Evening ${money(farm.milk.evening)}</small></div><div><small>Eggs today</small><strong>${money(farm.eggs.todayTotal)} trays</strong></div><div><small>Active livestock</small><strong>${Number(farm.livestock.activeCount)}</strong></div><div><small>Inventory shortages</small><strong>${Number(farm.inventory.shortagesCount)}</strong></div><div><small>Sales this month</small><strong>KES ${money(farm.finance.salesThisMonth)}</strong></div><div><small>Expenses this month</small><strong>KES ${money(farm.finance.expensesThisMonth)}</strong></div></div><div class="weather-grid"><section><h3>Weather & climate</h3>${weather ? `<p>Latest observation: ${escapeHtml(weather.observation_date)}</p><p>Rainfall: ${money(weather.rain_mm)} mm · Temperature: ${money(weather.temp_c)} °C</p><small>Source: ${escapeHtml(weather.source)} · Recorded/fetched separately from forecasts.</small>` : '<p class="empty">No weather observations recorded.</p>'}</section><section><h3>Forecast caveat</h3><p class="muted">Historical observations, forecasts and synthetic weather scenarios must be kept separate. No synthetic weather scenario is presented here.</p></section></div></section>`
  }

  const renderStatus = () => {
    const status = data?.status || {}
    const log = status.lastLog
    return `<section class="panel trend-status"><h2>Trend status</h2><div class="trend-status-grid"><div><small>Current reporting window</small><strong>${escapeHtml(status.currentPeriod || 'Calculating')}</strong></div><div><small>Next scheduled refresh</small><strong>${escapeHtml(status.nextScheduledRefresh ? formatDate(status.nextScheduledRefresh) : 'Not available')}</strong></div><div><small>Last job</small><strong>${escapeHtml(log ? formatDate(log.completed_at) : 'No refresh recorded')}</strong></div><div><small>Live / synthetic / farm</small><strong>${escapeHtml(log?.live_status || 'unknown')} / ${escapeHtml(log?.synthetic_status || 'unknown')} / ${escapeHtml(log?.actual_farm_status || 'unknown')}</strong></div></div><small>Missed periods: ${Number(status.missedPeriodsCount || 0)} · timezone: Africa/Nairobi</small></section>`
  }

  const renderHistory = async () => {
    try {
      const [history, metrics] = await Promise.all([
        api.trendsHistory({ scope, type: 'all', limit: historyRange === '1d' ? 4 : historyRange === '7d' ? 28 : 100 }),
        api.trendsMetrics({ scope, limit: 200 }),
      ])
      const rows = (history.history || []).slice(0, 12).map((item) => `<tr><td>${escapeHtml(item.trend_type)}</td><td>${escapeHtml(item.geographic_scope)}</td><td>${escapeHtml(item.period_start)}</td><td>${escapeHtml(item.period_end)}</td><td>${escapeHtml(item.status)}</td></tr>`).join('')
      const series = (metrics.metrics || []).slice(0, 4)
      const charts = series.map((metric) => {
        const points = metric.points || []
        if (!points.length) return ''
        const values = points.map((point) => Number(point.value))
        const min = Math.min(...values)
        const max = Math.max(...values)
        const width = 480
        const height = 120
        const coords = values.map((value, index) => {
          const x = values.length === 1 ? width / 2 : 20 + (index * (width - 40) / (values.length - 1))
          const y = max === min ? height / 2 : 15 + ((max - value) * (height - 30) / (max - min))
          return `${x.toFixed(1)},${y.toFixed(1)}`
        }).join(' ')
        return `<figure class="trend-chart"><figcaption>${escapeHtml(metric.metricName)} · SIMULATED</figcaption><svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(metric.metricName)} synthetic history"><polyline points="${coords}" fill="none" stroke="#d66f3d" stroke-width="3"></polyline></svg></figure>`
      }).join('')
      return `<section class="panel trend-section"><h2>Trend history</h2><label>Reporting period<select id="trend-history-range"><option value="1d">Previous 24 hours</option><option value="7d" selected>Previous 7 days</option><option value="30d">Previous 30 days</option><option value="12m">Previous 12 months</option></select></label><div class="trend-history-charts">${charts || '<p class="empty">No historical synthetic metrics yet.</p>'}</div><div class="table-wrap"><table><thead><tr><th>Type</th><th>Scope</th><th>Period start</th><th>Period end</th><th>Status</th></tr></thead><tbody>${rows || '<tr><td colspan="5" class="empty">No snapshots found for this period.</td></tr>'}</tbody></table></div></section>`
    } catch (error) {
      return `<section class="panel trend-section"><h2>Trend history</h2>${message(error.message, true)}</section>`
    }
  }

  const load = async () => {
    content.querySelector('#trends-content').innerHTML = '<p class="muted">Loading agricultural intelligence...</p>'
    try {
      data = await api.trends()
      content.querySelector('#trends-content').innerHTML = `<nav class="trend-tabs" aria-label="Geographic scope"><button type="button" data-trend-scope="kenya" class="${scope === 'kenya' ? 'active' : ''}">Kenya</button><button type="button" data-trend-scope="east_africa" class="${scope === 'east_africa' ? 'active' : ''}">East Africa</button><button type="button" data-trend-scope="global" class="${scope === 'global' ? 'active' : ''}">Global</button></nav>${renderStatus()}${renderLive()}${renderSynthetic()}${await renderHistory()}${renderFarm()}`
      content.querySelectorAll('[data-trend-scope]').forEach((button) => button.addEventListener('click', () => {
        scope = button.dataset.trendScope
        load()
      }))
      content.querySelector('#trend-history-range')?.addEventListener('change', (event) => {
        historyRange = event.target.value
        load()
      })
    } catch (error) {
      content.querySelector('#trends-content').innerHTML = message(error.message, true)
    }
  }

  content.querySelector('#trends-refresh').addEventListener('click', async (event) => {
    event.currentTarget.disabled = true
    try {
      await api.refreshTrends()
      await load()
    } catch (error) {
      content.querySelector('#trends-content').insertAdjacentHTML('afterbegin', message(error.message, true))
    } finally {
      event.currentTarget.disabled = false
    }
  })
  await load()
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

async function renderAssistantModule(target = app.querySelector('.content')) {
  const content = target
  const permissionData = await api.permissions()
  const rolePermissions = permissionData.permissions || permissionData.matrix?.[currentUser.role] || {}
  const canRunScenarios = Boolean(rolePermissions['scenarios.run']?.allowed)
  content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Farm assistant</h1><p class="muted">Advice from farm records and public agriculture signals.</p></div><span class="health-pill" id="assistant-mode">Rule-based mode</span></header><div class="assistant-layout"><section class="panel assistant-panel"><div id="assistant-messages" class="assistant-messages"><p class="message success">Ask for advice, current agriculture trends, or a review of farm records.</p></div><form id="assistant-form" class="assistant-form"><input name="message" placeholder="Ask a farm question..." required><button>Ask assistant</button></form></section>${canRunScenarios ? `<section class="panel assistant-scenario"><h2>30-day yield scenario</h2><p class="muted">Estimate the effect of adding animals using recent recorded production.</p><form id="assistant-scenario-form"><label>Additional dairy cattle<input name="additionalCows" type="number" min="0" max="250" value="0" required></label><label>Additional laying hens<input name="additionalHens" type="number" min="0" max="250" value="0" required></label><button>Estimate yield</button></form><div id="assistant-scenario-result" aria-live="polite"></div><p class="muted scenario-limitation">Estimates assume added animals perform like the current group. They do not predict feed, health, weather or management effects.</p></section>` : ''}</div>`
  const starterPrompts = document.createElement('div'); starterPrompts.className = 'assistant-suggestions'
  for (const text of ['Show milk production for the last 30 days', 'Compare sales and expenses this year', 'How is my farm doing?']) {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary-action'; button.textContent = text
    button.addEventListener('click', () => { const form = content.querySelector('#assistant-form'); form.elements.namedItem('message').value = text; form.requestSubmit() })
    starterPrompts.append(button)
  }
  content.querySelector('#assistant-messages').append(starterPrompts)
  content.querySelector('#assistant-form').addEventListener('submit', async (event) => {
    event.preventDefault()
    const form = event.target
    const input = form.querySelector('input')
    const messages = content.querySelector('#assistant-messages')
    const question = input.value
    messages.insertAdjacentHTML('beforeend', `<p class="assistant-question">${escapeHtml(question)}</p>`)
    input.value = ''
    try { const result = await api.assistantChat(question); content.querySelector('#assistant-mode').textContent = result.mode || 'Analytics'; appendAssistantResponse(messages, result) } catch (error) { messages.insertAdjacentHTML('beforeend', `<p class="message danger">${escapeHtml(error.message)}</p>`) }
  })
  content.querySelector('#assistant-scenario-form')?.addEventListener('submit', async (event) => {
    event.preventDefault()
    const result = content.querySelector('#assistant-scenario-result')
    result.innerHTML = '<p class="muted">Calculating from recorded production...</p>'
    try {
      const forecast = await api.assistantScenario(Object.fromEntries(new FormData(event.target)))
      const yieldRows = forecast.scenarios.map((scenario) => {
        const label = scenario.product === 'milk' ? 'Milk' : 'Eggs'
        if (scenario.status === 'insufficient_data') return `<p class="message forecast-warning">${label}: ${Number(scenario.recordingDays)} consecutive days available; at least 21 are needed.</p>`
        if (scenario.status === 'no_active_animals') return `<p class="message forecast-warning">${label}: no active animals are recorded for this production type.</p>`
        return `<div class="scenario-result"><strong>${label}: ${Number(scenario.estimatedTotal).toLocaleString()} ${escapeHtml(scenario.unit)} over 30 days</strong><span>Baseline ${Number(scenario.baselineTotal).toLocaleString()} ${escapeHtml(scenario.unit)} · change +${Number(scenario.change).toLocaleString()}</span></div>${scenario.includesSimulatedData ? '<p class="message forecast-warning">This estimate includes simulated demonstration records.</p>' : ''}`
      }).join('')
      const feedRows = forecast.feedUse.map((item) => item.status === 'ready'
        ? `<div class="scenario-result"><strong>${escapeHtml(item.item)}</strong><span>Recorded 30-day stock-out: ${Number(item.recordedUse30Days).toLocaleString()} ${escapeHtml(item.unit)} · estimated with added animals: ${Number(item.estimatedUseWithAddedAnimals).toLocaleString()} ${escapeHtml(item.unit)}</span></div>`
        : `<p class="message forecast-warning">${escapeHtml(item.item)}: no recent feed stock-out records for a 30-day estimate.</p>`).join('')
      result.innerHTML = `${yieldRows}<h3>Feed-use estimate</h3>${feedRows || '<p class="muted">Add feed inventory items and record stock-outs to estimate feed use.</p>'}<p class="muted scenario-limitation">${escapeHtml(forecast.limitations)}</p>`
    } catch (error) { result.innerHTML = message(error.message, true) }
  })
}

function appendAssistantResponse(messages, result) {
  const bubble = document.createElement('article')
  bubble.className = 'assistant-response message success'
  const answer = document.createElement('p')
  answer.className = 'assistant-advice'
  answer.textContent = result.answer || ''
  bubble.append(answer)
  const refs = []
  const palette = ['#176b55', '#c45120', '#315f9a', '#9b4c18', '#6a4c93', '#4f7c42', '#9d334a', '#147a8a']
  for (const chartData of result.charts || []) {
    const card = document.createElement('section'); card.className = 'assistant-chart-card'
    const title = document.createElement('h3'); title.textContent = chartData.title
    const unit = document.createElement('p'); unit.className = 'assistant-chart-unit'; unit.textContent = `Unit: ${chartData.unit || 'count'}`
    const stage = document.createElement('div'); stage.className = 'assistant-chart-canvas'
    const canvas = document.createElement('canvas'); canvas.setAttribute('role', 'img'); canvas.setAttribute('aria-label', `${chartData.title}. ${chartData.labels.join(', ')}`); stage.append(canvas)
    const actions = document.createElement('div'); actions.className = 'assistant-chart-actions'
    const png = document.createElement('button'); png.type = 'button'; png.className = 'secondary-action'; png.textContent = 'Download PNG'
    const csv = document.createElement('button'); csv.type = 'button'; csv.className = 'secondary-action'; csv.textContent = 'Download data (CSV)'
    actions.append(png, csv); card.append(title, unit, stage, actions); bubble.append(card)
    const ink = getComputedStyle(document.documentElement).getPropertyValue('--chart-ink').trim() || '#19352d'
    const datasets = (chartData.datasets || []).map((set, i) => ({ label: set.label, data: set.data, borderColor: palette[i % palette.length], backgroundColor: chartData.type === 'pie' ? set.data.map((_, j) => palette[j % palette.length]) : `${palette[i % palette.length]}55`, borderWidth: 2, tension: .25, fill: chartData.type === 'line' }))
    const chart = new Chart(canvas, { type: chartData.type, data: { labels: chartData.labels, datasets }, options: { responsive: true, maintainAspectRatio: false, color: ink, plugins: { legend: { display: chartData.type === 'pie' || datasets.length > 1, labels: { color: ink, usePointStyle: true } }, tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${context.formattedValue} ${chartData.unit || ''}` } } }, scales: chartData.type === 'pie' ? {} : { x: { ticks: { color: ink, maxRotation: 45 }, grid: { color: `${ink}18` } }, y: { beginAtZero: true, ticks: { color: ink }, grid: { color: `${ink}18` }, title: { display: Boolean(chartData.unit), text: chartData.unit, color: ink } } } } })
    refs.push({ chartData, canvas, chart })
    const filename = String(chartData.title || 'chart').toLowerCase().replace(/[^a-z0-9]+/g, '-')
    png.addEventListener('click', () => canvas.toBlob((blob) => { if (blob) downloadAssistantFile(blob, `${filename}.png`) }, 'image/png'))
    csv.addEventListener('click', () => {
      const rows = [['period', ...(chartData.datasets || []).map((set) => `${set.label} (${chartData.unit || ''})`)]]
      chartData.labels.forEach((label, row) => rows.push([label, ...(chartData.datasets || []).map((set) => set.data[row] ?? '')]))
      const csvText = rows.map((line) => line.map((value) => `"${String(value).replace(/"/g, '""')}"`).join(',')).join('\r\n')
      downloadAssistantFile(new Blob([csvText], { type: 'text/csv;charset=utf-8' }), `${filename}.csv`)
    })
  }
  if (refs.length > 1) {
    const pdf = document.createElement('button'); pdf.type = 'button'; pdf.className = 'secondary-action assistant-pdf-action'; pdf.textContent = 'Download report (PDF)'
    pdf.addEventListener('click', () => printAssistantReport(result.answer || '', refs)); bubble.append(pdf)
  }
  if (Array.isArray(result.suggestions) && result.suggestions.length) {
    const suggestions = document.createElement('div'); suggestions.className = 'assistant-suggestions'
    result.suggestions.forEach((text) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary-action'; button.textContent = text; button.addEventListener('click', () => { const form = messages.parentElement.querySelector('#assistant-form'); form.elements.namedItem('message').value = text; form.requestSubmit() }); suggestions.append(button) })
    bubble.append(suggestions)
  }
  messages.append(bubble); messages.scrollTop = messages.scrollHeight
}

function downloadAssistantFile(blob, filename) {
  const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = filename; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1500)
}

function printAssistantReport(advice, charts) {
  const popup = window.open('', '_blank')
  if (!popup) return
  const sections = charts.map(({ chartData, canvas }) => `<section><h2>${escapeHtml(chartData.title)}</h2><p>Unit: ${escapeHtml(chartData.unit || 'count')}</p><img alt="${escapeHtml(chartData.title)} chart" src="${canvas.toDataURL('image/png')}"></section>`).join('')
  popup.document.write(`<!doctype html><html><head><title>LFMIS farm report</title><style>body{font:16px Arial,sans-serif;color:#19352d;max-width:900px;margin:36px auto;padding:0 24px}h1{font-size:30px}.advice{padding:16px;background:#edf2eb;border-left:4px solid #176b55;line-height:1.6}section{margin:32px 0;break-inside:avoid}img{width:100%;height:auto}@media print{body{margin:0 auto}}</style></head><body><h1>LFMIS farm report</h1><p class="advice">${escapeHtml(advice)}</p>${sections}<script>window.onload=()=>window.print()</script></body></html>`)
  popup.document.close()
}

async function renderLivestockModule() {
  const content=app.querySelector('.content'); try { const data=await api.animals(); content.innerHTML=`<header><div><p class="kicker">FARM MODULE</p><h1>Livestock register</h1><p class="muted">Register and review the herd.</p></div><span class="health-pill">${data.animals.length} animals</span></header><section class="panel module-panel"><form id="animal-module-form" class="compact-form"><input name="tagNumber"   placeholder="e.g. CAT-001" required><select name="species"><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="sheep">Sheep</option></select>  <input name="breed" placeholder="e.g. Friesian or Kienyeji"><select name="sex"><option value="female">Female</option><option value="male">Male</option></select><button>Add animal</button></form><div id="animal-module-feedback" aria-live="polite"></div><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Species</th><th>Breed</th><th>Status</th></tr></thead><tbody>${data.animals.map((a)=>`<tr><td>${escapeHtml(a.tag_number)}</td><td>${a.species}</td><td>${escapeHtml(a.breed||'—')}</td><td>${a.status}</td></tr>`).join('')||'<tr><td colspan="4" class="empty">No animals yet.</td></tr>'}</tbody></table></div></section>`; content.querySelector('form').addEventListener('submit',async(e)=>{e.preventDefault();try{await api.createAnimal(Object.fromEntries(new FormData(e.target)));await renderLivestockModule()}catch(error){content.querySelector('#animal-module-feedback').innerHTML=message(error.message,true)}}) } catch(error){content.innerHTML=message(error.message,true)}
}

async function renderHealthModule() {
  const content = app.querySelector('.content')
  const speciesNames = { cattle: 'Cattle', goat: 'Goat', sheep: 'Sheep', rabbit: 'Rabbit', chicken: 'Chicken', other: 'Other' }
  try {
    const [animals, data] = await Promise.all([api.animals(), api.healthRecords()])
    const animalTypes = [...new Set(animals.animals.map((animal) => animal.species))].sort()
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>Animal health</h1><p class="muted">Record treatment, vaccination and follow-ups.</p></div><span class="health-pill" id="health-record-count">${data.records.length} records</span></header><section class="panel module-panel"><form id="health-module-form" class="compact-form"><select name="animalId" required><option value="">Animal</option>${animals.animals.map((animal) => `<option value="${Number(animal.id)}">${escapeHtml(speciesNames[animal.species] || animal.species)} · ${escapeHtml(animal.tag_number)}</option>`).join('')}</select><select name="recordType"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option></select><input name="description"     placeholder="e.g. Vaccinated or treatment given" required><input name="recordDate" type="date" required><button>Save health record</button></form><div class="health-record-toolbar"><label for="health-species-filter">Animal type<select id="health-species-filter"><option value="">All animal types</option>${animalTypes.map((species) => `<option value="${escapeHtml(species)}">${escapeHtml(speciesNames[species] || species)}</option>`).join('')}</select></label></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal type</th><th>Animal</th><th>Record type</th><th>Description</th></tr></thead><tbody id="health-record-rows"></tbody></table></div></section>`

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
  const content = app.querySelector('.content')
  try {
    const data = await api.users()
    content.innerHTML = `<header><div><p class="kicker">FARM MODULE</p><h1>User accounts</h1><p class="muted">Create accounts and control access.</p></div><span class="health-pill">Admin access</span></header><section class="module-grid"><section class="panel"><h2>Create account</h2><form id="user-module-form" class="compact-form">    <input name="fullName" placeholder="e.g. Alex Kamau" required>    <input name="username" placeholder="e.g. alex.kamau" required>    <input name="email" type="email" placeholder="name@example.com" required>    <input name="password" type="password" minlength="12" placeholder="Temporary password, at least 12 characters" required><select name="role"><option value="worker">Worker</option><option value="veterinarian">Veterinarian</option><option value="buyer">Buyer</option><option value="customer">Customer</option></select><button>Create account</button></form></section><section class="panel"><h2>Accounts</h2><p class="muted">Removing an account revokes sign-in access; farm records are retained.</p><div class="table-wrap"><table><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>${data.users.length ? data.users.map((user) => `<tr><td>${escapeHtml(user.full_name)}</td><td>${escapeHtml(user.role)}</td><td>${user.is_active ? 'Active' : 'Inactive'}</td><td><button class="danger-action" type="button" data-remove-user="${user.id}" ${Number(user.id) === Number(currentUser.id) ? 'disabled title="You cannot remove your own account"' : ''}>Remove</button></td></tr>`).join('') : '<tr><td colspan="4" class="empty">No user accounts.</td></tr>'}</tbody></table></div></section></section>`
    content.querySelector('#user-module-form').addEventListener('submit', async (event) => {
      event.preventDefault()
      try {
        await api.createUser(Object.fromEntries(new FormData(event.target)))
        await renderUsersModule()
      } catch (error) { content.prepend(Object.assign(document.createElement('div'), { innerHTML: message(error.message, true) })) }
    })
    content.querySelectorAll('[data-remove-user]').forEach((button) => button.addEventListener('click', async () => {
      const user = data.users.find((item) => Number(item.id) === Number(button.dataset.removeUser))
      if (!user || !window.confirm(`Remove ${user.full_name}'s account? They will lose sign-in access. Farm records will be retained.`)) return
      button.disabled = true
      try {
        await api.deleteUser(Number(user.id))
        await renderUsersModule()
        content.prepend(Object.assign(document.createElement('div'), { innerHTML: message(`${user.full_name}'s account was removed. Farm records were retained.`) }))
      } catch (error) {
        button.disabled = false
        content.prepend(Object.assign(document.createElement('div'), { innerHTML: message(error.message, true) }))
      }
    }))
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderPermissionsModule() {
  const content = app.querySelector('.content')
  try {
    const { matrix } = await api.permissions()
    const roleForms = Object.entries(matrix).filter(([role]) => role !== 'admin').map(([role, permissions]) => `<section class="panel permission-role"><h2>${escapeHtml(role)}</h2><form data-permission-role="${escapeHtml(role)}"><div class="permission-list">${Object.entries(permissions).map(([code, permission]) => `<label class="permission-item"><input type="checkbox" name="permission" value="${escapeHtml(code)}" ${permission.allowed ? 'checked' : ''}><span>${escapeHtml(permission.label)}</span></label>`).join('')}</div><button type="submit">Save ${escapeHtml(role)} access</button></form></section>`).join('')
    content.innerHTML = `<header><div><p class="kicker">ADMINISTRATION</p><h1>Role permissions</h1><p class="muted">Changes take effect on the next API request. Administrator access is fixed to prevent lockout.</p></div><span class="health-pill">${Object.keys(matrix).length - 1} configurable roles</span></header><div class="permissions-grid">${roleForms}</div>`
    content.querySelectorAll('[data-permission-role]').forEach((form) => form.addEventListener('submit', async (event) => {
      event.preventDefault()
      const selected = new FormData(form).getAll('permission')
      const permissions = Object.fromEntries(selected.map((code) => [code, true]))
      try {
        await api.updateRolePermissions(form.dataset.permissionRole, permissions)
        await renderPermissionsModule()
      } catch (error) { content.prepend(Object.assign(document.createElement('div'), { innerHTML: message(error.message, true) })) }
    }))
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderAuditModule(filters = {}) {
  const content = app.querySelector('.content')
  try {
    const [auditData, userData] = await Promise.all([api.auditLogs(filters), api.users()])
    const users = userData.users.map((user) => `<option value="${Number(user.id)}" ${String(filters.userId || '') === String(user.id) ? 'selected' : ''}>${escapeHtml(user.full_name)} · ${escapeHtml(user.role)}</option>`).join('')
    const rows = auditData.logs.map((log) => {
      let details = log.details || ''
      try { details = JSON.stringify(JSON.parse(details), null, 2) } catch {}
      return `<tr><td>${escapeHtml(log.created_at)}</td><td>${escapeHtml(log.user_name || 'System/unknown')}</td><td>${escapeHtml(log.action)}</td><td>${escapeHtml(log.entity_type)}${log.entity_id ? ` #${Number(log.entity_id)}` : ''}</td><td><pre>${escapeHtml(details)}</pre></td><td>${escapeHtml(log.ip_address || '—')}</td></tr>`
    }).join('')
    content.innerHTML = `<header><div><p class="kicker">ADMINISTRATION</p><h1>Audit log</h1><p class="muted">Review sign-ins, permission changes, account actions, payments, orders and exports.</p></div><div class="button-row"><button id="audit-export" class="secondary-action">Export farm data</button><button id="backup-now">Back up now</button></div></header><section class="panel"><form id="audit-filter-form" class="audit-filters"><label>User<select name="userId"><option value="">All users</option>${users}</select></label><label>Action    <input name="action" placeholder="e.g. sign_in_succeeded" value="${escapeHtml(filters.action || '')}" maxlength="40"></label><label>From<input type="date" name="from" value="${escapeHtml(filters.from || '')}"></label><label>To<input type="date" name="to" value="${escapeHtml(filters.to || '')}"></label><button>Filter</button></form><div id="audit-notice" aria-live="polite"></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP address</th></tr></thead><tbody>${rows || '<tr><td colspan="6" class="empty">No audit entries match these filters.</td></tr>'}</tbody></table></div></section>`
    content.querySelector('#audit-filter-form').addEventListener('submit', (event) => {
      event.preventDefault()
      renderAuditModule(Object.fromEntries(new FormData(event.target)))
    })
    content.querySelector('#audit-export').addEventListener('click', async (event) => {
      const button = event.currentTarget
      button.disabled = true
      try {
        const result = await api.exportData()
        const blob = new Blob([JSON.stringify(result, null, 2)], { type: 'application/json' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `lfmis-farm-export-${nairobiDate()}.json`
        link.click()
        URL.revokeObjectURL(url)
        content.querySelector('#audit-notice').innerHTML = message('Farm data export downloaded.')
      } catch (error) {
        content.querySelector('#audit-notice').innerHTML = message(error.message, true)
      } finally {
        button.disabled = false
      }
    })
    content.querySelector('#backup-now').addEventListener('click', async (event) => {
      const button = event.currentTarget
      button.disabled = true
      try {
        const result = await api.createBackup()
        content.querySelector('#audit-notice').innerHTML = message(`Backup created on the server: ${result.backup.filename}`)
      } catch (error) {
        content.querySelector('#audit-notice').innerHTML = message(error.message, true)
      } finally {
        button.disabled = false
      }
    })
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderRightsModule(target = app.querySelector('.content')) {
  const content = target
  try {
    const data = await api.permissions()
    const permissions = data.permissions || data.matrix?.[data.role] || {}
    const grants = Object.values(permissions).filter((permission) => permission.allowed)
    content.innerHTML = `<header><div><p class="kicker">ACCOUNT & DATA</p><h1>Your rights</h1><p class="muted">Access is based on your assigned role: ${escapeHtml(data.role)}.</p></div><span class="health-pill">${grants.length} granted actions</span></header><section class="panel rights-panel"><h2>Your access</h2><ul class="rights-permissions">${grants.map((permission) => `<li>${escapeHtml(permission.label)}</li>`).join('') || '<li>No module permissions are currently assigned.</li>'}</ul><h2>Personal data</h2><div class="rights-copy"><p>You may access records made available to your role and ask the farm administrator to correct your account details or a farm record.</p><p>You may request a copy of your personal account data or ask for your account to be deleted or deactivated. Some transaction and audit records may need to be retained for farm operations or legal obligations; the administrator can explain what applies.</p><p>Your sign-in email address is used for security alerts. Contact the farm administrator to update it or discuss a data request.</p></div></section>`
  } catch (error) { content.innerHTML = message(error.message, true) }
}

async function renderFeedbackModule() {
  const content=app.querySelector('.content'); try { const data=await api.feedback(); content.innerHTML=`<header><div><p class="kicker">FARM MODULE</p><h1>Feedback</h1><p class="muted">Review customer feedback, complaints and suggestions.</p></div><span class="health-pill">${data.feedback.length} messages</span></header><section class="panel module-panel"><div class="table-wrap"><table><thead><tr><th>Date</th><th>From</th><th>Type</th><th>Message</th><th>Status</th><th></th></tr></thead><tbody>${data.feedback.map((f)=>`<tr><td>${f.created_at}</td><td>${escapeHtml(f.user_name||'Customer')}</td><td>${f.category}</td><td>${escapeHtml(f.message)}</td><td>${f.status}</td><td>${f.status==='open'?`<button class="small-action" data-resolve="${f.id}">Resolve</button>`:''}</td></tr>`).join('')||'<tr><td colspan="6" class="empty">No feedback yet.</td></tr>'}</tbody></table></div></section>`; content.querySelectorAll('[data-resolve]').forEach((b)=>b.addEventListener('click',async()=>{await api.resolveFeedback(b.dataset.resolve);await renderFeedbackModule()})) } catch(error){content.innerHTML=message(error.message,true)}
}

async function renderRoleWorkspace(initialModule) {
  const workspaces = {
    worker: { title: 'Daily operations', description: 'Production, farm tasks and stock movements.', modules: ['Home', 'Production', 'Activities', 'Inventory', 'Assistant', 'Your rights'] },
    veterinarian: { title: 'Animal health', description: 'Livestock records, treatment history and follow-up care.', modules: ['Home', 'Livestock', 'Health records', 'Due follow-ups', 'Assistant', 'Your rights'] },
    customer: { title: 'Farm shop', description: 'Browse farm produce, place orders and follow up on payments.', modules: ['Farm shop', 'My orders', 'Feedback', 'Assistant', 'Your rights'] },
    buyer: { title: 'Livestock buying', description: 'Browse available livestock and track your requests.', modules: ['Livestock catalogue', 'My orders', 'Feedback', 'Assistant', 'Your rights'] },
  }
  const workspace = workspaces[currentUser.role]
  const permissionData = await api.permissions()
  const rolePermissions = permissionData.permissions || {}
  currentPermissions = rolePermissions
  const modulePermissions = {
    Home: [],
    Production: ['production.view'], Activities: ['activities.view'], Inventory: ['inventory.view'],
    Livestock: ['animals.view'], 'Health records': ['health.view'], 'Due follow-ups': ['health.view'],
    'Farm shop': ['products.view'], 'Livestock catalogue': ['products.view'], 'My orders': ['orders.view'],
    Feedback: ['feedback.view', 'feedback.create'], Assistant: ['assistant.use'],
  }
  const visibleModules = workspace.modules.filter((item) => item === 'Home' || item === 'Your rights' || (modulePermissions[item] || []).some((permission) => rolePermissions[permission]?.allowed))
  const activeModule = visibleModules.includes(initialModule) ? initialModule : (visibleModules[0] || 'Your rights')
  const isLowLiteracyRole = ['worker', 'veterinarian'].includes(currentUser.role)
  const translatedModule = roleModuleLabel(activeModule)
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>${escapeHtml(isLowLiteracyRole ? roleCopy(workspace.title, currentUser.role === 'worker' ? 'Kazi za kila siku' : 'Afya ya wanyama') : workspace.title)}</h2><nav class="workspace-nav" aria-label="${escapeHtml(roleCopy('Workspace modules', 'Sehemu za kazi'))}">${visibleModules.map((item) => `<button class="workspace-nav-item ${item === activeModule ? 'active' : ''}" data-role-module="${escapeHtml(item)}">${escapeHtml(roleModuleLabel(item))}</button>`).join('')}</nav><div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="logout" class="quiet">${escapeHtml(roleCopy('Sign out', 'Toka'))}</button></aside><main class="content"><header><div><p class="kicker">${escapeHtml(roleCopy('YOUR WORKSPACE', 'SEHEMU YAKO'))}</p><h1>${escapeHtml(translatedModule)}</h1><p class="muted">${escapeHtml(roleCopy(workspace.description, currentUser.role === 'worker' ? 'Rekodi maziwa, mayai, kazi na stoo.' : 'Rekodi matibabu ya wanyama na miadi.'))}</p></div><div class="workspace-header-actions">${isLowLiteracyRole ? `<button id="role-language-toggle" class="secondary-action" type="button">${roleLanguage === 'en' ? 'Kiswahili' : 'English'}</button>` : ''}<span class="health-pill">${escapeHtml(roleCopy('Role protected', 'Akaunti salama'))}</span></div></header><div id="role-content"></div></main></div>`
  app.querySelector('#role-language-toggle')?.addEventListener('click', () => {
    roleLanguage = roleLanguage === 'en' ? 'sw' : 'en'
    renderRoleWorkspace(activeModule)
  })
  const accountSecurityButton = document.createElement('button')
  accountSecurityButton.id = 'account-security'
  accountSecurityButton.className = 'quiet'
  accountSecurityButton.textContent = roleCopy('Account security', 'Usalama wa akaunti')
  app.querySelector('aside .user-chip').before(accountSecurityButton)
  accountSecurityButton.addEventListener('click', renderAccountSecurity)
  installFixedSidebar()
  app.querySelector('#logout').addEventListener('click', async () => { await api.logout(); currentUser = null; renderLanding(app, () => renderAuth('login'), () => renderAuth('login')) })
  app.querySelectorAll('[data-role-module]').forEach((button) => button.addEventListener('click', () => renderRoleWorkspace(button.dataset.roleModule)))
  await renderRoleModule(activeModule)
}

async function renderRoleModule(module) {
  const content = app.querySelector('#role-content')
  const showError = (error) => { content.innerHTML = message(error.message, true) }
  if (module === 'Assistant') {
    await renderAssistantModule(content)
    return
  }
  if (module === 'Your rights') {
    await renderRightsModule(content)
    return
  }
  if (module === 'Home') {
    const actions = currentUser.role === 'worker'
      ? [['Production', '🥛', roleCopy('Record milk or eggs', 'Rekodi maziwa au mayai')], ['Activities', '📝', roleCopy('Record a task', 'Rekodi kazi')], ['Inventory', '📦', roleCopy('Check stock', 'Angalia stoo')]]
      : [['Health records', '🩺', roleCopy('Add animal care', 'Ongeza matibabu')], ['Due follow-ups', '⏰', roleCopy('Check follow-ups', 'Angalia miadi')], ['Livestock', '🐄', roleCopy('View animals', 'Tazama wanyama')]]
    content.innerHTML = `<section class="panel quick-actions-panel"><h2>${escapeHtml(roleCopy('Choose an action', 'Chagua kazi'))}</h2><div class="quick-action-grid">${actions.map(([moduleName, icon, label]) => `<button class="quick-action" type="button" data-quick-module="${moduleName}"><span aria-hidden="true">${icon}</span><strong>${escapeHtml(label)}</strong></button>`).join('')}</div></section>`
    content.querySelectorAll('[data-quick-module]').forEach((button) => button.addEventListener('click', () => renderRoleWorkspace(button.dataset.quickModule)))
    return
  }
  try {
    if (module === 'Production') {
      const [data, summary, animalData] = await Promise.all([api.production(), api.productionSummary(), api.animals()])
      const canRecord = Boolean(currentPermissions['production.record']?.allowed)
      content.innerHTML = `<section class="module-grid">${canRecord ? `<section class="panel"><h2>${escapeHtml(roleCopy('Record daily produce', 'Rekodi mazao ya leo'))}</h2><form id="role-production-form" class="compact-form"><select name="produceName" aria-label="${escapeHtml(roleCopy('Produce', 'Mazao'))}"><option value="Milk">${escapeHtml(roleCopy('Milk', 'Maziwa'))}</option><option value="Eggs">${escapeHtml(roleCopy('Eggs', 'Mayai'))}</option></select><input name="productionDate" type="date" aria-label="${escapeHtml(roleCopy('Production date', 'Tarehe'))}" value="${nairobiDate()}" required><select name="session" aria-label="${escapeHtml(roleCopy('Milking session', 'Zamu'))}"><option value="morning">${escapeHtml(roleCopy('Morning', 'Asubuhi'))}</option><option value="evening">${escapeHtml(roleCopy('Evening', 'Jioni'))}</option><option value="">${escapeHtml(roleCopy('No session', 'Hakuna zamu'))}</option></select><select name="animalId"><option value="">Animal (milk only, optional)</option>${animalData.animals.filter((animal) => ['cattle', 'goat'].includes(animal.species) && animal.status === 'active').map((animal) => `<option value="${animal.id}">${escapeHtml(animal.tag_number)}</option>`).join('')}</select><input name="quantityProduced" type="number" min="0" step="0.01" placeholder="${escapeHtml(roleCopy('Quantity produced', 'Kiasi kilichozalishwa'))}" required><select name="unit" aria-label="${escapeHtml(roleCopy('Unit', 'Kipimo'))}"><option value="litres">${escapeHtml(roleCopy('Litres', 'Lita'))}</option><option value="trays">${escapeHtml(roleCopy('Trays', 'Trei'))}</option><option value="eggs">${escapeHtml(roleCopy('Eggs', 'Mayai'))}</option></select><input name="quantitySold" type="number" min="0" step="0.01" value="0" aria-label="${escapeHtml(roleCopy('Quantity sold', 'Kiasi kilichouzwa'))}" required><input name="quantityDamaged" type="number" min="0" step="0.01" value="0" aria-label="${escapeHtml(roleCopy('Quantity damaged', 'Kiasi kilichoharibika'))}" required><button>${escapeHtml(roleCopy('Save production', 'Hifadhi rekodi'))}</button></form></section>` : ''}<section class="panel"><h2>${escapeHtml(roleCopy('Recent records', 'Rekodi za hivi karibuni'))}</h2><div id="role-production-records" class="table-wrap"><table><thead><tr><th>${escapeHtml(roleCopy('Date', 'Tarehe'))}</th><th>${escapeHtml(roleCopy('Produce', 'Mazao'))}</th><th>${escapeHtml(roleCopy('Session', 'Zamu'))}</th><th>${escapeHtml(roleCopy('Quantity', 'Kiasi'))}</th><th>${escapeHtml(roleCopy('Unit', 'Kipimo'))}</th></tr></thead><tbody>${data.records.map((item) => `<tr><td>${escapeHtml(item.production_date)}</td><td>${escapeHtml(item.produce_name)}</td><td>${escapeHtml(item.production_session || '—')}</td><td>${Number(item.quantity_produced).toLocaleString()}</td><td>${escapeHtml(item.unit)}</td></tr>`).join('') || `<tr><td colspan="5" class="empty">${escapeHtml(roleCopy('No production records yet.', 'Hakuna rekodi za mazao bado.'))}</td></tr>`}</tbody></table></div></section></section>`
      content.insertAdjacentHTML('afterbegin', renderProductionSummary(summary))
      if (canRecord) {
        const table = content.querySelector('#role-production-records table')
        table?.tHead?.rows[0]?.insertCell().append('Action')
        data.records.forEach((item, index) => {
          const row = table?.tBodies[0]?.rows[index]
          if (!row) return
          const button = document.createElement('button')
          button.type = 'button'
          button.className = 'small-action'
          button.textContent = 'Edit'
          button.dataset.productionEdit = item.id
          row.insertCell().append(button)
        })
      }
      bindProductionEditor(content, data.records, () => renderRoleModule(module))
      return
    }
    if (module === 'Activities') {
      const canCreate = Boolean(currentPermissions['activities.create']?.allowed)
      const [data, animalData] = await Promise.all([
        api.activities(),
        canCreate ? api.animals() : Promise.resolve({ animals: [] }),
      ])
      const canComplete = Boolean(currentPermissions['activities.complete']?.allowed)
      content.innerHTML = `${canCreate ? `<section class="panel"><h2>${escapeHtml(roleCopy('Record an activity', 'Rekodi kazi'))}</h2><form id="role-activity-form" class="compact-form"><select name="activityType" aria-label="${escapeHtml(roleCopy('Activity type', 'Aina ya kazi'))}"><option value="feeding">${escapeHtml(roleCopy('Feeding', 'Kulisha'))}</option><option value="milking">${escapeHtml(roleCopy('Milking', 'Kukamua'))}</option><option value="cleaning">${escapeHtml(roleCopy('Cleaning', 'Kusafisha'))}</option><option value="health">${escapeHtml(roleCopy('Health follow-up', 'Ufuatiliaji wa afya'))}</option><option value="maintenance">${escapeHtml(roleCopy('Maintenance', 'Matengenezo'))}</option><option value="other">${escapeHtml(roleCopy('Other', 'Nyingine'))}</option></select><input name="description" placeholder="${escapeHtml(roleCopy('What did you do?', 'Umefanya nini?'))}" required><input name="activityAt" type="datetime-local" aria-label="${escapeHtml(roleCopy('Date and time', 'Tarehe na saa'))}" required>${activityHealthFields(animalData.animals)}<button>${escapeHtml(roleCopy('Save activity', 'Hifadhi kazi'))}</button></form></section>` : ''}<section class="panel"><h2>${escapeHtml(roleCopy('Assigned and shared activities', 'Kazi ulizopewa na za pamoja'))}</h2><div class="table-wrap"><table><thead><tr><th>${escapeHtml(roleCopy('When', 'Lini'))}</th><th>${escapeHtml(roleCopy('Task', 'Kazi'))}</th><th>${escapeHtml(roleCopy('Description', 'Maelezo'))}</th><th>${escapeHtml(roleCopy('Status', 'Hali'))}</th><th></th></tr></thead><tbody>${data.activities.map((item) => `<tr><td>${escapeHtml(item.activity_at)}</td><td>${escapeHtml(item.activity_type)}</td><td>${escapeHtml(item.description)}</td><td>${escapeHtml(item.status)}</td><td>${canComplete && item.status === 'planned' ? `<button class="small-action" data-complete-activity="${Number(item.id)}">${escapeHtml(roleCopy('Complete', 'Kamilisha'))}</button>` : ''}</td></tr>`).join('') || `<tr><td colspan="5" class="empty">${escapeHtml(roleCopy('No assigned activities.', 'Hakuna kazi ulizopewa.'))}</td></tr>`}</tbody></table></div></section>`
      const activityForm = content.querySelector('#role-activity-form')
      if (activityForm) {
        wireActivityHealthFields(activityForm)
        activityForm.addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createActivity(Object.fromEntries(new FormData(activityForm))); await renderRoleModule(module) } catch (error) { showError(error) } })
      }
      content.querySelectorAll('[data-complete-activity]').forEach((button) => button.addEventListener('click', async () => { try { await api.completeActivity(button.dataset.completeActivity); await renderRoleModule(module) } catch (error) { showError(error) } }))
      return
    }
    if (module === 'Inventory') {
      const [data, history] = await Promise.all([api.inventory(), api.inventoryTransactions()])
      const canTransact = Boolean(currentPermissions['inventory.transact']?.allowed)
      content.innerHTML = `<section class="module-grid"><section class="panel"><h2>${escapeHtml(roleCopy('Stock on hand', 'Stoo iliyopo'))}</h2><div class="table-wrap"><table><thead><tr><th>${escapeHtml(roleCopy('Item', 'Bidhaa'))}</th><th>${escapeHtml(roleCopy('Balance', 'Kiasi'))}</th><th>${escapeHtml(roleCopy('Reorder at', 'Kiwango cha kuagiza'))}</th></tr></thead><tbody>${data.items.map((item) => `<tr class="${Number(item.quantity_on_hand) <= Number(item.reorder_level) ? 'low-stock-row' : ''}"><td>${escapeHtml(item.name)}</td><td>${Number(item.quantity_on_hand).toLocaleString()} ${escapeHtml(item.unit)}</td><td>${Number(item.reorder_level).toLocaleString()}</td></tr>`).join('')}</tbody></table></div></section>${canTransact ? `<section class="panel"><h2>${escapeHtml(roleCopy('Record stock use', 'Rekodi matumizi ya stoo'))}</h2><form id="role-stock-form" class="compact-form"><select name="itemId" aria-label="${escapeHtml(roleCopy('Choose item', 'Chagua bidhaa'))}" required><option value="">${escapeHtml(roleCopy('Choose item', 'Chagua bidhaa'))}</option>${data.items.map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.name)} · ${Number(item.quantity_on_hand)} ${escapeHtml(item.unit)}</option>`).join('')}</select><select name="transactionType" aria-label="${escapeHtml(roleCopy('Stock movement', 'Mabadiliko ya stoo'))}"><option value="out">${escapeHtml(roleCopy('Stock out', 'Toa stoo'))}</option><option value="in">${escapeHtml(roleCopy('Stock in', 'Ongeza stoo'))}</option><option value="adjustment">${escapeHtml(roleCopy('Adjustment', 'Marekebisho'))}</option></select><input name="quantityChange" type="number" step="0.01" placeholder="${escapeHtml(roleCopy('Quantity', 'Kiasi'))}" required><input name="reason" placeholder="${escapeHtml(roleCopy('Reason', 'Sababu'))}" required><button>${escapeHtml(roleCopy('Save movement', 'Hifadhi mabadiliko'))}</button></form></section>` : ''}</section><section class="panel"><h2>${escapeHtml(roleCopy('Recent stock movements', 'Mabadiliko ya stoo ya hivi karibuni'))}</h2><div class="table-wrap"><table><thead><tr><th>${escapeHtml(roleCopy('Date', 'Tarehe'))}</th><th>${escapeHtml(roleCopy('Item', 'Bidhaa'))}</th><th>${escapeHtml(roleCopy('Change', 'Mabadiliko'))}</th><th>${escapeHtml(roleCopy('Reason', 'Sababu'))}</th></tr></thead><tbody>${history.transactions.map((item) => `<tr><td>${escapeHtml(item.transaction_date)}</td><td>${escapeHtml(item.item_name)}</td><td>${Number(item.quantity_change).toLocaleString()}</td><td>${escapeHtml(item.reason || '—')}</td></tr>`).join('') || `<tr><td colspan="4" class="empty">${escapeHtml(roleCopy('No stock movements.', 'Hakuna mabadiliko ya stoo.'))}</td></tr>`}</tbody></table></div></section>`
      content.querySelector('#role-stock-form')?.addEventListener('submit', async (event) => { event.preventDefault(); const values = Object.fromEntries(new FormData(event.target)); const itemId = values.itemId; delete values.itemId; try { await api.inventoryTransaction(itemId, values); await renderRoleModule(module) } catch (error) { showError(error) } })
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
      const canRecordHealth = Boolean(currentPermissions['health.record']?.allowed)
      content.innerHTML = `<section class="module-grid">${canRecordHealth ? `<section class="panel"><h2>${escapeHtml(roleCopy('Record treatment or care', 'Rekodi matibabu'))}</h2><form id="role-health-form" class="compact-form"><select name="animalId" aria-label="${escapeHtml(roleCopy('Choose animal', 'Chagua mnyama'))}" required><option value="">${escapeHtml(roleCopy('Choose animal', 'Chagua mnyama'))}</option>${animals.animals.map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.tag_number)} · ${escapeHtml(item.species)}</option>`).join('')}</select><select name="recordType" aria-label="${escapeHtml(roleCopy('Care type', 'Aina ya matibabu'))}"><option value="treatment">${escapeHtml(roleCopy('Treatment', 'Matibabu'))}</option><option value="vaccination">${escapeHtml(roleCopy('Vaccination', 'Chanjo'))}</option><option value="deworming">${escapeHtml(roleCopy('Deworming', 'Dawa ya minyoo'))}</option><option value="checkup">${escapeHtml(roleCopy('Checkup', 'Uchunguzi'))}</option><option value="illness">${escapeHtml(roleCopy('Illness', 'Ugonjwa'))}</option><option value="issue">${escapeHtml(roleCopy('Issue', 'Tatizo'))}</option></select><input name="description" placeholder="${escapeHtml(roleCopy('Clinical note or action', 'Maelezo ya afya au hatua'))}" required><input name="medication" placeholder="${escapeHtml(roleCopy('Medication, if applicable', 'Dawa, ikiwa ipo'))}"><input name="recordDate" type="date" aria-label="${escapeHtml(roleCopy('Record date', 'Tarehe ya rekodi'))}" value="${nairobiDate()}" required><input name="nextDueDate" type="date" aria-label="${escapeHtml(roleCopy('Next follow-up date', 'Tarehe ya ufuatiliaji'))}"><button>${escapeHtml(roleCopy('Save health record', 'Hifadhi rekodi ya afya'))}</button></form></section>` : ''}<section class="panel"><h2>${escapeHtml(roleCopy('Due follow-ups', 'Miadi inayofuata'))}</h2><div class="table-wrap"><table><thead><tr><th>${escapeHtml(roleCopy('Due', 'Tarehe'))}</th><th>${escapeHtml(roleCopy('Animal', 'Mnyama'))}</th><th>${escapeHtml(roleCopy('Type', 'Aina'))}</th><th>${escapeHtml(roleCopy('Description', 'Maelezo'))}</th></tr></thead><tbody>${dueRows || `<tr><td colspan="4" class="empty">${escapeHtml(roleCopy('No open follow-ups due.', 'Hakuna miadi inayosubiri.'))}</td></tr>`}</tbody></table></div></section></section><section class="panel"><h2>${escapeHtml(roleCopy('Health history', 'Historia ya afya'))}</h2><div class="table-wrap"><table><thead><tr><th>${escapeHtml(roleCopy('Date', 'Tarehe'))}</th><th>${escapeHtml(roleCopy('Animal', 'Mnyama'))}</th><th>${escapeHtml(roleCopy('Type', 'Aina'))}</th><th>${escapeHtml(roleCopy('Description', 'Maelezo'))}</th></tr></thead><tbody>${recordRows || `<tr><td colspan="4" class="empty">${escapeHtml(roleCopy('No health records.', 'Hakuna rekodi za afya.'))}</td></tr>`}</tbody></table></div></section>`
      content.querySelector('#role-health-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createHealthRecord(Object.fromEntries(new FormData(event.target))); await renderRoleModule(module) } catch (error) { showError(error) } })
      return
    }
    if (module === 'Farm shop' || module === 'Livestock catalogue') {
      const data = await api.products()
      const products = data.products.filter((item) => Number(item.available_quantity) > 0)
      const canOrder = Boolean(currentPermissions['orders.create']?.allowed)
      content.innerHTML = `<section class="panel"><h2>Available listings</h2>${canOrder ? `<form id="role-order-form" class="compact-form"><select name="productId" required><option value="">Choose listing</option>${products.map((item) => `<option value="${Number(item.id)}">${escapeHtml(item.name)} · ${Number(item.available_quantity).toLocaleString()} ${escapeHtml(item.unit)} · KES ${Math.round(Number(item.unit_price)).toLocaleString()}</option>`).join('')}</select><input name="quantity" type="number" min="0.01" step="0.01"       placeholder="e.g. 5 litres or 2 head" required><button>Request order</button></form>` : ''}<div class="table-wrap"><table><thead><tr><th>Listing</th><th>Type</th><th>Available</th><th>Unit price</th></tr></thead><tbody>${products.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.product_type)}</td><td>${Number(item.available_quantity).toLocaleString()} ${escapeHtml(item.unit)}</td><td>KES ${Math.round(Number(item.unit_price)).toLocaleString()}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">No available listings.</td></tr>'}</tbody></table></div></section>`
      content.querySelector('#role-order-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createOrder(Object.fromEntries(new FormData(event.target))); await renderRoleWorkspace('My orders') } catch (error) { showError(error) } })
      return
    }
    if (module === 'My orders' || module === 'Payments') {
      const [orderData, paymentData] = await Promise.all([api.orders(), api.payments()])
      const mpesaDemoMode = orderData.mpesaDemoMode === true
      const latestPayment = new Map()
      paymentData.payments.forEach((payment) => {
        if (!latestPayment.has(Number(payment.order_id))) latestPayment.set(Number(payment.order_id), payment)
      })
      const orders = orderData.orders.map((item) => {
        const orderId = Number(item.id)
        const payment = latestPayment.get(orderId)
        const payable = !['paid', 'fulfilled', 'cancelled'].includes(item.status)
        const paymentStatus = payment?.status === 'failed'
          ? payable ? 'Payment failed. Retry below.' : 'Payment failed, but this order is no longer payable. Contact the farm administrator.'
          : payment?.status === 'confirmed' ? 'Paid.' : ''
        const paymentAction = !payable ? '' : `<button type="button" class="small-action" data-mpesa-demo="${orderId}">${payment?.status === 'failed' ? 'Retry simulated payment' : 'Simulate M-PESA payment'}</button>`
        return `<tr><td>#${orderId}</td><td>${escapeHtml(item.ordered_at)}</td><td>${escapeHtml(item.status)}</td><td>KES ${Math.round(Number(item.total_amount)).toLocaleString()}</td><td>${paymentAction}<span data-mpesa-status="${orderId}" aria-live="polite">${escapeHtml(paymentStatus)}</span></td></tr>`
      }).join('')
      content.innerHTML = `<section class="module-grid orders-payments-grid"><section class="panel"><h2>My orders</h2>${message('M-PESA is simulated locally for this project. No phone prompt or real payment is made.') }<div class="table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th><th>Payment</th></tr></thead><tbody>${orders || '<tr><td colspan="5" class="empty">No orders yet.</td></tr>'}</tbody></table></div></section><section class="panel"><h2>Payments</h2><p class="muted">Simulated payments are recorded with a SIM receipt reference. No real money moves.</p><div class="table-wrap"><table><thead><tr><th>Order</th><th>Method</th><th>Status</th><th>Amount</th></tr></thead><tbody>${paymentData.payments.map((item) => `<tr><td>#${Number(item.order_id)}</td><td>${escapeHtml(item.method)}</td><td>${escapeHtml(item.status)}</td><td>KES ${Math.round(Number(item.amount)).toLocaleString()}</td></tr>`).join('') || '<tr><td colspan="4" class="empty">No payments recorded.</td></tr>'}</tbody></table></div></section></section>`
      content.querySelectorAll('[data-mpesa-demo]').forEach((button) => button.addEventListener('click', async () => {
        const orderId = Number(button.dataset.mpesaDemo)
        button.disabled = true
        button.textContent = 'Simulating…'
        try {
          await api.initiateMpesaPayment({ orderId })
          await renderRoleModule(module)
        } catch (error) {
          button.disabled = false
          button.textContent = 'Simulate M-PESA payment'
          content.querySelector(`[data-mpesa-status="${orderId}"]`).textContent = error.message
        }
      }))
      content.querySelectorAll('[data-mpesa-pay]').forEach((button) => button.addEventListener('click', async () => {
        const orderId = Number(button.dataset.mpesaPay)
        const phone = content.querySelector(`#mpesa-phone-${orderId}`).value
        const status = content.querySelector(`[data-mpesa-status="${orderId}"]`)
        button.disabled = true
        status.textContent = 'Starting M-PESA payment…'
        try {
          await api.initiateMpesaPayment({ orderId, phone })
          status.textContent = 'Check your phone and enter your M-PESA PIN.'
          const deadline = Date.now() + 60_000
          while (Date.now() < deadline) {
            await new Promise((resolve) => setTimeout(resolve, 3000))
            const [ordersNow, paymentsNow] = await Promise.all([api.orders(), api.payments()])
            const orderNow = ordersNow.orders.find((item) => Number(item.id) === orderId)
            const paymentNow = paymentsNow.payments.find((item) => Number(item.order_id) === orderId)
            if (orderNow?.status === 'paid' || paymentNow?.status === 'confirmed') {
              status.textContent = 'Paid. Thank you.'
              await renderRoleModule(module)
              return
            }
            if (paymentNow?.status === 'failed') {
              status.textContent = 'Payment failed. You can retry.'
              button.disabled = false
              return
            }
          }
          status.textContent = 'Still waiting for confirmation. Try again after the payment status updates.'
          button.disabled = false
        } catch (error) {
          status.textContent = `${error.message} You can retry.`
          button.disabled = false
        }
      }))
      return
    }
    if (module === 'Feedback') {
      const canView = Boolean(currentPermissions['feedback.view']?.allowed)
      const canCreate = Boolean(currentPermissions['feedback.create']?.allowed)
      const data = canView ? await api.feedback() : { feedback: [] }
      content.innerHTML = `<section class="module-grid">${canCreate ? `<section class="panel"><h2>Send feedback</h2><form id="role-feedback-form" class="compact-form"><select name="category"><option value="feedback">Feedback</option><option value="complaint">Complaint</option><option value="suggestion">Suggestion</option></select>      <textarea name="message" rows="5" placeholder="Describe your question, complaint or suggestion" required></textarea><button>Send message</button></form></section>` : ''}${canView ? `<section class="panel"><h2>My messages</h2><div class="feedback-list">${data.feedback.map((item) => `<article class="feedback-item"><p class="kicker">${escapeHtml(item.category)} · ${escapeHtml(item.status)}</p><p>${escapeHtml(item.message)}</p><small>${escapeHtml(item.created_at)}</small></article>`).join('') || '<p class="empty">No messages sent.</p>'}</div></section>` : ''}</section>`
      content.querySelector('#role-feedback-form')?.addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createFeedback(Object.fromEntries(new FormData(event.target))); await renderRoleWorkspace('Feedback') } catch (error) { showError(error) } })
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
    if (currentUser.mustEnable2fa) await renderAccountSecurity(true)
    else await renderApp()
  } catch {
    try {
      const status = await api.setupStatus()
      renderLanding(app, () => renderAuth(status.setupRequired ? 'setup' : 'login'), () => renderAuth('login'))
    } catch (error) { app.innerHTML = message(`Backend unavailable: ${error.message}`, true) }
  }
}

start()
