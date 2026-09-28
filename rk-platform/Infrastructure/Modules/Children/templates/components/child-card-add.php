<?php
/**
 * Composant : Carte "طفل جديد" — style aligné sur l'étape 4 du wizard
 * de réservation (riadakids-booking → .rk-child-card-add).
 *
 * Toujours affichée en tête de grille sur /my-account/my-children/.
 * Clic → ouvre le même modal d'ajout que le bouton d'en-tête
 * (délégation gérée dans child-modal.js, sélecteur #rk-open-modal-card).
 *
 * @package RK_My_Children
 * @since   11.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<button type="button" class="rk-child-card-add" id="rk-open-modal-card">
    <span class="rk-child-add-icon" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"/>
            <path d="M8.5 14c.9 1 2.1 1.5 3.5 1.5s2.6-.5 3.5-1.5"/>
            <path d="M9 9.5h.01"/>
            <path d="M15 9.5h.01"/>
        </svg>
    </span>
    <strong><?php esc_html_e( 'طفل جديد', 'rk-my-children' ); ?></strong>
    <small><?php esc_html_e( 'أضف طفلاً آخر لحجز مغامرته', 'rk-my-children' ); ?></small>
    <span class="rk-btn rk-btn-primary rk-child-add-btn">
        <?php esc_html_e( 'أضف طفلاً', 'rk-my-children' ); ?> ←
    </span>
</button>
