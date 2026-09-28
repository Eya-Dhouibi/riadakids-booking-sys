// Test DOM du dashboard Coordinateur (jsdom). Usage : node tests/phase3-js.test.js (jsdom requis)
const { JSDOM } = require('jsdom'); const fs = require('fs'); const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '../assets/js/coordinator.js'), 'utf8');
let pass = 0, fail = 0; const t = (n, ok, d) => { ok ? pass++ : fail++; console.log((ok ? 'PASS' : 'FAIL') + '  ' + n + (ok || !d ? '' : '  → ' + d)); };
const shell = `<main id="rk-coord"><button id="rk-coord-refresh"></button><div id="rk-coord-status"></div>
<section data-section="new"><span data-count="new"></span><div data-list="new"></div></section>
<section data-section="pending"><span data-count="pending"></span><div data-list="pending"></div></section>
<section data-section="changes" hidden><span data-count="changes"></span><div data-list="changes"></div></section>
<section data-section="coaches" hidden><span data-count="coaches"></span><div data-list="coaches"></div></section>
<aside id="rk-coord-panel" hidden><button id="rk-coord-panel-close"></button><h3 id="rk-coord-panel-title"></h3><div id="rk-coord-panel-body"></div></aside></main>`;
const B = (id, name, extra = {}) => Object.assign({ booking_id: id, status: 'pending_schedule', children: [{ id: id, name, age: 9 }], parent: { id: 10, name: 'Parent Ali' }, program: 'الريادة', course: 'دورة', session: 'حصة', requested_at: '2026-10-01 09:00:00', proposal: null, change: null }, extra);
function run(dash, caps, booking) {
  const dom = new JSDOM('<!doctype html><body>' + shell, { runScripts: 'outside-only', url: 'https://example.test/' });
  const calls = [];
  dom.window.FormData = dom.window.FormData; dom.window.rkCoordinator = { ajaxUrl: '/ajax', nonce: 'N' };
  dom.window.fetch = (u, o) => { const a = o.body.get('action'); calls.push({ a, nonce: o.body.get('nonce'), id: o.body.get('booking_id') });
    const data = a === 'rk_coord_get_dashboard' ? dash : a === 'rk_coord_get_coaches' ? { coaches: [{ id: 55, name: 'Coach A', open_proposals: 2 }] } : { booking, caps };
    return Promise.resolve({ json: () => Promise.resolve({ success: true, data }) }); };
  dom.window.eval(src); return new Promise(r => setTimeout(() => r({ dom, calls, doc: dom.window.document }), 30));
}
(async () => {
  const caps = { view_details: true, assign_coach: true, schedule: true, reschedule: true, manage_change: true, view_coaches: true };
  const evil = '<img src=x onerror=alert(1)><script>alert(2)</script>';
  const dash = { caps, new: [B(1, 'أحمد العلي'), B(2, 'سارة العلي')],
    pending: [B(3, 'ياسمين', { status: 'pending_parent_confirmation', proposal: { coach_id: 55, coach_name: 'Coach A', datetime: '2026-10-15 17:00:00', scheduled_by_name: 'Salma', scheduled_at: '2026-10-01 09:00:00' } })],
    changes: [B(4, 'أحمد', { status: 'change_requested', proposal: { coach_id: 55, coach_name: 'Coach A', datetime: '2026-10-15T17:00:00', scheduled_by_name: 'S', scheduled_at: '' }, change: { note: evil, requested_at: '2026-10-02 08:00:00' } })] };
  let { doc, calls, dom } = await run(dash, caps, B(1, 'أحمد العلي'));
  const cnt = k => doc.querySelector(`[data-count="${k}"]`).textContent;
  t('J1 compteurs : new=2, pending=1, changes=1, coaches=1', cnt('new') === '2' && cnt('pending') === '1' && cnt('changes') === '1' && cnt('coaches') === '1');
  t('J2 sections changes + coaches visibles avec les droits', !doc.querySelector('[data-section="changes"]').hidden && !doc.querySelector('[data-section="coaches"]').hidden);
  t('J3 nonce envoyé à chaque appel AJAX, action correcte', calls.length >= 2 && calls.every(c => c.nonce === 'N') && calls[0].a === 'rk_coord_get_dashboard');
  t('J4 boutons « تحديد الموعد » (2, sur les nouvelles) + « إعادة تحديد الموعد » (1) ; aucun bouton sur « en attente »', doc.querySelectorAll('[data-list="new"] button').length === 2 && doc.querySelectorAll('[data-list="changes"] button').length === 1 && doc.querySelectorAll('[data-list="pending"] button').length === 0);
  t('J5 XSS : note parent rendue en TEXTE (aucune balise img/script injectée)', doc.querySelector('.rk-coord__note').textContent === evil && !doc.querySelector('[data-list="changes"] img, [data-list="changes"] script'));
  t('J6 date 2026-10-15T17:00:00 → 15/10/2026 — 17:00 (sans décalage de fuseau)', doc.querySelector('[data-list="changes"]').textContent.includes('15/10/2026 — 17:00') && doc.querySelector('[data-list="pending"]').textContent.includes('15/10/2026 — 17:00'));
  t('J7 carte « en attente » : coach, coordinateur, mention بانتظار تأكيد ولي الأمر', ['Coach A', 'Salma', 'بانتظار تأكيد ولي الأمر'].every(x => doc.querySelector('[data-list="pending"]').textContent.includes(x)));
  doc.querySelector('[data-list="new"] button').click(); await new Promise(r => setTimeout(r, 40));
  const body = doc.getElementById('rk-coord-panel-body');
  const send = [...body.querySelectorAll('button')].pop();
  t('J8 panneau ouvert, get_booking appelé avec l\'ID de la carte', !doc.getElementById('rk-coord-panel').hidden && calls.some(c => c.a === 'rk_coord_get_booking' && c.id === '1'));
  t('J9 panneau : liste des coachs chargée, bouton d\'envoi DÉSACTIVÉ (aucune planification en Phase 3)', body.querySelectorAll('#rk-coord-coach option').length === 2 && send.disabled === true);
  t('J10 aucun appel autre que get_dashboard / get_coaches / get_booking (lecture seule)', calls.every(c => ['rk_coord_get_dashboard', 'rk_coord_get_coaches', 'rk_coord_get_booking'].includes(c.a)));
  doc.getElementById('rk-coord-panel-close').click();
  t('J11 fermeture du panneau', doc.getElementById('rk-coord-panel').hidden === true && body.textContent === '');
  const lim = { caps: Object.assign({}, caps, { manage_change: false, view_coaches: false, schedule: false }), new: dash.new, pending: dash.pending, changes: null };
  ({ doc } = await run(lim, lim.caps, null));
  t('J12 droits restreints : section changes masquée, coaches masquée, aucun bouton de planification', doc.querySelector('[data-section="changes"]').hidden && doc.querySelector('[data-section="coaches"]').hidden && doc.querySelectorAll('button.rk-coord__btn').length === 0);
  const emp = { caps, new: [], pending: [], changes: [] };
  ({ doc } = await run(emp, caps, null));
  t('J13 listes vides : message « لا توجد عناصر. » + compteurs à 0', doc.querySelector('[data-list="new"]').textContent === 'لا توجد عناصر.' && cnt('new') === '0');
  console.log('\n' + (fail ? 'ÉCHEC' : 'OK') + ' — ' + pass + ' PASS / ' + fail + ' FAIL'); process.exit(fail ? 1 : 0);
})();
