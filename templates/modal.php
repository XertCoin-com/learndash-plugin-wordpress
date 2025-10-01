<?php if (!defined('ABSPATH')) { exit; } ?>
<div id="psl-modal" class="psl-modal" aria-hidden="true">
  <div class="psl-modal__overlay" data-psl-close></div>

  <div class="psl-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="psl-modal-title">
    <button class="psl-modal__close" type="button" data-psl-close aria-label="<?php echo esc_attr__('Close modal', 'pexelle-for-learndash'); ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="M18.3 5.7a1 1 0 0 0-1.4-1.4L12 9.17 7.1 4.3A1 1 0 0 0 5.7 5.7L10.6 10.6 5.7 15.5a1 1 0 1 0 1.4 1.4L12 12.03l4.9 4.87a1 1 0 0 0 1.4-1.4L13.4 10.6l4.9-4.9Z" fill="currentColor"/></svg>
    </button>

    <h3 id="psl-modal-title" class="psl-modal__title"><?php echo esc_html__('Share to Pexelle', 'pexelle-for-learndash'); ?></h3>
    <p class="psl-modal__desc"><?php echo esc_html__('Scan this QR to view/verify the certificate:', 'pexelle-for-learndash'); ?></p>

    <div id="psl-qr" class="psl-qr" aria-live="polite"></div>

    <div class="psl-modal__actions">
      <?php
        $install = \PSL\Psl_Plugin::get_option('help_install_url', '');
        $how     = \PSL\Psl_Plugin::get_option('help_how_url', '');
      ?>

      <?php if (!empty($install)) : ?>
        <a class="psl-btn psl-btn--ghost" target="_blank" rel="noopener" href="<?php echo esc_url($install); ?>">
          <span class="psl-icon" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24"><path d="M12 3a1 1 0 0 1 1 1v8.59l2.3-2.3a1 1 0 1 1 1.4 1.42l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.42l2.3 2.3V4a1 1 0 0 1 1-1ZM5 19a1 1 0 1 0 0 2h14a1 1 0 1 0 0-2H5Z" fill="currentColor"/></svg>
          </span>
          <?php echo esc_html__('How to install Pexelle?', 'pexelle-for-learndash'); ?>
        </a>
      <?php endif; ?>

      <?php if (!empty($how)) : ?>
        <a class="psl-btn psl-btn--ghost" target="_blank" rel="noopener" href="<?php echo esc_url($how); ?>">
          <span class="psl-icon" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10.011 10.011 0 0 0 12 2Zm0 17a1.25 1.25 0 1 1 1.25-1.25A1.25 1.25 0 0 1 12 19Zm2.02-7.98c-.58.5-1.02.89-1.02 1.98a1 1 0 0 1-2 0 3.79 3.79 0 0 1 1.49-3.11c.62-.49.93-.8.93-1.39a1.42 1.42 0 0 0-1.52-1.44 1.71 1.71 0 0 0-1.76 1.41 1 1 0 0 1-1 .83 1 1 0 0 1-.99-1.16 3.72 3.72 0 0 1 3.75-3.08 3.46 3.46 0 0 1 3.52 3.37c0 1.44-.82 2.1-1.4 2.59Z" fill="currentColor"/></svg>
          </span>
          <?php echo esc_html__('How Pexelle works?', 'pexelle-for-learndash'); ?>
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
