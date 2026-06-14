/**
 * Mandatory-read acknowledgement: POST the receipt, then dismiss the banner.
 */
((Drupal, drupalSettings) => {
  function init() {
    const token = (drupalSettings.aabenintraCompliance || {}).csrfToken || '';
    document.querySelectorAll('[data-ai-ack]').forEach((btn) => {
      if (btn.dataset.aiAckBound) return;
      btn.dataset.aiAckBound = '1';
      btn.addEventListener('click', () => {
        const nid = btn.getAttribute('data-ai-ack');
        btn.disabled = true;
        fetch(`/aabenintra/acknowledge/${nid}`, {
          method: 'POST',
          headers: { 'X-CSRF-Token': token },
          credentials: 'same-origin',
        })
          .then((r) => {
            if (!r.ok) throw new Error('failed');
            const banner = document.querySelector(`[data-ai-mandatory="${nid}"]`);
            if (banner) banner.remove();
          })
          .catch(() => { btn.disabled = false; });
      });
    });
  }
  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})(Drupal, drupalSettings);
