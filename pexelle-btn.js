// pexelle-btn.js — v1.0.2
(function () {
  function addButtons() {
    var certLinks = document.querySelectorAll('a.ld-certificate-link');
    if (!certLinks.length) return;

    certLinks.forEach(function (cert) {
      if (cert.nextElementSibling && cert.nextElementSibling.classList &&
          cert.nextElementSibling.classList.contains('pexelle-app-btn')) {
        return;
      }
      var a = document.createElement('a');
      a.className = 'ld-button ld-button-primary pexelle-app-btn';
      a.href   = (window.LD_PEXELLE_BTN && LD_PEXELLE_BTN.url)    || '/app';
      a.target = (window.LD_PEXELLE_BTN && LD_PEXELLE_BTN.target) || '_blank';
      a.rel    = 'noopener';
      a.textContent = (window.LD_PEXELLE_BTN && LD_PEXELLE_BTN.label) || 'Pexelle App';
      cert.insertAdjacentElement('afterend', a);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addButtons);
  } else {
    addButtons();
  }
  var obs = new MutationObserver(function () { addButtons(); });
  obs.observe(document.documentElement, { childList: true, subtree: true });
  var tries = 0;
  var iv = setInterval(function () {
    addButtons(); tries++;
    if (tries > 8) clearInterval(iv);
  }, 600);
})();
