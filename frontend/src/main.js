import './style.css'
import { api } from './api.js'

const app = document.querySelector('#app')
let currentUser = null

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]))
const message = (text, danger = false) => `<p class="message ${danger ? 'danger' : 'success'}">${escapeHtml(text)}</p>`

function renderAuth(mode = 'login', notice = '') {
  const setup = mode === 'setup'
  app.innerHTML = `<main class="auth-shell"><section class="auth-panel"><p class="kicker">FLOURi FARM · LFMIS</p><h1>${setup ? 'Set up the farm workspace' : 'Welcome back'}</h1><p class="muted">${setup ? 'Create the first farm owner account.' : 'Sign in to manage the farm.'}</p>${notice}${setup ? `<form id="setup-form"><label>Full name<input name="fullName" required></label><label>Username<input name="username" required pattern="[A-Za-z0-9_.]{3,30}"></label><label>Email<input name="email" type="email" required></label><label>Password<span class="password-field"><input name="password" type="password" minlength="12" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Create owner account</button></form>` : `<form id="login-form"><label>Username or email<input name="login" required></label><label>Password<span class="password-field"><input name="password" type="password" required><button type="button" class="password-toggle" aria-label="Show password">◉</button></span></label><button>Sign in</button></form>`}</section></main>`
  const form = app.querySelector('form')
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

async function renderApp() {
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
  app.innerHTML = `<div class="shell"><aside><p class="brand">F<span>F</span></p><p class="kicker">LFMIS</p><h2>Farm workspace</h2><div class="user-chip">${escapeHtml(currentUser.fullName)}<small>${escapeHtml(currentUser.role)}</small></div><button id="logout" class="quiet">Sign out</button></aside><main class="content"><header><div><p class="kicker">OVERVIEW</p><h1>Good day, ${escapeHtml(currentUser.fullName.split(' ')[0])}</h1><p class="muted">Keep the herd records complete and easy to trust.</p></div><span class="health-pill">API connected</span></header><section class="stats"><div><small>Animals</small><strong>${animals.length}</strong></div><div><small>Health records</small><strong>${healthRecords.length}</strong></div><div><small>Role</small><strong>${escapeHtml(currentUser.role)}</strong></div></section><div class="grid"><section class="panel"><div class="panel-head"><h2>Livestock register</h2><span>${animals.length} records</span></div><form id="animal-form" class="compact-form"><input name="tagNumber" placeholder="Tag number" required><select name="species"><option value="cattle">Cattle</option><option value="goat">Goat</option><option value="rabbit">Rabbit</option><option value="chicken">Chicken</option><option value="sheep">Sheep</option></select><input name="breed" placeholder="Breed"><select name="sex"><option value="female">Female</option><option value="male">Male</option></select><button>Add animal</button></form><div class="table-wrap"><table><thead><tr><th>Tag</th><th>Species</th><th>Breed</th><th>Status</th></tr></thead><tbody>${animals.length ? animals.map((animal) => `<tr><td>${escapeHtml(animal.tag_number)}</td><td>${escapeHtml(animal.species)}</td><td>${escapeHtml(animal.breed || '—')}</td><td><span class="status">${escapeHtml(animal.status)}</span></td></tr>`).join('') : '<tr><td colspan="4" class="empty">No animals yet.</td></tr>'}</tbody></table></div></section><section class="panel"><div class="panel-head"><h2>Animal health</h2><span>${healthRecords.length} records</span></div><form id="health-form" class="compact-form"><select name="animalId" required><option value="">Animal</option>${animals.map((animal) => `<option value="${animal.id}">${escapeHtml(animal.tag_number)}</option>`).join('')}</select><select name="recordType"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="deworming">Deworming</option><option value="checkup">Checkup</option></select><input name="description" placeholder="Description" required><input name="recordDate" type="date" required><button>Save record</button></form><div class="table-wrap"><table><thead><tr><th>Date</th><th>Animal</th><th>Type</th><th>Description</th></tr></thead><tbody>${healthRecords.length ? healthRecords.map((record) => `<tr><td>${escapeHtml(record.record_date)}</td><td>${escapeHtml(record.tag_number)}</td><td>${escapeHtml(record.record_type)}</td><td>${escapeHtml(record.description)}</td></tr>`).join('') : '<tr><td colspan="4" class="empty">No health records yet.</td></tr>'}</tbody></table></div></section></div></main></div>`
  app.querySelector('#logout').addEventListener('click', async () => { await api.logout(); currentUser = null; renderAuth() })
  app.querySelector('#animal-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createAnimal(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
  app.querySelector('#health-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api.createHealthRecord(Object.fromEntries(new FormData(event.target))); await renderApp() } catch (error) { alert(error.message) } })
}

async function start() {
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
      renderAuth(status.setupRequired ? 'setup' : 'login')
    } catch (error) { app.innerHTML = message(`Backend unavailable: ${error.message}`, true) }
  }
}

start()
