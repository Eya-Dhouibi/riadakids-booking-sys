/**
 * RK Child Dashboard — Magic JS
 * Countdown · Count-up · Badge popup · Confetti · Bars · Tabs
 */
(function () {
    'use strict';

    var rkCDB = window.rkCDB || {};

    function qs(s)  { return document.querySelector(s); }
    function qsa(s) { return Array.prototype.slice.call(document.querySelectorAll(s)); }
    function text(el, val) { if (el) el.textContent = val; }
    function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

    /* ──────────────────────────────────────────────────────────
       COUNTDOWN — blocs jours/heures/minutes
       ────────────────────────────────────────────────────────── */
    function initCountdown() {
        var appointment = (rkCDB.appointment || '').replace(' ', 'T');
        if (!appointment) return;

        var target = new Date(appointment).getTime();
        if (isNaN(target)) return;

        var elDays  = qs('#rk-cd-days');
        var elHours = qs('#rk-cd-hours');
        var elMins  = qs('#rk-cd-mins');
        if (!elDays || !elHours || !elMins) return;

        function tick() {
            var diff = target - Date.now();
            if (diff <= 0) {
                text(elDays,  '0');
                text(elHours, '00');
                text(elMins,  '00');
                return;
            }
            text(elDays,  Math.floor(diff / 864e5));
            text(elHours, pad(Math.floor((diff % 864e5) / 36e5)));
            text(elMins,  pad(Math.floor((diff % 36e5)  / 6e4)));
        }
        function pad(n) { return String(n).padStart(2, '0'); }

        tick();
        window.setInterval(tick, 30000);
    }

    /* ──────────────────────────────────────────────────────────
       COUNT-UP — animation chiffres
       ────────────────────────────────────────────────────────── */
    function initCountUp() {
        if (!('IntersectionObserver' in window)) {
            qsa('.rk-countup[data-count-up]').forEach(function (el) {
                el.textContent = el.dataset.countUp;
            });
            return;
        }

        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el     = entry.target;
                var target = parseInt(el.dataset.countUp || '0', 10);
                var dur    = 1100;
                var start  = performance.now();

                function frame(now) {
                    var t   = Math.min((now - start) / dur, 1);
                    el.textContent = Math.round(easeOutCubic(t) * target);
                    if (t < 1) requestAnimationFrame(frame);
                }
                requestAnimationFrame(frame);
                obs.unobserve(el);
            });
        }, { threshold: 0.4 });

        qsa('.rk-countup[data-count-up]').forEach(function (el) {
            obs.observe(el);
        });
    }

    /* ──────────────────────────────────────────────────────────
       PROGRESS BARS — animate on scroll
       ────────────────────────────────────────────────────────── */
    function animateBars() {
        if (!('IntersectionObserver' in window)) return;

        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                var w  = el.style.width;
                el.style.width      = '0';
                el.style.transition = 'none';
                requestAnimationFrame(function () {
                    setTimeout(function () {
                        el.style.transition = 'width .9s cubic-bezier(.4,0,.2,1)';
                        el.style.width      = w;
                    }, 50);
                });
                obs.unobserve(el);
            });
        }, { threshold: 0.2 });

        qsa('.rk-progress-bar__fill, .rk-cdb__progress-bar, .rk-cdb__skill-bar').forEach(function (el) {
            obs.observe(el);
        });
    }

    /* ──────────────────────────────────────────────────────────
       LEVEL-UP OVERLAY
       ────────────────────────────────────────────────────────── */
    function initLevelUp() {
        if (!rkCDB.levelup) return;
        var overlay = qs('#rk-levelup-overlay');
        if (!overlay) return;

        window.setTimeout(function () {
            overlay.hidden = false;
            launchConfetti(60);
        }, 600);

        var btn = qs('#rk-levelup-close');
        if (btn) {
            btn.addEventListener('click', function () {
                overlay.hidden = true;
            });
        }
    }

    /* ──────────────────────────────────────────────────────────
       BADGE POPUP
       ────────────────────────────────────────────────────────── */
    function initBadgePopup() {
        qsa('.rk-badge-card[data-badge-name]').forEach(function (card) {
            card.addEventListener('click', function () {
                openBadgePopup(
                    this.dataset.badgeName   || '',
                    this.dataset.badgeRarity || '',
                    this.dataset.badgeStory  || '',
                    this.dataset.badgeEarned === '1',
                    this.dataset.badgeDate   || '',
                    this.querySelector('.rk-badge-card__icon')
                );
            });
        });

        var backdrop = qs('#rk-badge-popup-backdrop');
        if (backdrop) backdrop.addEventListener('click', closeBadgePopup);

        var closeBtn = qs('#rk-badge-popup-close');
        if (closeBtn) closeBtn.addEventListener('click', closeBadgePopup);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeBadgePopup();
        });
    }

    function openBadgePopup(name, rarity, story, earned, date, iconEl) {
        var popup = qs('#rk-badge-popup');
        if (!popup) return;

        var rarityLabels = {
            start: 'انطلاق', streak: 'استمرارية',
            mastery: 'إتقان', skill: 'مهارة',
            special: 'مميزة', level: 'مستوى'
        };

        text(qs('#rk-bp-name'),       name);
        text(qs('#rk-bp-story'),      story);
        text(qs('#rk-bp-date-text'),  date);
        text(qs('#rk-bp-rarity'),     rarityLabels[rarity] || rarity);

        var rarityEl = qs('#rk-bp-rarity');
        if (rarityEl) {
            rarityEl.className = 'rk-badge-popup__rarity rk-badge-popup__rarity--' + (rarity || 'start');
        }

        /* Clone icon dans le popup */
        var iconTarget = qs('#rk-bp-icon');
        if (iconTarget) {
            iconTarget.innerHTML = '';
            if (iconEl) {
                var clone = iconEl.cloneNode(true);
                clone.style.cssText = 'width:80px;height:80px;border-radius:24px;display:flex;align-items:center;justify-content:center;';
                clone.querySelectorAll('svg').forEach(function (s) {
                    s.setAttribute('width', '44');
                    s.setAttribute('height', '44');
                });
                iconTarget.appendChild(clone);
            }
        }

        var wrapper = qs('#rk-bp-icon-wrapper');
        if (wrapper) {
            wrapper.className = 'rk-badge-popup__icon' + (earned ? ' rk-badge-popup__icon--earned' : '');
        }

        var dateRow = qs('#rk-bp-date');
        if (dateRow) dateRow.style.display = date ? '' : 'none';

        popup.hidden = false;
        document.body.style.overflow = 'hidden';

        if (earned) window.setTimeout(function () { launchConfetti(30); }, 200);
    }

    function closeBadgePopup() {
        var popup = qs('#rk-badge-popup');
        if (popup) popup.hidden = true;
        document.body.style.overflow = '';
    }

    /* ──────────────────────────────────────────────────────────
       CONFETTI
       ────────────────────────────────────────────────────────── */
    function launchConfetti(count) {
        count = count || 40;
        var colors = [
            'var(--e-global-color-primary, #E8500A)',
            'var(--e-global-color-secondary, #1B4F8C)',
            '#FFD700', '#FF6B35', '#7C3AED', '#10B981', '#F59E0B', '#EC4899'
        ];
        for (var i = 0; i < count; i++) {
            (function (delay) {
                window.setTimeout(function () {
                    var piece = document.createElement('div');
                    piece.className = 'rk-confetti-piece';
                    piece.style.left             = (Math.random() * 100) + 'vw';
                    piece.style.background       = colors[Math.floor(Math.random() * colors.length)];
                    piece.style.width            = (7 + Math.random() * 9) + 'px';
                    piece.style.height           = (7 + Math.random() * 9) + 'px';
                    piece.style.animationDuration= (1.6 + Math.random() * 2) + 's';
                    piece.style.animationDelay   = (Math.random() * .35) + 's';
                    piece.style.borderRadius     = Math.random() > .5 ? '50%' : '3px';
                    document.body.appendChild(piece);
                    window.setTimeout(function () {
                        if (piece.parentNode) piece.parentNode.removeChild(piece);
                    }, 4500);
                }, delay);
            })(i * 28);
        }
    }

    /* ──────────────────────────────────────────────────────────
       SMOOTH ANCHOR — bottom nav
       ────────────────────────────────────────────────────────── */
    function initBottomNav() {
        qsa('.rk-bottom-navigation__item').forEach(function (link) {
            link.addEventListener('click', function (e) {
                var href = this.getAttribute('href');
                if (href && href.charAt(0) === '#') {
                    e.preventDefault();
                    var target = qs(href);
                    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    /* ──────────────────────────────────────────────────────────
       MSG TABS — coach / admin
       ────────────────────────────────────────────────────────── */
    function initMsgTabs() {
        qsa('.rk-msg-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                qsa('.rk-msg-tab').forEach(function (t) {
                    t.classList.remove('rk-msg-tab--active');
                    t.setAttribute('aria-selected', 'false');
                });
                qsa('.rk-msg-panel').forEach(function (p) {
                    p.classList.add('rk-msg-panel--hidden');
                });
                this.classList.add('rk-msg-tab--active');
                this.setAttribute('aria-selected', 'true');
                var panelId = 'panel-' + this.dataset.channel;
                var panel   = qs('#' + panelId);
                if (panel) panel.classList.remove('rk-msg-panel--hidden');
            });
        });
    }

    /* ──────────────────────────────────────────────────────────
       TOOLTIPS
       ────────────────────────────────────────────────────────── */
    function initTooltips() {
        qsa('[title]').forEach(function (el) {
            var title = el.getAttribute('title');
            if (!title || el.hasAttribute('data-tippy')) return;
            var tooltip;

            function showTip(e) {
                tooltip = document.createElement('div');
                tooltip.className   = 'rk-cdb__tooltip';
                tooltip.textContent = title;
                document.body.appendChild(tooltip);
                el.removeAttribute('title');
                moveTip(e);
            }
            function moveTip(e) {
                if (!tooltip) return;
                tooltip.style.position = 'fixed';
                tooltip.style.top  = (e.clientY - 38) + 'px';
                tooltip.style.left = (e.clientX - tooltip.offsetWidth / 2) + 'px';
            }
            function hideTip() {
                if (tooltip && tooltip.parentNode) tooltip.parentNode.removeChild(tooltip);
                tooltip = null;
                el.setAttribute('title', title);
            }
            el.addEventListener('mouseenter', showTip);
            el.addEventListener('mousemove',  moveTip);
            el.addEventListener('mouseleave', hideTip);
        });
    }

    /* ──────────────────────────────────────────────────────────
       LESSON DOTS — animate on scroll
       ────────────────────────────────────────────────────────── */
    function initLessonDots() {
        if (!('IntersectionObserver' in window)) return;
        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var dots = entry.target.querySelectorAll('.rk-lesson-dot');
                dots.forEach(function (dot, idx) {
                    dot.style.opacity   = '0';
                    dot.style.transform = 'scale(0)';
                    window.setTimeout(function () {
                        dot.style.transition = 'opacity .25s, transform .25s';
                        dot.style.opacity    = '1';
                        dot.style.transform  = '';
                    }, idx * 25 + 50);
                });
                obs.unobserve(entry.target);
            });
        }, { threshold: 0.3 });

        qsa('.rk-lesson-dots').forEach(function (el) { obs.observe(el); });
    }

    /* ──────────────────────────────────────────────────────────
       INIT
       ────────────────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        initCountdown();
        initCountUp();
        animateBars();
        initLevelUp();
        initBadgePopup();
        initBottomNav();
        initMsgTabs();
        initTooltips();
        initLessonDots();
    });

    /* Expose publiquement */
    window.rkLaunchConfetti  = launchConfetti;
    window.rkCloseBadgePopup = closeBadgePopup;

})();
