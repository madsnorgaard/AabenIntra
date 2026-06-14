/**
 * @file
 * Progressive enhancement for the reactions bar: AJAX toggle + live counts.
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  Drupal.behaviors.aabenintraReactions = {
    attach(context) {
      once('ai-reactions', '.ai-reactions', context).forEach((widget) => {
        const nid = widget.dataset.nid;
        const total = widget.querySelector('.ai-reactions__total');

        widget.addEventListener('click', async (event) => {
          const btn = event.target.closest('.ai-reactions__btn');
          if (!btn) {
            return;
          }
          const reaction = btn.dataset.reaction;
          btn.disabled = true;
          try {
            const response = await fetch(
              `${drupalSettings.path.baseUrl}aabenintra/react/${nid}/${reaction}`,
              {
                method: 'POST',
                headers: {
                  'X-CSRF-Token': drupalSettings.aabenintraSocial.csrfToken,
                  'Accept': 'application/json',
                },
              },
            );
            if (!response.ok) {
              return;
            }
            const data = await response.json();
            // Update every count.
            Object.entries(data.counts).forEach(([key, count]) => {
              const el = widget.querySelector(`.ai-reactions__count[data-count="${key}"]`);
              if (el) {
                el.textContent = count;
              }
            });
            // Update active state (one reaction per user).
            widget.dataset.mine = data.mine || '';
            widget.querySelectorAll('.ai-reactions__btn').forEach((b) => {
              const active = b.dataset.reaction === data.mine;
              b.classList.toggle('is-active', active);
              b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            if (total) {
              const sum = Object.values(data.counts).reduce((a, b) => a + b, 0);
              total.textContent = `${sum} ${Drupal.t('reactions')}`;
            }
          } finally {
            btn.disabled = false;
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
