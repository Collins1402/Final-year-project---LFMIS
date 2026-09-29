import './style.css'
import './app.css'
import { api } from './api.js'

const root = document.querySelector('#app')
const navSections = [
  ['⌂', 'Overview'], ['◉', 'Livestock'], ['♡', 'Animal health'],
  ['☷', 'Activities'], ['◌', 'Production'], ['▣', 'Inventory'], ['↗', 'Sales & orders'], ['▥', 'Reports'], ['◇', 'Feedback'], ['♙', 'User accounts'],
  ['▦', 'Products'], ['✦', 'Old Mark Donald'],
]

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[character]))

const formatMoney = (value) => new Intl.NumberFormat('en-KE', {
  style: 'currency', currency: 'KES', maximumFractionDigits: 0,
}).format(Number(value) || 0)

const aboutSection = `
  <section class="about-panel" aria-labelledby="about-title">
    <p class="eyebrow">About us</p>
    <h2 id="about-title">Flouri Farm</h2>
    <div class="about-animals">
      ${[['cow', 'Cattle'], ['goat', 'Goats'], ['sheep', 'Sheep'], ['chicken', 'Chickens'], ['rabbit', 'Rabbits']].map(([file, label]) => `<figure><img src="/animals/${file}.svg" alt="" width="64" height="64"><figcaption>${label}</figcaption></figure>`).join('')}
    </div>
    <p>Flouri Farm is a mixed livestock farm raising dairy cattle, goats, sheep, rabbits and chickens, and producing fresh milk, eggs and wool.</p>
    <p>This Livestock Farm Management Information System (LFMIS) replaces paper notebooks with one simple, shared record of animal health, daily produce, stock and sales, so the farm can care for its animals better and serve customers reliably.</p>
    <ul class="about-list">
      <li>Buy fresh milk, eggs and wool directly from the farm</li>
      <li>Purchase healthy livestock with up-to-date availability</li>
      <li>Track your orders online</li>
    </ul>
    <div class="about-contact">
      <div><span>Location</span><strong>Nairobi, Kenya</strong></div>
      <div><span>Phone</span><strong><a href="tel:+254701004028">0701 004 028</a></strong></div>
    </div>
  </section>`

const usernameInput = '<input name="username" autocomplete="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_.]{3,30}" title="3–30 letters, numbers, underscores or dots">'

const eyeIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
const eyeOffIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.5 18.5 0 0 1 5.1-5.9M9.9 5.2A9.8 9.8 0 0 1 12 5c6.5 0 10 7 10 7a18.6 18.6 0 0 1-2.2 3.2M14.1 14.1a3 3 0 1 1-4.2-4.2"/><path d="M2 2l20 20"/></svg>'

function addPasswordToggles() {
  root.querySelectorAll('input[type="password"]:not([data-toggle])').forEach((input) => {
    input.dataset.toggle = 'true'
    const wrapper = document.createElement('span')
    wrapper.className = 'password-field'
    input.replaceWith(wrapper)
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'password-toggle'
    button.setAttribute('aria-label', 'Show password')
    button.innerHTML = eyeIcon
    button.addEventListener('click', () => {
      const show = input.type === 'password'
      input.type = show ? 'text' : 'password'
      button.innerHTML = show ? eyeOffIcon : eyeIcon
      button.setAttribute('aria-label', show ? 'Hide password' : 'Show password')
      input.focus()
    })
    wrapper.append(input, button)
  })
}

function showMessage(element, message, isError = false) {
  element.textContent = message
  element.classList.toggle('error', isError)
  element.classList.toggle('success', !isError)
  element.hidden = !message
}

function renderAuth(mode, notice = '') {
  const setup = mode === 'setup'
  root.innerHTML = `
    <main class="auth-shell"><div class="${setup ? '' : 'auth-layout'}">${setup ? '' : '<button class="text-link auth-home" type="button" id="back-home">← Back to home</button>'}<section class="auth-panel" aria-labelledby="auth-title">
      <div class="brand auth-brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>LFMIS workspace</small></div></div>
      <p class="eyebrow">${setup ? 'Initial setup' : 'Farm workspace'}</p>
      <h1 id="auth-title">${setup ? 'Create the farm owner account' : 'Welcome back'}</h1>
      <p class="intro">${setup ? 'This first account will have administrator access.' : 'Admins, farm workers, veterinarians, customers, and livestock buyers all sign in here. Your workspace opens based on your account.'}</p>
      <form id="auth-form" class="form-stack">
        ${setup ? `<label>Full name<input name="fullName" autocomplete="name" required maxlength="120"></label><label>Username${usernameInput}</label><label>Email<input type="email" name="email" autocomplete="email" required maxlength="190"></label>` : '<label>Username or email<input name="login" autocomplete="username" required maxlength="190"></label>'}
        <label>Password<input type="password" name="password" autocomplete="${setup ? 'new-password' : 'current-password'}" minlength="${setup ? '12' : '1'}" required></label>
        <p class="form-message ${notice ? 'success' : ''}" role="status" ${notice ? '' : 'hidden'}>${escapeHtml(notice)}</p>
        <button class="primary-button" type="submit">${setup ? 'Create account' : 'Sign in'}</button>
      </form>
      ${setup ? '' : '<button class="text-link" type="button" id="forgot-password">Forgot password?</button><p class="auth-switch">New to Flouri Farm? <button class="text-link" type="button" id="open-register">Create an account</button></p>'}
    </section>${setup ? '' : aboutSection}</div></main>`

  const form = root.querySelector('#auth-form')
  root.querySelector('#forgot-password')?.addEventListener('click', renderForgotPassword)
  root.querySelector('#back-home')?.addEventListener('click', renderLanding)
  root.querySelector('#open-register')?.addEventListener('click', renderRegister)
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(form))
    const feedback = form.querySelector('.form-message')
    const button = form.querySelector('button[type="submit"]')
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

function renderLanding() {
  const animals = [['cow', 'Cattle'], ['goat', 'Goats'], ['sheep', 'Sheep'], ['chicken', 'Chickens'], ['rabbit', 'Rabbits']]
  const services = [
    ['◉', 'Livestock records', 'Every animal registered with tag, breed, age, weight and status.'],
    ['♡', 'Animal health', 'Vaccinations, treatments and due dates tracked so nothing is missed.'],
    ['◌', 'Daily produce', 'Milk, eggs and wool recorded daily with weekly progress and WhatsApp sharing.'],
    ['▣', 'Inventory', 'Feed and supplies tracked with automatic low-stock alerts.'],
    ['↗', 'Farm shop', 'Customers order fresh milk, eggs and wool straight from the farm.'],
    ['♥', 'Livestock sales', 'Buyers check live availability, choose how many animals and download details as CSV.'],
  ]
  const faqs = [
    ['How do I buy produce or livestock?', 'Click Get Started and create a Customer account for milk, eggs and wool, or a Buyer account for cattle, sheep, goats and chickens. The farm confirms each request before fulfilment.'],
    ['How is availability checked?', 'Livestock availability comes from the farm\'s live herd records, and produce stock updates as workers record each day\'s production.'],
    ['I work on the farm. How do I get access?', 'Create an account as a Farm worker or Veterinarian. The farm administrator approves it before you can sign in.'],
    ['How do I pay?', 'Once the farm accepts your order, it records payment by cash, bank transfer or M-Pesa.'],
  ]
  root.innerHTML = `
    <div class="landing">
      <header class="landing-header">
        <a class="landing-logo" href="#top"><span class="brand-mark">F</span>Flouri Farm</a>
        <nav class="landing-nav" aria-label="Website">
          <a href="#services">Services</a><a href="#about">About Us</a><a href="#faq">FAQ</a><a href="#contacts">Contacts</a>
        </nav>
        <div class="landing-actions"><button class="landing-login" type="button" data-go="login">Log In</button><button class="landing-cta" type="button" data-go="register">Get Started</button></div>
      </header>
      <main id="top">
        <section class="landing-hero">
          <p class="eyebrow">Livestock Farm Management Information System</p>
          <h1>Healthier animals.<br>Better records. <span>Fresh from the farm.</span></h1>
          <p class="landing-lead">Flouri Farm manages its herd, health care, daily produce and sales in one simple system — and lets you buy directly from the farm.</p>
          <div class="landing-hero-actions"><button class="landing-cta" type="button" data-go="register">Get Started</button><a class="landing-secondary" href="#services">See what we do →</a></div>
        </section>
        <section class="landing-showcase" aria-label="Farm highlights">
          <article class="showcase-card">
            <div class="showcase-thumb"><img src="/animals/cow.svg" alt=""><span>Daily</span></div>
            <p class="showcase-quote">Every litre of milk, every tray of eggs and every animal — recorded in one place.</p>
            <p class="showcase-meta"><strong>Flouri Farm</strong>, Nairobi</p>
            <button class="showcase-play" type="button" data-go="register" aria-label="Get started">▶</button>
            <span class="showcase-link">Shop the farm</span>
          </article>
          <div class="showcase-visual">
            <div class="showcase-panel showcase-dashboard" aria-hidden="true">
              <div class="mock-top"><span></span><span></span><span></span></div>
              <p class="mock-title">Weekly production</p>
              <div class="mock-bars">${[45, 62, 58, 74, 69, 83, 90].map((height) => `<i style="height:${height}%"></i>`).join('')}</div>
              <div class="mock-legend"><span>Milk</span><span>Eggs</span><span>Wool</span></div>
            </div>
            <div class="showcase-panel showcase-farm">
              <img class="farm-scene" src="/farm-scene.svg" alt="Illustrated farm with a red barn and green hills">
              ${animals.slice(0, 4).map(([file], index) => `<img class="farm-animal farm-animal-${index}" src="/animals/${file}.svg" alt="">`).join('')}
            </div>
          </div>
        </section>
        <section class="landing-section" id="services">
          <p class="eyebrow">Services</p><h2>Everything the farm runs on</h2>
          <div class="service-grid">${services.map(([icon, title, text]) => `<article class="service-card"><span class="service-icon">${icon}</span><h3>${title}</h3><p>${text}</p></article>`).join('')}</div>
        </section>
        <section class="landing-section landing-about" id="about">
          <div>
            <p class="eyebrow">About Us</p><h2>A mixed livestock farm in Nairobi</h2>
            <p>Flouri Farm raises dairy cattle, goats, sheep, rabbits and chickens, and produces fresh milk, eggs and wool.</p>
            <p>Our Livestock Farm Management Information System replaces paper notebooks with one shared record of animal health, daily produce, stock and sales — so we can care for our animals better and serve customers reliably.</p>
          </div>
          <div class="landing-animals">${animals.map(([file, label]) => `<figure><img src="/animals/${file}.svg" alt=""><figcaption>${label}</figcaption></figure>`).join('')}</div>
        </section>
        <section class="landing-section" id="faq">
          <p class="eyebrow">FAQ</p><h2>Common questions</h2>
          <div class="faq-list">${faqs.map(([question, answer]) => `<details><summary>${question}</summary><p>${answer}</p></details>`).join('')}</div>
        </section>
        <section class="landing-section landing-contact" id="contacts">
          <div><p class="eyebrow">Contacts</p><h2>Visit or call the farm</h2></div>
          <div class="contact-cards">
            <div><span>Location</span><strong>Nairobi, Kenya</strong></div>
            <div><span>Phone</span><strong><a href="tel:+254701004028">0701 004 028</a></strong></div>
          </div>
        </section>
      </main>
      <footer class="landing-footer">© ${new Date().getFullYear()} Flouri Farm · LFMIS</footer>
    </div>`
  root.querySelectorAll('[data-go]').forEach((button) => button.addEventListener('click', () => {
    if (button.dataset.go === 'register') renderRegister()
    else renderAuth('login')
  }))
  root.querySelectorAll('.landing a[href^="#"]').forEach((link) => link.addEventListener('click', (event) => {
    event.preventDefault()
    root.querySelector(link.getAttribute('href'))?.scrollIntoView({ behavior: 'smooth' })
  }))
}

function renderRegister() {
  root.innerHTML = `
    <main class="auth-shell"><section class="auth-panel" aria-labelledby="register-title">
      <div class="brand auth-brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>LFMIS workspace</small></div></div>
      <p class="eyebrow">New account</p><h1 id="register-title">Create an account</h1>
      <p class="intro">Choose the type of account you need.</p>
      <form id="register-form" class="form-stack">
        <label>User type<select name="role" required>
          <option value="customer">Customer</option>
          <option value="buyer">Buyer</option>
          <option value="worker">Farm worker</option>
          <option value="veterinarian">Veterinarian</option>
          <option value="admin" disabled>Admin</option>
        </select></label>
        <p class="store-availability" id="role-hint">You can start shopping as soon as the account is created.</p>
        <label>Full name<input name="fullName" autocomplete="name" required maxlength="120"></label>
        <label>Username${usernameInput}</label>
        <label>Email<input type="email" name="email" autocomplete="username" required maxlength="190"></label>
        <label>Password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
        <p class="form-message" role="alert" hidden></p>
        <button class="primary-button" type="submit">Create account</button>
      </form>
      <button class="text-link" type="button" id="back-to-login">Back to sign in</button>
    </section></main>`
  root.querySelector('#back-to-login').addEventListener('click', () => renderAuth('login'))
  const form = root.querySelector('#register-form')
  const roleSelect = form.querySelector('[name="role"]')
  roleSelect.addEventListener('change', () => {
    root.querySelector('#role-hint').textContent = ['worker', 'veterinarian'].includes(roleSelect.value)
      ? 'Staff accounts can sign in after the farm administrator approves them.'
      : 'You can start shopping as soon as the account is created.'
  })
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const button = form.querySelector('button[type="submit"]')
    button.disabled = true
    try {
      const result = await api.register(Object.fromEntries(new FormData(form)))
      if (result.pending) {
        renderAuth('login', result.message)
        return
      }
      renderHome(result.user)
    } catch (error) {
      showMessage(form.querySelector('.form-message'), error.message, true)
      button.disabled = false
    }
  })
}

async function signOut() {
  try { await api.logout() } catch {}
  window.history.replaceState(null, '', window.location.pathname)
  renderAuth('login', 'You have signed out. Sign in with another account to switch roles.')
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
    const button = form.querySelector('button[type="submit"]')
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
    if (user.role === 'veterinarian') return ['Overview', 'Livestock', 'Animal health', 'Activities', 'Old Mark Donald'].includes(label)
    if (user.role === 'worker') return ['Overview', 'Livestock', 'Activities', 'Production', 'Inventory', 'Old Mark Donald'].includes(label)
    return false
  })
  const roleNames = { admin: 'Administrator', worker: 'Farm worker', veterinarian: 'Veterinarian', customer: 'Customer', buyer: 'Livestock buyer' }
  const roleName = roleNames[user.role] || user.role
  root.innerHTML = `
    <aside class="sidebar">
      <div class="brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>LFMIS workspace</small></div></div>
      <div class="farm-switcher"><span class="online-dot"></span><span>Farm operations</span></div>
      <nav aria-label="Main navigation"><p class="nav-label">Workspace</p>
        ${visibleSections.map(([icon, label]) => `<button class="nav-item ${label === active ? 'active' : ''}" data-section="${escapeHtml(label)}"><span class="nav-icon">${icon}</span>${escapeHtml(label)}</button>`).join('')}
      </nav>
      <div class="sidebar-bottom"><button class="nav-item" id="logout">↪ Sign out</button><div class="user"><span class="avatar">${escapeHtml(initials)}</span><div><strong>${escapeHtml(user.fullName)}</strong><small>${escapeHtml(roleName)}</small></div></div></div>
    </aside>
    <main class="main-content"><header class="topbar"><div class="mobile-brand"><span class="brand-mark">F</span> Flouri Farm</div><div class="top-actions"><span class="role-label">${escapeHtml(roleName)}</span><button class="header-logout" id="header-logout" type="button" aria-label="Sign out" title="Sign out">↪ Sign out</button></div></header>
      <div class="page-wrap">${content}<div id="toast" class="toast" role="status"></div></div>
    </main>`

  root.querySelectorAll('.nav-item[data-section]').forEach((item) => item.addEventListener('click', () => navigate(user, item.dataset.section)))
  root.querySelectorAll('#logout, #header-logout').forEach((button) => button.addEventListener('click', signOut))
}

function toast(message) {
  const element = root.querySelector('#toast')
  if (!element) return
  element.textContent = message
  element.classList.add('visible')
  window.setTimeout(() => element.classList.remove('visible'), 2400)
}

async function renderDashboard(user) {
  shell(user, 'Overview', `<section class="welcome-row"><div><p class="eyebrow">Farm overview</p><h1>Welcome, <span id="welcome-name"></span></h1><p class="intro">Live figures from your farm records.</p></div><div class="welcome-actions"><button class="primary-button" id="add-animal"><span>＋</span> Register animal</button>${user.role === 'admin' ? '<button class="text-button" id="load-demo-data">Load demo records</button>' : ''}</div></section><p class="page-error" id="page-error" role="alert" hidden></p><section class="stat-grid" aria-label="Farm summary"><article class="stat-card"><div class="stat-top"><span class="stat-icon green">♧</span></div><p>Active livestock</p><strong id="stat-animals">—</strong><small>From registered animal records</small></article><article class="stat-card"><div class="stat-top"><span class="stat-icon amber">◌</span></div><p>Today\'s milk</p><strong><span id="stat-milk">—</span> <em>litres</em></strong><small>Recorded for today</small></article><article class="stat-card"><div class="stat-top"><span class="stat-icon blue">▣</span></div><p>Monthly sales</p><strong id="stat-sales">—</strong><small>Recorded sales</small></article><article class="stat-card"><div class="stat-top"><span class="stat-icon coral">!</span></div><p>Open tasks</p><strong id="stat-tasks">—</strong><small>Planned tasks due</small></article></section><section class="content-grid"><article class="panel"><div class="panel-heading"><div><p class="eyebrow">Livestock inventory</p><h2>Herd overview</h2></div><button class="text-button" data-section="Livestock">View records →</button></div><div class="herd-list" id="herd-list"><p class="empty-state">Loading farm records…</p></div></article><article class="panel alerts-panel"><div class="panel-heading"><div><p class="eyebrow">Needs attention</p><h2>Stock alerts</h2></div><span class="alert-count" id="alert-count">0</span></div><div class="alert-list" id="stock-alerts"><p class="empty-state">Loading stock levels…</p></div></article></section>`)
  root.querySelector('.welcome-row').insertAdjacentHTML('afterend', '<section class="weekly-strip" aria-label="Last seven days production"><div><span>Milk · last 7 days</span><strong id="week-milk">—</strong><small>litres recorded</small></div><div><span>Eggs · last 7 days</span><strong id="week-eggs">—</strong><small>trays recorded</small></div><div><span>Wool · last 7 days</span><strong id="week-wool">—</strong><small>kg recorded</small></div></section>')
  root.querySelector('.weekly-strip').insertAdjacentHTML('afterend', '<section class="insight-grid"><article class="panel predictive-panel"><div class="panel-heading"><div><p class="eyebrow">Predictive AI</p><h2>What to check next</h2></div><span class="signal-badge">Decision support</span></div><p id="insight-summary" class="insight-summary">Inspecting production and farm signals…</p><div id="prediction-checks" class="prediction-checks"><p class="empty-state">Loading predictive checks…</p></div></article><article class="panel trend-panel"><div class="panel-heading"><div><p class="eyebrow">Production signal</p><h2>Milk trend</h2></div><span class="chart-key"><i></i>litres</span></div><div id="milk-trend-chart" class="line-chart"><p class="empty-state">Loading trend…</p></div></article><article class="panel composition-panel"><div class="panel-heading"><div><p class="eyebrow">Herd mix</p><h2>Active animals</h2></div></div><div id="herd-composition" class="herd-composition"><p class="empty-state">Loading herd mix…</p></div></article></section>')
  root.querySelector('#welcome-name').textContent = user.fullName.split(/\s+/)[0]
  root.querySelector('#add-animal').addEventListener('click', () => navigate(user, 'Livestock'))
  root.querySelector('#load-demo-data')?.addEventListener('click', async (event) => {
    const button = event.currentTarget
    button.disabled = true
    try {
      await api.loadDemoData()
      toast('Demo farm records loaded')
      await renderDashboard(user)
    } catch (error) {
      showMessage(root.querySelector('#page-error'), error.message, true)
      button.disabled = false
    }
  })
  root.querySelector('.text-button[data-section="Livestock"]').addEventListener('click', () => navigate(user, 'Livestock'))
  try {
    const data = await api.dashboard()
    root.querySelector('#stat-animals').textContent = data.activeAnimals
    root.querySelector('#stat-milk').textContent = Number(data.milkTodayLitres).toLocaleString()
    root.querySelector('#week-milk').textContent = Number(data.milkWeekLitres).toLocaleString()
    root.querySelector('#week-eggs').textContent = Number(data.eggWeekTrays).toLocaleString()
    root.querySelector('#week-wool').textContent = Number(data.woolWeekKg).toLocaleString()
    root.querySelector('#stat-sales').textContent = formatMoney(data.salesThisMonth)
    root.querySelector('#stat-tasks').textContent = data.openTasks
    const counts = Object.fromEntries(data.herdBySpecies.map(({ species, total }) => [species, total]))
    const speciesNames = { cattle: 'Cattle', sheep: 'Sheep', goat: 'Goats', rabbit: 'Rabbits', chicken: 'Poultry', other: 'Other' }
    root.querySelector('#herd-list').innerHTML = Object.entries(speciesNames).map(([key, label]) => `<div class="herd-row"><span class="animal">${label[0]}</span><div><strong>${label}</strong><small>Active animals</small></div><b>${counts[key] || 0}</b></div>`).join('')
    root.querySelector('#alert-count').textContent = data.stockAlerts.length
    root.querySelector('#stock-alerts').innerHTML = data.stockAlerts.length
      ? data.stockAlerts.map((item) => `<div class="alert-item"><span class="alert-symbol red">!</span><div><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.quantity_on_hand)} ${escapeHtml(item.unit)} remaining</small></div></div>`).join('')
      : '<p class="empty-state">No items are below their reorder level.</p>'
  } catch (error) {
    showMessage(root.querySelector('#page-error'), error.message, true)
  }
  try {
    const insights = await api.predictiveChecks()
    root.querySelector('#insight-summary').textContent = insights.summary
    root.querySelector('#prediction-checks').innerHTML = insights.checks.map((check) => `<article class="prediction-check ${escapeHtml(check.priority)}"><div><span class="priority-dot"></span><strong>${escapeHtml(check.title)}</strong></div><p>${escapeHtml(check.detail)}</p><small>${escapeHtml(check.action)}</small></article>`).join('')
    const trend = insights.trend || []
    if (trend.length > 1) {
      const values = trend.map((item) => Number(item.litres))
      const min = Math.min(...values)
      const max = Math.max(...values)
      const range = max - min || 1
      const points = values.map((value, index) => `${(index / (values.length - 1)) * 100},${92 - ((value - min) / range) * 76}`).join(' ')
      root.querySelector('#milk-trend-chart').innerHTML = `<svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Milk production trend"><line x1="0" y1="92" x2="100" y2="92"></line><polyline points="${points}"></polyline>${values.map((value, index) => `<circle cx="${(index / (values.length - 1)) * 100}" cy="${92 - ((value - min) / range) * 76}" r="1.7"><title>${escapeHtml(trend[index].date)}: ${escapeHtml(value)} litres</title></circle>`).join('')}</svg><div class="chart-axis"><span>${escapeHtml(trend[0].date)}</span><span>${escapeHtml(trend[trend.length - 1].date)}</span></div>`
    } else {
      root.querySelector('#milk-trend-chart').innerHTML = '<p class="empty-state">More records are needed for a trend line.</p>'
    }
    const totalAnimals = data.herdBySpecies.reduce((sum, item) => sum + Number(item.total), 0)
    const palette = ['#e07a5f', '#3d7ea6', '#d9a441', '#6b5ca5', '#4f9a63', '#8b6f47']
    let angle = 0
    const slices = data.herdBySpecies.map((item, index) => {
      const start = angle
      angle += totalAnimals ? (Number(item.total) / totalAnimals) * 360 : 0
      return `${palette[index % palette.length]} ${start}deg ${angle}deg`
    }).join(', ')
    root.querySelector('#herd-composition').innerHTML = totalAnimals ? `<div class="pie-chart" style="background:conic-gradient(${slices})"><span>${totalAnimals}<small>active</small></span></div><div class="pie-legend">${data.herdBySpecies.map((item, index) => `<span><i style="background:${palette[index % palette.length]}"></i>${escapeHtml(item.species)} <b>${escapeHtml(item.total)}</b></span>`).join('')}</div>` : '<p class="empty-state">No active animals yet.</p>'
  } catch (error) {
    root.querySelector('#insight-summary').textContent = error.message
  }
}

async function renderLivestock(user) {
  shell(user, 'Livestock', '<section class="welcome-row"><div><p class="eyebrow">Farm records</p><h1>Livestock</h1><p class="intro">Registered animals and their current status.</p></div><button class="primary-button" id="open-animal-form"><span>＋</span> Register animal</button></section><form id="animal-form" class="entry-form" hidden><label>Tag number<input name="tagNumber" maxlength="80"></label><label>Species<select name="species" required><option value="">Choose species</option><option value="cattle">Cattle</option><option value="sheep">Sheep</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="other">Other</option></select></label><label>Name<input name="name" maxlength="100"></label><label>Breed<input name="breed" maxlength="100"></label><label>Sex<select name="sex"><option value="unknown">Unknown</option><option value="female">Female</option><option value="male">Male</option></select></label><label>Weight (kg)<input name="weightKg" type="number" min="0" step="0.01"></label><label>Date of birth<input name="dateOfBirth" type="date"></label><button class="primary-button" type="submit">Save animal</button><p class="form-message" role="alert" hidden></p></form><p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Animal</th><th>Breed</th><th>Sex</th><th>Weight</th><th>Status</th></tr></thead><tbody id="animal-rows"><tr><td colspan="6">Loading records…</td></tr></tbody></table></div>')
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
  const intro = user.role === 'admin'
    ? 'Daily and weekly produce recorded by farm workers, with who recorded each entry.'
    : 'Record today\'s produce, track weekly progress, and share the numbers on WhatsApp.'
  shell(user, 'Production', `<section class="welcome-row"><div><p class="eyebrow">Daily farm records</p><h1>Production</h1><p class="intro">${intro}</p></div></section><p class="page-error" id="page-error" role="alert" hidden></p><section class="production-weekly"><div class="production-weekly-heading"><div><p class="eyebrow">Progress</p><h2>Today and this week</h2></div><form id="whatsapp-form" class="whatsapp-form"><label>WhatsApp number<input name="phone" type="tel" inputmode="tel" maxlength="20" placeholder="254712345678"></label><button class="whatsapp-button" type="submit">Share to WhatsApp</button></form></div><div class="production-totals progress-grid" id="progress-cards"><p class="empty-state">Loading totals…</p></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Milk (litres)</th><th>Eggs (trays)</th><th>Wool (kg)</th></tr></thead><tbody id="week-rows"><tr><td colspan="4">Loading weekly totals…</td></tr></tbody></table></div></section><h2 class="section-title">Record daily produce</h2><form id="milk-form" class="entry-form"><label>Date<input name="productionDate" type="date" required></label><label>Milking session<select name="session" required><option value="morning">Morning</option><option value="evening">Evening</option><option value="other">Other</option></select></label><label>Milk (litres)<input name="quantityLitres" type="number" min="0" step="0.01" required></label><label>Notes<input name="notes" maxlength="500"></label><button class="primary-button" type="submit">Save milk</button><p class="form-message" role="alert" hidden></p></form><form id="egg-form" class="entry-form"><label>Date<input name="productionDate" type="date" required></label><label>Eggs (trays)<input name="quantityTrays" type="number" min="0" step="0.01" required></label><label>Notes<input name="notes" maxlength="500"></label><button class="primary-button" type="submit">Save eggs</button><p class="form-message" role="alert" hidden></p></form><form id="wool-form" class="entry-form"><label>Date<input name="productionDate" type="date" required></label><label>Wool (kg)<input name="quantityKg" type="number" min="0" step="0.01" required></label><label>Notes<input name="notes" maxlength="500"></label><button class="primary-button" type="submit">Save wool</button><p class="form-message" role="alert" hidden></p></form><h2 class="section-title">Recent entries</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Produce</th><th>Quantity</th><th>Session</th><th>Recorded by</th><th>Entered at</th></tr></thead><tbody id="log-rows"><tr><td colspan="6">Loading entries…</td></tr></tbody></table></div>`)
  const produce = [
    ['milkLitres', 'Milk', 'litres'],
    ['eggTrays', 'Eggs', 'trays'],
    ['woolKg', 'Wool', 'kg'],
  ]
  const today = new Date().toISOString().slice(0, 10)
  root.querySelectorAll('#milk-form, #egg-form, #wool-form').forEach((form) => { form.querySelector('[name="productionDate"]').value = today })
  let summary = null

  async function refresh() {
    const [latest, log] = await Promise.all([api.productionSummary(), api.productionLog()])
    summary = latest
    root.querySelector('#progress-cards').innerHTML = produce.map(([key, label, unit]) => {
      const week = Number(summary.thisWeek[key])
      const previous = Number(summary.previousWeek[key])
      const change = previous > 0 ? Math.round(((week - previous) / previous) * 100) : null
      const width = previous > 0 ? Math.min(100, Math.round((week / previous) * 100)) : (week > 0 ? 100 : 0)
      const trend = change === null ? 'No figures for the previous week' : `${change >= 0 ? '↑' : '↓'} ${Math.abs(change)}% vs previous week (${previous.toLocaleString()} ${unit})`
      return `<div class="progress-card"><span>${label}</span><strong>${Number(summary.today[key]).toLocaleString()} <em>${unit} today</em></strong><small>${week.toLocaleString()} ${unit} this week</small><div class="progress-bar" role="img" aria-label="${label} this week compared with previous week"><span style="width:${width}%"></span></div><small class="${change !== null && change < 0 ? 'trend-down' : 'trend-up'}">${escapeHtml(trend)}</small></div>`
    }).join('')
    root.querySelector('#week-rows').innerHTML = summary.days.map((day) => `<tr><td>${escapeHtml(day.date)}</td><td>${escapeHtml(day.milkLitres)}</td><td>${escapeHtml(day.eggTrays)}</td><td>${escapeHtml(day.woolKg)}</td></tr>`).join('')
    root.querySelector('#log-rows').innerHTML = log.records.length
      ? log.records.map((record) => `<tr><td>${escapeHtml(record.production_date)}</td><td>${escapeHtml(record.product)}</td><td>${escapeHtml(record.quantity)} ${escapeHtml(record.unit)}</td><td>${escapeHtml(record.detail || '—')}</td><td>${escapeHtml(record.recorded_by || '—')}</td><td>${escapeHtml(record.created_at)}</td></tr>`).join('')
      : '<tr><td colspan="6">No production has been recorded yet.</td></tr>'
  }

  const recorders = { '#milk-form': [api.recordMilk, 'Milk recorded'], '#egg-form': [api.recordEggs, 'Eggs recorded'], '#wool-form': [api.recordWool, 'Wool recorded'] }
  Object.entries(recorders).forEach(([selector, [save, message]]) => {
    const form = root.querySelector(selector)
    form.addEventListener('submit', async (event) => {
      event.preventDefault()
      try {
        await save(Object.fromEntries(new FormData(form)))
        toast(message)
        form.querySelector('[name^="quantity"]').value = ''
        form.querySelector('[name="notes"]').value = ''
        showMessage(form.querySelector('.form-message'), '')
        await refresh()
      } catch (error) { showMessage(form.querySelector('.form-message'), error.message, true) }
    })
  })

  const whatsappForm = root.querySelector('#whatsapp-form')
  const phoneInput = whatsappForm.querySelector('[name="phone"]')
  phoneInput.value = window.localStorage.getItem('lfmis-whatsapp') || ''
  whatsappForm.addEventListener('submit', (event) => {
    event.preventDefault()
    if (!summary) return
    const digits = phoneInput.value.replace(/\D/g, '')
    if (digits && (digits.length < 8 || digits.length > 15)) {
      toast('Enter the number in international format, e.g. 254712345678')
      return
    }
    window.localStorage.setItem('lfmis-whatsapp', digits)
    const lines = [
      `*Flouri Farm production report* (${user.fullName})`,
      '',
      `*Today (${summary.today.date})*`,
      ...produce.map(([key, label, unit]) => `${label}: ${summary.today[key]} ${unit}`),
      '',
      '*Last 7 days*',
      ...produce.map(([key, label, unit]) => `${label}: ${summary.thisWeek[key]} ${unit} (previous week ${summary.previousWeek[key]} ${unit})`),
      '',
      '*Daily breakdown*',
      ...summary.days.map((day) => `${day.date}: ${day.milkLitres} L milk, ${day.eggTrays} trays eggs, ${day.woolKg} kg wool`),
    ]
    window.open(`https://wa.me/${digits}?text=${encodeURIComponent(lines.join('\n'))}`, '_blank', 'noopener,noreferrer')
  })

  try { await refresh() } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
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

function assistantChartHtml(chartData) {
  if (!chartData || !chartData.points?.length) return ''
  const palette = ['#e07a5f', '#3d7ea6', '#d9a441', '#6b5ca5', '#4f9a63', '#8b6f47']
  if (chartData.type === 'pie') {
    const total = chartData.points.reduce((sum, point) => sum + Number(point.value), 0)
    let angle = 0
    const slices = chartData.points.map((point, index) => {
      const start = angle
      angle += total ? Number(point.value) / total * 360 : 0
      return `${palette[index % palette.length]} ${start}deg ${angle}deg`
    }).join(', ')
    return `<div class="assistant-chart"><strong>${escapeHtml(chartData.title)}</strong><div class="assistant-pie-layout"><div class="pie-chart" style="background:conic-gradient(${slices})"><span>${total}<small>${escapeHtml(chartData.unit)}</small></span></div><div class="pie-legend">${chartData.points.map((point, index) => `<span><i style="background:${palette[index % palette.length]}"></i>${escapeHtml(point.label)} <b>${escapeHtml(point.value)}</b></span>`).join('')}</div></div></div>`
  }
  const values = chartData.points.map((point) => Number(point.litres))
  if (chartData.type === 'line' && values.length > 1) {
    const min = Math.min(...values)
    const range = Math.max(...values) - min || 1
    const points = values.map((value, index) => `${(index / (values.length - 1)) * 100},${92 - ((value - min) / range) * 76}`).join(' ')
    return `<div class="assistant-chart"><strong>${escapeHtml(chartData.title)}</strong><div class="assistant-line-chart"><svg viewBox="0 0 100 100" preserveAspectRatio="none"><line x1="0" y1="92" x2="100" y2="92"></line><polyline points="${points}"></polyline>${values.map((value, index) => `<circle cx="${(index / (values.length - 1)) * 100}" cy="${92 - ((value - min) / range) * 76}" r="1.7"><title>${escapeHtml(chartData.points[index].date)}: ${escapeHtml(value)} ${escapeHtml(chartData.unit)}</title></circle>`).join('')}</svg></div></div>`
  }
  const max = Math.max(...values, 1)
  return `<div class="assistant-chart"><strong>${escapeHtml(chartData.title)}</strong>${chartData.points.map((point) => `<div class="chart-row"><span>${escapeHtml(point.date.slice(5))}</span><div class="chart-track"><i style="width:${Math.max(4, (Number(point.litres) / max) * 100)}%"></i></div><b>${escapeHtml(point.litres)} ${escapeHtml(chartData.unit)}</b></div>`).join('')}</div>`
}

async function renderAssistant(user) {
  shell(user, 'Old Mark Donald', `<section class="welcome-row"><div><p class="eyebrow">Farm intelligence</p><h1>Old Mark Donald</h1><p class="intro">A practical assistant for your daily farm decisions.</p></div></section><section class="panel assistant-panel"><div class="assistant-intro"><span class="assistant-mark">✦</span><div><h2>Ask about the farm</h2><p>Use plain language to ask about production, livestock records, or planned work.</p></div></div><div id="assistant-messages" class="assistant-messages" aria-live="polite"><div class="assistant-message assistant-message-bot"><strong>Old Mark Donald</strong><p>Hello. I can help you make sense of the farm records. What would you like to check?</p></div></div><form id="assistant-form" class="assistant-form"><label class="sr-only" for="assistant-input">Message Old Mark Donald</label><textarea id="assistant-input" maxlength="2000" rows="2" placeholder="Ask about today\'s milk, active animals, or open tasks..." required></textarea><button class="primary-button" type="submit">Send <span>→</span></button></form><p id="assistant-suggestion" class="assistant-suggestion" aria-live="polite"></p><p id="assistant-status" class="assistant-note">For animal illness, use this as decision support and contact the veterinarian.</p></section>`)
  const messages = root.querySelector('#assistant-messages')
  const form = root.querySelector('#assistant-form')
  const input = root.querySelector('#assistant-input')
  const status = root.querySelector('#assistant-status')
  const suggestionHint = root.querySelector('#assistant-suggestion')
  let previousMessage = ''
  let suggestion = ''
  let suggestionRequest = 0
  input.addEventListener('input', () => {
    const prefix = input.value.trim()
    suggestion = ''
    suggestionHint.textContent = ''
    if (!prefix) return
    const requestId = ++suggestionRequest
    window.setTimeout(async () => {
      try {
        const response = await api.assistantSuggest({ prefix })
        if (requestId !== suggestionRequest || input.value.trim() !== prefix) return
        suggestion = response.suggestion || ''
        suggestionHint.textContent = suggestion ? `Tab to accept: ${prefix}${suggestion}` : ''
      } catch {}
    }, 220)
  })
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Tab' && suggestion) {
      event.preventDefault()
      input.value = `${input.value}${suggestion}`
      suggestion = ''
      suggestionHint.textContent = ''
      return
    }
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault()
      form.requestSubmit()
    }
  })
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const message = input.value.trim()
    if (!message) return
    messages.insertAdjacentHTML('beforeend', `<div class="assistant-message assistant-message-user"><strong>You</strong><p>${escapeHtml(message)}</p></div>`)
    input.value = ''
    suggestion = ''
    suggestionHint.textContent = ''
    input.disabled = true
    form.querySelector('button').disabled = true
    status.textContent = 'Old Mark Donald is thinking…'
    messages.scrollTop = messages.scrollHeight
    try {
      const response = await api.assistantChat({ message, previousMessage })
      previousMessage = message
      const chartHtml = assistantChartHtml(response.chart)
      messages.insertAdjacentHTML('beforeend', `<div class="assistant-message assistant-message-bot"><strong>Old Mark Donald</strong><p>${escapeHtml(response.reply)}</p>${chartHtml}</div>`)
      status.textContent = response.source === 'model' ? 'Connected to the configured AI model.' : 'Using the local farm assistant until an AI model is configured.'
    } catch (error) {
      messages.insertAdjacentHTML('beforeend', `<div class="assistant-message assistant-message-bot"><strong>Old Mark Donald</strong><p>${escapeHtml(error.message)}</p></div>`)
      status.textContent = 'Assistant unavailable.'
    } finally {
      input.disabled = false
      form.querySelector('button').disabled = false
      input.focus()
      messages.scrollTop = messages.scrollHeight
    }
  })
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
  shell(user, 'Sales & orders', `<section class="welcome-row"><div><p class="eyebrow">Farm transactions</p><h1>${admin ? 'Sales &amp; orders' : 'Order requests'}</h1><p class="intro">${admin ? 'Record sales, manage customer requests, and review totals.' : 'Send the farm an order request for confirmation.'}</p></div></section>${saleFormMarkup}${admin ? '<h2 class="section-title">Create customer order</h2>' : '<h2 class="section-title">New request</h2>'}${orderFormMarkup}<p class="page-error" id="page-error" role="alert" hidden></p>${salesTable}<h2 class="section-title">${admin ? 'Orders' : 'Your orders'}</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Customer</th><th>Items</th><th>Status</th><th>Total</th>${admin ? '<th>Quote/status</th>' : ''}</tr></thead><tbody id="order-rows"><tr><td colspan="${admin ? 6 : 5}">Loading orders…</td></tr></tbody></table></div>${paymentSection}`)
  const refresh = async () => {
    const orders = await api.orders()
    if (admin) {
      const sales = await api.sales()
      root.querySelector('#sales-rows').innerHTML = sales.sales.length
        ? sales.sales.map((sale) => `<tr><td>${escapeHtml(sale.sold_at)}</td><td>${escapeHtml(sale.description)}</td><td>${escapeHtml(sale.quantity)} ${escapeHtml(sale.unit)}</td><td>${formatMoney(sale.total_amount)}</td></tr>`).join('')
        : '<tr><td colspan="4">No sales recorded.</td></tr>'
    }
    root.querySelector('#order-rows').innerHTML = orders.orders.length
      ? orders.orders.map((order) => `<tr><td>${escapeHtml(order.ordered_at)}</td><td>${escapeHtml(order.customer)}</td><td>${escapeHtml(order.items || '—')}</td><td>${escapeHtml(order.status)}</td><td>${formatMoney(order.total_amount)}</td>${admin ? `<td><form class="order-update" data-order-id="${escapeHtml(order.id)}"><select name="status"><option value="pending" ${order.status === 'pending' ? 'selected' : ''}>Pending</option><option value="accepted" ${order.status === 'accepted' ? 'selected' : ''}>Accepted</option><option value="fulfilled" ${order.status === 'fulfilled' ? 'selected' : ''}>Fulfilled</option><option value="cancelled" ${order.status === 'cancelled' ? 'selected' : ''}>Cancelled</option></select><input name="totalAmount" type="number" min="0" step="0.01" value="${escapeHtml(order.total_amount)}" aria-label="Quoted total in KES"><button class="small-action" type="submit">Save</button></form></td>` : ''}</tr>`).join('')
      : `<tr><td colspan="${admin ? 6 : 5}">No orders recorded.</td></tr>`
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
  const roleOptions = '<option value="worker">Farm worker</option><option value="veterinarian">Veterinarian</option><option value="customer">Customer (produce)</option><option value="buyer">Livestock buyer</option>'
  shell(user, 'User accounts', `<section class="welcome-row"><div><p class="eyebrow">Access control</p><h1>User accounts</h1><p class="intro">Create accounts, approve staff sign-ups, and manage the single admin role.</p></div></section><form id="user-form" class="entry-form"><label>Full name<input name="fullName" required maxlength="120"></label><label>Username${usernameInput}</label><label>Email<input name="email" type="email" required maxlength="190"></label><label>Password<input name="password" type="password" required minlength="12"></label><label>User type<select name="role">${roleOptions}</select></label><button class="primary-button" type="submit">Create account</button><p class="form-message" role="alert" hidden></p></form><p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Email</th><th>User type</th><th>Status</th><th></th></tr></thead><tbody id="user-rows"><tr><td colspan="6">Loading accounts…</td></tr></tbody></table></div><h2 class="section-title">Your username</h2><form id="username-form" class="entry-form"><label>Username${usernameInput}</label><button class="primary-button" type="submit">Save username</button><p class="form-message" role="alert" hidden></p></form><h2 class="section-title">Transfer admin</h2><div id="transfer-panel" class="transfer-panel"><p class="empty-state">Loading…</p></div>`)
  const form = root.querySelector('#user-form')
  const transferPanel = root.querySelector('#transfer-panel')
  const usernameForm = root.querySelector('#username-form')
  usernameForm.addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      await api.setUsername(Object.fromEntries(new FormData(usernameForm)))
      showMessage(usernameForm.querySelector('.form-message'), 'Username saved. You can now sign in with it.')
      await refresh()
    } catch (error) { showMessage(usernameForm.querySelector('.form-message'), error.message, true) }
  })
  const roleNames = { admin: 'Admin', worker: 'Farm worker', veterinarian: 'Veterinarian', customer: 'Customer', buyer: 'Livestock buyer' }
  async function refresh() {
    const { users } = await api.users()
    const me = users.find((account) => Number(account.id) === Number(user.id))
    usernameForm.querySelector('[name="username"]').value = me?.username || ''
    root.querySelector('#user-rows').innerHTML = users.map((account) => {
      const self = Number(account.id) === Number(user.id)
      const active = Number(account.is_active) === 1
      const action = account.role === 'admin' || self
        ? ''
        : `<button class="small-action" data-user-id="${escapeHtml(account.id)}" data-active="${active ? 'false' : 'true'}">${active ? 'Deactivate' : 'Approve'}</button>`
      return `<tr><td>${escapeHtml(account.full_name)}${self ? ' (you)' : ''}</td><td>${escapeHtml(account.username || '—')}</td><td>${escapeHtml(account.email)}</td><td>${escapeHtml(roleNames[account.role] || account.role)}</td><td>${active ? 'Active' : 'Awaiting approval / inactive'}</td><td>${action}</td></tr>`
    }).join('')
  }
  async function refreshTransfer(shareUrl = '', emailed = null) {
    const { invite } = await api.adminTransfer()
    const shareBlock = shareUrl
      ? `<div class="invite-link"><p>${emailed ? 'The invitation was emailed.' : 'Email could not be sent (SMTP not configured), so share this link yourself.'} The link is shown only once and expires in 72 hours.</p><input readonly value="${escapeHtml(shareUrl)}" aria-label="Invitation link"><div class="invite-actions"><button class="small-action" type="button" id="copy-invite">Copy link</button><a class="csv-link" target="_blank" rel="noopener noreferrer" href="https://wa.me/?text=${encodeURIComponent(`You have been invited to become the Flouri Farm LFMIS admin. Confirm here: ${shareUrl}`)}">Share on WhatsApp</a></div></div>`
      : ''
    if (!invite) {
      transferPanel.innerHTML = `<p class="intro">Invite anyone — a farm member or someone new — by email. They confirm through a secure link, then you verify it is the right person and approve. Admin access only changes after your approval.</p><form id="invite-form" class="entry-form"><label>New admin's email<input name="email" type="email" required maxlength="190"></label><label>Your user type afterwards<select name="newRole" required>${roleOptions}</select></label><label>Your current password<input name="password" type="password" required autocomplete="current-password"></label><button class="primary-button" type="submit">Send invitation</button><p class="form-message" role="alert" hidden></p></form>`
      const inviteForm = transferPanel.querySelector('#invite-form')
      inviteForm.addEventListener('submit', async (event) => {
        event.preventDefault()
        try {
          const result = await api.inviteAdmin(Object.fromEntries(new FormData(inviteForm)))
          await refreshTransfer(result.inviteUrl, result.emailed)
        } catch (error) { showMessage(inviteForm.querySelector('.form-message'), error.message, true) }
      })
      return
    }
    if (invite.status === 'pending') {
      transferPanel.innerHTML = `<div class="invite-status"><p class="eyebrow">Step 2 of 3 · waiting for confirmation</p><p>Invitation sent to <strong>${escapeHtml(invite.email)}</strong>. Expires ${escapeHtml(invite.expires_at)}.</p>${shareBlock}<button class="text-link" type="button" id="cancel-invite">Cancel invitation</button></div>`
    } else {
      transferPanel.innerHTML = `<div class="invite-status"><p class="eyebrow">Step 3 of 3 · your approval</p><p>The invitation was confirmed on ${escapeHtml(invite.confirmed_at)} by:</p><dl class="invite-details"><dt>Name</dt><dd>${escapeHtml(invite.recipient_name || '—')}</dd><dt>Username</dt><dd>${escapeHtml(invite.recipient_username || '—')}</dd><dt>Email</dt><dd>${escapeHtml(invite.email)}</dd><dt>You become</dt><dd>${escapeHtml(roleNames[invite.new_role_for_current] || invite.new_role_for_current)}</dd></dl><p>Check with them directly that these details are theirs before approving.</p><form id="approve-form" class="entry-form"><label>Your current password<input name="password" type="password" required autocomplete="current-password"></label><button class="primary-button danger-button" type="submit">Approve and hand over admin</button><p class="form-message" role="alert" hidden></p></form><button class="text-link" type="button" id="cancel-invite">Not the right person — reject</button></div>`
      const approveForm = transferPanel.querySelector('#approve-form')
      approveForm.addEventListener('submit', async (event) => {
        event.preventDefault()
        if (!window.confirm(`Hand over admin to ${invite.recipient_name}? You will lose admin access immediately.`)) return
        try {
          const result = await api.approveAdminTransfer(Object.fromEntries(new FormData(approveForm)))
          renderHome(result.user)
        } catch (error) { showMessage(approveForm.querySelector('.form-message'), error.message, true) }
      })
    }
    transferPanel.querySelector('#copy-invite')?.addEventListener('click', async () => {
      await navigator.clipboard.writeText(shareUrl)
      toast('Invitation link copied')
    })
    transferPanel.querySelector('#cancel-invite').addEventListener('click', async () => {
      if (!window.confirm('Cancel this admin invitation?')) return
      try { await api.cancelAdminInvite(); toast('Invitation cancelled'); await refreshTransfer() }
      catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
    })
  }
  try { await Promise.all([refresh(), refreshTransfer()]) } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    try {
      await api.createUser(Object.fromEntries(new FormData(form)))
      toast('Account created')
      form.reset()
      await refresh()
    } catch (error) { showMessage(form.querySelector('.form-message'), error.message, true) }
  })
  root.querySelector('#user-rows').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-user-id]')
    if (!button) return
    try {
      await api.updateUser(button.dataset.userId, { isActive: button.dataset.active === 'true' })
      toast(button.dataset.active === 'true' ? 'Account approved' : 'Account deactivated')
      await refresh()
    } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  })
}

async function renderAdminInvite(token) {
  root.innerHTML = '<main class="auth-shell"><section class="auth-panel" aria-labelledby="invite-title"><div class="brand auth-brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>LFMIS workspace</small></div></div><p class="eyebrow">Admin handover</p><h1 id="invite-title">Confirm admin invitation</h1><div id="invite-body"><p class="intro">Checking invitation…</p></div><button class="text-link" type="button" id="back-to-login">Go to sign in</button></section></main>'
  root.querySelector('#back-to-login').addEventListener('click', () => renderAuth('login'))
  const body = root.querySelector('#invite-body')
  let invite
  try { invite = await api.adminInvite(token) } catch (error) {
    body.innerHTML = `<p class="form-message">${escapeHtml(error.message)}</p>`
    return
  }
  const fields = invite.hasAccount
    ? '<p class="intro">An account already exists for this email. Enter its password to confirm it is you.</p><label>Password<input type="password" name="password" autocomplete="current-password" required></label>'
    : `<p class="intro">Create your account to accept. It stays inactive until the current admin approves the handover.</p><label>Full name<input name="fullName" autocomplete="name" required maxlength="120"></label><label>Username${usernameInput}</label><label>Password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>`
  body.innerHTML = `<p class="intro"><strong>${escapeHtml(invite.invitedBy)}</strong> invited <strong>${escapeHtml(invite.email)}</strong> to become the farm administrator.</p><form id="invite-accept-form" class="form-stack">${fields}<p class="form-message" role="alert" hidden></p><button class="primary-button" type="submit">Confirm invitation</button></form>`
  const form = body.querySelector('#invite-accept-form')
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const button = form.querySelector('button[type="submit"]')
    button.disabled = true
    try {
      const result = await api.acceptAdminInvite({ ...Object.fromEntries(new FormData(form)), token })
      body.innerHTML = `<p class="form-message success">${escapeHtml(result.message)}</p>`
    } catch (error) {
      showMessage(form.querySelector('.form-message'), error.message, true)
      button.disabled = false
    }
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

async function renderProducts(user) {
  shell(user, 'Products', '<section class="welcome-row"><div><p class="eyebrow">Customer catalog</p><h1>Products</h1><p class="intro">Manage livestock listings and produce availability.</p></div></section><form id="product-form" class="entry-form"><label>Listing name<input name="name" required maxlength="140" placeholder="Fresh milk, Merino wool, etc."></label><label>Type<select name="productType" required><option value="produce">Produce</option><option value="livestock">Livestock</option><option value="other">Other</option></select></label><label id="species-field" hidden>Species<select name="species"><option value="">Choose species</option><option value="cattle">Cattle</option><option value="sheep">Sheep</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="other">Other</option></select></label><label id="production-kind-field">Production source<select name="productionKind"><option value="">Manual stock</option><option value="milk">Milk records</option><option value="eggs">Egg records</option><option value="wool">Wool records</option><option value="other">Other produce</option></select></label><label>Unit<input name="unit" required maxlength="30" value="litres" placeholder="litres, trays, kg"></label><label>Opening stock<input name="availableQuantity" type="number" min="0" step="0.01" value="0" required></label><label>Unit price (KES)<input name="unitPrice" type="number" min="0" step="0.01" required></label><button class="primary-button" type="submit">Publish listing</button><p class="form-message" role="alert" hidden></p></form><p class="page-error" id="page-error" role="alert" hidden></p><div class="table-wrap"><table><thead><tr><th>Listing</th><th>Type</th><th>Species/source</th><th>Available</th><th>Unit price</th><th>Active</th><th></th></tr></thead><tbody id="product-rows"><tr><td colspan="7">Loading catalog…</td></tr></tbody></table></div>')
  const form = root.querySelector('#product-form')
  const type = form.querySelector('[name="productType"]')
  const speciesField = form.querySelector('#species-field')
  const sourceField = form.querySelector('#production-kind-field')
  const quantityField = form.querySelector('[name="availableQuantity"]')
  const unitField = form.querySelector('[name="unit"]')
  function updateTypeFields() {
    const livestock = type.value === 'livestock'
    speciesField.hidden = !livestock
    speciesField.querySelector('select').required = livestock
    sourceField.hidden = type.value !== 'produce'
    quantityField.disabled = livestock
    unitField.value = livestock ? 'animals' : unitField.value === 'animals' ? '' : unitField.value
  }
  type.addEventListener('change', updateTypeFields)
  updateTypeFields()
  async function refresh() {
    const { products } = await api.products()
    root.querySelector('#product-rows').innerHTML = products.length
      ? products.map((product) => `<tr><td>${escapeHtml(product.name)}</td><td>${escapeHtml(product.product_type)}</td><td>${escapeHtml(product.species || product.production_kind || '—')}</td><td>${escapeHtml(product.current_availability)} ${escapeHtml(product.unit)}</td><td>${formatMoney(product.unit_price)}</td><td>${Number(product.is_active) ? 'Listed' : 'Hidden'}</td><td><form class="product-update" data-product-id="${escapeHtml(product.id)}"><input name="availableQuantity" type="number" min="0" step="0.01" value="${escapeHtml(product.available_quantity)}" ${product.product_type === 'livestock' ? 'disabled' : ''} aria-label="Stock quantity"><input name="unitPrice" type="number" min="0" step="0.01" value="${escapeHtml(product.unit_price)}" aria-label="Unit price"><label class="checkbox-label"><input name="isActive" type="checkbox" ${Number(product.is_active) ? 'checked' : ''}> Listed</label><button class="small-action" type="submit">Save</button></form></td></tr>`).join('')
      : '<tr><td colspan="7">No customer listings yet.</td></tr>'
  }
  try { await refresh() } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const data = Object.fromEntries(new FormData(form))
    if (data.productType === 'livestock') data.availableQuantity = 0
    try { await api.createProduct(data); toast('Listing published'); form.reset(); updateTypeFields(); await refresh() }
    catch (error) { showMessage(form.querySelector('.form-message'), error.message, true) }
  })
  root.querySelector('#product-rows').addEventListener('submit', async (event) => {
    const rowForm = event.target.closest('.product-update')
    if (!rowForm) return
    event.preventDefault()
    const data = Object.fromEntries(new FormData(rowForm))
    data.isActive = rowForm.querySelector('[name="isActive"]').checked
    try { await api.updateProduct(rowForm.dataset.productId, data); toast('Listing updated'); await refresh() }
    catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  })
}

async function renderStorefront(user) {
  const buyer = user.role === 'buyer'
  const copy = buyer
    ? { label: 'Livestock market', title: 'Buy livestock', intro: 'Choose an animal type and how many you need. Availability is checked against the farm\'s live herd records.', grid: 'Animals available', item: 'Animal type', qty: 'Number of animals', empty: 'The farm has not listed any livestock for sale yet.' }
    : { label: 'Farm produce shop', title: 'Buy farm produce', intro: 'Fresh milk, eggs, wool and other produce from Flouri Farm.', grid: 'Produce available', item: 'Produce', qty: 'Quantity', empty: 'The farm has not listed any produce yet.' }
  root.innerHTML = `<main class="storefront"><header class="storefront-header"><div class="brand"><span class="brand-mark">F</span><div><strong>Flouri Farm</strong><small>${copy.label}</small></div></div><div class="storefront-actions"><span>Signed in as ${escapeHtml(user.fullName)} · ${buyer ? 'Livestock buyer' : 'Customer'}</span><button class="header-logout" id="store-logout" type="button" aria-label="Sign out" title="Sign out">↪ Sign out</button></div></header><div class="storefront-main"><section class="storefront-intro"><p class="eyebrow">Direct from Flouri Farm</p><h1>${copy.title}</h1><p class="intro">${copy.intro}</p></section><p class="page-error" id="page-error" role="alert" hidden></p><section class="store-layout"><div><h2>${copy.grid}</h2><div class="store-grid" id="store-products"><p class="empty-state">Loading…</p></div></div><section class="store-order-panel"><p class="eyebrow">Your purchase request</p><h2>${buyer ? 'Choose animals' : 'Choose produce'}</h2><form id="store-order-form" class="form-stack"><label>${copy.item}<select name="productId" required><option value="">Choose a listing</option></select></label><label>${copy.qty}<input name="quantity" type="number" min="${buyer ? 1 : 0.01}" step="${buyer ? 1 : 0.01}" required></label><p class="store-availability" id="store-availability">Choose an item to see current availability.</p><button class="primary-button" type="submit">Send purchase request</button>${buyer ? '<button class="csv-link" type="button" id="selection-csv">Download CSV of these animals</button>' : ''}<p class="form-message" role="alert" hidden></p></form></section></section><section class="store-orders"><div class="store-orders-heading"><div><p class="eyebrow">Order history</p><h2>Your requests</h2></div><button class="csv-link" type="button" id="orders-csv">Download orders CSV</button></div><div class="table-wrap"><table><thead><tr><th>Requested</th><th>Items</th><th>Status</th><th>Quoted total</th></tr></thead><tbody id="store-order-rows"><tr><td colspan="4">Loading orders…</td></tr></tbody></table></div></section></div><div id="toast" class="toast" role="status"></div></main>`
  root.querySelector('#store-logout').addEventListener('click', signOut)
  const form = root.querySelector('#store-order-form')
  const selector = form.querySelector('[name="productId"]')
  const quantityInput = form.querySelector('[name="quantity"]')
  const availability = root.querySelector('#store-availability')
  const formMessage = form.querySelector('.form-message')
  let products = []

  async function refreshOrders() {
    const { orders } = await api.orders()
    root.querySelector('#store-order-rows').innerHTML = orders.length
      ? orders.map((order) => `<tr><td>${escapeHtml(order.ordered_at)}</td><td>${escapeHtml(order.items || '—')}</td><td>${escapeHtml(order.status)}</td><td>${formatMoney(order.total_amount)}</td></tr>`).join('')
      : '<tr><td colspan="4">No purchase requests yet.</td></tr>'
  }
  async function refreshProducts() {
    products = (await api.storeProducts()).products
    selector.innerHTML = '<option value="">Choose a listing</option>' + products.map((product) => `<option value="${escapeHtml(product.id)}" ${Number(product.available_quantity) <= 0 ? 'disabled' : ''}>${escapeHtml(product.name)} · ${escapeHtml(Number(product.available_quantity))} ${escapeHtml(product.unit)}</option>`).join('')
    root.querySelector('#store-products').innerHTML = products.length
      ? products.map((product) => `<article class="store-product"><p class="eyebrow">${escapeHtml(product.species || product.production_kind || product.product_type)}</p><h3>${escapeHtml(product.name)}</h3><p class="store-stock">${escapeHtml(Number(product.available_quantity))} ${escapeHtml(product.unit)} available</p><strong>${formatMoney(product.unit_price)} <small>/ ${escapeHtml(buyer ? 'animal' : product.unit)}</small></strong><button class="text-button" type="button" data-select-product="${escapeHtml(product.id)}" ${Number(product.available_quantity) <= 0 ? 'disabled' : ''}>${Number(product.available_quantity) <= 0 ? 'Out of stock' : 'Select →'}</button></article>`).join('')
      : `<p class="empty-state">${copy.empty}</p>`
  }
  function selectedProduct() {
    return products.find((item) => String(item.id) === String(selector.value))
  }
  function updateAvailability() {
    const product = selectedProduct()
    const requested = Number(quantityInput.value)
    availability.classList.remove('error', 'ok')
    if (!product) {
      availability.textContent = 'Choose an item to see current availability.'
      return true
    }
    const available = Number(product.available_quantity)
    quantityInput.max = available
    if (!requested) {
      availability.textContent = `${available} ${product.unit} available · ${formatMoney(product.unit_price)} each ${buyer ? 'animal' : product.unit}.`
      return true
    }
    if (buyer && !Number.isInteger(requested)) {
      availability.textContent = 'Enter a whole number of animals.'
      availability.classList.add('error')
      return false
    }
    if (requested > available) {
      availability.textContent = `Only ${available} ${product.unit} available — reduce the quantity.`
      availability.classList.add('error')
      return false
    }
    availability.textContent = `✓ ${requested} of ${available} available · estimated ${formatMoney(requested * Number(product.unit_price))}. The farm confirms your request before fulfillment.`
    availability.classList.add('ok')
    return true
  }

  try {
    await refreshProducts()
    await refreshOrders()
  } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }

  selector.addEventListener('change', updateAvailability)
  quantityInput.addEventListener('input', updateAvailability)
  root.querySelector('#store-products').addEventListener('click', (event) => {
    const button = event.target.closest('[data-select-product]')
    if (!button) return
    selector.value = button.dataset.selectProduct
    updateAvailability()
    quantityInput.focus()
  })
  root.querySelector('#selection-csv')?.addEventListener('click', async () => {
    if (!form.reportValidity() || !updateAvailability()) return
    try {
      await api.downloadLivestockSelection(selector.value, quantityInput.value)
      showMessage(formMessage, '')
    } catch (error) { showMessage(formMessage, error.message, true) }
  })
  root.querySelector('#orders-csv').addEventListener('click', async () => {
    try { await api.downloadOrders() } catch (error) { showMessage(root.querySelector('#page-error'), error.message, true) }
  })
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    if (!updateAvailability()) return
    try {
      await api.createOrder({ items: [{ productId: selector.value, quantity: quantityInput.value }] })
      toast('Purchase request sent to the farm')
      showMessage(formMessage, '')
      form.reset()
      await refreshProducts()
      updateAvailability()
      await refreshOrders()
    } catch (error) { showMessage(formMessage, error.message, true) }
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
  if (section === 'Products' && user.role === 'admin') return renderProducts(user)
  if (section === 'Feedback' && ['admin', 'customer'].includes(user.role)) return renderFeedback(user)
  if (section === 'Reports') return renderReports(user)
  if (section === 'Old Mark Donald') return renderAssistant(user)
  renderNotReady(user, section)
}

function renderHome(user) {
  if (['customer', 'buyer'].includes(user.role)) return renderStorefront(user)
  return renderDashboard(user)
}

async function start() {
  root.innerHTML = '<main class="auth-shell"><p class="intro">Connecting to the farm database…</p></main>'
  const resetToken = new URLSearchParams(window.location.hash.slice(1)).get('reset_token')
  const inviteToken = new URLSearchParams(window.location.hash.slice(1)).get('admin_invite')
  if (inviteToken) {
    window.history.replaceState(null, '', window.location.pathname + window.location.search)
    renderAdminInvite(inviteToken)
    return
  }
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
      renderLanding()
      return
    }
    root.innerHTML = `<main class="auth-shell"><section class="auth-panel"><h1>Can't connect to LFMIS</h1><p class="intro">${escapeHtml(error.message)} Start the PHP API and confirm the MySQL service is running.</p></section></main>`
  }
}

new MutationObserver(addPasswordToggles).observe(root, { childList: true, subtree: true })
start()