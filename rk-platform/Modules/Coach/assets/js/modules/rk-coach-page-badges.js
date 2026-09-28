/**
 * RK Coach — Page "الشارات" (Badges) de la SPA coach.
 *
 * Rôle : CRUD complet des badges (système + custom) exposé en AJAX pur
 * pour l'onglet #badges de la SPA (espace-coach/#badges). Consomme
 * l'API REST de RK_Coach_Badges_Controller.
 *
 * RECONSTITUÉ depuis rk-coach-page-badges.min.js (le fichier source
 * .js avait été accidentellement écrasé par du contenu PHP — voir
 * historique du 12/08/2026). Renommage de variables + commentaires
 * ajoutés pour lisibilité ; comportement runtime IDENTIQUE au .min.js
 * de production — à valider par diff du .min.js régénéré avant déploiement.
 */
!(function () {
  "use strict";

  var RK   = window.RKCoach;
  var Core = RK.Core;
  var Utils = RK.Utils;

  // ── Icônes SVG inline (évite une requête réseau par icône) ──────────
  var ICON_BADGE_PLACEHOLDER =
    '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>';
  var ICON_LOCKED =
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
  var ICON_PLUS =
    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
  var ICON_EDIT =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg>';
  var ICON_DELETE =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

  // ── Couleurs par catégorie (doit rester synchro avec valid_cats() PHP) ──
  var CATEGORY_COLORS = {
    skill: "#1B4F8C",
    special: "#E8500A",
    mastery: "#7c3aed",
    streak: "#d97706",
    start: "#059669",
    level: "#0891b2",
  };

  // ── État local de la page (persiste tant que l'onglet SPA reste ouvert) ──
  var state = {
    childId: 0,          // (non utilisé actuellement, réservé)
    overview: null,       // dernière réponse de GET /coach/badges
    filterChildId: 0,     // élève sélectionné dans le filtre catalogue
    filterBadges: null,   // badges du filterChildId (GET /coach/badges/child)
  };

  /**
   * Point d'entrée : charge la vue d'ensemble et peint toute la page.
   * Appelé au premier affichage de l'onglet #badges ET après toute
   * mutation qui doit rafraîchir intégralement l'état (create/delete).
   */
  function render() {
    state.childId = 0;
    Utils.setMain('<div class="rk-section-loading">جاري تحميل الشارات…</div>');

    Core.apiGet("/coach/badges", false)
      .then(function (data) {
        if (!data || data.code) {
          Utils.setMain('<div class="rk-section-loading">تعذّر تحميل الشارات.</div>');
          return;
        }
        state.overview = data;
        renderPage();
      })
      .catch(function () {
        Utils.setMain('<div class="rk-section-loading">تعذّر تحميل الشارات.</div>');
      });
  }

  /** Peint la page complète (filtre élève + catalogue) depuis state.overview. */
  function renderPage() {
    var overview = state.overview;
    var students = overview.students || [];

    var studentOptions = students
      .map(function (s) {
        var selected = state.filterChildId === s.id ? " selected" : "";
        return (
          '<option value="' + s.id + '"' + selected + ">" +
          Utils.e(s.name) + " (" + s.earned + "/" + s.total + ")</option>"
        );
      })
      .join("");

    var filterBar =
      '<div class="rk-badges-filter-bar">' +
        '<label class="rk-badges-filter-label" for="rk-badges-child-filter">تصفية حسب الطالب:</label>' +
        '<select class="rk-mf-input rk-badges-filter-select" id="rk-badges-child-filter">' +
          '<option value="0"' + (state.filterChildId ? "" : " selected") + ">— عرض الكتالوج فقط —</option>" +
          studentOptions +
        "</select>" +
      "</div>";

    var catalogueHtml = renderCatalogue(overview.catalogue || [], overview.categories || {});

    Utils.setMain(
      '<div class="rk-page-head">' +
        "<h2 class=\"rk-page-title\">" + ICON_BADGE_PLACEHOLDER + " إدارة الشارات</h2>" +
        '<button class="rk-btn-primary rk-btn-sm" id="rk-badges-new" type="button">' + ICON_PLUS + " شارة جديدة</button>" +
      "</div>" +
      filterBar +
      '<div id="rk-badges-catalogue">' + catalogueHtml + "</div>"
    );

    bindPageEvents();
    bindStudentFilter();
  }

  /** (Re)binde le <select> de filtrage par élève — appelé une seule fois par renderPage(). */
  function bindStudentFilter() {
    var select = document.getElementById("rk-badges-child-filter");
    if (!select) return;

    select.addEventListener("change", function () {
      var childId = parseInt(select.value, 10) || 0;
      state.filterChildId = childId;

      if (!childId) {
        state.filterBadges = null;
        refreshCatalogueOnly();
        return;
      }

      Core.apiGet("/coach/badges/child?child_id=" + childId, false)
        .then(function (res) {
          state.filterBadges = res && res.badges ? res.badges : [];
          refreshCatalogueOnly();
        })
        .catch(function () {
          state.filterBadges = null;
          Utils.showToast("تعذّر تحميل شارات الطالب.", "error");
        });
    });
  }

  /**
   * Re-peint UNIQUEMENT le bloc catalogue (pas tout le head/filtre),
   * à partir de state.overview déjà en mémoire — utilisé après un
   * changement de filtre élève, pas besoin d'un nouveau GET /coach/badges.
   */
  function refreshCatalogueOnly() {
    var container = document.getElementById("rk-badges-catalogue");
    if (!container) return;
    var overview = state.overview;
    // v9.35 — garde défensive : si state.overview est null/undefined
    // (ex: state réinitialisé par un autre appel entre-temps), l'accès
    // à overview.catalogue levait une TypeError non catchée par du code
    // synchrone, qui remontait comme rejet de Promise jusqu'au .catch()
    // silencieux d'awardBadgeToFilteredChild()/revokeBadgeFromFilteredChild()
    // — le bouton se réactivait sans aucun message, symptôme observé :
    // "loading avec opacity, puis rien, statut jamais changé".
    if (!overview) {
      console.error("[RK_BADGES] refreshCatalogueOnly() appelée sans state.overview — rechargement complet.");
      Core.apiGet("/coach/badges", false).then(function (res) {
        state.overview = res;
        refreshCatalogueOnly();
      });
      return;
    }
    container.innerHTML = renderCatalogue(overview.catalogue || [], overview.categories || {});
    bindPageEvents();
  }

  /** Construit le HTML d'une carte badge du catalogue (achievement, custom ou système). */
  function renderBadgeCard(badge, earnedRecord) {
    var filterActive = !!state.filterChildId;
    var isEarnedByFilteredChild = !!earnedRecord;

    // Boutons crayon/poubelle : uniquement pour les badges "custom"
    // (custom === true couvre aussi bien les badges système migrés que
    // les badges créés par un coach — voir RKP_BadgeQueryService::catalogue()).
    var editDeleteActions = badge.custom
      ? '<div class="rk-badges-card__icon-actions">' +
          '<button class="rk-badges-card__icon-btn" data-edit-custom data-key="' + Utils.e(badge.key) + '" type="button" title="تعديل" aria-label="تعديل الشارة">' + ICON_EDIT + "</button>" +
          '<button class="rk-badges-card__icon-btn rk-badges-card__icon-btn--danger" data-delete-custom data-key="' + Utils.e(badge.key) + '" type="button" title="حذف" aria-label="حذف الشارة">' + ICON_DELETE + "</button>" +
        "</div>"
      : "";

    // Bouton منح/سحب : uniquement visible quand un élève est filtré.
    var assignAction = "";
    if (filterActive) {
      assignAction = isEarnedByFilteredChild
        ? '<button class="rk-badges-card__btn rk-badges-card__btn--revoke" data-revoke-cat data-key="' + Utils.e(badge.key) + '" type="button">سحب</button>'
        : '<button class="rk-badges-card__btn rk-badges-card__btn--award" data-award-cat data-key="' + Utils.e(badge.key) + '" type="button">منح</button>';
    }

    var iconHtml = badge.icon_url
      ? '<img src="' + Utils.e(badge.icon_url) + '" alt="" width="52" height="52" loading="lazy" ' +
        'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">' +
        '<span class="rk-badges-card__icon-fallback" aria-hidden="true">' + ICON_BADGE_PLACEHOLDER + "</span>"
      : ICON_BADGE_PLACEHOLDER;

    return (
      '<div class="rk-badges-card rk-badges-card--catalogue' + (isEarnedByFilteredChild ? " rk-badges-card--earned" : "") + '">' +
        editDeleteActions +
        '<div class="rk-badges-card__icon" style="--cat-color:' + (CATEGORY_COLORS[badge.cat] || "#1B4F8C") + '">' + iconHtml + "</div>" +
        '<p class="rk-badges-card__name">' + Utils.e(badge.name) + "</p>" +
        (badge.custom && !badge.is_system ? '<span class="rk-badges-card__custom-tag">مخصصة</span>' : "") +
        (badge.desc ? '<p class="rk-badges-card__desc">' + Utils.e(badge.desc) + "</p>" : "") +
        (assignAction ? '<div class="rk-badges-card__assign">' + assignAction + "</div>" : "") +
      "</div>"
    );
  }

  /** Construit le HTML du catalogue complet, groupé par catégorie. */
  function renderCatalogue(catalogue, categoryLabels) {
    var byCategory = {};
    catalogue.forEach(function (badge) {
      var cat = badge.cat || "skill";
      (byCategory[cat] = byCategory[cat] || []).push(badge);
    });

    // BUGFIX — filterBadges contient le CATALOGUE COMPLET pour l'élève
    // filtré (chaque badge avec earned:true|false), pas seulement ses
    // badges obtenus (voir RK_Coach_Badges_Controller::get_child_view()
    // → RKP_BadgeQueryService::get_catalogue_for_child()). Sans le
    // ".filter(earned)" ci-dessous, earnedByKey contenait une entrée
    // pour CHAQUE badge du catalogue → isEarnedByFilteredChild était
    // toujours truthy → bouton "سحب" affiché partout, y compris pour un
    // élève n'ayant encore aucun badge (au lieu de "منح").
    var earnedByKey = {};
    (state.filterBadges || [])
      .filter(function (b) { return b.earned; })
      .forEach(function (b) {
        earnedByKey[b.key] = b;
      });

    var sectionsHtml = Object.keys(byCategory)
      .map(function (cat) {
        var label = categoryLabels[cat] || cat;
        var color = CATEGORY_COLORS[cat] || "#1B4F8C";
        var cardsHtml = byCategory[cat]
          .map(function (badge) {
            return renderBadgeCard(badge, earnedByKey[badge.key]);
          })
          .join("");

        return (
          '<div class="rk-badges-cat-section">' +
            '<p class="rk-badges-cat-title" style="--cat-color:' + color + '">' + Utils.e(label) + "</p>" +
            '<div class="rk-badges-cat-grid">' + cardsHtml + "</div>" +
          "</div>"
        );
      })
      .join("");

    return (
      '<div class="rk-section">' +
        '<div class="rk-section-head">' +
          "<h3 class=\"rk-section-title\">" + ICON_BADGE_PLACEHOLDER + ' شارات الإنجاز <span class="rk-badges-count-pill">' + catalogue.length + "</span></h3>" +
        "</div>" +
        (sectionsHtml || '<p class="rk-badges-empty-hint">لا توجد شارات في الكتالوج بعد.</p>') +
      "</div>"
    );
  }

  /**
   * Attache tous les listeners du bloc catalogue + bouton "شارة جديدة".
   * DOIT être rappelée après CHAQUE remplacement de innerHTML (renderPage
   * et refreshCatalogueOnly), sinon les nouveaux noeuds DOM restent inertes.
   */
  function bindPageEvents() {
    var newBtn = document.getElementById("rk-badges-new");
    if (newBtn) {
      newBtn.addEventListener("click", function () {
        openBadgeForm(null);
      });
    }

    document.querySelectorAll("[data-edit-custom]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var key = btn.dataset.key;
        var badge = (state.overview.custom_badges || []).filter(function (b) {
          return b.badge_key === key;
        })[0];

        if (!badge) {
          // Le badge existe dans le catalogue affiché mais pas dans
          // custom_badges (liste scoping coach) : état local désynchronisé.
          // On recharge tout plutôt que d'échouer silencieusement.
          Utils.showToast("الشارة غير محدّثة محليًا، جاري إعادة التحميل…", "error");
          render();
          return;
        }
        openBadgeForm(badge);
      });
    });

    document.querySelectorAll("[data-delete-custom]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var key = btn.dataset.key;
        var badge = (state.overview.custom_badges || []).filter(function (b) {
          return b.badge_key === key;
        })[0];

        if (!badge) {
          Utils.showToast("الشارة غير محدّثة محليًا، جاري إعادة التحميل…", "error");
          render();
          return;
        }
        deleteCustomBadge(key, !!badge.is_system, btn);
      });
    });

    document.querySelectorAll("[data-award-cat]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        awardBadgeToFilteredChild(btn, state.filterChildId);
      });
    });

    document.querySelectorAll("[data-revoke-cat]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        revokeBadgeFromFilteredChild(btn, state.filterChildId);
      });
    });
  }

  /**
   * Supprime un badge custom/système après confirmation.
   *
   * v9.59 — Avertissement du nombre d'enfants affectés AVANT la
   * confirmation, via GET /coach/badges/custom/{key}/impact. Le message
   * de confirmation était auparavant générique ("سيسحبها من جميع
   * الطلاب") sans jamais dire combien — l'utilisateur confirmait à
   * l'aveugle. Anti double-clic sur l'icône poubelle pendant la requête
   * de pré-vérification.
   */
  function deleteCustomBadge(badgeKey, isSystem, triggerBtn) {
    if (triggerBtn && triggerBtn.dataset.rkBusy === "1") return;
    if (triggerBtn) triggerBtn.dataset.rkBusy = "1";

    Core.apiGet("/coach/badges/custom/" + badgeKey + "/impact", false)
      .then(function (res) {
        if (triggerBtn) triggerBtn.dataset.rkBusy = "0";

        var count = (res && res.success) ? (res.children_count || 0) : null;

        var confirmMsg = isSystem
          ? "هذه شارة أساسية مشتركة بين جميع المدربين. حذفها سيؤثر على الجميع."
          : "سيتم حذف هذه الشارة نهائياً.";

        if (count === null) {
          // Pré-vérification indisponible : on ne bloque pas la suppression,
          // mais on ne peut plus promettre un chiffre exact.
          confirmMsg += " سيتم سحبها من جميع الطلاب الذين حصلوا عليها. هل أنت متأكد؟";
        } else if (count > 0) {
          confirmMsg += " هذه الشارة ممنوحة حالياً لـ " + count + " " +
            (count === 1 ? "طالب" : "طالب/طلاب") +
            " — سيتم سحبها منهم أيضاً. هل أنت متأكد من الحذف؟";
        } else {
          confirmMsg += " لا يوجد أي طالب حاصل عليها حالياً. هل أنت متأكد من الحذف؟";
        }

        if (!confirm(confirmMsg)) return;

        Core.apiDelete("/coach/badges/custom/" + badgeKey)
          .then(function (res2) {
            if (res2 && res2.success) {
              var affected = res2.children_affected || 0;
              Utils.showToast(
                affected > 0
                  ? "تم حذف الشارة وسحبها من " + affected + " طالب."
                  : "تم حذف الشارة.",
                "success"
              );
              Core.cacheDelete("/coach/badges");
              render();
            } else {
              Utils.showToast("تعذّر حذف الشارة.", "error");
            }
          })
          .catch(function () {
            Utils.showToast("تعذّر حذف الشارة.", "error");
          });
      })
      .catch(function () {
        if (triggerBtn) triggerBtn.dataset.rkBusy = "0";
        Utils.showToast("تعذّر التحقق من الشارة.", "error");
      });
  }

  /* ── État de chargement des boutons de carte (v9.57) ─────────────
   * Avant : seul btn.disabled = true était posé. Aucune règle CSS ne
   * ciblait [disabled] sur .rk-badges-card__btn — le clic ne produisait
   * donc AUCUN retour visuel pendant l'appel réseau. Symptôme rapporté :
   * "loaded sans effet".
   * Le libellé d'origine est mémorisé pour pouvoir restaurer le bouton
   * en cas d'échec ; en cas de succès la carte est de toute façon
   * redessinée par refreshCatalogueOnly().
   * ─────────────────────────────────────────────────────────────── */

  /** Passe un bouton de carte badge en état "chargement". */
  function setBtnLoading(btn) {
    if (!btn) return;
    btn.dataset.rkLabel = btn.textContent;
    btn.classList.add("is-loading");
    btn.setAttribute("aria-busy", "true");
    btn.disabled = true;
    btn.innerHTML = '<span class="rk-btn-spinner" aria-hidden="true"></span>';
  }

  /**
   * Bascule localement l'etat d'un badge dans state.filterBadges.
   *
   * v9.58 — Avant, l'affichage n'etait mis a jour qu'apres le GET
   * /coach/badges/child qui suit l'action : le bouton restait fige
   * pendant tout l'aller-retour, puis changeait d'un coup. Ici on
   * applique l'etat cible immediatement (le serveur vient de le
   * confirmer par un 200), le GET qui suit ne fait que confirmer.
   */
  function setLocalEarned(badgeKey, earned) {
    var list = state.filterBadges || [];
    var found = false;
    list.forEach(function (b) {
      if (b.key === badgeKey) {
        b.earned = earned;
        if (!earned) { b.earned_at = null; b.is_new = false; }
        found = true;
      }
    });
    if (!found && earned) list.push({ key: badgeKey, earned: true });
    state.filterBadges = list;

    /*
     * v9.62 — BUGFIX : "le statut ne change pas directement apres le clic".
     *
     * Reproduit et confirme par execution reelle du module dans un DOM
     * simule (GET de resynchronisation retarde de 30ms) : la carte
     * passait correctement a سحب au clic, PUIS revenait a منح des la
     * reponse du GET qui suit (award()/revoke() -> GET /child pour
     * "confirmer"). Cause : ce GET peut lire une valeur pas encore
     * propagee cote serveur (cache objet, replica, requete servie avant
     * que l'ecriture precedente soit visible) -- il ecrasait alors l'etat
     * fraichement confirme par le 200 de award()/revoke() lui-meme, qui
     * EST la source de verite pour CE badge precis a cet instant.
     *
     * Le badge vient de recevoir une confirmation serveur explicite : on
     * le marque protege pendant une courte fenetre. Le GET de
     * resynchronisation qui suit ignore ce badge s'il tente de le
     * contredire -- il reste utile pour tous les AUTRES badges (etat
     * modifie par un autre coach en parallele, etc.), simplement pas
     * pour celui que l'action en cours vient de trancher.
     */
    state.pinnedBadges = state.pinnedBadges || {};
    state.pinnedBadges[badgeKey] = earned;
    setTimeout(function () {
      if (state.pinnedBadges) delete state.pinnedBadges[badgeKey];
    }, 4000);
  }

  /**
   * Applique la reponse d'un GET de resynchronisation SANS jamais
   * contredire un badge protege par setLocalEarned() (voir ci-dessus).
   */
  function applyServerBadges(serverBadges) {
    var pinned = state.pinnedBadges || {};
    var merged = (serverBadges || []).map(function (b) {
      if (Object.prototype.hasOwnProperty.call(pinned, b.key)) {
        var copy = {};
        for (var k in b) copy[k] = b[k];
        copy.earned = pinned[b.key];
        return copy;
      }
      return b;
    });
    state.filterBadges = merged;
  }

  /** Restaure un bouton après échec. */
  function clearBtnLoading(btn) {
    if (!btn) return;
    btn.classList.remove("is-loading");
    btn.removeAttribute("aria-busy");
    btn.disabled = false;
    if (btn.dataset.rkLabel) btn.textContent = btn.dataset.rkLabel;
    btn.dataset.rkBusy = "0";
  }

  /** Attribue le badge de la carte cliquée à l'élève actuellement filtré. */
  function awardBadgeToFilteredChild(btn, childId) {
    // v9.61 — Le bouton منح n'est normalement rendu QUE quand un élève
    // est filtré (voir renderBadgeCard : assignAction reste vide sinon).
    // Mais childId est capturé au moment du clic, pas au moment du
    // rendu : entre les deux (carte encore affichée pendant un
    // refreshCatalogueOnly() en vol, ou clic juste au moment où le
    // filtre repasse sur "0 — عرض الكتالوج فقط —"), il peut valoir 0.
    // Avant ce correctif, la requête partait quand même avec
    // child_id: 0 et le serveur répondait 400 'missing_params' — code
    // sans libellé dédié, affiché brut sans expliquer au coach qu'il
    // doit d'abord choisir un élève (point 4 du comportement attendu).
    if (!childId) {
      Utils.showToast("يرجى اختيار طالب أولاً لمنح الشارة.", "error");
      return;
    }

    // v9.22 — anti double-clic : sans ce garde-fou, un second clic
    // pendant que la 1re requête est encore en vol (award + GET child
    // + re-render) pouvait déclencher un 2e award() sur le même badge,
    // ou pire, faire arriver la réponse du GET dans le désordre et
    // réafficher le mauvais état (بouton سحب/منح inversé par rapport à
    // la réalité en base) une fois le catalogue redessiné.
    if (btn.dataset.rkBusy === "1") return;
    btn.dataset.rkBusy = "1";
    var badgeKey = btn.dataset.key;
    setBtnLoading(btn);

    Core.apiPost("/coach/badges/award", { child_id: childId, badge_key: badgeKey })
      .then(function (res) {
        if (res && res.success) {
          Utils.showToast("تم منح الشارة!", "success");
          // Bascule instantanee : le serveur a confirme (200).
          setLocalEarned(badgeKey, true);
          refreshCatalogueOnly();
          return Core.apiGet("/coach/badges/child?child_id=" + childId, false).then(function (r) {
            applyServerBadges(r && r.badges ? r.badges : []);
            refreshCatalogueOnly();
          });
        }

        // v9.30 — le serveur renvoie désormais un code précis (voir
        // RKP_BadgeCommandService::award_with_reason()). 'already_awarded'
        // est le cas le plus fréquent en usage réel : l'état affiché côté
        // coach était désynchronisé (double-clic, onglet resté ouvert
        // pendant qu'un autre coach agissait...) — le badge EST bien
        // acquis en base, donc on resynchronise la carte au lieu de
        // juste afficher une erreur et laisser le bouton "منح" à tort.
        if (res && res.code === "already_awarded") {
          Utils.showToast("الطالب يملك هذه الشارة بالفعل — تم تحديث العرض.", "info");
          // L'etat reel EST "acquis" : on l'applique tout de suite, sinon
          // la carte se reaffichait sur "منح" et le clic suivant renvoyait
          // encore 409 — boucle sans issue.
          setLocalEarned(badgeKey, true);
          refreshCatalogueOnly();
          return Core.apiGet("/coach/badges/child?child_id=" + childId, false).then(function (r) {
            applyServerBadges(r && r.badges ? r.badges : []);
            refreshCatalogueOnly();
          });
        }

        // v9.58 — Le code d'echec etait avale : la console montrait
        // "409 (Conflict)" et l'utilisateur un "تعذّر منح الشارة"
        // generique, sans moyen de savoir LAQUELLE des 4 causes
        // (unknown_badge / already_awarded / insert_failed /
        // points_failed) s'etait produite. On l'affiche desormais.
        // v9.60 — 'not_authorized' ajoute : ce code sort de
        // coach_owns_child() cote serveur (voir le controller). Absent
        // d'ici, il tombait dans le message generique "تعذّر منح الشارة
        // (not_authorized)", incomprehensible pour le coach — surtout
        // qu'un 403 passe d'abord par le circuit de rafraichissement de
        // token (apiPost()) avant d'arriver ici, ce qui n'aide pas au
        // diagnostic si on ne voit que le code brut.
        var CODE_MSG = {
          unknown_badge: "هذه الشارة لم تعد موجودة في الكتالوج.",
          not_authorized: "هذا الطالب غير مرتبط بحسابك كمدرب. حدّث الصفحة وحاول مجدداً.",
          insert_failed: "تعذّر حفظ الشارة في قاعدة البيانات.",
          points_failed: "تم منح الشارة لكن تعذّر احتساب النقاط — لم يتم الحفظ.",
          service_unavailable: "خدمة الشارات غير متوفرة حالياً."
        };
        var code = (res && res.code) ? res.code : "unknown_error";
        console.error("[RK_BADGES] award refuse par le serveur, code =", code, res);
        clearBtnLoading(btn);
        Utils.showToast((CODE_MSG[code] || "تعذّر منح الشارة") + " (" + code + ")", "error");
      })
      .catch(function (err) {
        // v9.35 — CRITIQUE : ce catch capturait aussi bien une vraie
        // erreur réseau/refresh-token QU'UNE EXCEPTION JS survenue dans
        // le .then() ci-dessus (ex: refreshCatalogueOnly() qui plante
        // si state.overview devient inattendu) — dans les deux cas,
        // le bouton se réactivait SANS AUCUN message, laissant
        // l'utilisateur devant un bouton qui "charge puis ne fait
        // rien" sans explication. On log désormais l'erreur réelle en
        // console pour le diagnostic, et on affiche systématiquement
        // un toast pour que l'échec soit au moins visible.
        console.error("[RK_BADGES] awardBadgeToFilteredChild a échoué :", err);
        clearBtnLoading(btn);
        Utils.showToast("حدث خطأ غير متوقع أثناء منح الشارة.", "error");
      });
  }

  /** Retire le badge de la carte cliquée à l'élève actuellement filtré. */
  function revokeBadgeFromFilteredChild(btn, childId) {
    if (btn.dataset.rkBusy === "1") return;
    var badgeKey = btn.dataset.key;
    if (!confirm("سحب هذه الشارة من الطالب؟")) return;

    btn.dataset.rkBusy = "1";
    setBtnLoading(btn);
    Core.apiPost("/coach/badges/revoke", { child_id: childId, badge_key: badgeKey })
      .then(function (res) {
        if (res && res.success) {
          // v9.58 — 'not_awarded' : l'enfant n'avait pas ce badge (badge
          // achievement calcule dynamiquement, ou etat d'affichage
          // desynchronise). Le serveur repond 200 car l'etat voulu est
          // atteint, mais on ne ment pas a l'utilisateur : message neutre
          // plutot qu'un "تم سحب الشارة" trompeur.
          if (res.code === "not_awarded") {
            Utils.showToast("الطالب لا يملك هذه الشارة — تم تحديث العرض.", "info");
          } else {
            Utils.showToast("تم سحب الشارة.", "success");
          }
          setLocalEarned(badgeKey, false);
          refreshCatalogueOnly();
          return Core.apiGet("/coach/badges/child?child_id=" + childId, false).then(function (r) {
            applyServerBadges(r && r.badges ? r.badges : []);
            refreshCatalogueOnly();
          });
        }
        clearBtnLoading(btn);
        var msg = (res && res.code === "unknown_badge")
          ? "هذه الشارة لم تعد موجودة في الكتالوج."
          : "تعذّر سحب الشارة.";
        Utils.showToast(msg, "error");
      })
      .catch(function (err) {
        // v9.35 — voir même correctif dans awardBadgeToFilteredChild :
        // ce catch capturait toute exception (réseau OU JS interne à
        // refreshCatalogueOnly()) sans jamais rien afficher.
        console.error("[RK_BADGES] revokeBadgeFromFilteredChild a échoué :", err);
        clearBtnLoading(btn);
        Utils.showToast("حدث خطأ غير متوقع أثناء سحب الشارة.", "error");
      });
  }

  /**
   * Ouvre la modale de création/édition d'un badge custom.
   * @param {Object|null} badge - null pour créer, sinon l'objet badge à éditer.
   */
  function openBadgeForm(badge) {
    var isEdit = !!badge;
    var categoryLabels = state.overview.categories || {};
    var pendingIconFile = null;

    var categoryOptions = Object.keys(categoryLabels)
      .map(function (catKey) {
        var selected = badge && badge.cat === catKey ? " selected" : "";
        return '<option value="' + catKey + '"' + selected + ">" + Utils.e(categoryLabels[catKey]) + "</option>";
      })
      .join("");

    var currentIconUrl = (badge && badge.icon_url) || "";
    var iconPreviewHtml = currentIconUrl
      ? '<img src="' + Utils.e(currentIconUrl) + '" alt="" width="64" height="64">'
      : ICON_BADGE_PLACEHOLDER;

    var overlay = document.createElement("div");
    overlay.className = "rk-modal-overlay";
    overlay.innerHTML =
      '<div class="rk-modal" role="dialog" aria-modal="true">' +
        '<div class="rk-modal__header">' +
          "<h3 class=\"rk-modal__title\">" + (isEdit ? "تعديل الشارة" : "إنشاء شارة جديدة") + "</h3>" +
          '<button class="rk-modal__close" type="button" aria-label="إغلاق">✕</button>' +
        "</div>" +
        '<form class="rk-modal__form" id="rk-badge-form" novalidate>' +
          '<div class="rk-mf-field">' +
            '<label class="rk-mf-label">صورة الشارة</label>' +
            '<div class="rk-mf-icon-upload">' +
              '<div class="rk-mf-icon-preview" id="rk-mf-icon-preview">' + iconPreviewHtml + "</div>" +
              "<div>" +
                '<button class="rk-mf-btn rk-mf-btn--cancel" id="rk-mf-icon-pick" type="button">اختر صورة</button>' +
                '<p class="rk-mf-icon-hint">SVG, PNG, JPG أو WebP — بحد أقصى 2 ميغابايت</p>' +
              "</div>" +
              '<input type="file" id="rk-mf-icon-file" accept="image/svg+xml,image/png,image/jpeg,image/webp" style="display:none">' +
            "</div>" +
          "</div>" +
          '<div class="rk-mf-field">' +
            '<label class="rk-mf-label">اسم الشارة <span class="rk-req">*</span></label>' +
            '<input class="rk-mf-input" name="name" type="text" required maxlength="100" placeholder="مثال: المبدع الصغير" value="' + Utils.e(badge ? badge.name : "") + '">' +
          "</div>" +
          '<div class="rk-mf-field">' +
            '<label class="rk-mf-label">الفئة</label>' +
            '<select class="rk-mf-input" name="cat">' + categoryOptions + "</select>" +
          "</div>" +
          '<div class="rk-mf-field">' +
            '<label class="rk-mf-label">الوصف</label>' +
            '<textarea class="rk-mf-input" name="desc" rows="2" placeholder="وصف قصير يظهر للطالب عند حصوله على الشارة">' + Utils.e(badge ? badge.desc : "") + "</textarea>" +
          "</div>" +
          '<div class="rk-mf-field">' +
            '<label class="rk-mf-label">نوع الشارة <span class="rk-req">*</span></label>' +
            '<div class="rk-mf-radio-row">' +
              '<label class="rk-mf-radio"><input type="radio" name="badge_type" value="achievement"' + (badge && badge.badge_type === "achievement" ? " checked" : "") + " required> شارات الإنجاز</label>" +
              '<label class="rk-mf-radio"><input type="radio" name="badge_type" value="adventure"' + (badge && badge.badge_type === "adventure" ? " checked" : "") + " required> شارات المغامرات</label>" +
            "</div>" +
          "</div>" +
          '<div class="rk-mf-actions">' +
            '<button class="rk-mf-btn rk-mf-btn--cancel" type="button">إلغاء</button>' +
            '<button class="rk-mf-btn rk-mf-btn--submit" type="submit">' + (isEdit ? "حفظ التعديلات" : "إنشاء الشارة") + "</button>" +
          "</div>" +
        "</form>" +
      "</div>";

    document.body.appendChild(overlay);

    var closeModal = function () {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    };
    overlay.querySelector(".rk-modal__close").addEventListener("click", closeModal);
    overlay.querySelector(".rk-mf-btn--cancel").addEventListener("click", closeModal);
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) closeModal();
    });

    // ── Upload d'icône (aperçu local, upload réel après sauvegarde) ──
    var fileInput = overlay.querySelector("#rk-mf-icon-file");
    var iconPreview = overlay.querySelector("#rk-mf-icon-preview");
    overlay.querySelector("#rk-mf-icon-pick").addEventListener("click", function () {
      fileInput.click();
    });
    fileInput.addEventListener("change", function () {
      var file = fileInput.files[0];
      if (!file) return;

      if (file.size > 2 * 1024 * 1024) {
        Utils.showToast("حجم الصورة يجب ألا يتجاوز 2 ميغابايت.", "error");
        fileInput.value = "";
        return;
      }
      pendingIconFile = file;

      var reader = new FileReader();
      reader.onload = function (e) {
        iconPreview.innerHTML = '<img src="' + e.target.result + '" alt="" width="64" height="64">';
      };
      reader.readAsDataURL(file);
    });

    // ── Soumission du formulaire (create ou update) ──────────────────
    overlay.querySelector("#rk-badge-form").addEventListener("submit", function (e) {
      e.preventDefault();

      var form = new FormData(e.target);
      var payload = {
        name: form.get("name"),
        icon_key: badge ? badge.icon_key : "star",
        cat: form.get("cat"),
        desc: form.get("desc"),
        badge_type: form.get("badge_type"),
      };

      if (!payload.name || !payload.badge_type) return;

      var submitBtn = overlay.querySelector(".rk-mf-btn--submit");
      submitBtn.disabled = true;
      submitBtn.textContent = "جاري الحفظ…";

      var savePromise = isEdit
        ? Core.apiPost("/coach/badges/custom/" + badge.badge_key, payload)
        : Core.apiPost("/coach/badges/custom", payload);

      savePromise
        .then(function (res) {
          if (!res || !res.success) {
            submitBtn.disabled = false;
            submitBtn.textContent = isEdit ? "حفظ التعديلات" : "إنشاء الشارة";
            var errorMessages = {
              empty_name: "اسم الشارة مطلوب.",
              missing_type: "يرجى اختيار نوع الشارة (إنجاز أو مغامرة).",
              not_found: "الشارة غير موجودة.",
            };
            Utils.showToast(errorMessages[res && res.code] || "حدث خطأ، حاول مرة أخرى.", "error");
            return;
          }

          var savedBadgeKey = isEdit ? badge.badge_key : res.badge_key;
          uploadPendingIcon(savedBadgeKey, pendingIconFile).then(function () {
            closeModal();
            Utils.showToast(isEdit ? "تم تحديث الشارة." : "تم إنشاء الشارة بنجاح!", "success");
            Core.cacheDelete("/coach/badges");
            render();
          });
        })
        .catch(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = isEdit ? "حفظ التعديلات" : "إنشاء الشارة";
        });
    });
  }

  /**
   * Upload l'icône en attente pour un badge donné, si l'utilisateur en a
   * choisi une. Ne bloque jamais le flux principal : en cas d'échec,
   * affiche un toast d'avertissement mais résout quand même la promesse
   * (le badge est déjà sauvegardé à ce stade).
   *
   * ATTENTION : nécessite que la route REST POST
   * /coach/badges/custom/{badge_key}/upload-icon soit enregistrée dans
   * class-rk-coach-api.php — absente au moment de cet audit (12/08/2026),
   * cet appel échouera en 404 tant qu'elle n'est pas ajoutée.
   */
  function uploadPendingIcon(badgeKey, file) {
    if (!file) return Promise.resolve(true);

    var formData = new FormData();
    formData.append("icon", file);

    return Core.apiUpload("/coach/badges/custom/" + badgeKey + "/upload-icon", formData)
      .then(function (res) {
        if (!res || !res.success) {
          Utils.showToast("تم حفظ الشارة، لكن تعذّر رفع الصورة.", "error");
        }
        return true;
      })
      .catch(function () {
        Utils.showToast("تم حفظ الشارة، لكن تعذّر رفع الصورة.", "error");
        return true;
      });
  }

  // ── Rendu de la mini-liste de badges dans le panneau détail élève ────
  var CHILD_PANEL_CATEGORY_LABELS = {
    start: "البداية",
    streak: "الاستمرارية",
    mastery: "الإتقان",
    skill: "المهارات",
    special: "خاصة",
    level: "المستويات",
  };

  function renderChildBadgeCard(badge) {
    var earned = badge.earned;
    var borderColor = earned ? "#fde68a" : "#e2e8f0";
    var bgColor = earned ? "#fffbeb" : "#f8fafc";
    var iconColor = earned ? "#d97706" : "#94a3b8";
    var nameColor = earned ? "#0D1F35" : "#94a3b8";
    var earnedAtLabel = earned && badge.earned_at ? RK.Pages.Students.fmtDate(badge.earned_at) : "";

    return (
      '<div class="rk-badge-card ' + (earned ? "rk-badge-card--earned" : "rk-badge-card--locked") + '" ' +
        'style="border:1px solid ' + borderColor + ";background:" + bgColor + ';border-radius:14px;padding:14px;text-align:center;">' +
        '<div style="color:' + iconColor + ';display:flex;justify-content:center;margin-bottom:6px;">' +
          (earned ? ICON_BADGE_PLACEHOLDER : ICON_LOCKED) +
        "</div>" +
        '<div style="font-weight:700;font-size:.85rem;color:' + nameColor + ';">' + Utils.e(badge.name) + "</div>" +
        '<div style="font-size:.72rem;color:#94a3b8;margin-top:2px;">' + Utils.e(badge.desc || "") + "</div>" +
        (earned && badge.earned_at
          ? '<div style="font-size:.68rem;color:#d97706;margin-top:6px;">' + Utils.e(earnedAtLabel) + "</div>"
          : "") +
      "</div>"
    );
  }

  /** Charge et affiche les badges d'un élève dans le panneau #rk-panel-badges (fiche élève). */
  function loadChildBadges(childId) {
    var panel = document.getElementById("rk-panel-badges");
    if (!panel) return;

    Core.apiGet("/coach/child/badges?child_id=" + childId, false).then(function (badges) {
      panel = document.getElementById("rk-panel-badges");
      if (!panel) return;

      if (!Array.isArray(badges) || !badges.length) {
        panel.innerHTML = '<div class="rk-empty" style="padding:24px"><p>لا توجد شارات متاحة.</p></div>';
        return;
      }

      var earnedCount = badges.filter(function (b) { return b.earned; }).length;
      var summaryHtml =
        '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">' +
          '<div style="color:#d97706;">' + ICON_BADGE_PLACEHOLDER + "</div>" +
          "<div>" +
            '<div style="font-weight:700;color:#0D1F35;">' + earnedCount + " / " + badges.length + " شارة</div>" +
            '<div style="font-size:.75rem;color:#94a3b8;">شارات محقّقة من إجمالي الشارات المتاحة</div>' +
          "</div>" +
        "</div>";

      var byCategory = {};
      badges.forEach(function (b) {
        var cat = b.cat || "other";
        (byCategory[cat] = byCategory[cat] || []).push(b);
      });

      var sectionsHtml = Object.keys(byCategory)
        .map(function (cat) {
          var label = CHILD_PANEL_CATEGORY_LABELS[cat] || cat;
          var cardsHtml = byCategory[cat].map(renderChildBadgeCard).join("");
          return (
            '<div style="margin-bottom:18px;">' +
              '<div style="font-size:.8rem;font-weight:700;color:#64748b;margin-bottom:8px;">' + Utils.e(label) + "</div>" +
              '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;">' + cardsHtml + "</div>" +
            "</div>"
          );
        })
        .join("");

      panel.innerHTML = summaryHtml + sectionsHtml;
    }).catch(function () {
      var panel2 = document.getElementById("rk-panel-badges");
      if (panel2) {
        panel2.innerHTML = '<div class="rk-empty" style="padding:24px"><p>تعذّر تحميل الشارات.</p></div>';
      }
    });
  }

  // ── Export public (consommé par rk-coach-nav.js / rk-coach-spa.js) ───
  RK.Pages = RK.Pages || {};
  RK.Pages.Badges = {
    render: render,
    loadChildBadges: loadChildBadges,
  };
})();