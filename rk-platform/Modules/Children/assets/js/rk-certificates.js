/**
 * RK Children — Actions sur les cartes certificat (شهاداتي).
 *
 * Gère : voir (ouverture directe), télécharger (export PDF via
 * html2canvas + jsPDF, chargés à la demande), partager (Web Share API
 * avec repli copie de lien).
 *
 * RECONSTITUÉ depuis rk-certificates.min.js. Renommage de variables +
 * commentaires pour lisibilité.
 *
 * v9.44 — L'action "عرض" (voir) n'ouvre plus de modale avec iframe.
 * Cause du retrait : .rkc2__modal fixait display:flex en dur dans le
 * CSS, ce qui l'emportait sur l'attribut HTML [hidden] (bug de
 * spécificité CSS séparé, corrigé aussi dans rk-dashboard-v4.css) —
 * la modale restait visible et vide dès le chargement de la page, même
 * sans clic. Plutôt que de re-fiabiliser un mécanisme modale+iframe
 * fragile, décision explicite de l'utilisateur (12/08/2026) : "voir"
 * ouvre directement le certificat dans un nouvel onglet, plus robuste
 * et sans dépendance à l'état caché/visible d'un élément superposé.
 */
!(function () {
  "use strict";

  var HTML2CANVAS_CDN = "https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js";
  var JSPDF_CDN = "https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js";
  var pdfLibsLoaded = null;

  /** Charge un script externe une seule fois (résout immédiatement si déjà présent). */
  function loadScriptOnce(src, isAlreadyLoaded) {
    if (isAlreadyLoaded()) return Promise.resolve();
    return new Promise(function (resolve, reject) {
      var script = document.createElement("script");
      script.src = src;
      script.onload = resolve;
      script.onerror = function () {
        reject(new Error("Failed to load " + src));
      };
      document.head.appendChild(script);
    });
  }

  /** Bascule un bouton en état "chargement" (spinner) et le restaure ensuite. */
  function setButtonLoading(btn, isLoading, restoreHtml) {
    if (isLoading) {
      btn.dataset.originalHtml = btn.innerHTML;
      btn.innerHTML =
        '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="rkc2__spin" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke-dasharray="42" stroke-dashoffset="14"/></svg>';
      btn.disabled = true;
    } else {
      btn.innerHTML = btn.dataset.originalHtml || restoreHtml || btn.innerHTML;
      btn.disabled = false;
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var root = document.querySelector("[data-rk-certificates]");
    if (!root) return;

    root.querySelectorAll("[data-rk-cert-card]").forEach(function (card) {
      var viewBtn = card.querySelector("[data-rk-cert-view]");
      var downloadBtn = card.querySelector("[data-rk-cert-download]");
      var shareBtn = card.querySelector("[data-rk-cert-share]");
      var printUrl = card.getAttribute("data-print-url") || "";
      var title = card.getAttribute("data-title") || "";

      if (viewBtn) {
        viewBtn.addEventListener("click", function () {
          if (!printUrl) return;
          window.open(printUrl, "_blank", "noopener");
        });
      }

      if (downloadBtn) {
        downloadBtn.addEventListener("click", function () {
          downloadCertificateAsPdf(printUrl, title, downloadBtn);
        });
      }

      if (shareBtn) {
        shareBtn.addEventListener("click", function () {
          shareCertificate(printUrl, title, shareBtn);
        });
      }
    });
  });

  /**
   * Génère un PDF du certificat en rendant la page d'impression dans un
   * iframe hors-écran (jamais visible à l'utilisateur — pur mécanisme
   * technique de capture, sans rapport avec l'ancienne modale "voir"),
   * puis capture le rendu via html2canvas et l'exporte en PDF via jsPDF.
   * Repli : ouverture de la page d'impression si la génération échoue.
   */
  function downloadCertificateAsPdf(printUrl, title, btn) {
    if (!printUrl) return;
    var originalHtml = btn.innerHTML;
    setButtonLoading(btn, true);

    (pdfLibsLoaded ||
      (pdfLibsLoaded = Promise.all([
        loadScriptOnce(HTML2CANVAS_CDN, function () { return typeof window.html2canvas !== "undefined"; }),
        loadScriptOnce(JSPDF_CDN, function () { return typeof window.jspdf !== "undefined"; }),
      ])))
      .then(function () {
        return new Promise(function (resolve, reject) {
          var iframe = document.createElement("iframe");
          iframe.style.position = "fixed";
          iframe.style.top = "-10000px";
          iframe.style.left = "-10000px";
          iframe.style.width = "900px";
          iframe.style.height = "700px";
          iframe.setAttribute("aria-hidden", "true");
          iframe.onload = function () {
            setTimeout(function () { resolve(iframe); }, 400);
          };
          iframe.onerror = function () {
            reject(new Error("iframe load failed"));
          };
          document.body.appendChild(iframe);
          iframe.src = printUrl;
        });
      })
      .then(function (iframe) {
        var surface = iframe.contentDocument.getElementById("rk-cert-surface");
        if (!surface) throw new Error("certificate surface not found");

        return window.html2canvas(surface, { scale: 2, useCORS: true, backgroundColor: "#ffffff" }).then(function (canvas) {
          iframe.remove();
          var pdf = new window.jspdf.jsPDF({
            orientation: "landscape",
            unit: "px",
            format: [canvas.width, canvas.height],
          });
          pdf.addImage(canvas.toDataURL("image/png"), "PNG", 0, 0, canvas.width, canvas.height);
          pdf.save((title || "certificate").replace(/[\\/:*?"<>|]/g, "-") + ".pdf");
        });
      })
      .catch(function () {
        // Repli : la génération PDF a échoué, on ouvre directement la page imprimable.
        window.open(printUrl, "_blank", "noopener,width=900,height=650");
      })
      .finally(function () {
        setButtonLoading(btn, false, originalHtml);
      });
  }

  /** Partage le lien du certificat via Web Share API, ou copie le lien en repli. */
  function shareCertificate(printUrl, title, btn) {
    if (!printUrl) return;
    var absoluteUrl = new URL(printUrl, window.location.href).href;

    if (navigator.share) {
      navigator.share({ title: title || "Certificate", url: absoluteUrl }).catch(function () {});
      return;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard
        .writeText(absoluteUrl)
        .then(function () {
          flashCopiedState(btn);
        })
        .catch(function () {
          window.prompt("انسخ الرابط:", absoluteUrl);
        });
      return;
    }

    window.prompt("انسخ الرابط:", absoluteUrl);
  }

  /** Affiche brièvement une coche de confirmation sur le bouton partage après copie. */
  function flashCopiedState(btn) {
    var originalHtml = btn.innerHTML;
    btn.innerHTML =
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    btn.classList.add("is-copied");
    setTimeout(function () {
      btn.innerHTML = originalHtml;
      btn.classList.remove("is-copied");
    }, 1800);
  }
})();