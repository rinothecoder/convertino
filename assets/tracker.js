(() => {
  'use strict';
  const cfg = window.ConvertinoConfig;
  if (!cfg || window.Convertino) return;
  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
  let viewId = uuid();
  let consent = false, started = false, pending = null, generation = 0;
  let queue = Promise.resolve();
  const bound = new WeakSet();
  const sent = new Set();
  const privacy = () => navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true || navigator.webdriver === true;
  async function post(path, data) {
    try {
      const res = await fetch(cfg.endpoint + path, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data), keepalive:true });
      return res.ok ? 200 : (res.status || 0);
    } catch { return 0; }
  }
  async function start() {
    if (!consent || privacy() || !cfg.page) return false;
    if (started) return true;
    if (pending) return pending;
    const gen = generation;
    const params = new URLSearchParams(location.search);
    pending = post('start', {page:cfg.page, proof:cfg.proof, id:viewId, consent:true, source:params.get('utm_source') || '', medium:params.get('utm_medium') || '', campaign:params.get('utm_campaign') || '', referrer:document.referrer}).then(status => {
      const ok = status === 200;
      if (gen === generation && consent) started = ok;
      return ok && consent && gen === generation;
    }).finally(() => { pending = null; });
    return pending;
  }
  function event(type, target, once = false) {
    if (!consent || privacy() || !cfg.page) return;
    const unique = type + ':' + target;
    if (once && sent.has(unique)) return;
    if (once) sent.add(unique);
    const id = uuid(), gen = generation;
    // Start must finish setting the cookie before dependent click/video requests.
    queue = queue.then(async () => {
      if (!await start() || !consent || gen !== generation) { if (once) sent.delete(unique); return; }
      const payload = {type, target, id, consent:true};
      let status = await post('event', payload);
      if (status === 409 && consent && gen === generation) {
        started = false; viewId = uuid(); sent.clear();
        if (await start()) status = await post('event', payload);
      } else if (status !== 200 && consent && gen === generation) status = await post('event', payload);
      if (status !== 200 && once) sent.delete(unique);
    }).catch(() => { if (once) sent.delete(unique); });
  }
  function bindPlayers() {
    document.querySelectorAll('[id^="fluent_player_"] media-player').forEach(player => {
      if (bound.has(player)) return;
      const container = player.closest('[id^="fluent_player_"]');
      if (!container) return;
      bound.add(player);
      player.addEventListener('play', () => event('video_play', container.id, true));
      player.addEventListener('ended', () => event('video_end', container.id, true));
    });
  }
  window.Convertino = {
    setConsent(granted) {
      const next = granted === true && !privacy();
      if (next === consent) return;
      consent = next; generation++;
      if (consent) {
        const gen = generation;
        Promise.resolve(pending).finally(() => { if (consent && gen === generation) start(); });
        bindPlayers();
      }
      else {
        started = false; sent.clear();
        // Wait for any start request before expiring its cookie.
        Promise.allSettled([pending, queue]).then(() => { if (!consent) post('revoke', {}); });
      }
    }
  };
  document.addEventListener('click', e => {
    if (!(e.target instanceof Element) || !consent) return;
    for (const button of cfg.buttons) {
      try { if (e.target.closest(button.selector)) { event('click', button.key); break; } } catch { /* Invalid selections never break the page. */ }
    }
  }, true);
  document.addEventListener('convertino:consent', e => window.Convertino.setConsent(e.detail === true || e.detail?.granted === true));
  document.addEventListener('cmplz_status_change', e => {
    if (e.detail?.category === 'statistics') window.Convertino.setConsent(e.detail.value === 'allow');
  });
  if (cfg.page) {
    bindPlayers();
    new MutationObserver(bindPlayers).observe(document.documentElement, {childList:true, subtree:true});
  }
  if (window.ConvertinoConsent?.granted === true || (typeof window.cmplz_has_consent === 'function' && window.cmplz_has_consent('statistics'))) window.Convertino.setConsent(true);
})();
