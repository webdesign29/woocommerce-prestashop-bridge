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
    var labels = { same: 'Déjà synchronisé.', missing: 'Copie absente : prête à être créée.', changed: 'Modifications enregistrées à synchroniser.', conflict: 'Conflit à examiner avant toute synchronisation.', disconnected: 'Boutique partenaire non connectée.', unavailable: 'Comparaison indisponible.', unmapped: 'Aucune correspondance disponible.' };
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
      status.textContent = data.reason || labels[data.state] || 'Comparaison actualisée.';
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
      text('[data-record-origin]', data.origin ? 'Vous consultez la fiche originale WooCommerce.' : 'Vous consultez la copie importée ; les modifications viennent de PrestaShop.');
      text('[data-record-woo]', summary(data.source === 'woo' ? data.source_summary : data.destination_summary));
      text('[data-record-ps]', summary(data.source === 'ps' ? data.source_summary : data.destination_summary));
      text('[data-record-changes]', data.state !== 'same' && Array.isArray(data.changes) && data.changes.length ? 'Écarts : ' + data.changes.join(', ') + '.' : '');
      var variants = data.variation_changes;
      text('[data-record-variations]', typeof variants === 'string' ? variants : variants && typeof variants.summary === 'string' ? variants.summary : variants && Number.isInteger(variants.count) ? variants.count + ' déclinaison(s) concernée(s).' : '');
      var detail = panel.querySelector('[data-record-variation-details]');
      var list = panel.querySelector('[data-record-variation-rows]');
      if (detail && list) {
        list.replaceChildren();
        var rows = variants && Array.isArray(variants.rows) ? variants.rows.filter(function (row) { return row && row.state !== 'same'; }) : [];
        rows.slice(0, 8).forEach(function (row) {
          var item = document.createElement('li');
          var states = { added: 'ajoutée', changed: 'modifiée', removed: 'retrait à vérifier dans l’administration' };
          item.textContent = String(row.label || row.key || 'Déclinaison') + ' · ' + (states[row.state] || 'à examiner');
          list.appendChild(item);
        });
        if (rows.length > 8 || (variants && variants.truncated)) {
          var note = document.createElement('li'); note.textContent = 'Aperçu limité ; consultez le rapport pour les autres déclinaisons.'; list.appendChild(note);
        }
        show(detail, list.children.length > 0);
      }
      text('[data-record-stock]', (data.stock_difference ? 'Stocks différents. ' : '') + (typeof data.stock_note === 'string' ? data.stock_note : data.stock_difference ? 'Les mouvements passent par la file de synchronisation ; cette action ne force pas leur égalité.' : ''));
      text('[data-record-customer]', typeof data.customer_note === 'string' ? data.customer_note : '');
      var native = data.native_account;
      var nativeLabels = { directory_only: 'Répertoire Sync uniquement ; aucun compte natif mis à jour.', not_linked: 'Aucun compte client natif lié.', disabled: 'Mise à jour des comptes natifs désactivée.', same: 'Compte client natif à jour.', conflict: 'Compte client natif modifié : conflit à examiner.', missing: 'Compte client natif absent.', linked: 'Compte client natif lié, sans modification locale détectée.' };
      text('[data-record-native]', native && typeof native === 'object' ? nativeLabels[native.state] || 'État du compte client natif à examiner.' : '');
      var event = data.last_event;
      var eventText = event && typeof event === 'object' ? [event.kind, event.state, event.created_at || event.at].filter(Boolean).join(' · ') : '';
      text('[data-record-events]', (panel.dataset.kind === 'product' ? 'Activité du produit parent : ' : '') + (Number.isInteger(data.pending_count) ? data.pending_count + ' événement(s) en attente.' : '') + (Number.isInteger(data.blocked_count) && data.blocked_count > 0 ? ' ' + data.blocked_count + ' événement(s) bloqué(s).' : '') + (eventText ? ' Dernier événement : ' + eventText : ''));
      show(comparison, !!(data.source && data.target));
      sync.textContent = (data.direction === 'in' ? 'Récupérer depuis ' + platform(data.source) : 'Envoyer vers ' + platform(data.target)) + ' →';
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
          throw Object.assign(new Error(response.status === 403 ? 'Session expirée ou accès refusé. Actualisez la page.' : payload && typeof payload.error === 'string' ? payload.error : 'Action non confirmée. Actualisez la comparaison et consultez les diagnostics.'), { wd29Safe: true });
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
      status.textContent = 'Comparaison des données enregistrées…';
      try { display(await request('compare')); }
      catch (error) { status.textContent = error.name === 'AbortError' ? 'La comparaison a pris trop de temps. Réessayez.' : error.wd29Safe ? error.message : 'Comparaison indisponible. Actualisez la page ou consultez les diagnostics.'; }
      finally { pending(false); refresh.textContent = 'Actualiser'; }
    }
    refresh.addEventListener('click', compare);
    sync.addEventListener('click', async function () {
      if (busy || !snapshot || snapshot.can_sync !== true) return;
      var target = platform(snapshot.target);
      if (!window.confirm('Synchroniser les données enregistrées sur ' + target + ' ?\n\nLes modifications non enregistrées dans cet éditeur ne sont pas incluses. Le mode automatique reste inchangé.')) return;
      var input = { key: snapshot.key, direction: snapshot.direction, hash: snapshot.hash, destination: snapshot.destination, confirm: '1' };
      pending(true);
      show(sync, false);
      status.textContent = 'Synchronisation vers ' + target + ' en cours…';
      try {
        var result = await request('sync', input);
        snapshot = null;
        status.textContent = 'Synchronisation confirmée. Vérification de l’état…';
        display(await request('compare'));
        if (typeof result.message === 'string') status.textContent = result.message + ' ' + status.textContent;
      } catch (error) {
        snapshot = null;
        status.textContent = error.name === 'AbortError' ? 'La réponse a pris trop de temps. Actualisez pour vérifier le résultat avant de réessayer.' : error.wd29Safe ? error.message : 'Action non confirmée. Actualisez pour vérifier le résultat et consultez les diagnostics.';
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
