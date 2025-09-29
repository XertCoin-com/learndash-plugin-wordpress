<?php if (!defined('ABSPATH')) { exit; } ?>
<div id="psl-modal" class="psl-modal" aria-hidden="true">
  <div class="psl-modal__overlay" data-psl-close></div>
  <div class="psl-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="psl-modal-title">
    <button class="psl-modal__close" type="button" data-psl-close>&times;</button>
    <h3 id="psl-modal-title" class="psl-modal__title"><?php echo esc_html__('Share to Pexelle', 'psl'); ?></h3>
    <p class="psl-modal__desc"><?php echo esc_html__('Scan this QR to view/verify the certificate:', 'psl'); ?></p>
    <div id="psl-qr" class="psl-qr" aria-live="polite"></div>

    <div class="psl-modal__actions">
      <?php
        $install = \PSL\Psl_Plugin::get_option('help_install_url', '');
        $how     = \PSL\Psl_Plugin::get_option('help_how_url', '');
      ?>
      <?php if (!empty($install)) : ?>
        <a class="psl-btn" target="_blank" rel="noopener" href="<?php echo esc_url($install); ?>">How to install Pexelle?</a>
      <?php endif; ?>
      <?php if (!empty($how)) : ?>
        <a class="psl-btn psl-btn--ghost" target="_blank" rel="noopener" href="<?php echo esc_url($how); ?>">How Pexelle works?</a>
      <?php endif; ?>
    </div>
  </div>
</div>
