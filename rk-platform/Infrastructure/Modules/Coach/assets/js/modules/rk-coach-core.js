/**
 * rk-coach-core.js — Auth, storage, cache, fetch helpers
 * Namespace: RKCoach.Core
 */
(function () {
  'use strict';
  var RK = window.RKCoach || (window.RKCoach = {});

  var cfg = window.RK_COACH || {};
  RK.config = {
    api:      cfg.api       || '',
    login:    cfg.login     || '',
    logo:     cfg.logo      || '',
    ficheUrl: cfg.fiche_url || '',
    badgesUrl: cfg.badges_url || '', // v9.14 — page Tutor native RK_Coach_Badges (CRUD + attribution شارات), déjà exposée côté PHP mais jamais consommée ici
    restNonce: cfg.restNonce || ''
  };

  /* ── État partagé du SPA — créé ICI (core = 1er fichier chargé) ──
   * Les 10 modules de pages font `var S = RK.state;` au chargement :
   * l'objet DOIT donc exister avant eux, sinon S === undefined et le
   * premier render() explose (ce qui était déguisé en ?expired=1). */
  RK.state = RK.state || {
    page: 'home', home: null, students: null,
    sessRange: 'week', childId: 0,
    evalsData: null, evalsFilter: { childId: 0, rating: 0 },
    pendingTab: null
  };

  var KEYS = {
    token:  'rk_coach_token',
    expiry: 'rk_coach_expiry',
    id:     'rk_coach_id',
    name:   'rk_coach_name',
    avatar: 'rk_coach_avatar'
  };

  function storeGet(k) {
    return sessionStorage.getItem(k) || localStorage.getItem(k) || null;
  }
  function storeClear() {
    Object.values(KEYS).forEach(function (k) {
      sessionStorage.removeItem(k);
      localStorage.removeItem(k);
    });
  }

  function persistToken(store, data) {
    store.setItem(KEYS.token,  data.token);
    store.setItem(KEYS.expiry, String((data.exp || 0) * 1000));
    if (data.id)     store.setItem(KEYS.id,     String(data.id));
    if (data.name)   store.setItem(KEYS.name,   String(data.name));
    if (data.avatar) store.setItem(KEYS.avatar, String(data.avatar));
  }

  /* Rafraîchit silencieusement le JWT via le cookie WP encore actif.
   * Évite le faux "?expired=1" quand le coach est toujours connecté
   * sur WordPress mais que son token JWT local a expiré. */
  function refreshToken() {
    var API = RK.config.api;
    return fetch(API + '/coach/refresh-token', {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: RK.config.restNonce ? { 'X-WP-Nonce': RK.config.restNonce } : {}
    }).then(function (r) {
      if (!r.ok) return null;
      return r.json();
    }).then(function (data) {
      if (!data || !data.token) return null;
      // Conserve le support choisi précédemment (sessionStorage vs
      // localStorage selon "se souvenir de moi").
      var store = localStorage.getItem(KEYS.token) ? localStorage : sessionStorage;
      persistToken(store, data);
      return data.token;
    }).catch(function () { return null; });
  }

  function getToken() {
    var token  = storeGet(KEYS.token);
    var expiry = parseInt(storeGet(KEYS.expiry) || '0', 10);
    if (!token || (expiry && Date.now() > expiry)) { storeClear(); return null; }
    return token;
  }

  var CACHE_TTL = 3 * 60 * 1000;

  function cacheGet(key) {
    try {
      var raw = sessionStorage.getItem('rk_api_' + key);
      if (!raw) return null;
      var item = JSON.parse(raw);
      if (Date.now() > item.exp) { sessionStorage.removeItem('rk_api_' + key); return null; }
      return item.data;
    } catch (err) { return null; }
  }

  function cacheSet(key, data) {
    try {
      sessionStorage.setItem('rk_api_' + key, JSON.stringify({ data: data, exp: Date.now() + CACHE_TTL }));
    } catch (err) { /* quota dépassé — silencieux */ }
  }

  function cacheDelete(key) {
    try { sessionStorage.removeItem('rk_api_' + key); } catch (err) {}
  }

  function redirect(url) { window.location.href = url; }

  /* Tente un refresh silencieux (cookie WP) puis relance le callback
   * fourni avec le nouveau token. Si le refresh échoue aussi, alors
   * seulement on considère la session réellement expirée. */
  function withRefreshFallback(retryFn) {
    return refreshToken().then(function (newToken) {
      if (!newToken) {
        storeClear();
        redirect(RK.config.login + '?expired=1');
        return Promise.reject('auth');
      }
      return retryFn(newToken);
    });
  }

  /* v3.2 — Certains hébergements mutualisés (LiteSpeed/LSAPI, ex.
   * Hostinger) suppriment le header "Authorization" avant qu'il
   * n'atteigne PHP, quelle que soit la config .htaccess (constaté via
   * /coach/ping : auth_header_reaches_php reste false). On duplique
   * donc systématiquement le token dans un header custom "X-*", jamais
   * filtré par ce mécanisme. Voir RK_Coach_Auth_Hardening (PHP) pour
   * la reconstruction côté serveur. On garde "Authorization" en plus,
   * au cas où l'hébergement le laisse passer normalement. */
  function authHeaders(tok, extra) {
    var h = { 'Authorization': 'Bearer ' + tok, 'X-RK-Coach-Auth': 'Bearer ' + tok };
    if (extra) for (var k in extra) h[k] = extra[k];
    return h;
  }

  function apiGet(path, useCache) {
    var API   = RK.config.api;
    var LOGIN = RK.config.login;
    var cacheKey = path.replace(/[^a-z0-9_]/gi, '_');
    if (useCache !== false) {
      var cached = cacheGet(cacheKey);
      if (cached !== null) return Promise.resolve(cached);
    }
    var token = getToken();
    if (!token) {
      return withRefreshFallback(function (newToken) {
        return doGet(newToken, false).then(function (r) { return r.json(); }).then(function (data) {
          if (useCache !== false) cacheSet(cacheKey, data);
          return data;
        });
      });
    }
    /* v3.1.2 — un 401/403 peut venir d'une réponse anonyme mise en cache
     * (LiteSpeed/proxy). Avant de déclarer la session expirée : UNE
     * relance silencieuse avec cache-buster + no-store. Si elle échoue
     * aussi → tenter un refresh JWT via le cookie WP avant de conclure
     * à une vraie expiration. */
    function doGet(tok, bust) {
      var url = API + path + (bust ? ((path.indexOf('?') === -1 ? '?' : '&') + '_rknc=' + Date.now()) : '');
      return fetch(url, {
        cache: 'no-store',
        headers: authHeaders(tok)
      });
    }
    return doGet(token, false).then(function (r) {
      if (r.status === 401 || r.status === 403) {
        return doGet(token, true).then(function (r2) {
          if (r2.status === 401 || r2.status === 403) {
            return withRefreshFallback(function (newToken) {
              return doGet(newToken, true).then(function (r3) { return r3.json(); });
            });
          }
          return r2.json();
        });
      }
      return r.json();
    }).then(function (data) {
      if (useCache !== false) cacheSet(cacheKey, data);
      return data;
    });
  }

  function apiPost(path, body) {
    var API = RK.config.api;
    function doPost(tok) {
      return fetch(API + path, {
        method:  'POST',
        headers: authHeaders(tok, { 'Content-Type': 'application/json' }),
        body:    JSON.stringify(body)
      });
    }
    var token = getToken();
    if (!token) {
      return withRefreshFallback(function (newToken) { return doPost(newToken).then(function (r) { return r.json(); }); });
    }
    return doPost(token).then(function (r) {
      if (r.status === 401 || r.status === 403) {
        return withRefreshFallback(function (newToken) { return doPost(newToken).then(function (r2) { return r2.json(); }); });
      }
      return r.json();
    });
  }

  /**
   * v9.59 — MANQUAIT : rk-coach-page-badges.js appelle Core.apiUpload()
   * depuis v9.20 (upload d'icône de badge), mais cette fonction n'a
   * jamais été définie ni exportée. L'appel échouait avec une
   * TypeError silencieuse, capturée par le .catch() du formulaire —
   * d'où "الشارة محفوظة، لكن تعذّر رفع الصورة" à chaque tentative,
   * même une fois la route serveur enregistrée.
   *
   * Pas de Content-Type manuel : le navigateur doit fixer le boundary
   * multipart lui-même.
   */
  function apiUpload(path, formData) {
    var API = RK.config.api;
    function doUpload(tok) {
      return fetch(API + path, {
        method:  'POST',
        headers: authHeaders(tok),
        body:    formData
      });
    }
    var token = getToken();
    if (!token) {
      return withRefreshFallback(function (newToken) { return doUpload(newToken).then(function (r) { return r.json(); }); });
    }
    return doUpload(token).then(function (r) {
      if (r.status === 401 || r.status === 403) {
        return withRefreshFallback(function (newToken) { return doUpload(newToken).then(function (r2) { return r2.json(); }); });
      }
      return r.json();
    });
  }

  function apiDelete(path) {
    var API = RK.config.api;
    function doDelete(tok) {
      return fetch(API + path, {
        method:  'DELETE',
        headers: authHeaders(tok)
      });
    }
    var token = getToken();
    if (!token) {
      return withRefreshFallback(function (newToken) { return doDelete(newToken).then(function (r) { return r.json(); }); });
    }
    return doDelete(token).then(function (r) {
      if (r.status === 401 || r.status === 403) {
        return withRefreshFallback(function (newToken) { return doDelete(newToken).then(function (r2) { return r2.json(); }); });
      }
      return r.json();
    });
  }

  RK.Core = {
    KEYS: KEYS,
    storeGet: storeGet,
    storeClear: storeClear,
    getToken: getToken,
    cacheGet: cacheGet,
    cacheSet: cacheSet,
    cacheDelete: cacheDelete,
    apiGet: apiGet,
    apiPost: apiPost,
    apiDelete: apiDelete,
    apiUpload: apiUpload,
    redirect: redirect
  };
})();