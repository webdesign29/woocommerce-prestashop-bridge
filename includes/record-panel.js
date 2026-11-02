(function () {
  'use strict';
  document.querySelectorAll('[data-wd29-record-panel]').forEach(function (panel) {
    if (panel.dataset.initialized) return;
    panel.dataset.initialized = '1';
    var status = panel.querySelector('[data-record-status]');
    var refresh = panel.querySelector('[data-record-refresh]');
    var sync = panel.querySelector('[data-record-sync]');
    var comparison = panel.querySelector('[data-record-comparison]');
    if (!status || !refresh || !sync || !comparison) return;
    var busy = false, started = false, snapshot = null;
    var labels = { same: window.InkluraSyncI18n.t('Already synchronized.'), missing: window.InkluraSyncI18n.t('Copy missing: ready to create.'), changed: window.InkluraSyncI18n.t('Saved changes ready to synchronize.'), conflict: window.InkluraSyncI18n.t('Conflict needs review before synchronization.'), disconnected: window.InkluraSyncI18n.t('Partner store not connected.'), unavailable: 'Comparaison indisponible.', unmapped: 'Aucune correspondance disponible.' };
    function show(node, visible) { node.hidden = !visible; node.style.display = visible ? '' : 'none'; }
    function text(selector, value) { var node = panel.querySelector(selector); if (node) node.textContent = value || ''; }
    function platform(side) { return side === 'woo' ? 'WooCommerce' : side === 'ps' ? 'PrestaShop' : ''; }
    function summary(value) {
      if (!value) return 'Aucune copie disponible.';
      if (typeof value === 'string') return value;
      return [value.label || value.number || '', value.detail || '', value.status_label || value.status || ''].filter(Boolean).join(' · ');
    }
    function display(data) {
      snapshot = data;
      status.textContent = data.reason || labels[data.state] || window.InkluraSyncI18n.t('Comparison refreshed.');
      text('[data-record-direction]', platform(data.source) + ' → ' + platform(data.target));
      var adminLink = panel.querySelector('[data-record-admin-link]');
      var adminNote = panel.querySelector('[data-record-admin-note]');
      if (adminLink && adminNote) {
        show(adminLink, false); show(adminNote, false); adminLink.removeAttribute('href');
        if (typeof data.remote_admin_url === 'string' && data.remote_admin_url && typeof data.peer_host === 'string') {
          try {
            var adminUrl = new URL(data.remote_admin_url);
            if (adminUrl.protocol === 'https:' && !adminUrl.username && !adminUrl.password && adminUrl.hostname.toLowerCase() === data.peer_host.toLowerCase()) {
              adminLink.href = adminUrl.href;
              adminLink.textContent = 'Ouvrir l’administration PrestaShop ↗';
              show(adminLink, true); show(adminNote, true);
            }
          } catch (_) { /* No guessed remote administration URL. */ }
        }
      }
      text('[data-record-origin]', data.origin ? 'Vous consultez la fiche originale WooCommerce.' : window.InkluraSyncI18n.t('You are viewing the imported copy; changes come from PrestaShop.'));
      text('[data-record-woo]', summary(data.source === 'woo' ? data.source_summary : data.destination_summary));
      text('[data-record-ps]', summary(data.source === 'ps' ? data.source_summary : data.destination_summary));
      text('[data-record-changes]', data.state !== 'same' && Array.isArray(data.changes) && data.changes.length ? window.InkluraSyncI18n.t('Differences: ') + data.changes.join(', ') + '.' : '');
      var variants = data.variation_changes;
      text('[data-record-variations]', typeof variants === 'string' ? variants : variants && typeof variants.summary === 'string' ? variants.summary : variants && Number.isInteger(variants.count) ? variants.count + window.InkluraSyncI18n.t(' affected variation(s).') : '');
      var detail = panel.querySelector('[data-record-variation-details]');
      var list = panel.querySelector('[data-record-variation-rows]');
      if (detail && list) {
        list.replaceChildren();
        var rows = variants && Array.isArray(variants.rows) ? variants.rows.filter(function (row) { return row && row.state !== 'same'; }) : [];
        rows.slice(0, 8).forEach(function (row) {
          var item = document.createElement('li');
          var states = { added: window.InkluraSyncI18n.t('added'), changed: window.InkluraSyncI18n.t('modified'), removed: window.InkluraSyncI18n.t('removal to check in the admin') };
          item.textContent = String(row.label || row.key || window.InkluraSyncI18n.t('Variation')) + ' · ' + (states[row.state] || window.InkluraSyncI18n.t('to review'));
          list.appendChild(item);
        });
        if (rows.length > 8 || (variants && variants.truncated)) {
          var note = document.createElement('li'); note.textContent = window.InkluraSyncI18n.t('Limited preview; check the report for other variations.'); list.appendChild(note);
        }
        show(detail, list.children.length > 0);
      }
      text('[data-record-stock]', (data.stock_difference ? window.InkluraSyncI18n.t('Stock differs. ') : '') + (typeof data.stock_note === 'string' ? data.stock_note : data.stock_difference ? window.InkluraSyncI18n.t('Stock movements use the synchronization queue; this action does not force equal quantities.') : ''));
      text('[data-record-customer]', typeof data.customer_note === 'string' ? data.customer_note : '');
      var native = data.native_account;
      var nativeLabels = { directory_only: window.InkluraSyncI18n.t('Sync directory only; no native account updated.'), not_linked: window.InkluraSyncI18n.t('No native customer account linked.'), disabled: window.InkluraSyncI18n.t('Native account updates disabled.'), same: window.InkluraSyncI18n.t('Native customer account up to date.'), conflict: window.InkluraSyncI18n.t('Native customer account changed: conflict needs review.'), missing: window.InkluraSyncI18n.t('Native customer account missing.'), linked: window.InkluraSyncI18n.t('Native customer account linked, with no local changes detected.') };
      text('[data-record-native]', native && typeof native === 'object' ? nativeLabels[native.state] || window.InkluraSyncI18n.t('Native customer account status needs review.') : '');
      var event = data.last_event;
      var eventText = event && typeof event === 'object' ? [event.kind, event.state, event.created_at || event.at].filter(Boolean).join(' · ') : '';
      text('[data-record-events]', (panel.dataset.kind === 'product' ? window.InkluraSyncI18n.t('Parent product activity: ') : '') + (Number.isInteger(data.pending_count) ? data.pending_count + window.InkluraSyncI18n.t(' pending event(s).') : '') + (Number.isInteger(data.blocked_count) && data.blocked_count > 0 ? ' ' + data.blocked_count + window.InkluraSyncI18n.t(' blocked event(s).') : '') + (eventText ? window.InkluraSyncI18n.t(' Last event: ') + eventText : ''));
      show(comparison, !!(data.source && data.target));
      sync.textContent = (data.direction === 'in' ? window.InkluraSyncI18n.t('Fetch from ') + platform(data.source) : 'Envoyer vers ' + platform(data.target)) + ' →';
      show(sync, data.can_sync === true && (data.direction === 'in' || data.direction === 'out') && typeof data.hash === 'string' && typeof data.destination === 'string' && typeof data.key === 'string');
    }
    async function request(op, input) {
      var controller = new AbortController();
      var timer = setTimeout(function () { controller.abort(); }, op === 'sync' ? 90000 : 25000);
      try {
        var response = await fetch(panel.dataset.endpoint, {
          method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: new URLSearchParams(Object.assign({ action: 'wd29_record_tools', op: op, kind: panel.dataset.kind, id: panel.dataset.id, nonce: panel.dataset.nonce }, input || {}))
        });
        var payload = await response.json();
        if (!response.ok || !payload || payload.ok !== true || !payload.data || payload.data.ok === false) {
          throw Object.assign(new Error(response.status === 403 ? window.InkluraSyncI18n.t('Session expired or access denied. Refresh the page.') : payload && typeof payload.error === 'string' ? payload.error : window.InkluraSyncI18n.t('Action not confirmed. Refresh the comparison and check diagnostics.')), { wd29Safe: true });
        }
        return payload.data;
      } finally { clearTimeout(timer); }
    }
    function pending(value) {
      busy = value;
      refresh.disabled = value;
      sync.disabled = value;
      if (value) panel.setAttribute('aria-busy', 'true'); else panel.removeAttribute('aria-busy');
    }
    async function compare() {
      if (busy) return;
      started = true;
      pending(true);
      snapshot = null;
      show(sync, false);
      status.textContent = window.InkluraSyncI18n.t('Comparing saved data…');
      try { display(await request('compare')); }
      catch (error) { status.textContent = error.name === 'AbortError' ? window.InkluraSyncI18n.t('Comparison timed out. Try again.') : error.wd29Safe ? error.message : window.InkluraSyncI18n.t('Comparison unavailable. Refresh the page or check diagnostics.'); }
      finally { pending(false); refresh.textContent = 'Actualiser'; }
    }
    refresh.addEventListener('click', compare);
    sync.addEventListener('click', async function () {
      if (busy || !snapshot || snapshot.can_sync !== true) return;
      var target = platform(snapshot.target);
      if (!window.confirm(window.InkluraSyncI18n.t('Synchronize saved data to ') + target + window.InkluraSyncI18n.t(' ?\n\nUnsaved changes in this editor are not included. Automatic mode remains unchanged.'))) return;
      var input = { key: snapshot.key, direction: snapshot.direction, hash: snapshot.hash, destination: snapshot.destination, confirm: '1' };
      pending(true);
      show(sync, false);
      status.textContent = 'Synchronisation vers ' + target + ' en cours…';
      try {
        var result = await request('sync', input);
        snapshot = null;
        status.textContent = window.InkluraSyncI18n.t('Synchronization confirmed. Checking status…');
        display(await request('compare'));
        if (typeof result.message === 'string') status.textContent = result.message + ' ' + status.textContent;
      } catch (error) {
        snapshot = null;
        status.textContent = error.name === 'AbortError' ? window.InkluraSyncI18n.t('The response timed out. Refresh to check the result before retrying.') : error.wd29Safe ? error.message : window.InkluraSyncI18n.t('Action not confirmed. Refresh to check the result and review diagnostics.');
      } finally { pending(false); }
    });
    if (!['disconnected', 'unavailable'].includes(panel.dataset.state) && 'IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        if (!started && entries.some(function (entry) { return entry.isIntersecting; })) { observer.disconnect(); compare(); }
      });
      observer.observe(panel);
    }
  });
})();
