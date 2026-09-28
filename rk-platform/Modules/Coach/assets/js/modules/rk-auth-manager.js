/**
 * RiadaKids — Gestionnaire d'auth unifié (v9.36)
 *
 * Gère les tokens JWT pour child/parent/coach en localStorage
 * (survit fermeture navigateur, pas de sessionStorage perdu).
 *
 * @module RKAuthManager
 */

!(function () {
  "use strict";

  const STORAGE_KEY = 'rk_auth_tokens';
  const CONTEXT_KEY = 'rk_context'; // 'child', 'parent', 'coach'

  window.RKAuth = {
    /**
     * Sauvegarder un token (localStorage = persiste)
     * @param {string} type - 'child', 'parent', ou 'coach'
     * @param {string} token - JWT ou session token
     * @param {number} expiresInSeconds - Durée de validité (défaut 86400 = 24h)
     */
    setToken: function(type, token, expiresInSeconds) {
      expiresInSeconds = expiresInSeconds || 86400;
      try {
        var data = this.getAll() || {};
        data[type + '_token'] = token;
        data[type + '_expires_at'] = Date.now() + (expiresInSeconds * 1000);
        localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        localStorage.setItem(CONTEXT_KEY, type);
      } catch (e) {
        console.error('[RKAuth] Failed to save token:', e.message);
      }
    },

    /**
     * Récupérer un token (null si absent ou expiré)
     * @param {string} type - 'child', 'parent', ou 'coach'
     * @returns {string|null}
     */
    getToken: function(type) {
      try {
        var data = this.getAll();
        if (!data) return null;

        var token = data[type + '_token'];
        var expiresAt = data[type + '_expires_at'];

        // Si expiré, supprimer et retourner null
        if (expiresAt && Date.now() > expiresAt) {
          this.removeToken(type);
          return null;
        }

        return token || null;
      } catch (e) {
        console.error('[RKAuth] Failed to get token:', e.message);
        return null;
      }
    },

    /**
     * Récupérer tous les tokens
     * @returns {object|null}
     */
    getAll: function() {
      try {
        var stored = localStorage.getItem(STORAGE_KEY);
        return stored ? JSON.parse(stored) : null;
      } catch (e) {
        console.error('[RKAuth] Failed to parse tokens:', e.message);
        return null;
      }
    },

    /**
     * Supprimer un token spécifique
     * @param {string} type
     */
    removeToken: function(type) {
      try {
        var data = this.getAll() || {};
        delete data[type + '_token'];
        delete data[type + '_expires_at'];

        if (Object.keys(data).length === 0) {
          localStorage.removeItem(STORAGE_KEY);
          localStorage.removeItem(CONTEXT_KEY);
        } else {
          localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        }
      } catch (e) {
        console.error('[RKAuth] Failed to remove token:', e.message);
      }
    },

    /**
     * Obtenir le contexte actuel ('child', 'parent', 'coach', ou null)
     * @returns {string|null}
     */
    getContext: function() {
      return localStorage.getItem(CONTEXT_KEY) || null;
    },

    /**
     * Logout complet — supprimer tous les tokens
     */
    logout: function() {
      try {
        localStorage.removeItem(STORAGE_KEY);
        localStorage.removeItem(CONTEXT_KEY);
        sessionStorage.clear(); // Au cas où du sessionStorage resterait
      } catch (e) {
        console.error('[RKAuth] Failed to logout:', e.message);
      }
    },

    /**
     * Vérifier si l'utilisateur est authentifié (type spécifique)
     * @param {string} type - 'child', 'parent', ou 'coach'
     * @returns {boolean}
     */
    isAuthenticated: function(type) {
      return !!this.getToken(type);
    }
  };

  // Export pour accès global
})();
