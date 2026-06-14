/**
 * AabenIntra theme entry. Built by Vite into dist/main.js.
 *
 * Owns the per-user customisation surface: the Personalise panel applies accent,
 * colour-scheme and density to <html> instantly (Gin-style live preview) and
 * persists to the server (user.data via the aabenintra_theme module) plus
 * localStorage as an instant-apply cache.
 */

const root = document.documentElement;
const LS_KEY = 'aabenintra:prefs';

function settings() {
  return (window.drupalSettings && window.drupalSettings.aabenintra) || {};
}

function apply(prefs) {
  if (prefs.accent) root.setAttribute('data-accent', prefs.accent);
  if (prefs.accent_custom) {
    root.style.setProperty('--accent', prefs.accent_custom);
    root.setAttribute('data-accent', 'custom');
  }
  if (prefs.color_scheme) root.setAttribute('data-color-scheme', prefs.color_scheme);
  if (prefs.density) root.setAttribute('data-density', prefs.density);
}

function readLocal() {
  try { return JSON.parse(localStorage.getItem(LS_KEY)) || {}; } catch { return {}; }
}
function writeLocal(prefs) {
  try { localStorage.setItem(LS_KEY, JSON.stringify(prefs)); } catch { /* ignore */ }
}

let current = {};
let saveTimer = null;

function persist() {
  const { endpoint, csrfToken } = settings();
  if (!endpoint) return;
  // One debounced request carrying the final full state - no read-modify-write
  // race, no out-of-order commits losing updates.
  fetch(endpoint, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
    },
    body: JSON.stringify(current),
    credentials: 'same-origin',
  }).catch(() => { /* offline cache already applied */ });
}

function save(patch) {
  current = { ...current, ...patch };
  apply(current);            // instant visual
  writeLocal(current);       // instant offline cache
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = setTimeout(persist, 350);
}

function initPanel() {
  const panel = document.querySelector('[data-ai-personalise]');
  const toggle = document.querySelector('[data-ai-personalise-toggle]');
  const backdrop = document.querySelector('[data-ai-personalise-backdrop]');
  if (!panel || !toggle) return;

  const open = (state) => {
    panel.classList.toggle('is-open', state);
    backdrop && backdrop.classList.toggle('is-open', state);
    toggle.setAttribute('aria-expanded', String(state));
  };
  toggle.addEventListener('click', () => open(!panel.classList.contains('is-open')));
  backdrop && backdrop.addEventListener('click', () => open(false));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') open(false); });

  // Accent swatches
  panel.querySelectorAll('.ai-swatch[data-accent]').forEach((btn) => {
    btn.addEventListener('click', () => {
      panel.querySelectorAll('.ai-swatch').forEach((b) => b.setAttribute('aria-pressed', 'false'));
      btn.setAttribute('aria-pressed', 'true');
      root.style.removeProperty('--accent');
      save({ accent: btn.dataset.accent, accent_custom: '' });
    });
  });
  // Custom colour picker
  const custom = panel.querySelector('.ai-swatch--custom input[type="color"]');
  custom && custom.addEventListener('input', (e) => {
    save({ accent: 'custom', accent_custom: e.target.value });
  });

  // Segmented toggles (scheme + density)
  panel.querySelectorAll('[data-ai-segment]').forEach((seg) => {
    const key = seg.dataset.aiSegment; // 'color_scheme' | 'density'
    seg.querySelectorAll('button').forEach((b) => {
      b.addEventListener('click', () => {
        seg.querySelectorAll('button').forEach((x) => x.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        save({ [key]: b.dataset.value });
      });
    });
  });
}

function initDashboard() {
  const grid = document.querySelector('[data-ai-dashboard]');
  if (!grid) return;
  grid.classList.add('is-reorderable');

  const order = () =>
    [...grid.querySelectorAll('.tile')].map((t) => Number(t.dataset.tileId));
  const pins = () =>
    [...grid.querySelectorAll('.tile__pinbtn[aria-pressed="true"]')]
      .map((b) => Number(b.dataset.aiPin));

  // Pin toggles
  grid.querySelectorAll('.tile__pinbtn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const pressed = btn.getAttribute('aria-pressed') === 'true';
      btn.setAttribute('aria-pressed', String(!pressed));
      btn.closest('.tile')?.classList.toggle('is-pinned', !pressed);
      save({ tile_pinned: pins(), tile_order: order() });
    });
  });

  // Drag-to-reorder (handle-initiated, native DnD)
  let dragged = null;
  grid.querySelectorAll('.tile').forEach((tile) => {
    const handle = tile.querySelector('[data-ai-drag]');
    if (handle) handle.addEventListener('mousedown', () => { tile.draggable = true; });
    tile.addEventListener('dragstart', (e) => {
      dragged = tile;
      tile.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    tile.addEventListener('dragend', () => {
      tile.classList.remove('is-dragging');
      tile.draggable = false;
      grid.querySelectorAll('.is-dragover').forEach((t) => t.classList.remove('is-dragover'));
      if (dragged) { save({ tile_order: order(), tile_pinned: pins() }); dragged = null; }
    });
    tile.addEventListener('dragover', (e) => {
      e.preventDefault();
      if (!dragged || dragged === tile) return;
      tile.classList.add('is-dragover');
      const rect = tile.getBoundingClientRect();
      const after = e.clientY > rect.top + rect.height / 2;
      grid.insertBefore(dragged, after ? tile.nextSibling : tile);
    });
    tile.addEventListener('dragleave', () => tile.classList.remove('is-dragover'));
  });
}

function initNav() {
  const nav = document.querySelector('[data-ai-nav]');
  const toggle = document.querySelector('[data-ai-nav-toggle]');
  const backdrop = document.querySelector('[data-ai-nav-backdrop]');
  if (!nav || !toggle) return;

  const open = (state) => {
    nav.classList.toggle('is-open', state);
    backdrop && backdrop.classList.toggle('is-open', state);
    toggle.setAttribute('aria-expanded', String(state));
    // Record the preference (collapsed = drawer not open).
    save({ nav_collapsed: !state });
  };
  toggle.addEventListener('click', () => open(!nav.classList.contains('is-open')));
  backdrop && backdrop.addEventListener('click', () => open(false));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') open(false); });
  nav.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => open(false)));
}

function init() {
  initPanel();
  initNav();
  initDashboard();
}

// Boot
current = { ...readLocal(), ...(settings().prefs || {}) };
apply(current);
if (document.readyState !== 'loading') init();
else document.addEventListener('DOMContentLoaded', init);
