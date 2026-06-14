/**
 * @file
 * Progressive enhancement for channel follow buttons: AJAX toggle.
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  Drupal.behaviors.aabenintraFollow = {
    attach(context) {
      once('ai-follow', '.ai-follow', context).forEach((btn) => {
        btn.addEventListener('click', async () => {
          const tid = btn.dataset.topic;
          btn.disabled = true;
          try {
            const response = await fetch(
              `${drupalSettings.path.baseUrl}aabenintra/follow/${tid}`,
              {
                method: 'POST',
                headers: {
                  'X-CSRF-Token': drupalSettings.aabenintraChannels.csrfToken,
                  'Accept': 'application/json',
                },
              },
            );
            if (!response.ok) {
              return;
            }
            const data = await response.json();
            btn.classList.toggle('is-following', data.following);
            btn.setAttribute('aria-pressed', data.following ? 'true' : 'false');
            const label = btn.querySelector('.ai-follow__label');
            if (label) {
              label.textContent = data.following ? Drupal.t('Following') : Drupal.t('Follow');
            }
            const count = btn.querySelector('[data-follow-count]');
            if (count) {
              count.textContent = data.followers;
            }
          } finally {
            btn.disabled = false;
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
