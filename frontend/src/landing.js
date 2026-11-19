import { api } from './api.js'

const animals = [
  ['cow', 'Cattle'], ['goat', 'Goats'], ['sheep', 'Sheep'], ['chicken', 'Chickens'], ['rabbit', 'Rabbits'],
]

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]))
export const formatEventDate = (date) => new Date(`${date}T12:00:00`).toLocaleDateString('en-KE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

export function renderLanding(root, openSetup, openLogin) {
  const services = [
    ['01', 'Livestock records', 'Every animal registered with tag, breed, age, weight and status.'],
    ['02', 'Animal health', 'Vaccinations, treatments and due dates tracked so nothing is missed.'],
    ['03', 'Daily produce', 'Milk, eggs and wool recorded daily with a clear production history.'],
    ['04', 'Inventory', 'Feed and supplies tracked with automatic low-stock alerts.'],
    ['05', 'Farm shop', 'Customers can request fresh produce directly from the farm.'],
    ['06', 'Livestock sales', 'Buyers can check availability and request animals responsibly.'],
  ]
  const faqs = [
    ['How do I buy produce or livestock?', 'Create a customer account for produce or a buyer account for livestock. The farm confirms each request before fulfilment.'],
    ['How is availability checked?', 'Availability comes from the farm\'s live herd and production records.'],
    ['I work on the farm. How do I get access?', 'Create a staff account and the farm administrator will approve it before sign-in.'],
    ['How do I pay?', 'The farm confirms your order and records the payment method used.'],
  ]
  root.innerHTML = `<div class="landing"><header class="landing-header"><a class="landing-logo" href="#top">F<span>F</span> <b>Flouri Farm</b></a><nav><a href="#services">Services</a><a href="#about">About Us</a><a href="#faq">FAQ</a><a href="#contact">Contact Us</a></nav><div class="landing-actions"><button class="landing-login" type="button" data-login>Log in</button><button class="landing-cta" type="button" data-start>Get started</button></div></header><main id="top"><section class="landing-hero"><div><p class="kicker">LIVESTOCK FARM MANAGEMENT INFORMATION SYSTEM</p><h1>Healthier animals.<br>Better records.<br><em>Fresh from the farm.</em></h1><p class="landing-lead">Flouri Farm manages animals, health care, daily produce, stock and sales in one simple workspace.</p><div class="landing-hero-actions"><button class="landing-cta" type="button" data-start>Get started</button><a href="#services">See what we do <span>→</span></a></div></div><div class="hero-art"><div class="hero-card"><small>Farm at a glance</small><strong>Care, recorded.</strong><span>Milk · Eggs · Livestock</span></div><img src="/farm-scene.svg" alt="Flouri Farm illustrated landscape"></div></section><section class="landing-section" id="services"><p class="kicker">SERVICES</p><h2>Everything the farm runs on</h2><div class="service-grid">${services.map(([number, title, text]) => `<article><span>${number}</span><h3>${title}</h3><p>${text}</p></article>`).join('')}</div></section><section class="landing-section about-band" id="about"><div><p class="kicker">ABOUT US</p><h2>A mixed livestock farm in Nairobi</h2><p>Flouri Farm raises dairy cattle, goats, sheep, rabbits and chickens, and produces fresh milk, eggs and wool.</p><p>LFMIS replaces paper notebooks with one shared record of animal health, daily produce, stock and sales, helping the farm care for animals and serve customers reliably.</p></div><div class="animal-strip">${animals.map(([file, label]) => `<figure><img src="/animals/${file}.svg" alt=""><figcaption>${label}</figcaption></figure>`).join('')}</div></section><section class="landing-section" id="faq"><p class="kicker">FAQ</p><h2>Common questions</h2><div class="faq-list">${faqs.map(([question, answer]) => `<details><summary>${question}</summary><p>${answer}</p></details>`).join('')}</div></section><section class="landing-section contact-band" id="contact"><div><p class="kicker">CONTACT US</p><h2>Visit or call the farm</h2><p>We are here to help with farm visits, orders and account access.</p></div><div class="contact-details"><div><small>Location</small><strong>Nairobi, Kenya</strong></div><div><small>Phone</small><strong><a href="tel:+254701004028">0701 004 028</a></strong></div><div><small>Email</small><strong><a href="mailto:collinskibiru27@gmail.com">collinskibiru27@gmail.com</a></strong></div></div></section></main><footer>© ${new Date().getFullYear()} Flouri Farm · LFMIS</footer></div>`
  const eventSection = document.createElement('section')
  eventSection.className = 'landing-section public-events'
  eventSection.id = 'events'
  eventSection.innerHTML = '<p class="kicker">VISIT THE FARM</p><h2>Upcoming events</h2><p class="event-policy">Join us to see what happens on the farm. Events are daytime visits only, finish by 4:00 p.m., and do not include overnight accommodation.</p><div class="event-public-grid" id="public-event-list"><p class="muted">Loading upcoming events…</p></div>'
  root.querySelector('#contact')?.before(eventSection)
  const eventsLink = document.createElement('a')
  eventsLink.href = '#events'
  eventsLink.textContent = 'Events'
  root.querySelector('.landing-header nav')?.insertBefore(eventsLink, root.querySelector('.landing-header nav a[href="#contact"]'))
  api.events().then(({ events }) => {
    const list = eventSection.querySelector('#public-event-list')
    if (!events.length) {
      list.innerHTML = '<p class="muted">No upcoming events have been published yet. Please check back soon.</p>'
      return
    }
    list.innerHTML = events.map((event) => `<article class="event-card ${event.status === 'cancelled' ? 'is-cancelled' : ''}"><div class="event-card-meta"><time>${formatEventDate(event.event_date)}</time>${event.status === 'cancelled' ? '<span class="event-status status-cancelled">Cancelled</span>' : ''}</div><h3>${escapeHtml(event.title)}</h3><p class="event-time">${escapeHtml(event.start_time.slice(0, 5))}–${escapeHtml(event.end_time.slice(0, 5))} · ${escapeHtml(event.location)}</p><p class="event-description-text">${escapeHtml(event.description)}</p></article>`).join('')
  }).catch(() => {
    eventSection.querySelector('#public-event-list').innerHTML = '<p class="muted">Upcoming event information is temporarily unavailable. Please check back soon.</p>'
  })
  root.querySelectorAll('[data-start]').forEach((button) => button.addEventListener('click', openSetup))
  root.querySelector('[data-login]').addEventListener('click', openLogin)
  root.querySelectorAll('a[href^="#"]').forEach((link) => link.addEventListener('click', (event) => {
    event.preventDefault()
    root.querySelector(link.getAttribute('href'))?.scrollIntoView({ behavior: 'smooth' })
  }))
}
