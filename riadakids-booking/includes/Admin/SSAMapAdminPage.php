<?php
/**
 * RiadaKids\Admin\SSAMapAdminPage — Association Cours (Dorra) ↔ Type de
 * rendez-vous SSA.
 *
 * AJOUT — remplit la table wp_rk_ssa_map (déjà créée par Install.php,
 * déjà lue par DB::get_ssa_type_for_course(), mais jusqu'ici jamais
 * exposée dans un écran admin). Sans cette association, chaque cours
 * gardait event_id=0 côté wizard, donc SSA affichait son propre écran
 * de sélection de type/coach (.booking-cards) — un écran que
 * RKSSAOverlay ne gère pas, cassant tout le pont JS de l'étape 5.
 *
 * Un cours = un seul type SSA (clé unique course_id+appointment_type_id,
 * mais en pratique on affiche/édite une seule ligne par cours).
 *
 * @package RiadaKids\Admin
 */

namespace RiadaKids\Admin;

use RiadaKids\Booking\SSAIntegration;
use RiadaKids\Core\Security;
use RiadaKids\Database\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

class SSAMapAdminPage {

	public function __construct() {
		add_action( 'admin_menu',                 [ $this, 'add_submenu' ] );
		add_action( 'admin_post_rk_save_ssa_map', [ $this, 'handle_save' ] );
	}

	// ── Sous-menu ─────────────────────────────────────────────────────────
	public function add_submenu(): void {
		add_submenu_page(
			'rk-bookings',                              // parent slug (AdminPages.php)
			'Association SSA — RiadaKids',
			'ربط SSA بالدورات',
			'manage_options',
			'rk-ssa-map',
			[ $this, 'render_page' ]
		);
	}

	// ── Rendu ─────────────────────────────────────────────────────────────
	public function render_page(): void {
		Security::require_admin();

		$courses   = get_posts( [
			'post_type'      => 'courses',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		$ssa_types = SSAIntegration::fetch_ssa_appointment_types();
		$maps      = DB::get_all_ssa_maps();
		$saved     = (bool) ( $_GET['saved'] ?? false );

		// Index rapide course_id → appointment_type_id existant
		$current_by_course = [];
		foreach ( $maps as $row ) {
			$current_by_course[ (int) $row->course_id ] = (int) $row->appointment_type_id;
		}
		?>
		<div class="wrap" dir="rtl">
			<h1>ربط الدورات بأنواع مواعيد SSA</h1>
			<p class="description">
				لكل دورة (Dorra)، اختر نوع الموعد المطابق في SSA. هذا الربط يسمح
				للنظام بفتح واجهة SSA مباشرة على شاشة اختيار الموعد، بدل شاشة
				اختيار النوع/المدرب الخاصة بـ SSA نفسه.
			</p>

			<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p>تم حفظ الربط بنجاح.</p></div>
			<?php endif; ?>

			<?php if ( empty( $ssa_types ) ) : ?>
			<div class="notice notice-warning"><p>
				تعذر العثور على أنواع مواعيد SSA (تحقق من أن الإضافة مفعّلة وتحتوي
				على أنواع مواعيد منشورة).
			</p></div>
			<?php else : ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'rk_save_ssa_map', 'rk_ssa_map_nonce' ); ?>
				<input type="hidden" name="action" value="rk_save_ssa_map">

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>الدورة</th>
							<th width="320">نوع الموعد في SSA</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $courses ) ) : ?>
						<tr><td colspan="2"><em>لا توجد دورات منشورة.</em></td></tr>
						<?php endif; ?>
						<?php foreach ( $courses as $course ) :
							$current = $current_by_course[ $course->ID ] ?? 0;
							?>
						<tr>
							<td><?php echo esc_html( $course->post_title ); ?></td>
							<td>
								<select name="rk_map[<?php echo (int) $course->ID; ?>]" style="width:100%;">
									<option value="0">— بدون ربط —</option>
									<?php foreach ( $ssa_types as $t ) : ?>
									<option value="<?php echo (int) $t['id']; ?>"
										<?php selected( $current, (int) $t['id'] ); ?>>
										#<?php echo (int) $t['id']; ?> — <?php echo esc_html( $t['title'] ); ?>
									</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( 'حفظ الربط' ); ?>
			</form>

			<?php endif; ?>
		</div>
		<?php
	}

	// ── Sauvegarde ────────────────────────────────────────────────────────
	public function handle_save(): void {
		Security::require_admin();
		check_admin_referer( 'rk_save_ssa_map', 'rk_ssa_map_nonce' );

		foreach ( (array) ( $_POST['rk_map'] ?? [] ) as $course_id => $type_id ) {
			$course_id = (int) $course_id;
			$type_id   = (int) $type_id;
			if ( $course_id <= 0 ) continue;

			if ( $type_id > 0 ) {
				DB::upsert_ssa_map( $course_id, 0, $type_id, get_the_title( $course_id ) );
			} else {
				// "— بدون ربط —" sélectionné : retire tout mapping existant
				// pour ce cours (comportement identique à avant ce correctif).
				DB::delete_ssa_map_for_course( $course_id );
			}
		}

		wp_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=rk-ssa-map' ) ) );
		exit;
	}
}
