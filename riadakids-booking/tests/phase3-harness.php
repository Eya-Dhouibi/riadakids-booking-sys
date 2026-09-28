<?php
/**
 * Harnais Phase 3 — CLI sans WordPress : php tests/phase3-harness.php
 * Valide la LOGIQUE (rôle/caps, endpoints, lecture seule, garde de route).
 * Ne remplace pas un test sur un vrai WordPress (rôles réellement persistés,
 * rewrite rules, thème, rendu du shell) — voir rapport, risques.
 */
namespace {
    define( 'ABSPATH', '/' ); define( 'DAY_IN_SECONDS', 86400 );
    define( 'RK_PLUGIN_URL', 'https://example.test/wp-content/plugins/riadakids-booking/' ); define( 'RK_VERSION', '4.18.0' );
    $GLOBALS['S'] = [ 'posts' => [], 'meta' => [], 'next' => 100, 'credits' => [ 10 => 5 ], 'credit_calls' => 0,
        'sql_inserts' => [], 'sql_updates' => 0, 'actions' => [], 'options' => [], 'opt_writes' => 0, 'now' => time(),
        'children' => [], 'ssa_calls' => 0 ];
    $GLOBALS['ROLES'] = [
        'administrator'    => [ 'manage_options' => true, 'edit_posts' => true, 'delete_posts' => true, 'manage_woocommerce' => true, 'read' => true ],
        'customer'         => [ 'read' => true ],
        'tutor_instructor' => [ 'read' => true, 'tutor_instructor' => true ],
    ];
    $GLOBALS['USERS'] = [
        1  => [ 'name' => 'Admin',        'roles' => [ 'administrator' ] ],
        3  => [ 'name' => 'Salma Coord',  'roles' => [ 'rk_coordinator' ] ],
        10 => [ 'name' => 'Parent Ali',   'roles' => [ 'customer' ] ],
        11 => [ 'name' => 'Parent Nour',  'roles' => [ 'customer' ] ],
        55 => [ 'name' => 'Coach Mohamed','roles' => [ 'tutor_instructor' ] ],
        56 => [ 'name' => 'Coach Sara',   'roles' => [ 'tutor_instructor' ] ],
        57 => [ 'name' => 'Coach Omar',   'roles' => [ 'tutor_instructor' ] ],
        // coordinateur restreint : voit les demandes mais pas la gestion des changements
        4  => [ 'name' => 'Limited',      'roles' => [], 'caps' => [ 'read' => true, 'rk_view_booking_requests' => true, 'rk_view_booking_details' => true ] ],
    ];
    $GLOBALS['CUR'] = 0;
    class JsonOut extends \Exception { public $ok; public $data; public $status; }
    class RedirectEx extends \Exception {} class DieEx extends \Exception {} class RenderEx extends \Exception {}

    function rk_log( $c, $m, $l = 'info' ) { if ( getenv( 'V' ) ) echo "  [$c/$l] $m\n"; }
    function __( $s ) { return $s; }
    function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
    function current_time( $t ) { return $t === 'timestamp' ? $GLOBALS['S']['now'] : date( 'Y-m-d H:i:s', $GLOBALS['S']['now'] ); }
    function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
    function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
    function absint( $v ) { return abs( (int) $v ); }
    function wp_unslash( $v ) { return $v; }
    function apply_filters( $t, $v ) { return $v; }
    function do_action( $t, ...$a ) { $GLOBALS['S']['actions'][] = $t; }
    $GLOBALS['HOOKS'] = [];
    function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['HOOKS'][] = [ 'action', $h, $p ]; }
    function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['HOOKS'][] = [ 'filter', $h, $p ]; }
    function add_rewrite_rule( $r, $q, $pos = 'bottom' ) { $GLOBALS['REWRITE'][] = [ $r, $q, $pos ]; }
    function flush_rewrite_rules( $h = true ) { $GLOBALS['S']['flushes'] = ( $GLOBALS['S']['flushes'] ?? 0 ) + 1; }
    function get_option( $k, $d = false ) { return $GLOBALS['S']['options'][ $k ] ?? $d; }
    function update_option( $k, $v ) { $GLOBALS['S']['options'][ $k ] = $v; $GLOBALS['S']['opt_writes']++; return true; }
    function home_url( $p = '' ) { return 'https://example.test' . $p; }
    function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
    function wp_login_url( $r = '' ) { return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( $r ); }
    function wp_safe_redirect( $u ) { throw new RedirectEx( $u ); }
    function status_header( $c ) { $GLOBALS['S']['http'] = $c; } function nocache_headers() {}
    function wp_die( $m = '', $t = '', $a = [] ) { throw new DieEx( (string) ( $a['response'] ?? 500 ) ); }
    function get_query_var( $k ) { return $GLOBALS['QV'][ $k ] ?? ''; }
    function wp_enqueue_style( ...$a ) {} function wp_enqueue_script( ...$a ) {} function wp_localize_script( ...$a ) {}
    function wp_create_nonce( $a ) { return 'nonce-' . $GLOBALS['CUR'] . '-' . $a; }
    function get_header() { throw new RenderEx(); } function get_footer() {}
    function check_ajax_referer( $a, $k = false, $die = true ) { return ( $_REQUEST[ $k ] ?? '' ) === 'nonce-' . $GLOBALS['CUR'] . '-' . $a; }
    function is_user_logged_in() { return $GLOBALS['CUR'] > 0; }
    function wp_send_json_success( $d = null ) { $e = new JsonOut(); $e->ok = true; $e->data = $d; $e->status = 200; throw $e; }
    function wp_send_json_error( $d = null, $s = 400 ) { $e = new JsonOut(); $e->ok = false; $e->data = $d; $e->status = $s; throw $e; }
    function user_caps( int $id ): array {
        $u = $GLOBALS['USERS'][ $id ] ?? null; if ( ! $u ) return [];
        $caps = $u['caps'] ?? [];
        foreach ( $u['roles'] as $r ) $caps = array_merge( $caps, $GLOBALS['ROLES'][ $r ] ?? [] );
        return $caps;
    }
    function current_user_can( $cap ) { return ! empty( user_caps( $GLOBALS['CUR'] )[ $cap ] ); }
    class WPRole { function __construct( public $name ) {} function has_cap( $c ) { return ! empty( $GLOBALS['ROLES'][ $this->name ][ $c ] ); } function add_cap( $c, $g = true ) { $GLOBALS['ROLES'][ $this->name ][ $c ] = $g; } }
    function get_role( $n ) { return isset( $GLOBALS['ROLES'][ $n ] ) ? new WPRole( $n ) : null; }
    function add_role( $n, $label, $caps ) { $GLOBALS['ROLES'][ $n ] = $caps; $GLOBALS['ROLE_LABELS'][ $n ] = $label; return new WPRole( $n ); }
    function get_userdata( $i ) { $u = $GLOBALS['USERS'][ $i ] ?? null; return $u ? (object) [ 'ID' => $i, 'display_name' => $u['name'] ] : false; }
    function get_users( $a ) { $out = []; foreach ( $GLOBALS['USERS'] as $id => $u ) if ( in_array( $a['role'], $u['roles'], true ) ) $out[] = (object) [ 'ID' => $id, 'display_name' => $u['name'] ]; return $out; }
    function get_term( $id, $tax ) { return (object) [ 'name' => 'الريادة' ]; }
    function get_the_title( $id ) { return 'عنوان-' . $id; }
    function is_wp_error( $x ) { return false; }
    function get_post( $id ) { return isset( $GLOBALS['S']['posts'][ $id ] ) ? (object) $GLOBALS['S']['posts'][ $id ] : null; }
    function get_post_meta( $id, $k, $single = true ) { return $GLOBALS['S']['meta'][ $id ][ $k ] ?? ''; }
    function update_post_meta( $id, $k, $v ) { $GLOBALS['S']['meta'][ $id ][ $k ] = $v; return true; }
    function wp_insert_post( $a, $e = false ) {
        $id = $GLOBALS['S']['next']++;
        $GLOBALS['S']['posts'][ $id ] = [ 'ID' => $id, 'post_type' => $a['post_type'], 'post_status' => $a['post_status'], 'post_author' => $a['post_author'], 'post_date' => date( 'Y-m-d H:i:s', $GLOBALS['S']['now'] - $id ) ];
        foreach ( $a['meta_input'] ?? [] as $k => $v ) $GLOBALS['S']['meta'][ $id ][ $k ] = $v;
        return $id;
    }
    if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $a, $l = null ) { return substr( $s, $a, $l ); } }

    class FakeWpdb {
        public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
        function prepare( $q, ...$a ) { $i = 0; return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ]; return $m[0] === '%d' ? (int) $v : "'" . addslashes( (string) $v ) . "'"; }, $q ); }
        private function ids( $q ) {
            $S = &$GLOBALS['S']; $out = []; $st = null;
            if ( preg_match( "/p\.post_status\s*=\s*'([^']*)'/", $q, $m ) ) $st = [ $m[1] ];
            elseif ( preg_match( "/p\.post_status\s+IN\s*\(([^)]*)\)/", $q, $m ) ) $st = array_map( fn( $x ) => trim( $x, " '" ), explode( ',', $m[1] ) );
            $mk = preg_match( "/m\.meta_key\s*=\s*'([^']+)'/", $q, $m ) ? $m[1] : null;
            $mv = preg_match( "/m\.meta_value\s*=\s*('[^']*'|\d+)/", $q, $m ) ? trim( $m[1], "'" ) : null;
            foreach ( $S['posts'] as $id => $p ) {
                if ( $p['post_type'] !== 'rk_booking' ) continue;
                if ( $st && ! in_array( $p['post_status'], $st, true ) ) continue;
                if ( $mk ) { $val = $S['meta'][ $id ][ $mk ] ?? null; if ( $mv !== null && (string) $val !== $mv ) continue; }
                $out[] = $id;
            }
            usort( $out, fn( $a, $b ) => strcmp( $S['posts'][ $b ]['post_date'], $S['posts'][ $a ]['post_date'] ) ?: $b <=> $a );
            return $out;
        }
        function get_var( $q ) { return $this->ids( $q )[0] ?? null; }
        function get_col( $q ) { return $this->ids( $q ); }
        function get_results( $q ) {
            if ( preg_match( '/FROM wp_rk_children WHERE id IN \(([^)]*)\)/', $q, $m ) ) {
                $want = array_map( 'intval', explode( ',', $m[1] ) ); $GLOBALS['S']['children_queries'] = ( $GLOBALS['S']['children_queries'] ?? 0 ) + 1;
                return array_values( array_filter( array_map( fn( $i ) => $GLOBALS['S']['children'][ $i ] ?? null, $want ) ) );
            }
            return [];
        }
        function insert( $t, $d, $f = null ) { $GLOBALS['S']['sql_inserts'][] = [ $t, $d ]; return 1; }
        function update( ...$a ) { $GLOBALS['S']['sql_updates']++; return 1; }
    }
    $GLOBALS['wpdb'] = new FakeWpdb();
}

namespace RiadaKids\Booking { class SSAIntegration { static function __callStatic( $n, $a ) { $GLOBALS['S']['ssa_calls']++; return null; } } }

namespace {
    $root = dirname( __DIR__ ) . '/includes/';
    foreach ( [ 'Booking/BookingStatus', 'Booking/BookingRepository', 'Coordinator/CoordinatorRole', 'Coordinator/CoordinatorService', 'Coordinator/CoordinatorAjax', 'Coordinator/CoordinatorDashboard' ] as $f ) require $root . $f . '.php';
    use RiadaKids\Booking\{BookingStatus as St, BookingRepository};
    use RiadaKids\Coordinator\{CoordinatorRole as Role, CoordinatorService, CoordinatorAjax, CoordinatorDashboard};

    $pass = 0; $fail = 0;
    function t( string $name, bool $ok, string $detail = '' ) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $name" . ( $ok || ! $detail ? '' : "  → $detail" ) . "\n"; }
    function call( int $user, string $method, array $post = [], ?string $nonce = 'auto' ): array {
        global $ajax; $GLOBALS['CUR'] = $user;
        $_POST = $post; $_REQUEST = $post;
        if ( $nonce === 'auto' ) $_REQUEST['nonce'] = 'nonce-' . $user . '-rk_coordinator_nonce'; elseif ( $nonce !== null ) $_REQUEST['nonce'] = $nonce;
        try { $ajax->$method(); } catch ( JsonOut $e ) { return [ 'ok' => $e->ok, 'data' => $e->data, 'status' => $e->status ]; }
        return [ 'ok' => null, 'data' => null, 'status' => 0 ];
    }
    function snapshot(): string { $S = $GLOBALS['S']; return md5( json_encode( [ $S['posts'], $S['meta'], count( $S['sql_inserts'] ), $S['sql_updates'], $S['credit_calls'], $S['credits'], $S['actions'], $S['ssa_calls'] ] ) ); }
    function mk( BookingRepository $r, int $user, int $session, int $child, string $status, array $extra = [] ): int {
        $id = wp_insert_post( [ 'post_type' => 'rk_booking', 'post_status' => St::wp_post_status( $status ), 'post_author' => $user,
            'meta_input' => [ '_rk_status' => $status, '_rk_child_ids' => [ $child ], '_rk_session_id' => $session, '_rk_session_name' => 'حصة ' . $session,
                '_rk_program_id' => 5, '_rk_adventure_id' => 77, '_rk_created_at' => date( 'Y-m-d H:i:s', $GLOBALS['S']['now'] ), '_rk_credits_deducted' => '0' ] + $extra ] );
        return $id;
    }

    $GLOBALS['S']['children'] = [
        101 => (object) [ 'id' => 101, 'user_id' => 10, 'child_name' => 'أحمد', 'child_family_name' => 'العلي', 'child_age' => 9 ],
        102 => (object) [ 'id' => 102, 'user_id' => 10, 'child_name' => 'سارة', 'child_family_name' => 'العلي', 'child_age' => 11 ],
        201 => (object) [ 'id' => 201, 'user_id' => 11, 'child_name' => 'ياسمين', 'child_family_name' => 'نور', 'child_age' => 8 ],
    ];
    $repo = new BookingRepository(); $service = new CoordinatorService( $repo ); $ajax = new CoordinatorAjax( $service );

    echo "== Rôle rk_coordinator ==\n";
    $forbidden = [ 'manage_options', 'edit_posts', 'delete_posts', 'manage_woocommerce' ];
    $admin_before = array_keys( $GLOBALS['ROLES']['administrator'] );
    Role::maybe_sync(); $writes = $GLOBALS['S']['opt_writes']; Role::maybe_sync(); Role::maybe_sync();
    $rc = $GLOBALS['ROLES']['rk_coordinator'] ?? [];
    t( 'R1 rôle créé avec le label « منسق المواعيد »', isset( $GLOBALS['ROLES']['rk_coordinator'] ) && $GLOBALS['ROLE_LABELS']['rk_coordinator'] === 'منسق المواعيد' );
    t( 'R2 exactement read + 7 capabilities rk_*', count( $rc ) === 8 && ! empty( $rc['read'] ) && count( array_filter( array_keys( $rc ), fn( $c ) => str_starts_with( $c, 'rk_' ) ) ) === 7 );
    t( 'R3 aucune capability admin/édition (manage_options, edit_posts, delete_posts, manage_woocommerce)', ! array_intersect( $forbidden, array_keys( array_filter( $rc ) ) ) );
    t( 'R4 les 7 noms conformes à la spec', ! array_diff( [ 'rk_view_booking_requests', 'rk_view_booking_details', 'rk_assign_booking_coach', 'rk_schedule_booking', 'rk_reschedule_booking', 'rk_manage_change_requests', 'rk_view_coach_availability' ], array_keys( $rc ) ) );
    t( 'R5 sync versionnée : 1 seule écriture d\'option sur 3 appels', $writes === 1 && $GLOBALS['S']['opt_writes'] === 1 );
    t( 'R6 administrator : +7 caps rk_* uniquement, anciennes caps intactes', count( array_diff( array_keys( $GLOBALS['ROLES']['administrator'] ), $admin_before ) ) === 7 && ! array_diff( $admin_before, array_keys( $GLOBALS['ROLES']['administrator'] ) ) );
    t( 'R7 customer (parent) et tutor_instructor (coach) : aucune cap rk_*', ! array_filter( array_keys( $GLOBALS['ROLES']['customer'] + $GLOBALS['ROLES']['tutor_instructor'] ), fn( $c ) => str_starts_with( $c, 'rk_' ) ) );

    echo "\n== Fixtures ==\n";
    $n1  = mk( $repo, 10, 92, 101, St::PENDING_SCHEDULE );
    $n2  = mk( $repo, 10, 92, 102, St::PENDING_SCHEDULE );
    $n3  = mk( $repo, 11, 92, 201, St::PENDING_SCHEDULE );
    $p1  = mk( $repo, 10, 93, 101, St::PENDING_PARENT_CONFIRMATION, [ '_rk_proposed_coach_id' => 55, '_rk_proposed_datetime' => '2026-10-15 17:00:00', '_rk_scheduled_by' => 3, '_rk_scheduled_at' => '2026-10-01 09:00:00' ] );
    $p2  = mk( $repo, 10, 93, 102, St::PENDING_PARENT_CONFIRMATION, [ '_rk_proposed_coach_id' => 55, '_rk_proposed_datetime' => '2026-10-15T18:00:00', '_rk_scheduled_by' => 3, '_rk_scheduled_at' => '2026-10-01 09:05:00' ] );
    $p3  = mk( $repo, 11, 93, 201, St::PENDING_PARENT_CONFIRMATION, [ '_rk_proposed_coach_id' => 56, '_rk_proposed_datetime' => '2026-10-16 10:00:00', '_rk_scheduled_by' => 3, '_rk_scheduled_at' => '2026-10-01 09:10:00' ] );
    $c1  = mk( $repo, 10, 94, 101, St::CHANGE_REQUESTED, [ '_rk_proposed_coach_id' => 55, '_rk_proposed_datetime' => '2026-10-15 17:00:00', '_rk_change_note' => 'الوقت غير مناسب <script>x</script>', '_rk_change_requested_at' => '2026-10-02 08:00:00' ] );
    $leg_pending   = mk( $repo, 10, 95, 101, 'pending' );
    $leg_confirmed = mk( $repo, 10, 96, 101, 'confirmed', [ '_rk_appointment_id' => 500 ] );
    $leg_cancelled = mk( $repo, 10, 97, 101, 'cancelled' );
    $other = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 10 ] ); // post non-rk_booking (test G5)
    $before = snapshot();

    echo "\n== Matrice d'accès (Administrator/Coordinator PASS ; Parent/Coach/Logged-out DENY) ==\n";
    $eps = [ 'handle_get_dashboard' => [], 'handle_get_booking' => [ 'booking_id' => $n1 ], 'handle_get_coaches' => [] ];
    $who = [ 'Administrator' => [ 1, true ], 'Coordinator' => [ 3, true ], 'Parent' => [ 10, false ], 'Coach' => [ 55, false ], 'Logged-out' => [ 0, false ] ];
    foreach ( $eps as $m => $args ) foreach ( $who as $label => [ $uid, $allow ] ) {
        $r = call( $uid, $m, $args );
        $expect_status = $allow ? 200 : ( $uid === 0 ? 401 : 403 );
        t( "M $m — $label → " . ( $allow ? 'PASS' : 'DENY' ) . " ($expect_status)", $r['ok'] === $allow && $r['status'] === $expect_status, json_encode( $r ) );
    }
    foreach ( $eps as $m => $args ) {
        $r = call( 3, $m, $args, 'bad-nonce' ); $r2 = call( 3, $m, $args, null );
        t( "N $m — nonce invalide / absent → 403 invalid_nonce (même pour un Coordinateur)", ! $r['ok'] && $r['status'] === 403 && $r['data']['msg'] === 'invalid_nonce' && ! $r2['ok'] && $r2['data']['msg'] === 'invalid_nonce' );
    }
    $r = call( 10, 'handle_get_dashboard', [], 'nonce-3-rk_coordinator_nonce' );
    t( 'N4 nonce d\'un autre utilisateur rejeté (parent avec le nonce du coordinateur → 403, aucune donnée)', ! $r['ok'] && $r['status'] === 403 && ! isset( $r['data']['new'] ) );
    $r = call( 10, 'handle_get_booking', [ 'booking_id' => $n1 ], 'nonce-10-rk_coordinator_nonce' );
    t( 'N5 parent PROPRIÉTAIRE du booking + nonce valide → toujours 403 (pas de capability)', ! $r['ok'] && $r['status'] === 403 );
    $hooks = array_map( fn( $h ) => $h[1], array_filter( $GLOBALS['HOOKS'], fn( $h ) => str_contains( $h[1], 'rk_coord' ) ) );
    ( new CoordinatorAjax( $service ) )->register();
    $reg = array_map( fn( $h ) => $h[1], array_filter( $GLOBALS['HOOKS'], fn( $h ) => str_contains( $h[1], 'rk_coord' ) ) );
    t( 'N6 3 hooks wp_ajax_ enregistrés, AUCUN wp_ajax_nopriv_', count( $reg ) === 3 && ! array_filter( $reg, fn( $h ) => str_contains( $h, 'nopriv' ) ), implode( ',', $reg ) );
    $lim_d = call( 4, 'handle_get_dashboard' );
    t( 'P1 coordinateur restreint (sans manage_change) : demandes visibles, section changements = null', $lim_d['ok'] && $lim_d['data']['changes'] === null && count( $lim_d['data']['new'] ) === 3 );
    t( 'P2 coordinateur restreint : booking change_requested → 403', call( 4, 'handle_get_booking', [ 'booking_id' => $c1 ] )['status'] === 403 );
    t( 'P3 coordinateur restreint : liste des coachs (rk_view_coach_availability) → 403', call( 4, 'handle_get_coaches' )['status'] === 403 );

    echo "\n== Données du dashboard ==\n";
    $d = call( 3, 'handle_get_dashboard' )['data'];
    $ids = fn( $rows ) => array_map( fn( $x ) => $x['booking_id'], $rows );
    t( 'D1 nouvelles demandes = uniquement pending_schedule (3)', $ids( $d['new'] ) === [ $n1, $n2, $n3 ] || ( count( $d['new'] ) === 3 && ! array_diff( $ids( $d['new'] ), [ $n1, $n2, $n3 ] ) ), json_encode( $ids( $d['new'] ) ) );
    t( 'D2 en attente de confirmation = pending_parent_confirmation (3)', count( $d['pending'] ) === 3 && ! array_diff( $ids( $d['pending'] ), [ $p1, $p2, $p3 ] ) );
    t( 'D3 demandes de changement = change_requested (1)', $ids( $d['changes'] ) === [ $c1 ] );
    $all_listed = array_merge( $ids( $d['new'] ), $ids( $d['pending'] ), $ids( $d['changes'] ) );
    t( 'D4 aucun booking legacy (pending / confirmed / cancelled) listé', ! array_intersect( $all_listed, [ $leg_pending, $leg_confirmed, $leg_cancelled ] ) );
    $by = []; foreach ( $d['new'] as $row ) $by[ $row['booking_id'] ] = $row;
    t( 'D5 multi-enfants : n1=أحمد, n2=سارة (même parent, même session) — lignes distinctes, noms corrects', $by[ $n1 ]['children'][0]['name'] === 'أحمد العلي' && $by[ $n2 ]['children'][0]['name'] === 'سارة العلي' && $by[ $n1 ]['children'][0]['id'] === 101 && $by[ $n2 ]['children'][0]['id'] === 102 );
    t( 'D6 n3 : parent 11 / enfant 201, aucun croisement avec le parent 10', $by[ $n3 ]['parent']['name'] === 'Parent Nour' && $by[ $n3 ]['children'][0]['name'] === 'ياسمين نور' && $by[ $n1 ]['parent']['name'] === 'Parent Ali' );
    t( 'D7 champs affichés : programme, cours, séance, date de demande', $by[ $n1 ]['program'] === 'الريادة' && $by[ $n1 ]['course'] === 'عنوان-77' && $by[ $n1 ]['session'] === 'حصة 92' && $by[ $n1 ]['requested_at'] !== '' );
    $pp = []; foreach ( $d['pending'] as $row ) $pp[ $row['booking_id'] ] = $row;
    t( 'D8 en attente : coach, date/heure, coordinateur, date de programmation', $pp[ $p1 ]['proposal']['coach_name'] === 'Coach Mohamed' && $pp[ $p1 ]['proposal']['datetime'] === '2026-10-15 17:00:00' && $pp[ $p1 ]['proposal']['scheduled_by_name'] === 'Salma Coord' && $pp[ $p1 ]['proposal']['scheduled_at'] === '2026-10-01 09:00:00' && $pp[ $p3 ]['proposal']['coach_name'] === 'Coach Sara' );
    t( 'D9 changement : note parent + date de demande + créneau actuel', $d['changes'][0]['change']['requested_at'] === '2026-10-02 08:00:00' && $d['changes'][0]['proposal']['datetime'] === '2026-10-15 17:00:00' && str_contains( $d['changes'][0]['change']['note'], 'الوقت غير مناسب' ) );
    t( 'D10 champs de section « new » sans faux créneau (proposal = null)', $by[ $n1 ]['proposal'] === null && $by[ $n1 ]['change'] === null );
    $GLOBALS['S']['children_queries'] = 0; call( 3, 'handle_get_dashboard' );
    t( 'D11 enfants chargés en 3 requêtes max (1 par section, pas de N+1)', $GLOBALS['S']['children_queries'] <= 3, (string) $GLOBALS['S']['children_queries'] );
    $co = call( 3, 'handle_get_coaches' )['data']['coaches']; $cm = []; foreach ( $co as $c ) $cm[ $c['id'] ] = $c;
    t( 'D12 coachs = tutor_instructor uniquement (3), propositions en attente par coach (55→2, 56→1, 57→0)', count( $co ) === 3 && $cm[55]['open_proposals'] === 2 && $cm[56]['open_proposals'] === 1 && $cm[57]['open_proposals'] === 0 && ! isset( $cm[3] ) && ! isset( $cm[10] ) );

    echo "\n== get_booking : validation serveur ==\n";
    $r = call( 3, 'handle_get_booking', [ 'booking_id' => $n2 ] );
    t( 'G1 booking valide → détail correct (enfant سارة)', $r['ok'] && $r['data']['booking']['children'][0]['name'] === 'سارة العلي' && $r['data']['booking']['booking_id'] === $n2 );
    t( 'G2 booking legacy pending → 404 (existence non révélée)', call( 3, 'handle_get_booking', [ 'booking_id' => $leg_pending ] )['status'] === 404 );
    t( 'G3 booking legacy confirmed → 404', call( 3, 'handle_get_booking', [ 'booking_id' => $leg_confirmed ] )['status'] === 404 );
    t( 'G4 ID inexistant → 404', call( 3, 'handle_get_booking', [ 'booking_id' => 999999 ] )['status'] === 404 );
    t( 'G5 ID d\'un post qui n\'est pas rk_booking → 404', call( 3, 'handle_get_booking', [ 'booking_id' => $other ] )['status'] === 404 );
    t( 'G6 booking_id absent / 0 / non numérique → 400', call( 3, 'handle_get_booking', [] )['status'] === 400 && call( 3, 'handle_get_booking', [ 'booking_id' => '0' ] )['status'] === 400 && call( 3, 'handle_get_booking', [ 'booking_id' => 'abc' ] )['status'] === 400 );
    t( 'G7 booking_id "12abc" → traité comme 12 (absint), jamais d\'injection : 404', call( 3, 'handle_get_booking', [ 'booking_id' => '12abc' ] )['status'] === 404 );
    t( 'G8 données du client ignorées : un paramètre child/status/user forgé ne change rien', call( 3, 'handle_get_booking', [ 'booking_id' => $n1, 'status' => 'confirmed', 'child_id' => 102, 'user_id' => 999 ] )['data']['booking']['children'][0]['id'] === 101 );
    t( 'G9 repo : états legacy/inconnus refusés (pending, confirmed, expired, "")', $repo->find_ids_by_business_status( 'pending' ) === [] && $repo->find_ids_by_business_status( 'confirmed' ) === [] && $repo->find_ids_by_business_status( 'expired' ) === [] && $repo->find_ids_by_business_status( '' ) === [] );

    echo "\n== Aucun effet de bord (lecture seule) ==\n";
    call( 3, 'handle_get_dashboard' ); call( 1, 'handle_get_booking', [ 'booking_id' => $p1 ] ); call( 3, 'handle_get_coaches' ); call( 10, 'handle_get_dashboard' ); call( 55, 'handle_get_booking', [ 'booking_id' => $n1 ] );
    t( 'S1 posts + postmeta + wp_rk_bookings + crédits + actions inchangés après tous les appels', snapshot() === $before );
    $S = $GLOBALS['S'];
    t( 'S2 0 ligne wp_rk_bookings, 0 UPDATE SQL', count( $S['sql_inserts'] ) === 0 && $S['sql_updates'] === 0 );
    t( 'S3 0 crédit déduit (solde parent 10 = 5, 0 appel decrease)', $S['credits'][10] === 5 && $S['credit_calls'] === 0 );
    t( 'S4 0 rk_booking_confirmed, 0 action émise', ! in_array( 'rk_booking_confirmed', $S['actions'], true ) && $S['actions'] === [] );
    t( 'S5 0 appel SSA (SSAIntegration jamais appelée)', $S['ssa_calls'] === 0 );
    t( 'S6 statuts intacts : tous les bookings de test gardent leur _rk_status', get_post_meta( $n1, '_rk_status' ) === 'pending_schedule' && get_post_meta( $p1, '_rk_status' ) === 'pending_parent_confirmation' && get_post_meta( $c1, '_rk_status' ) === 'change_requested' && get_post_meta( $leg_confirmed, '_rk_status' ) === 'confirmed' );

    echo "\n== Garde de la route /coordinator-dashboard/ ==\n";
    $dash = new CoordinatorDashboard(); $dash->register(); $dash->add_rewrite();
    t( 'T1 règle de réécriture ^coordinator-dashboard/?$ → rk_coordinator=1', $GLOBALS['REWRITE'][0][0] === '^coordinator-dashboard/?$' && str_contains( $GLOBALS['REWRITE'][0][1], 'rk_coordinator=1' ) );
    $fl0 = $GLOBALS['S']['flushes'] ?? 0; $dash->maybe_flush_rewrite(); $dash->maybe_flush_rewrite();
    t( 'T2 flush des rewrite rules : une seule fois (option dédiée, RK_VERSION non touchée)', ( $GLOBALS['S']['flushes'] ?? 0 ) - $fl0 === 1 );
    $route = function ( int $uid ) use ( $dash ) { $GLOBALS['CUR'] = $uid; try { $dash->maybe_render(); } catch ( RedirectEx $e ) { return 'redirect:' . $e->getMessage(); } catch ( DieEx $e ) { return 'die:' . $e->getMessage(); } catch ( RenderEx $e ) { return 'render'; } return 'noop'; };
    $GLOBALS['QV'] = [ 'rk_coordinator' => '1' ];
    $rr = $route( 0 );  t( 'T3 déconnecté → redirection wp-login avec retour sur le dashboard', str_starts_with( $rr, 'redirect:https://example.test/wp-login.php' ) && str_contains( $rr, rawurlencode( 'https://example.test/coordinator-dashboard/' ) ), $rr );
    t( 'T4 parent → 403', $route( 10 ) === 'die:403' );
    t( 'T5 coach (tutor_instructor) → 403', $route( 55 ) === 'die:403' );
    t( 'T6 coordinateur → page rendue', $route( 3 ) === 'render' );
    t( 'T7 administrateur → page rendue', $route( 1 ) === 'render' );
    $GLOBALS['QV'] = []; t( 'T8 autres pages du site : la garde ne fait rien (aucun impact sur le reste du site)', $route( 10 ) === 'noop' && $route( 0 ) === 'noop' );

    echo "\n" . ( $fail ? 'ÉCHEC' : 'OK' ) . " — $pass PASS / $fail FAIL\n"; exit( $fail ? 1 : 0 );
}
