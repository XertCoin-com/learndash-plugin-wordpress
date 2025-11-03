(function(){
  if (typeof PSL_SETTINGS === 'undefined') return;

  const Modal = {
    el: null,
    qrEl: null,
    actionsEl: null,
    qrcode: null,

    open(payload){
      if (!this.el) return;
      this.el.setAttribute('aria-hidden', 'false');

      let qrUrl = '';
      let approveUrl = '';
      if (typeof payload === 'string') {
        qrUrl = payload;
      } else if (payload && typeof payload === 'object') {
        qrUrl = payload.qrUrl || '';
        approveUrl = payload.approveUrl || '';
      }

      this.renderQR(qrUrl);
      this.renderActions(approveUrl);
    },

    close(){
      if (!this.el) return;
      this.el.setAttribute('aria-hidden', 'true');
      this.clearQR();
      this.clearActions();
    },

    renderQR(url){
      if (!this.qrEl) return;
      this.clearQR();
      if (!url) {
        this.qrEl.innerHTML = '<p style="color:#c00">QR URL missing.</p>';
        return;
      }
      try {
        this.qrcode = new QRCode(this.qrEl, {
          text: url,
          width: 220,
          height: 220,
          correctLevel: QRCode.CorrectLevel.M
        });
      } catch(e) {
        this.qrEl.innerHTML = '<p style="color:#c00">QR generation failed.</p>';
      }
    },

    clearQR(){
      if (this.qrEl) this.qrEl.innerHTML = '';
      this.qrcode = null;
    },

    renderActions(approveUrl){
      if (!this.actionsEl) return;
      this.clearActions();

      if (approveUrl) {
        const approveBtn = document.createElement('a');
        approveBtn.href = approveUrl;
        approveBtn.target = '_blank';
        approveBtn.rel = 'noopener';
        approveBtn.className = 'psl-btn';
        approveBtn.textContent = 'Approve login';
        approveBtn.dataset.pslDynamic = '1';
        this.actionsEl.appendChild(approveBtn);
      }
    },

    clearActions(){
      if (!this.actionsEl) return;
      const dynamic = this.actionsEl.querySelectorAll('[data-psl-dynamic="1"]');
      dynamic.forEach(el => el.remove());
    },

    mount(){
      this.el = document.getElementById('psl-modal');
      if (!this.el) return;
      this.qrEl = document.getElementById('psl-qr');
      this.actionsEl = this.el.querySelector('.psl-modal__actions') || null;

      this.el.addEventListener('click', (e)=>{
        if (e.target && e.target.hasAttribute('data-psl-close')) this.close();
      });
      const closeBtn = this.el.querySelector('.psl-modal__close');
      if (closeBtn) closeBtn.addEventListener('click', ()=>this.close());
      document.addEventListener('keydown', (e)=>{
        if (e.key === 'Escape') this.close();
      });
    }
  };

  function createMagicRequestAll(){
    if (!PSL_SETTINGS.ajax_url) {
      return Promise.reject(new Error('ajax_url not set'));
    }
    // action dedicated for "all courses"
    const body = 'action=psl_magic_create_all';
    return fetch(PSL_SETTINGS.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    }).then(r => r.json());
  }

  function onShareAllClick(){
    Modal.open('about:blank');
    if (Modal.qrEl) Modal.qrEl.innerHTML = '<p>Preparing secure link…</p>';

    createMagicRequestAll()
      .then(json => {
        if (!json || !json.success || !json.data) {
          throw new Error((json && json.data) ? json.data : 'Failed to create magic link');
        }
        const qrUrl = json.data.qr_url;
        const approveUrl = json.data.approve_url || '';
        Modal.renderQR(qrUrl);
        Modal.renderActions(approveUrl);
      })
      .catch(err => {
        if (Modal.qrEl) Modal.qrEl.innerHTML = '<p style="color:#c00">Error: ' + (err && err.message ? err.message : 'Unknown') + '</p>';
        Modal.clearActions();
      });
  }

  // Inject a button + <p> under the ld-profile-heading
function injectProfileButton() {
  const selector = PSL_SETTINGS.profileSelector || '.ld-profile-heading';
  const heading = document.querySelector(selector);
  if (!heading) return;
  if (heading.dataset.pslEnhanced === '1') return;

  // Button
  const btn = document.createElement('button');
  btn.className = 'psl-share-btn';
  btn.type = 'button';
  btn.textContent = PSL_SETTINGS.buttonText || 'Add to Pexelle Record';
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    onShareAllClick();
  });

  // <p> description
  const p = document.createElement('p');
  p.className = 'psl-share-desc';
  p.textContent = PSL_SETTINGS.descText || 'Pexelle keeps your verified achievements portable and secure.';

  // Insert in desired order: button first, then p
  heading.insertAdjacentElement('afterend', btn);
  btn.insertAdjacentElement('afterend', p);

  heading.dataset.pslEnhanced = '1';
}

  function watchForProfileHeading() {
    injectProfileButton();
    const mo = new MutationObserver(() => injectProfileButton());
    mo.observe(document.body, { childList: true, subtree: true });
  }

  document.addEventListener('DOMContentLoaded', () => {
    Modal.mount();
    watchForProfileHeading(); // only profile injection; no cert-link buttons anymore
  });

})();
