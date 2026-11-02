(function () {
  'use strict';
  document.querySelectorAll('[data-wd29-product-link]').forEach(function (box) {
    if (box.dataset.initialized) return;
    box.dataset.initialized = '1';
    var status = box.querySelector('[data-wd29-link-status]');
    var link = box.querySelector('[data-wd29-link-public]');
    var retry = box.querySelector('[data-wd29-link-retry]');
    var host = box.querySelector('[data-wd29-link-host]');
    if (!status || !link || !retry) return;
    function show(node, visible) { node.hidden = !visible; node.style.display = visible ? '' : 'none'; }
    var pending = false;
    var started = false;
    var messages = {
      unmapped: window.InkluraSyncI18n.t('This product has no synchronized mapping yet.'),
      disconnected: window.InkluraSyncI18n.t('No PrestaShop store connected.'),
      unpublished: window.InkluraSyncI18n.t('Remote product not published: no public product page available on PrestaShop.'),
      missing: window.InkluraSyncI18n.t('No matching public product page on PrestaShop. The product may be missing or unpublished.'),
      unavailable: window.InkluraSyncI18n.t('The partner store cannot be checked right now. Try again.')
    };
    function unavailable(message) {
      status.textContent = message || messages.unavailable;
      retry.textContent = window.InkluraSyncI18n.t('Retry');
      show(retry, true);
    }
    async function lookup() {
      if (pending) return;
      pending = true;
      started = true;
      retry.disabled = true;
      show(link, false);
      link.removeAttribute('href');
      box.setAttribute('aria-busy', 'true');
      status.textContent = window.InkluraSyncI18n.t('Checking the PrestaShop product page…');
      var controller = new AbortController();
      var timer = setTimeout(function () { controller.abort(); }, 12000);
      try {
        var response = await fetch(box.dataset.endpoint, {
          method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: new URLSearchParams({ action: 'wd29_product_link', product: box.dataset.product, nonce: box.dataset.nonce })
        });
        var payload = await response.json();
        if (!response.ok || !payload || payload.ok !== true || !payload.data) {
          unavailable(response.status === 403 ? window.InkluraSyncI18n.t('Session expired or access denied. Refresh the page.') : window.InkluraSyncI18n.t('Unable to check the link. Try again.'));
          return;
        }
        var data = payload.data;
        if (data.state === 'linked' && typeof data.url === 'string') {
          var url = new URL(data.url);
          if (url.protocol !== 'https:' || url.username || url.password || !data.peer_host || url.hostname.toLowerCase() !== String(data.peer_host).toLowerCase()) throw new Error('Invalid public URL');
          link.href = url.href;
          show(link, true);
          if (host) host.textContent = String(data.peer_host);
          status.textContent = window.InkluraSyncI18n.t('Matching public product page found on PrestaShop.');
          show(retry, false);
        } else {
          status.textContent = messages[data.state] || messages.unavailable;
          retry.textContent = window.InkluraSyncI18n.t('Check again');
          show(retry, true);
        }
      } catch (_) { unavailable(); }
      finally {
        clearTimeout(timer);
        pending = false;
        retry.disabled = false;
        box.removeAttribute('aria-busy');
      }
    }
    retry.addEventListener('click', lookup);
    if (box.dataset.state !== 'ready') {
      show(retry, true);
      return;
    }
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        if (!started && entries.some(function (entry) { return entry.isIntersecting; })) {
          observer.disconnect();
          lookup();
        }
      });
      observer.observe(box);
    } else {
      show(retry, true);
    }
  });
})();
