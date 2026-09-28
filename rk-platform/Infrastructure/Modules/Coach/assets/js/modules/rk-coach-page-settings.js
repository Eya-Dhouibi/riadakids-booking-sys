/**
 * rk-coach-page-settings.js — Page الإعدادات
 * Édition du profil + changement de mot de passe intégrés (sans wp-admin).
 * Namespace: RKCoach.Pages.Settings
 */
(function () {
  'use strict';
  var RK    = window.RKCoach;
  var Core  = RK.Core;
  var Utils = RK.Utils;
  var Icons = RK.Icons;
  var S     = RK.state;

  var SVG = {
    star: '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" aria-hidden="true" style="vertical-align:-1px;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    eye:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
    check:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>'
  };

  function field(id, label, type, value, placeholder, extra) {
    return '<div class="rk-set-field">'
      + '<label for="' + id + '" class="rk-set-label">' + label + '</label>'
      + '<input type="' + type + '" id="' + id + '" class="rk-set-input" value="' + Utils.e(value || '') + '"'
      + (placeholder ? ' placeholder="' + Utils.e(placeholder) + '"' : '')
      + (extra || '')
      + '>'
      + '</div>';
  }

  function pwField(id, label) {
    return '<div class="rk-set-field">'
      + '<label for="' + id + '" class="rk-set-label">' + label + '</label>'
      + '<div class="rk-set-pw-wrap">'
      + '<input type="password" id="' + id + '" class="rk-set-input rk-set-input--pw" autocomplete="new-password">'
      + '<button type="button" class="rk-pw-toggle" data-target="' + id + '" aria-label="إظهار كلمة المرور">' + SVG.eye + '</button>'
      + '</div>'
      + '</div>';
  }

  function notice(el, msg, ok) {
    el.innerHTML = '<div class="rk-set-notice ' + (ok ? 'rk-set-notice--ok' : 'rk-set-notice--err') + '">'
      + (ok ? SVG.check + ' ' : '') + Utils.e(msg) + '</div>';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function render() {
    Utils.setMain('<div class="rk-section-loading">جاري تحميل الإعدادات…</div>');

    Core.apiGet('/coach/profile', false).then(function (p) {
      p = p || {};
      var name   = Utils.e(((p.first_name || '') + ' ' + (p.last_name || '')).trim() || p.display || 'المدرب');
      var avatar = p.avatar || RK.config.logo;

      Utils.setMain(
        '<div class="rk-settings">'

        /* ── En-tête profil (hero) ── */
        + '<div class="rk-settings-profile">'
        +   '<div class="rk-settings-profile__avatar-wrap">'
        +     '<img src="' + Utils.e(avatar) + '" alt="" class="rk-settings-profile__avatar">'
        +   '</div>'
        +   '<div class="rk-settings-profile__info">'
        +     '<h2 class="rk-settings-profile__name" id="rk-set-display">' + name + '</h2>'
        +     '<p class="rk-settings-profile__role">' + SVG.star + ' مدرب RiadaKids</p>'
        +     '<p class="rk-settings-profile__email" dir="ltr">' + Utils.e(p.email || '') + '</p>'
        +   '</div>'
        + '</div>'

        + '<div class="rk-settings-grid">'

        /* ── Carte infos ── */
        + '<div class="rk-settings-section">'
        +   '<h3 class="rk-settings-section__title">' + Icons.iUser1 + ' المعلومات الشخصية</h3>'
        +   '<div class="rk-settings-card">'
        +     '<div id="rk-prof-notice"></div>'
        +     '<div class="rk-set-cols">'
        +       field('rk-set-first', 'الاسم الأول *', 'text', p.first_name)
        +       field('rk-set-last',  'اسم العائلة',   'text', p.last_name)
        +     '</div>'
        +     field('rk-set-email', 'البريد الإلكتروني', 'email', p.email, '', ' autocomplete="email" dir="ltr"')
        +     field('rk-set-phone', 'رقم الهاتف',        'tel',   p.phone, '+966…', ' autocomplete="tel" dir="ltr"')
        +     '<div class="rk-set-field">'
        +       '<label for="rk-set-bio" class="rk-set-label">نبذة تعريفية</label>'
        +       '<textarea id="rk-set-bio" rows="3" class="rk-set-input rk-set-textarea">' + Utils.e(p.bio || '') + '</textarea>'
        +     '</div>'
        +     '<button type="button" id="rk-prof-save" class="rk-set-btn">حفظ التغييرات</button>'
        +   '</div>'
        + '</div>'

        /* ── Carte mot de passe ── */
        + '<div class="rk-settings-section">'
        +   '<h3 class="rk-settings-section__title">' + Icons.iKey + ' تغيير كلمة المرور</h3>'
        +   '<div class="rk-settings-card">'
        +     '<div id="rk-pw-notice"></div>'
        +     pwField('rk-pw-current', 'كلمة المرور الحالية *')
        +     '<div class="rk-set-cols">'
        +       pwField('rk-pw-new',     'كلمة المرور الجديدة *')
        +       pwField('rk-pw-confirm', 'تأكيد كلمة المرور *')
        +     '</div>'
        +     '<div class="rk-set-pw-meter"><div class="rk-set-pw-meter__fill" id="rk-pw-meter"></div></div>'
        +     '<p class="rk-set-hint">8 أحرف على الأقل — يُفضّل مزج أحرف وأرقام ورموز.</p>'
        +     '<button type="button" id="rk-pw-save" class="rk-set-btn">تغيير كلمة المرور</button>'
        +   '</div>'
        + '</div>'

        + '</div><!-- .rk-settings-grid -->'

        /* ── Liens & déconnexion ── */
        + '<div class="rk-settings-section">'
        +   '<h3 class="rk-settings-section__title">التطبيق</h3>'
        +   '<div class="rk-settings-list">'
        +     '<a href="#" id="rk-settings-home" class="rk-settings-item">'
        +       '<span class="rk-settings-item__icon">' + Icons.iHome + '</span>'
        +       '<span class="rk-settings-item__label">الرئيسية</span>'
        +       '<span class="rk-settings-item__arrow" aria-hidden="true">›</span>'
        +     '</a>'
        +   '</div>'
        + '</div>'
        + '<div class="rk-settings-section">'
        +   '<button class="rk-settings-logout" id="rk-settings-logout" type="button">'
        +     Icons.iLogout + '<span>تسجيل الخروج</span>'
        +   '</button>'
        + '</div>'
        + '</div>'
      );

      bind();
    }).catch(function () {
      Utils.setMain('<div class="rk-section-loading">تعذّر تحميل الإعدادات.</div>');
    });
  }

  function pwStrength(v) {
    var s = 0;
    if (v.length >= 8)  s++;
    if (v.length >= 12) s++;
    if (/[A-Za-z]/.test(v) && /\d/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    return s; // 0..4
  }

  function bind() {
    /* Afficher/masquer mot de passe */
    document.querySelectorAll('.rk-pw-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var inp = document.getElementById(btn.dataset.target);
        if (inp) inp.type = inp.type === 'password' ? 'text' : 'password';
      });
    });

    /* Jauge de force du mot de passe */
    var pwNew = document.getElementById('rk-pw-new');
    var meter = document.getElementById('rk-pw-meter');
    if (pwNew && meter) {
      pwNew.addEventListener('input', function () {
        var s      = pwStrength(pwNew.value);
        var widths = ['0%', '25%', '50%', '75%', '100%'];
        var colors = ['#e2e8f0', '#dc2626', '#d97706', '#059669', '#059669'];
        meter.style.width      = widths[s];
        meter.style.background = colors[s];
      });
    }

    /* Sauvegarde profil */
    var profBtn = document.getElementById('rk-prof-save');
    profBtn.addEventListener('click', function () {
      var noticeEl = document.getElementById('rk-prof-notice');
      var payload  = {
        first_name: document.getElementById('rk-set-first').value.trim(),
        last_name:  document.getElementById('rk-set-last').value.trim(),
        email:      document.getElementById('rk-set-email').value.trim(),
        phone:      document.getElementById('rk-set-phone').value.trim(),
        bio:        document.getElementById('rk-set-bio').value.trim()
      };
      if (!payload.first_name) { notice(noticeEl, 'الاسم الأول مطلوب.', false); return; }

      profBtn.disabled = true; profBtn.classList.add('rk-set-btn--busy');
      Core.apiPost('/coach/profile', payload, false).then(function (res) {
        profBtn.disabled = false; profBtn.classList.remove('rk-set-btn--busy');
        if (res && res.success) {
          notice(noticeEl, 'تم حفظ التغييرات بنجاح.', true);
          var disp = document.getElementById('rk-set-display');
          if (disp && res.display) disp.textContent = res.display;
          if (res.display) {
            try { Core.storeGet(Core.KEYS.name) && sessionStorage.setItem(Core.KEYS.name, res.display); } catch (e) {}
          }
          Core.cacheDelete('_coach_home');
          Core.cacheDelete('_coach_me');
        } else {
          notice(noticeEl, (res && res.message) || 'تعذّر الحفظ. حاول مجدداً.', false);
        }
      }).catch(function () {
        profBtn.disabled = false; profBtn.classList.remove('rk-set-btn--busy');
        notice(noticeEl, 'تعذّر الاتصال بالخادم.', false);
      });
    });

    /* Changement de mot de passe */
    var pwBtn = document.getElementById('rk-pw-save');
    pwBtn.addEventListener('click', function () {
      var noticeEl = document.getElementById('rk-pw-notice');
      var current  = document.getElementById('rk-pw-current').value;
      var neu      = document.getElementById('rk-pw-new').value;
      var confirm  = document.getElementById('rk-pw-confirm').value;

      if (!current || !neu) { notice(noticeEl, 'جميع الحقول مطلوبة.', false); return; }
      if (neu.length < 8)   { notice(noticeEl, 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.', false); return; }
      if (neu !== confirm)  { notice(noticeEl, 'كلمتا المرور غير متطابقتين.', false); return; }

      pwBtn.disabled = true; pwBtn.classList.add('rk-set-btn--busy');
      Core.apiPost('/coach/password', {
        current_password: current,
        new_password:     neu,
        confirm_password: confirm
      }, false).then(function (res) {
        pwBtn.disabled = false; pwBtn.classList.remove('rk-set-btn--busy');
        if (res && res.success) {
          notice(noticeEl, res.message || 'تم تغيير كلمة المرور بنجاح.', true);
          ['rk-pw-current', 'rk-pw-new', 'rk-pw-confirm'].forEach(function (id) {
            document.getElementById(id).value = '';
          });
          var meterEl = document.getElementById('rk-pw-meter');
          if (meterEl) meterEl.style.width = '0%';
        } else {
          notice(noticeEl, (res && res.message) || 'تعذّر تغيير كلمة المرور.', false);
        }
      }).catch(function () {
        pwBtn.disabled = false; pwBtn.classList.remove('rk-set-btn--busy');
        notice(noticeEl, 'تعذّر الاتصال بالخادم.', false);
      });
    });

    /* Retour à l'accueil de la SPA */
    var homeLink = document.getElementById('rk-settings-home');
    if (homeLink) {
      homeLink.addEventListener('click', function (ev) {
        ev.preventDefault();
        window.nav('home');
      });
    }

    /* Déconnexion */
    document.getElementById('rk-settings-logout').addEventListener('click', function () {
      Core.storeClear();
      window.location.href = RK.config.login;
    });
  }

  RK.Pages = RK.Pages || {};
  RK.Pages.Settings = { render: render };
})();