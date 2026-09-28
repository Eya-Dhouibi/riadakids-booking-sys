/**
 * RK Children — Page "شاراتي" (Badges) du dashboard enfant.
 *
 * Gère : le filtre الحالة (statut earned/locked), le filtre المجال
 * (catégorie/programme), la bascule شارات الإنجاز / المغامرات, le
 * marquage "vu" des badges جديدة, et le carrousel mobile (swipe/drag).
 *
 * RECONSTITUÉ depuis rk-badges.min.js (le fichier "source" ne contenait
 * en réalité que du JS déjà minifié — voir historique du 12/08/2026).
 * Renommage de variables + commentaires ajoutés pour lisibilité.
 *
 * v9.33 — Le filtre الحالة (مكتسبة/مقفلة) n'affecte plus la catégorie
 * "المستويات" (data-category="level") : ces badges sont attribués
 * manuellement par le coach comme les autres catégories, mais restent
 * TOUJOURS visibles en entier quel que soit le statut choisi — décision
 * explicite de l'utilisateur (12/08/2026). L'option "جديدة" a également
 * été retirée du filtre (redondante avec "مكتسبة" : mêmes badges).
 */
!(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    var root = document.querySelector("[data-rk-badges]");
    if (!root) return;

    var badgeCards   = root.querySelectorAll("[data-rkb-card]");
    var sections      = root.querySelectorAll("[data-rkb-section]");
    var noResultsEl   = root.querySelector("[data-rkb-no-results]");
    var filterWidgets = root.querySelectorAll("[data-rkb-filter]");

    // Catégorie exemptée du filtre "الحالة" — toujours affichée en
    // entier, peu importe مكتسبة/مقفلة (voir v9.33 ci-dessus).
    var STATUS_FILTER_EXEMPT_CATEGORY = "level";

    var activeFilters = {
      status: "all",       // "all" | "earned" | "locked"
      type: "achievement",  // "achievement" | "adventure"
      domain: "all",        // "all" | "cat:xxx" | "prog:xxx"
    };

    /**
     * Applique les 3 filtres actifs (statut, type, domaine) à toutes
     * les cartes, masque les sections/groupes vides, et affiche le
     * message "aucun résultat" si besoin. Appelée à chaque changement
     * de filtre.
     *
     * @param {boolean} [scrollToResult] - si true, scrolle jusqu'à la
     *   première section visible après filtrage (utilisé pour le
     *   filtre المجال uniquement).
     */
    function applyFilters(scrollToResult) {
      var visibleCount = 0;

      badgeCards.forEach(function (card) {
        var category = card.getAttribute("data-category");
        var isExemptFromStatus = category === STATUS_FILTER_EXEMPT_CATEGORY;

        var matchesStatus =
          isExemptFromStatus ||
          activeFilters.status === "all" ||
          (activeFilters.status === "earned" && card.getAttribute("data-earned") === "1") ||
          (activeFilters.status === "locked" && card.getAttribute("data-earned") === "0");

        var matchesType = card.getAttribute("data-type") === activeFilters.type;

        var matchesDomain = true;
        if (activeFilters.domain !== "all") {
          if (activeFilters.domain.indexOf("cat:") === 0) {
            matchesDomain = category === activeFilters.domain.slice(4);
          } else if (activeFilters.domain.indexOf("prog:") === 0) {
            var advGroup = card.closest(".rkb2__adv-group");
            matchesDomain = !!advGroup && advGroup.getAttribute("data-program") === activeFilters.domain.slice(5);
          }
        }

        var isVisible = matchesStatus && matchesType && matchesDomain;
        card.hidden = !isVisible;
        if (isVisible) visibleCount++;
      });

      // Masque les groupes "aventure" (par programme) devenus vides.
      root.querySelectorAll(".rkb2__adv-group").forEach(function (group) {
        var hasVisibleCard = Array.prototype.some.call(
          group.querySelectorAll("[data-rkb-card]"),
          function (card) { return !card.hidden; }
        );
        group.hidden = !hasVisibleCard;
      });

      // Masque les sections de catégorie devenues vides.
      sections.forEach(function (section) {
        var hasVisibleCard = Array.prototype.some.call(
          section.querySelectorAll("[data-rkb-card]"),
          function (card) { return !card.hidden; }
        );
        section.hidden = !hasVisibleCard;
      });

      if (noResultsEl) noResultsEl.hidden = visibleCount > 0;

      if (scrollToResult && visibleCount > 0) {
        var firstVisible = root.querySelector(
          "[data-rkb-section]:not([hidden]), .rkb2__adv-group:not([hidden])"
        );
        if (firstVisible) {
          firstVisible.scrollIntoView({ behavior: "smooth", block: "start" });
        }
      }
    }

    // ── Dropdowns de filtre (الحالة + المجال) — comportement listbox ARIA ──
    filterWidgets.forEach(function (widget) {
      var filterKey     = widget.getAttribute("data-rkb-filter");
      var trigger        = widget.querySelector("[data-rkb-filter-trigger]");
      var triggerText    = widget.querySelector("[data-rkb-filter-text]");
      var list           = widget.querySelector(".rkb2__filter-list");
      var options         = Array.prototype.slice.call(widget.querySelectorAll('[role="option"]'));

      function closeDropdown() {
        widget.setAttribute("data-open", "false");
        trigger.setAttribute("aria-expanded", "false");
        list.hidden = true;
      }

      function focusOption(index) {
        if (options[index]) options[index].focus();
      }

      if (!trigger || !triggerText || !list) return;

      trigger.addEventListener("click", function (e) {
        e.stopPropagation();
        if (widget.getAttribute("data-open") === "true") {
          closeDropdown();
          return;
        }
        widget.setAttribute("data-open", "true");
        trigger.setAttribute("aria-expanded", "true");
        list.hidden = false;
        var selected = list.querySelector(".is-selected") || options[0];
        if (selected) focusOption(options.indexOf(selected));
      });

      options.forEach(function (option) {
        option.addEventListener("click", function () {
          var value = option.getAttribute("data-value") || "achievement";
          var label = option.textContent.trim();

          activeFilters[filterKey] = value;
          triggerText.textContent = label;

          options.forEach(function (opt) {
            var isSelected = (opt.getAttribute("data-value") || "all") === value;
            opt.classList.toggle("is-selected", isSelected);
            opt.setAttribute("aria-selected", isSelected ? "true" : "false");
          });

          applyFilters(filterKey === "domain");
          closeDropdown();
          trigger.focus();
        });
      });

      list.addEventListener("keydown", function (e) {
        var currentIndex = options.indexOf(document.activeElement);
        if (e.key === "ArrowDown") {
          e.preventDefault();
          focusOption(currentIndex + 1 < options.length ? currentIndex + 1 : 0);
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          focusOption(currentIndex > 0 ? currentIndex - 1 : options.length - 1);
        } else if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          if (document.activeElement && document.activeElement.getAttribute("role") === "option") {
            document.activeElement.click();
          }
        } else if (e.key === "Escape") {
          closeDropdown();
          trigger.focus();
        }
      });

      document.addEventListener("click", function (e) {
        if (!widget.contains(e.target)) closeDropdown();
      });
    });

    // ── Bascule شارات الإنجاز / المغامرات — filtre le dropdown المجال ──
    (function setupTypeToggle() {
      var typeToggle = root.querySelector("[data-rkb-type-toggle]");
      var domainWidget = root.querySelector('[data-rkb-filter="domain"]');
      var domainText = domainWidget ? domainWidget.querySelector("[data-rkb-filter-text]") : null;
      var domainOptions = domainWidget
        ? Array.prototype.slice.call(domainWidget.querySelectorAll('[role="option"]'))
        : [];

      if (typeToggle) {
        var typeButtons = Array.prototype.slice.call(typeToggle.querySelectorAll("[data-value]"));
        typeButtons.forEach(function (btn) {
          btn.addEventListener("click", function () {
            var type = btn.getAttribute("data-value") || "achievement";
            activeFilters.type = type;
            typeButtons.forEach(function (b) { b.classList.toggle("is-active", b === btn); });
            syncDomainOptionsForType(type);
            applyFilters();
          });
        });
        syncDomainOptionsForType(activeFilters.type);
      }

      /**
       * Affiche/masque les options du dropdown المجال selon le type
       * actif (achievement vs adventure), via leur data-scope. Si
       * l'option actuellement sélectionnée n'est plus dans le scope,
       * retombe automatiquement sur la première option valide.
       */
      function syncDomainOptionsForType(type) {
        var currentSelectionStillValid = false;

        domainOptions.forEach(function (opt) {
          var scopes = (opt.getAttribute("data-scope") || "").split(",");
          var inScope = scopes.indexOf(type) !== -1;
          opt.hidden = !inScope;
          if (inScope && opt.classList.contains("is-selected")) {
            currentSelectionStillValid = true;
          }
        });

        if (!currentSelectionStillValid && domainOptions.length) {
          var fallback = domainOptions[0];
          domainOptions.forEach(function (opt) {
            var isFallback = opt === fallback;
            opt.classList.toggle("is-selected", isFallback);
            opt.setAttribute("aria-selected", isFallback ? "true" : "false");
          });
          activeFilters.domain = fallback.getAttribute("data-value") || "all";
          if (domainText) domainText.textContent = fallback.textContent.trim();
        }
      }
    })();

    applyFilters();

    // ── Marquage "vu" des badges جديدة (flag retiré au premier clic) ──
    if (typeof window.rkExperience !== "undefined") {
      badgeCards.forEach(function (card) {
        if (card.getAttribute("data-new") !== "1") return;

        card.addEventListener("click", function markSeenOnce() {
          card.removeEventListener("click", markSeenOnce);
          var badgeKey = card.getAttribute("data-badge-key") || "";
          if (!badgeKey) return;

          var params = new URLSearchParams();
          params.set("action", "rk_badge_mark_seen");
          params.set("nonce", window.rkExperience.nonce);
          params.set("badge_key", badgeKey);

          fetch(window.rkExperience.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: params.toString(),
          })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              if (res && res.success && res.data.is_new === false) {
                card.classList.remove("is-new");
                card.setAttribute("data-new", "0");
                var flag = card.querySelector(".rkb2__card-flag");
                if (flag) flag.remove();
              }
            })
            .catch(function () {});
        });
      });
    }

    // ── Carrousel mobile (auto-scroll + swipe/drag manuel) ─────────────
    var isMobileViewport = window.matchMedia("(max-width: 640px)");
    var prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    Array.prototype.slice
      .call(root.querySelectorAll(".rkb2__grid, .rkb1__grid"))
      .forEach(function (grid) {
        var autoScrollInterval = null;
        var resumeTimeout = null;

        function scrollToNextCard() {
          var visibleChildren = Array.prototype.filter.call(grid.children, function (c) {
            return !c.hidden;
          });
          if (visibleChildren.length < 2) return;

          var currentScroll = grid.scrollLeft;
          var closestIndex = 0;
          var closestDistance = Infinity;
          visibleChildren.forEach(function (child, idx) {
            var distance = Math.abs(child.offsetLeft - currentScroll);
            if (distance < closestDistance) {
              closestDistance = distance;
              closestIndex = idx;
            }
          });

          var nextCard = visibleChildren[(closestIndex + 1) % visibleChildren.length];
          grid.scrollTo({ left: nextCard.offsetLeft, behavior: "smooth" });
        }

        function startAutoScroll() {
          if (autoScrollInterval || prefersReducedMotion || !isMobileViewport.matches) return;
          autoScrollInterval = window.setInterval(scrollToNextCard, 3500);
        }

        function stopAutoScroll() {
          if (autoScrollInterval) {
            window.clearInterval(autoScrollInterval);
            autoScrollInterval = null;
          }
        }

        /** Pause l'auto-scroll pendant une interaction manuelle, reprend après 5s d'inactivité. */
        function pauseThenResume() {
          stopAutoScroll();
          if (resumeTimeout) window.clearTimeout(resumeTimeout);
          resumeTimeout = window.setTimeout(startAutoScroll, 5000);
        }

        grid.setAttribute("data-rk-carousel", "");
        grid.addEventListener("touchstart", pauseThenResume, { passive: true });
        grid.addEventListener("pointerdown", pauseThenResume);

        if (isMobileViewport.addEventListener) {
          isMobileViewport.addEventListener("change", function () {
            isMobileViewport.matches ? startAutoScroll() : stopAutoScroll();
          });
        } else {
          // Fallback Safari < 14 (pas d'addEventListener sur MediaQueryList).
          isMobileViewport.addListener(function () {
            isMobileViewport.matches ? startAutoScroll() : stopAutoScroll();
          });
        }

        startAutoScroll();
      });
  });
})();