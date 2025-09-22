// pexelle-btn.js
(function () {
  function addButton() {
    var rows = document.querySelectorAll(
      '.ld-profile .ld-course-list .ld-course-list-items .ld-course-list-item, ' +
      '.ld-profile .ld-item-list .ld-item-list-item, ' +
      '.ld-profile .ld-dashboard .ld-table-list .ld-table-list-item'
    );
    if (!rows.length) return;

    rows.forEach(function (row) {
      var actions =
        row.querySelector('.ld-item-actions') ||
        row.querySelector('.ld-course-actions') ||
        row.querySelector('.ld-table-list-item-actions') ||
        row.querySelector('.ld-status-actions');

      if (!actions) return;

      if (actions.querySelector('.pexelle-app-btn')) return;

      var a = document.createElement('a');
      a.className = 'ld-button ld-button-primary pexelle-app-btn';
      a.href = (window.LD_PEXELLE_BTN && LD_PEXELLE_BTN.url) || '/app';
      a.target = (window.LD_PEXELLE_BTN && LD_PEXELLE_BTN.target) || '_blank';
      a.rel = 'noopener';
      a.textContent = (window.LD_PEXELLE_BTN && LD_PEXELLE_BTN.label) || 'Pexelle App';

      var cert =
        actions.querySelector('a[title*="Certificate" i]') ||
        actions.querySelector('a[href*="certificate" i]') ||
        actions.querySelector('.ld-icon-certificate') ||
        actions.querySelector('.ld-status-cert');

      if (cert && cert.parentNode) {
        cert.parentNode.insertBefore(a, cert.nextSibling);
      } else {
        actions.appendChild(a);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addButton);
  } else {
    addButton();
  }

  var tries = 0;
  var iv = setInterval(function () {
    tries++;
    addButton();
    if (tries > 8) clearInterval(iv);
  }, 600);
})();
