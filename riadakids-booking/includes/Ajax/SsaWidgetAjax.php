<?php
namespace RiadaKids\Ajax;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SsaWidgetAjax — Phase 3.1
 *
 * Classe conservée vide pour éviter une erreur fatale dans Plugin.php
 * si la référence n'est pas encore retirée.
 *
 * Tous les hooks AJAX SSA ont été supprimés :
 *   - wp_ajax_rk_get_ssa_event_types        (géré nativement par l'iframe SSA)
 *   - wp_ajax_nopriv_rk_get_ssa_event_types
 *   - wp_ajax_rk_get_ssa_widget             (supprimé — iframe rendue par BookingForm.php)
 *   - wp_ajax_nopriv_rk_get_ssa_widget
 *
 * NOTE : Retirer également la ligne suivante dans Plugin.php pour nettoyer complètement :
 *   ( new \RiadaKids\Ajax\SsaWidgetAjax() )->register();
 * Ce fichier peut ensuite être supprimé du plugin.
 */
class SsaWidgetAjax {

    public function register(): void {
        // Aucun hook enregistré — tous supprimés en Phase 3.1
    }
}