// PhotoSwipe gallery initialization and mark button logic
// Externalized from Fluid template to be included via <f:asset.script type="module">.

import PhotoSwipeLightbox from 'https://unpkg.com/photoswipe@5/dist/photoswipe-lightbox.esm.min.js';
import PhotoSwipe from 'https://unpkg.com/photoswipe@5/dist/photoswipe.esm.min.js';

// SVG icons (white outline vs filled) used in both grid and lightbox
const STAR_OUTLINE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="pswp-icn-svg" focusable="false" aria-hidden="true"><polygon points="12,2 15,8.5 22,9.3 17,14 18.5,21 12,17.5 5.5,21 7,14 2,9.3 9,8.5" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round"/></svg>';
const STAR_FILLED_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="pswp-icn-svg" focusable="false" aria-hidden="true"><polygon points="12,2 15,8.5 22,9.3 17,14 18.5,21 12,17.5 5.5,21 7,14 2,9.3 9,8.5" fill="#fff" stroke="#fff" stroke-width="2" stroke-linejoin="round"/></svg>';

function initGallery(galleryEl) {
  if (!galleryEl) return;

  const contentUid = parseInt(galleryEl.dataset.contentUid || '0', 10);
  const maxSelectable = parseInt(galleryEl.dataset.max || '0', 10);
  const gallerySelector = `#pswp-gallery-${contentUid}`;

  let marked = [];
  try { marked = JSON.parse(galleryEl.dataset.state || '[]') || []; } catch (e) {}

  // Helpers to read options from data-*
  const readFloat = (v, d) => { const n = parseFloat(v); return Number.isFinite(n) ? n : d; };
  const readBool = (v, d) => { if (v === '1' || v === 'true') return true; if (v === '0' || v === 'false') return false; return d; };
  const readStr  = (v, d) => (v && v.length ? v : d);

  const psOptions = {
    initialZoomLevel: readFloat(galleryEl.dataset.initialZoomLevel, 1),
    secondaryZoomLevel: readFloat(galleryEl.dataset.secondaryZoomLevel, 2),
    maxZoomLevel: readFloat(galleryEl.dataset.maxZoomLevel, 4),
    mouseMovePan: readBool(galleryEl.dataset.mouseMovePan, true),
    showHideAnimationType: readStr(galleryEl.dataset.showHideAnimationType, 'zoom'),
    bgOpacity: readFloat(galleryEl.dataset.bgOpacity, 0.8)
  };

  function updateButtons() {
    galleryEl.querySelectorAll('.js-pswp-mark').forEach(btn => {
      const refUid = parseInt(btn.dataset.refuid, 10);
      const isMarked = marked.includes(refUid);
      btn.classList.toggle('is-marked', isMarked);
      btn.setAttribute('aria-pressed', isMarked ? 'true' : 'false');
      const icon = btn.querySelector('.js-pswp-mark-icon');
      if (icon) icon.innerHTML = isMarked ? STAR_FILLED_SVG : STAR_OUTLINE_SVG;
    });
  }

  async function toggleMark(refUid) {
    const isMarked = marked.includes(refUid);
    if (!isMarked && maxSelectable > 0 && marked.length >= maxSelectable) {
      alert(`Es dürfen maximal ${maxSelectable} Bilder markiert werden.`);
      return;
    }
    const form = new FormData();
    form.set('contentUid', String(contentUid));
    form.set('refUid', String(refUid));
    form.set('action', 'toggle');
    try {
      const res = await fetch('index.php?photoswipe_mark=1', { method: 'POST', body: form, credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) {
        marked = json.marked || [];
        updateButtons();
        updatePswpButton();
      } else if (json.error === 'limit_reached') {
        alert(`Es dürfen maximal ${json.max} Bilder markiert werden.`);
      } else if (json.error) {
        console.warn('PhotoSwipe mark error', json.error);
      }
    } catch (e) {
      console.error(e);
    }
  }

  const lightbox = new PhotoSwipeLightbox(Object.assign({
    gallery: gallerySelector,
    children: 'a',
    pswpModule: () => PhotoSwipe
  }, psOptions));

  // Lightbox "Mark" button inside PhotoSwipe UI
  let pswpMarkBtnEl = null;
  function currentRefUidFromPswp() {
    const pswp = lightbox.pswp;
    const el = pswp?.currSlide?.data?.element;
    const v = el?.dataset?.refuid || '0';
    const id = parseInt(v, 10);
    return Number.isFinite(id) ? id : 0;
  }
  function updatePswpButton() {
    if (!pswpMarkBtnEl || !lightbox.pswp) return;
    const refUid = currentRefUidFromPswp();
    const isMarked = refUid > 0 && marked.includes(refUid);
    pswpMarkBtnEl.classList.toggle('is-marked', !!isMarked);
    const icn = pswpMarkBtnEl.querySelector('.pswp__icn-star');
    if (icn) icn.innerHTML = isMarked ? STAR_FILLED_SVG : STAR_OUTLINE_SVG;
    pswpMarkBtnEl.setAttribute('aria-pressed', isMarked ? 'true' : 'false');
    pswpMarkBtnEl.setAttribute('title', isMarked ? 'Markierung entfernen' : 'Bild markieren');
  }

  lightbox.on('uiRegister', () => {
    lightbox.pswp?.ui?.registerElement({
      name: 'markButton',
      order: 9,
      isButton: true,
      ariaLabel: 'Bild markieren',
      className: 'pswp__button--markButton',
      html: '<span class="pswp__icn-star" aria-hidden="true"></span>',
      onInit: (el) => { pswpMarkBtnEl = el; updatePswpButton(); },
      onClick: () => {
        const refUid = currentRefUidFromPswp();
        if (refUid > 0) {
          toggleMark(refUid).then(() => updatePswpButton());
        }
      }
    });
  });

  lightbox.on('afterInit', () => updatePswpButton());
  lightbox.on('change', () => updatePswpButton());

  lightbox.init();

  // Prevent PhotoSwipe/lightbox from opening when clicking the star button
  // Capture-phase guard: do NOT call preventDefault here, otherwise the subsequent click event may not be fired on the button.
  const stopCapture = (ev) => {
    try { ev.stopImmediatePropagation(); } catch (e) {}
    try { ev.stopPropagation(); } catch (e) {}
    return false;
  };
  // Bubble-phase click guard on the button: stop and prevent navigation
  const stopClick = (ev) => {
    try { ev.stopImmediatePropagation(); } catch (e) {}
    try { ev.stopPropagation(); } catch (e) {}
    try { ev.preventDefault(); } catch (e) {}
    return false;
  };

  // Bind handlers directly on each button to ensure capture-phase blocking
  const bindButtonGuards = () => {
    const buttons = galleryEl.querySelectorAll('.js-pswp-mark');
    buttons.forEach((btn) => {
      ['pointerdown','mousedown','touchstart'].forEach((evt) => {
        btn.addEventListener(evt, (e) => { stopCapture(e); }, { capture: true });
      });
      btn.addEventListener('click', (e) => {
        stopClick(e);
        const refUid = parseInt(btn.dataset.refuid, 10);
        toggleMark(refUid);
      });
      btn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          stopClick(e);
          const refUid = parseInt(btn.dataset.refuid, 10);
          toggleMark(refUid);
        }
      });
    });
  };

  bindButtonGuards();
  updateButtons();
}

// Initialize all galleries on the page; guard against double init
document.querySelectorAll('.photoswipe-gallery').forEach((galleryEl) => {
  if (galleryEl.dataset.pswpInitialized === '1') return;
  galleryEl.dataset.pswpInitialized = '1';
  initGallery(galleryEl);
});
