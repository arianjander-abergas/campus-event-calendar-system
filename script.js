/* =============================================================
   SUPABASE — fetch events (with their images) from the database.
   Falls back to placeholder demo data if Supabase isn't
   configured yet (see supabase-config.js) or the request fails,
   so the page always renders correctly either way.
   ============================================================= */

const DEMO_EVENTS = [
  { title: 'JPSSITE PACE LEVEL UP v.6.2', badge_type: 'seminar', event_date: 'Month 00 0000', location: 'AVR 1, CITE Building', slots_text: '000 slots', image_url: 'https://placehold.co/400x300/6a1b9a/ffffff?text=Event+Cover', is_upcoming: false },
  { title: 'OSH Training or SIES-ACpEs', badge_type: 'seminar', event_date: 'Month 00 0000', location: 'AVR 1, CITE Building', slots_text: '000 slots', image_url: 'https://placehold.co/400x300/b8860b/ffffff?text=Event+Cover', is_upcoming: false },
  { title: 'GEN Z Night 2026', badge_type: 'event', event_date: 'Month 00 0000', location: 'AVR 1, CITE Building', slots_text: '000 slots', image_url: 'https://placehold.co/400x300/1a1a1a/ff3b3b?text=Event+Cover', is_upcoming: true },
  { title: 'JPSSITE TALK MISINFORMATION', badge_type: 'seminar', event_date: 'Month 00 0000', location: 'AVR 1, CITE Building', slots_text: '000 slots', image_url: 'https://placehold.co/400x300/b23a6b/ffffff?text=Event+Cover', is_upcoming: false },
  { title: 'JPSSITE LIVE BAND UPSTATE', badge_type: 'event', event_date: 'Month 00 0000', location: 'AVR 1, CITE Building', slots_text: '000 slots', image_url: 'https://placehold.co/120x120/c62828/ffffff?text=%E2%99%AB', is_upcoming: true },
  { title: 'TAWAG NG JPSSITE', badge_type: 'event', event_date: 'Month 00 0000', location: 'AVR 1, CITE Building', slots_text: '000 slots', image_url: 'https://placehold.co/120x120/8a5a00/ffffff?text=%F0%9F%8E%A4', is_upcoming: true }
];

let supabaseClient = null;

function resolveImageUrl(imageUrl, fallback) {
  if (!imageUrl) return fallback;
  if (/^https?:\/\//i.test(imageUrl)) return imageUrl; // already a full URL
  // Otherwise treat it as a filename inside the Supabase storage bucket
  if (supabaseClient && window.SUPABASE_CONFIG) {
    const { data } = supabaseClient.storage
      .from(window.SUPABASE_CONFIG.imageBucket)
      .getPublicUrl(imageUrl);
    return data?.publicUrl || fallback;
  }
  return fallback;
}

const ICONS = {
  calendar: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>',
  pin: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="2.6"/></svg>',
  seat: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>'
};

function buildEventCard(ev) {
  const badgeClass = ev.badge_type === 'event' ? 'badge-event' : 'badge-seminar';
  const badgeLabel = ev.badge_type === 'event' ? 'Event' : 'Seminar';
  const img = resolveImageUrl(ev.image_url, 'https://placehold.co/400x300/1b5fa8/ffffff?text=Event+Cover');
  const article = document.createElement('article');
  article.className = 'event-card';
  article.innerHTML = `
    <div class="event-thumb">
      <img src="${img}" alt="${ev.title} cover image" loading="lazy">
      <span class="badge ${badgeClass}">${badgeLabel}</span>
    </div>
    <div class="event-body">
      <h3>${ev.title}</h3>
      <ul class="event-meta">
        <li>${ICONS.calendar} ${ev.event_date}</li>
        <li>${ICONS.pin} ${ev.location}</li>
        <li>${ICONS.seat} ${ev.slots_text}</li>
      </ul>
    </div>`;
  return article;
}

function buildUpcomingItem(ev) {
  const img = resolveImageUrl(ev.image_url, 'https://placehold.co/120x120/1b5fa8/ffffff?text=%E2%98%85');
  const li = document.createElement('li');
  li.innerHTML = `
    <img class="upcoming-thumb" src="${img}" alt="${ev.title} thumbnail" loading="lazy">
    <div class="upcoming-info">
      <p class="upcoming-title">${ev.title}</p>
      <p class="upcoming-meta">${ev.event_date} &nbsp;|&nbsp; ${ev.location}</p>
    </div>`;
  return li;
}

function renderEvents(events) {
  const grid = document.getElementById('eventsGrid');
  const upcomingList = document.getElementById('upcomingList');
  if (grid) {
    grid.innerHTML = '';
    events.forEach(ev => grid.appendChild(buildEventCard(ev)));
  }
  if (upcomingList) {
    const upcoming = events.filter(ev => ev.is_upcoming).slice(0, 3);
    if (upcoming.length) {
      upcomingList.innerHTML = '';
      upcoming.forEach(ev => upcomingList.appendChild(buildUpcomingItem(ev)));
    }
  }
  attachEventCardHandlers();
}

async function loadEventsFromSupabase() {
  const config = window.SUPABASE_CONFIG;
  if (!config || !config.isConfigured || typeof supabase === 'undefined') {
    console.info('[Campus Event Hub] Supabase not configured yet — using placeholder demo data. See supabase-config.js.');
    return;
  }
  try {
    supabaseClient = supabase.createClient(config.url, config.anonKey);
    const { data, error } = await supabaseClient
      .from('events')
      .select('*')
      .order('sort_order', { ascending: true })
      .order('created_at', { ascending: true });

    if (error) throw error;
    if (data && data.length) {
      renderEvents(data);
    } else {
      console.info('[Campus Event Hub] Connected to Supabase, but the "events" table is empty — showing placeholders. Run database-schema.sql to add sample rows.');
    }
  } catch (err) {
    console.warn('[Campus Event Hub] Could not load events from Supabase, falling back to placeholders:', err.message || err);
    renderEvents(DEMO_EVENTS);
  }
}

function attachEventCardHandlers() {
  document.querySelectorAll('.event-card').forEach(card => {
    card.style.cursor = 'pointer';
    card.addEventListener('click', () => {
      const title = card.querySelector('h3')?.textContent;
      console.log(`Opening event: ${title}`);
      // Hook point for real navigation, e.g. window.location.href = `/events/${id}`
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {

  /* ---------- Load events (Supabase, falls back to on-page placeholders) ---------- */
  loadEventsFromSupabase();

  /* ---------- Mobile nav toggle ---------- */
  const toggle = document.getElementById('mobileToggle');
  const mobileNav = document.getElementById('mobileNav');

  if (toggle && mobileNav) {
    toggle.addEventListener('click', () => {
      const isOpen = mobileNav.classList.toggle('open');
      toggle.classList.toggle('open', isOpen);
      toggle.setAttribute('aria-expanded', isOpen);
    });

    // Close mobile nav when a link is clicked
    mobileNav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        mobileNav.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  /* ---------- Animated stat counters ---------- */
  // Real figures for the counters; formatted display handled per-stat
  const statTargets = [
    { value: 128, suffix: '+', label: 'Events' },
    { value: 42, suffix: '+', label: 'Organizations' },
    { value: 3.4, suffix: 'k+', label: 'Active Users' },
    { value: 9, suffix: 'k+', label: 'Registrations' }
  ];

  const statEls = document.querySelectorAll('.stat-num');

  function animateCount(el, target, suffix, duration = 1400) {
    const isDecimal = target % 1 !== 0;
    const start = performance.now();

    function tick(now) {
      const progress = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
      const current = target * eased;
      el.textContent = (isDecimal ? current.toFixed(1) : Math.round(current)) + suffix;
      if (progress < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }

  let statsAnimated = false;
  const statsSection = document.querySelector('.feature-strip');

  if (statsSection && statEls.length) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting && !statsAnimated) {
          statsAnimated = true;
          statEls.forEach((el, i) => {
            const data = statTargets[i];
            if (data) animateCount(el, data.value, data.suffix);
          });
          observer.disconnect();
        }
      });
    }, { threshold: 0.4 });

    observer.observe(statsSection);
  }

  /* ---------- Search: pressing Enter gives quick feedback ---------- */
  const searchInputs = document.querySelectorAll('.search-bar input, .mobile-search input');
  searchInputs.forEach(input => {
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && input.value.trim()) {
        e.preventDefault();
        // Scroll to events as the nearest relevant section
        document.getElementById('events')?.scrollIntoView({ behavior: 'smooth' });
      }
    });
  });

  /* ---------- Event card click -> placeholder navigation ---------- */
  attachEventCardHandlers();

  /* ---------- Header shadow on scroll ---------- */
  const header = document.querySelector('.site-header');
  let lastScroll = 0;
  window.addEventListener('scroll', () => {
    const y = window.scrollY;
    header.style.boxShadow = y > 8 ? '0 4px 16px rgba(15,45,75,0.08)' : 'none';
    lastScroll = y;
  }, { passive: true });

});
