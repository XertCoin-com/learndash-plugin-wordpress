(function(){
  if (typeof PSL_SETTINGS === 'undefined') return;
  const Modal = {
    el: null,
    qrEl: null,
    qrcode: null,
    open(url){
      if (!this.el) return;
      this.el.setAttribute('aria-hidden', 'false');
      this.renderQR(url);
    },
    close(){
      if (!this.el) return;
      this.el.setAttribute('aria-hidden', 'true');
      this.clearQR();
    },
    renderQR(url){
      if (!this.qrEl) return;
      this.clearQR();
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
    mount(){
      this.el = document.getElementById('psl-modal');
      if (!this.el) return;
      this.qrEl = document.getElementById('psl-qr');
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

  function injectButtons() {
    const selector = PSL_SETTINGS.certSelector || '.ld-certificate-link';
    const certLinks = document.querySelectorAll(selector);
    if (!certLinks || !certLinks.length) return;

    certLinks.forEach((a) => {
      if (a.dataset.pslEnhanced === '1') return;

      const certUrl = a.getAttribute('href');
      if (!certUrl) return;

      const btn = document.createElement('button');
      btn.className = 'psl-share-btn';
      btn.type = 'button';
      btn.textContent = PSL_SETTINGS.buttonText || 'Share to Pexelle';
      btn.addEventListener('click', () => Modal.open(certUrl));

      a.insertAdjacentElement('afterend', btn);
      a.dataset.pslEnhanced = '1';
    });
  }

  function watchForCertificates() {
    injectButtons();
    const mo = new MutationObserver(() => injectButtons());
    mo.observe(document.body, { childList: true, subtree: true });
  }

  document.addEventListener('DOMContentLoaded', () => {
    Modal.mount();
    watchForCertificates();
    const installUrl = PSL_SETTINGS.helpInstall;
    const howUrl = PSL_SETTINGS.helpHow;
  });

})();
