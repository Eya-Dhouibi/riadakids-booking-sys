(function () {
  /* Confettis floraux à la fin du quiz */
  var COLORS = ['#ff3d9a','#ffe000','#7fff6a','#4C95D7','#a020f0','#FF4411','#ffffff','#ff8ccc'];

  function burst() {
    for (var i = 0; i < 60; i++) {
      (function (i) {
        setTimeout(function () {
          var el  = document.createElement('div');
          var dur = (1.2 + Math.random() * 1.4).toFixed(2);
          var sz  = (6 + Math.random() * 11).toFixed(0);
          el.className = 'rk-confetti-dot';
          el.style.cssText =
            'left:'        + (Math.random() * 100).toFixed(1) + 'vw;' +
            'top:-16px;'   +
            'width:'       + sz + 'px;height:' + sz + 'px;' +
            'background:'  + COLORS[i % COLORS.length] + ';' +
            'animation-duration:' + dur + 's;' +
            'border-radius:' + (Math.random() > .4 ? '50%' : '4px') + ';';
          document.body.appendChild(el);
          setTimeout(function () { el.remove(); }, parseFloat(dur) * 1000 + 300);
        }, i * 35);
      })(i);
    }
  }

  /* ═══════════════════════════════════════════════════════════════
     v2.6.3 — DÉTECTION DE FIN ROBUSTE + BOUTON RETOUR GARANTI
     L'écran de résultat AYS varie selon le thème/la version (le sélecteur
     historique `ays_finish_quiz_` ne matche pas toujours — cf. écran
     "Your score is 100%" sans bannière). Trois défenses :
       1. MutationObserver PROFOND : le nœud ajouté OU ses descendants ;
       2. polling de secours (800 ms, 5 min) sur les sélecteurs de score ;
       3. le bouton « ← العودة إلى اختباراتي » s'affiche DÈS la détection,
          même si l'appel result-notify échoue — l'enfant n'est jamais coincé.
     Le pipeline (XP, événements, notifs, caches) part au même moment.
     ═══════════════════════════════════════════════════════════════ */
  var RK_FINISH_SEL = [
    '[id^="ays_finish_quiz_"]', '.ays-finish-quiz', '.ays_finish_quiz',
    '.ays_score_message', '.ays-score-message', '.ays_quiz_results',
    '.ays_score_percent', '.ays-progress-value', '.ays-score-bar'
  ].join(',');

  function rkNodeIsFinish(n) {
    if (!n || n.nodeType !== 1) return false;
    if (n.matches && n.matches(RK_FINISH_SEL)) return true;
    return !!(n.querySelector && n.querySelector(RK_FINISH_SEL));
  }

  function rkFloatingBack(backUrl) {
    if (document.getElementById('rk-qp-back-float')) return;
    var a = document.createElement('a');
    a.id = 'rk-qp-back-float';
    a.href = backUrl;
    a.textContent = '← العودة إلى اختباراتي';
    a.style.cssText = 'position:fixed;bottom:22px;left:50%;transform:translateX(-50%);z-index:99999;'
      + 'padding:14px 34px;border-radius:50px;background:linear-gradient(135deg,#FF4411,#4C95D7);'
      + 'color:#fff;font-weight:800;font-size:1rem;text-decoration:none;font-family:Tajawal,Cairo,sans-serif;'
      + 'box-shadow:0 6px 28px rgba(76,149,215,.5);animation:rk-score-pop .5s cubic-bezier(.34,1.56,.64,1) both;';
    document.body.appendChild(a);
  }

  function rkOnFinish() {
    burst();
    setTimeout(burst, 1400);
    setTimeout(burst, 2800);

    var d = window.RK_QUIZ_DATA || {};
    var backUrl = d.backUrl || '/dashboard/my-quiz-attempts/';

    // 1) Bouton retour IMMÉDIAT — indépendant du réseau.
    rkFloatingBack(backUrl);

    // 2) Pipeline serveur (XP, événements, notifs, caches) + bannière enrichie.
    if (d.loggedIn && d.quizId) {
      fetch(d.api + '/quiz/result-notify', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-WP-Nonce':d.nonce},
        body: JSON.stringify({quiz_id: d.quizId})
      })
      .then(function(r) { return r.json(); })
      .then(function(resp) {
        var sc = resp && resp.score_data;
        if (!sc) return;
        var card = document.querySelector('.rk-qp-card');
        if (!card) return;
        var passed = sc.passed;
        card.innerHTML = '<div class="rk-qp-accent"></div>'
                  + '<div style="padding:36px 28px 40px;text-align:center;">'
                  + '<div style="font-size:3.8rem;animation:rk-score-pop .7s cubic-bezier(.34,1.56,.64,1) both;">' + (passed ? '🏆' : '📖') + '</div>'
                  + '<p style="font-size:1.05rem;font-weight:800;color:#ffe000;margin:10px 0 4px;text-shadow:0 0 20px rgba(255,224,0,.6);">'
                  + (passed ? 'أحسنت! لقد نجحت' : 'واصل التدريب، ستنجح قريباً!') + '</p>'
                  + '<div style="font-size:4.2rem;font-weight:900;color:#ffe000;'
                  + 'text-shadow:0 0 30px rgba(255,224,0,.7),0 0 60px rgba(255,61,154,.4);'
                  + 'animation:rk-score-pop .7s cubic-bezier(.34,1.56,.64,1) .1s both;margin:16px 0 8px;">'
                  + sc.score + '%</div>'
                  + '<div style="display:inline-block;padding:6px 28px;border-radius:50px;font-size:1rem;font-weight:800;'
                  + (passed
                      ? 'background:rgba(74,222,128,.22);color:#bbf7d0;border:2px solid #4ade80;'
                      : 'background:rgba(220,38,38,.18);color:#fca5a5;border:2px solid #ef4444;')
                  + 'margin-bottom:20px;">' + (passed ? '✅ نجح' : '❌ لم ينجح') + '</div>'
                  + '<div style="display:flex;justify-content:center;gap:20px;flex-wrap:wrap;margin-bottom:24px;">'
                  + '<div style="background:rgba(255,255,255,.10);border-radius:12px;padding:12px 24px;min-width:110px;">'
                  + '<div style="font-size:1.6rem;font-weight:800;color:#fff;">' + sc.corrects + '/' + sc.total + '</div>'
                  + '<div style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:2px;">إجابات صحيحة</div></div>'
                  + '<div style="background:rgba(255,255,255,.10);border-radius:12px;padding:12px 24px;min-width:110px;">'
                  + '<div style="font-size:1.6rem;font-weight:800;color:#fff;">' + sc.passing_grade + '%</div>'
                  + '<div style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:2px;">درجة النجاح</div></div></div>'
                  + (resp.xp ? '<div style="display:inline-block;margin:0 0 18px;padding:8px 26px;border-radius:50px;'
                      + 'background:rgba(255,224,0,.16);border:2px solid rgba(255,224,0,.55);color:#ffe000;'
                      + 'font-weight:800;font-size:1rem;text-shadow:0 0 14px rgba(255,224,0,.5);">'
                      + '⭐ +' + resp.xp + ' نقطة خبرة</div><br>' : '')
                  + '<a href="' + backUrl + '" style="display:inline-block;padding:12px 32px;border-radius:50px;'
                  + 'background:linear-gradient(135deg,#FF4411,#4C95D7);color:#fff;font-weight:800;'
                  + 'font-size:.95rem;text-decoration:none;box-shadow:0 4px 24px rgba(76,149,215,.45);">'
                  + '← العودة إلى اختباراتي</a>'
                  + '<p style="margin-top:18px;font-size:.78rem;color:rgba(255,255,255,.40);">'
                  + ((d.maxAttempts > 1 && (d.attempts + 1) < d.maxAttempts)
                      ? ('المحاولات المتبقية: ' + (d.maxAttempts - d.attempts - 1))
                      : 'انتهت محاولاتك لهذا الاختبار') + '</p>'
                  + '</div>';
              })
              .catch(function(){ /* le bouton flottant est déjà là */ });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var fired = false;
    function fireOnce() { if (fired) return; fired = true; rkOnFinish(); }

    // Défense 1 : observation PROFONDE (nœud + descendants).
    var obs = new MutationObserver(function (muts) {
      if (fired) return;
      for (var i = 0; i < muts.length; i++) {
        var added = muts[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          if (rkNodeIsFinish(added[j])) { fireOnce(); return; }
        }
      }
    });
    var roots = document.querySelectorAll('[id^="ays-quiz-container-"]');
    (roots.length ? roots : [document.body]).forEach(function (c) {
      obs.observe(c, { childList: true, subtree: true });
    });

    // Défense 2 : polling de secours — attrape tout écran de résultat,
    // quel que soit le mécanisme d'injection d'AYS.
    var tries = 0;
    var poll = setInterval(function () {
      if (fired || ++tries > 375) { clearInterval(poll); return; } // ~5 min
      if (document.querySelector(RK_FINISH_SEL)) fireOnce();
    }, 800);
  });
})();

/* ── Traduction arabe des boutons Quiz Maker + direction RTL ── */
(function () {
  var TRANS = {
    'Start': 'ابدأ الاختبار',
    'start': 'ابدأ الاختبار',
    'Next': 'التالي ←',
    'next': 'التالي ←',
    'Prev': '→ السابق',
    'prev': '→ السابق',
    'Previous': '→ السابق',
    'Finish': 'إنهاء الاختبار',
    'finish': 'إنهاء الاختبار',
    'See Result': 'عرض النتيجة',
    'Check': 'تحقق',
    'Clear': 'مسح',
    'Submit': 'إرسال',
    'Send feedback': 'إرسال ملاحظة',
    'Load more': 'تحميل المزيد',
    'Exit': 'خروج',
    'Restart quiz': 'إعادة الاختبار',
    'Restart Quiz': 'إعادة الاختبار'
  };

  function translateEl(el) {
    if (el.tagName === 'INPUT' && (el.type === 'submit' || el.type === 'button')) {
      var v = (el.value || '').trim();
      if (TRANS[v]) el.value = TRANS[v];
    } else if (el.tagName === 'BUTTON' || el.tagName === 'A') {
      var t = el.textContent.trim();
      if (TRANS[t]) el.textContent = TRANS[t];
    }
  }

  function translateAll(root) {
    root = root || document;
    root.querySelectorAll('input[type="submit"], input[type="button"], button, a.action-button').forEach(translateEl);
    /* RTL sur les conteneurs Quiz Maker */
    root.querySelectorAll('[id^="ays-quiz-container-"], [id^="ays_finish_quiz_"], .ays_block_content, .ays_quiz_main_div').forEach(function (c) {
      c.setAttribute('dir', 'rtl');
      c.style.direction = 'rtl';
      c.style.textAlign = 'right';
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    translateAll(document);
    var obs = new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        m.addedNodes.forEach(function (n) {
          if (n.nodeType !== 1) return;
          translateEl(n);
          translateAll(n);
        });
      });
    });
    document.querySelectorAll('[id^="ays-quiz-container-"]').forEach(function (c) {
      obs.observe(c, { childList: true, subtree: true });
    });
    /* Fallback interval pour les boutons chargés en retard */
    var runs = 0;
    var iv = setInterval(function () {
      translateAll(document);
      if (++runs >= 10) clearInterval(iv);
    }, 600);
  });
})();
