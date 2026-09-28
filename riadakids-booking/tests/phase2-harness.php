<?php
/**
 * Harnais Phase 2 — exécutable en CLI SANS WordPress : php tests/phase2-harness.php
 *
 * Simule wpdb/posts/postmeta en mémoire (évaluateur SQL minimal pour les requêtes
 * du Repository) et remplace Credits/DB/Notifier par des espions. Il valide la
 * LOGIQUE (états, gardes, effets de bord). Il ne remplace PAS un test sur staging :
 * le SQL réel MySQL, les dashboards A et SSA ne sont pas exécutés ici.
 */
namespace {
    define( 'ABSPATH', '/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 );
    $GLOBALS['S'] = [ 'posts' => [], 'meta' => [], 'next' => 100, 'credits' => [ 10 => 5 ], 'credit_calls' => 0,
        'sql_inserts' => [], 'actions' => [], 'notified' => 0, 'now' => time() ];
    function rk_log( $c, $m, $l = 'info' ) { if ( getenv( 'V' ) ) echo "  [$c/$l] $m\n"; }
    function __( $s ) { return $s; }
    function current_time( $t ) { return $t === 'timestamp' ? $GLOBALS['S']['now'] : date( 'Y-m-d H:i:s', $GLOBALS['S']['now'] ); }
    function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
    function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
    function apply_filters( $t, $v ) { return $v; }
    function do_action( $t, ...$a ) { $GLOBALS['S']['actions'][] = $t; }
    function wp_generate_uuid4() { return bin2hex( random_bytes( 8 ) ); }
    function get_userdata( $i ) { return false; }
    function get_current_user_id() { return 0; }
    function set_transient( ...$a ) {} function get_transient( ...$a ) { return false; }
    function delete_user_meta( ...$a ) {}
    function get_post( $id ) { return isset( $GLOBALS['S']['posts'][ $id ] ) ? (object) $GLOBALS['S']['posts'][ $id ] : null; }
    function get_post_meta( $id, $k, $single = true ) { return $GLOBALS['S']['meta'][ $id ][ $k ] ?? ''; }
    function update_post_meta( $id, $k, $v ) { $GLOBALS['S']['meta'][ $id ][ $k ] = $v; return true; }
    function wp_insert_post( $a, $e = false ) {
        $id = $GLOBALS['S']['next']++;
        $GLOBALS['S']['posts'][ $id ] = [ 'ID' => $id, 'post_type' => $a['post_type'], 'post_status' => $a['post_status'],
            'post_author' => $a['post_author'], 'post_date' => date( 'Y-m-d H:i:s', $GLOBALS['S']['now'] ) ];
        foreach ( $a['meta_input'] ?? [] as $k => $v ) $GLOBALS['S']['meta'][ $id ][ $k ] = $v;
        return $id;
    }
    function wp_update_post( $a ) { foreach ( $a as $k => $v ) if ( $k !== 'ID' ) $GLOBALS['S']['posts'][ $a['ID'] ][ $k ] = $v; return $a['ID']; }
    function is_wp_error( $x ) { return false; }
    if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $a, $l = null ) { return substr( $s, $a, $l ); } } // WP fournit un polyfill (compat.php)

    class FakeWpdb {
        public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
        function prepare( $q, ...$a ) {
            $i = 0; return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $a ) {
                $v = $a[ $i++ ]; return $m[0] === '%d' ? (int) $v : "'" . addslashes( (string) $v ) . "'"; }, $q );
        }
        private function ids( $q ) {
            $S = &$GLOBALS['S']; $out = [];
            $st = null;
            if ( preg_match( "/p\.post_status\s*=\s*'([^']*)'/", $q, $m ) ) $st = [ $m[1] ];
            elseif ( preg_match( "/p\.post_status\s+IN\s*\(([^)]*)\)/", $q, $m ) ) $st = array_map( fn( $x ) => trim( $x, " '" ), explode( ',', $m[1] ) );
            $author = preg_match( '/p\.post_author\s*=\s*(\d+)/', $q, $m ) ? (int) $m[1] : null;
            $mk = preg_match( "/m\.meta_key\s*=\s*'([^']+)'/", $q, $m ) ? $m[1] : null;
            $mv = preg_match( "/m\.meta_value\s*=\s*('[^']*'|\d+)/", $q, $m ) ? trim( $m[1], "'" ) : null;
            $win = strpos( $q, 'INTERVAL 10 MINUTE' ) !== false;
            $or0 = strpos( $q, "m.meta_value = '0'" ) !== false; // finders legacy event+user
            foreach ( $S['posts'] as $id => $p ) {
                if ( $p['post_type'] !== 'rk_booking' ) continue;
                if ( $st && ! in_array( $p['post_status'], $st, true ) ) continue;
                if ( $author !== null && (int) $p['post_author'] !== $author ) continue;
                if ( $win && strtotime( $p['post_date'] ) < $S['now'] - 600 ) continue;
                if ( $mk ) { $val = $S['meta'][ $id ][ $mk ] ?? null;
                    if ( $or0 ) { if ( ! ( $val === null || (string) $val === '0' || (string) $val === $mv ) ) continue; }
                    elseif ( $mv !== null && (string) $val !== $mv ) continue; }
                $out[] = $id;
            }
            rsort( $out ); return $out;
        }
        function get_var( $q ) {
            if ( strpos( $q, 'FROM wp_posts' ) !== false ) return $this->ids( $q )[0] ?? null;
            if ( strpos( $q, 'FROM wp_rk_bookings' ) !== false ) return null;
            return null;
        }
        function get_col( $q ) { return $this->ids( $q ); }
        function insert( $t, $d, $f = null ) { $GLOBALS['S']['sql_inserts'][] = [ $t, $d ]; return 1; }
        function update( ...$a ) { return 1; }
    }
    $GLOBALS['wpdb'] = new FakeWpdb();
}

namespace RiadaKids\Credits {
    class CreditRepository {
        static function get_balance( $u ) { return $GLOBALS['S']['credits'][ $u ] ?? 0; }
        static function decrease_safe( $u, $n ) { $GLOBALS['S']['credit_calls']++; $GLOBALS['S']['credits'][ $u ] -= $n; return $GLOBALS['S']['credits'][ $u ]; }
    }
    class CreditLogger { static function log( ...$a ) {} }
}
namespace RiadaKids\Database {
    class DB {
        static function acquire_sql_lock( $n, $t = 0 ) { return true; }
        static function release_sql_lock( $n ) {}
        static function booking_exists( $id ) { return 0; }
        static function get_ssa_appointment_array( $id ) { return []; }
    }
}
namespace RiadaKids\Core { class Helpers { static function get_appt_id( $a ) { return (int) ( $a['id'] ?? 0 ); } } }
namespace RiadaKids\Booking {
    class SSAIntegration { static function is_valid_event_id( $i ) { return true; } static function find_event( $i ) { return null; } }
    class BookingNotifier { function notify_booking_confirmed( ...$a ) { $GLOBALS['S']['notified']++; } }
}

namespace {
    $base = dirname( __DIR__ ) . '/includes/Booking/';
    foreach ( [ 'BookingStatus', 'BookingContext', 'BookingRepository', 'BookingService' ] as $f ) require $base . $f . '.php';
    use RiadaKids\Booking\{BookingStatus as St, BookingContext, BookingRepository, BookingService, BookingNotifier};

    $pass = 0; $fail = 0;
    function t( string $name, bool $ok, string $detail = '' ) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $name" . ( $ok || ! $detail ? '' : "  → $detail" ) . "\n"; }
    function ctx( int $user, int $session, int $child ): BookingContext {
        $c = new BookingContext(); $c->user_id = $user; $c->program_id = 1; $c->adventure_id = 2; $c->session_id = $session; $c->child_ids = [ $child ]; $c->credits_used = 1; return $c; }
    function snap() { $S = $GLOBALS['S']; return [ 'sql' => count( $S['sql_inserts'] ), 'cred' => $S['credit_calls'], 'conf' => count( array_filter( $S['actions'], fn( $a ) => $a === 'rk_booking_confirmed' ) ), 'mail' => $S['notified'] ]; }
    function workflow_booking( BookingRepository $r, int $u, int $s, int $c ): int {
        $id = $r->create( ctx( $u, $s, $c ), St::PENDING_SCHEDULE );
        update_post_meta( $id, '_rk_child_ids', [ $c ] ); update_post_meta( $id, '_rk_session_id', $s ); update_post_meta( $id, '_rk_credits_used', 1 ); return $id; }

    $repo = new BookingRepository(); $svc = new BookingService( $repo, new BookingNotifier() );

    echo "== Machine d'états (pure) ==\n";
    $allowed = [ 'pending_schedule' => [ 'pending_parent_confirmation', 'cancelled', 'expired' ],
        'pending_parent_confirmation' => [ 'confirmed', 'change_requested', 'cancelled', 'expired' ],
        'change_requested' => [ 'pending_parent_confirmation', 'cancelled', 'expired' ],
        'confirmed' => [ 'completed', 'cancelled', 'rescheduled' ] ];
    $all = [ 'pending_schedule', 'pending_parent_confirmation', 'change_requested', 'confirmed', 'completed', 'cancelled', 'expired', 'rescheduled', 'pending' ];
    $bad = [];
    foreach ( $all as $f ) foreach ( $all as $to ) { $exp = in_array( $to, $allowed[ $f ] ?? [], true ); if ( St::can_transition( $f, $to ) !== $exp ) $bad[] = "$f->$to"; }
    t( 'table de transitions conforme à la spec (81 couples)', ! $bad, implode( ',', $bad ) );
    t( 'legacy pending/booked non touchés par is_pre_confirmation', ! St::is_pre_confirmation( 'pending' ) && ! St::is_pre_confirmation( 'confirmed' ) );
    t( 'is_confirmed: confirmed+rescheduled', St::is_confirmed( 'confirmed' ) && St::is_confirmed( 'rescheduled' ) && ! St::is_confirmed( 'pending' ) );
    t( 'is_terminal: completed/cancelled/canceled/expired', St::is_terminal( 'completed' ) && St::is_terminal( 'canceled' ) && St::is_terminal( 'expired' ) && ! St::is_terminal( 'confirmed' ) );
    t( 'post_status legacy inchangé (pending/publish/cancelled)', St::wp_post_status( 'pending' ) === 'pending' && St::wp_post_status( 'confirmed' ) === 'publish' && St::wp_post_status( 'cancelled' ) === 'cancelled' );
    t( 'post_status pré-confirmation = rk_awaiting (hors listes legacy)', St::wp_post_status( 'change_requested' ) === 'rk_awaiting' && ! in_array( 'rk_awaiting', [ 'pending', 'publish', 'cancelled', 'trash' ], true ) );

    echo "\n== Test A — ancien booking pending (flux SSA legacy) ==\n";
    $r = $svc->pre_create_booking( ctx( 10, 92, 101 ) ); $legacy = $r['booking_id'];
    t( 'A1 création legacy: _rk_status=pending, post_status=pending', get_post_meta( $legacy, '_rk_status' ) === 'pending' && get_post( $legacy )->post_status === 'pending' );
    $ok = $svc->confirm_from_ssa( 500, 7, '2026-10-15 17:00:00', 10, $r['booking_uuid'], 'Coach' ); $s = snap();
    t( 'A2 confirm_from_ssa legacy → confirmed', $ok && get_post_meta( $legacy, '_rk_status' ) === 'confirmed' && get_post( $legacy )->post_status === 'publish' );
    t( 'A3 legacy: 1 crédit, 1 ligne SQL (status confirmed), 1 hook, 1 mail', $s === [ 'sql' => 1, 'cred' => 1, 'conf' => 1, 'mail' => 1 ] && $GLOBALS['S']['sql_inserts'][0][1]['status'] === 'confirmed' && $GLOBALS['S']['sql_inserts'][0][1]['booking_id'] === 500, json_encode( $s ) );
    $svc->confirm_from_ssa( 500, 7, '2026-10-15 17:00:00', 10, $r['booking_uuid'], 'Coach' );
    t( 'A4 idempotence legacy (2e callback: rien de plus)', snap() === $s );
    t( 'A5 crédit user 10: 5 → 4', $GLOBALS['S']['credits'][10] === 4 );

    echo "\n== Test B — pending_schedule ==\n";
    $before = snap(); $b = workflow_booking( $repo, 10, 92, 101 );
    t( 'B1 _rk_status=pending_schedule, post_status=rk_awaiting', get_post_meta( $b, '_rk_status' ) === 'pending_schedule' && get_post( $b )->post_status === 'rk_awaiting' );
    t( 'B2 aucune ligne SQL / crédit / hook', snap() === $before );
    $uuid_b = get_post_meta( $b, '_rk_booking_uuid' );
    $x = $svc->confirm_from_ssa( 900, 7, '2026-10-15 17:00:00', 10, $uuid_b, 'Coach' );
    t( 'B3 callback SSA via UUID, appointment NON lié → refusé (false), aucun effet', $x === false && snap() === $before && get_post_meta( $b, '_rk_status' ) === 'pending_schedule' );
    update_post_meta( $b, '_rk_appointment_id', 900 );
    $x = $svc->confirm_from_ssa( 900, 7, '2026-10-15 17:00:00', 10, $uuid_b, 'Coach' );
    t( 'B4 callback SSA appointment lié → no-op (true), aucun effet, reste pending_schedule', $x === true && snap() === $before && get_post_meta( $b, '_rk_status' ) === 'pending_schedule' );
    $x = $svc->confirm_from_ssa( 901, 7, '2026-10-15 17:00:00', 10, $uuid_b, 'Coach' );
    t( 'B5 callback avec un AUTRE appointment → refusé, aucun effet', $x === false && snap() === $before );
    $leg2 = $svc->pre_create_booking( ctx( 10, 93, 102 ) )['booking_id'];
    $x = $svc->confirm_from_ssa( 900, 7, '2026-10-15 17:00:00', 10, '', 'Coach' );
    t( 'B6 même parent a un legacy pending : callback de l\'appt du workflow ne le confirme PAS', $x === true && get_post_meta( $leg2, '_rk_status' ) === 'pending' && snap() === $before );
    t( 'B7 cron ensure_from_ssa(appt lié) → 0, aucune ligne SQL', BookingService::ensure_from_ssa( [ 'id' => 900, 'appointment_type_id' => 7, 'start_date' => '2026-10-15 17:00:00' ] ) === 0 && snap() === $before );
    t( 'B8 finders legacy ne voient pas le workflow (find_by_appointment_id=0, find_pending_booking_by_event_and_user≠b)', $repo->find_by_appointment_id( 900 ) === 0 && $repo->find_pending_booking_by_event_and_user( 7, 10 ) !== $b );

    echo "\n== Test C — pending_parent_confirmation ==\n";
    $res = $svc->transition_status( $b, St::PENDING_PARENT_CONFIRMATION, [ 'scheduled_by' => 3, 'coach_id' => 55, 'datetime' => '2026-10-15 17:00:00' ] );
    t( 'C1 transition OK + métas coordinateur/coach/date', $res['success'] && get_post_meta( $b, '_rk_scheduled_by' ) === 3 && get_post_meta( $b, '_rk_proposed_coach_id' ) === 55 && get_post_meta( $b, '_rk_proposed_datetime' ) === '2026-10-15 17:00:00' && get_post_meta( $b, '_rk_scheduled_at' ) !== '' );
    t( 'C2 aucune ligne SQL / crédit / hook', snap() === $before );
    $c3 = workflow_booking( $repo, 10, 99, 101 );
    t( 'C3 date invalide refusée, état inchangé', ! $svc->transition_status( $c3, St::PENDING_PARENT_CONFIRMATION, [ 'scheduled_by' => 3, 'coach_id' => 5, 'datetime' => 'garbage' ] )['success'] && get_post_meta( $c3, '_rk_status' ) === 'pending_schedule' && get_post_meta( $c3, '_rk_scheduled_by' ) === '' );
    $c2 = workflow_booking( $repo, 10, 94, 101 );
    t( 'C4 pending_schedule → pending_parent_confirmation sans coach → refusé', ! $svc->transition_status( $c2, St::PENDING_PARENT_CONFIRMATION, [ 'scheduled_by' => 3, 'datetime' => '2026-10-15 17:00:00' ] )['success'] && get_post_meta( $c2, '_rk_status' ) === 'pending_schedule' );
    t( 'C5 pending_schedule → change_requested interdit', ! $svc->transition_status( $c2, St::CHANGE_REQUESTED, [ 'note' => 'x' ] )['success'] );
    t( 'C6 → confirmed REFUSÉ par transition_status (porte unique Phase 6)', ! $svc->transition_status( $b, St::CONFIRMED )['success'] && get_post_meta( $b, '_rk_status' ) === 'pending_parent_confirmation' && snap() === $before );
    t( 'C7 booking legacy refusé par transition_status', ! $svc->transition_status( $leg2, St::CANCELLED )['success'] && get_post_meta( $leg2, '_rk_status' ) === 'pending' );

    echo "\n== Test D — change_requested ==\n";
    t( 'D1 note vide refusée', ! $svc->transition_status( $b, St::CHANGE_REQUESTED, [ 'note' => '   ' ] )['success'] );
    $res = $svc->transition_status( $b, St::CHANGE_REQUESTED, [ 'note' => "Heure <b>non</b> adaptée" ] );
    t( 'D2 transition OK, note sanitizée, date posée', $res['success'] && get_post_meta( $b, '_rk_change_note' ) === 'Heure non adaptée' && get_post_meta( $b, '_rk_change_requested_at' ) !== '' );
    t( 'D3 aucun crédit / SQL / hook', snap() === $before );
    $res = $svc->transition_status( $b, St::PENDING_PARENT_CONFIRMATION, [ 'scheduled_by' => 3, 'coach_id' => 56, 'datetime' => '2026-10-16 18:00:00' ] );
    t( 'D4 reprogrammation: change_requested → pending_parent_confirmation (même booking, coach/date mis à jour)', $res['success'] && get_post_meta( $b, '_rk_proposed_coach_id' ) === 56 && get_post_meta( $b, '_rk_status' ) === 'pending_parent_confirmation' && count( array_filter( $GLOBALS['S']['posts'], fn( $p ) => $p['post_type'] === 'rk_booking' && ( $GLOBALS['S']['meta'][ $p['ID'] ]['_rk_booking_uuid'] ?? '' ) === $uuid_b ) ) === 1 );
    $res = $svc->transition_status( $b, St::CANCELLED );
    t( 'D5 annulation pré-confirmation: cancelled, crédit jamais déduit, aucun remboursement', $res['success'] && get_post_meta( $b, '_rk_status' ) === 'cancelled' && get_post_meta( $b, '_rk_credits_deducted' ) === '0' && snap() === $before );
    t( 'D6 état terminal: plus aucune transition', ! $svc->transition_status( $b, St::PENDING_PARENT_CONFIRMATION, [ 'scheduled_by' => 3, 'coach_id' => 1, 'datetime' => '2026-10-16 18:00:00' ] )['success'] );

    echo "\n== Test E — ancien confirmed ==\n";
    t( 'E1 booking legacy confirmé intact: SQL row (status confirmed), post publish, meta appointment', get_post( $legacy )->post_status === 'publish' && get_post_meta( $legacy, '_rk_appointment_id' ) === 500 && $GLOBALS['S']['sql_inserts'][0][1]['status'] === 'confirmed' );
    t( 'E2 get_user_bookings-style lookup: find_by_appointment_id(500) retrouve le legacy', $repo->find_by_appointment_id( 500 ) === $legacy );
    echo "  (dashboards Coach/Enfant/Parent = plateforme A, non modifiée : à vérifier sur staging)\n";

    echo "\n== Test F — multi-enfants ==\n";
    $a = $svc->pre_create_booking( ctx( 10, 95, 101 ) )['booking_id']; $b2 = $svc->pre_create_booking( ctx( 10, 95, 102 ) )['booking_id']; $a_again = $svc->pre_create_booking( ctx( 10, 95, 101 ) )['booking_id'];
    t( 'F1 legacy: enfant 101 et 102 (même user+session) → 2 bookings distincts', $a !== $b2 && get_post_meta( $a, '_rk_child_ids' ) === [ 101 ] && get_post_meta( $b2, '_rk_child_ids' ) === [ 102 ] );
    t( 'F2 legacy: même enfant redemandé < 10 min → réutilisation conservée', $a_again === $a );
    $w1 = workflow_booking( $repo, 10, 92, 201 ); $w2 = workflow_booking( $repo, 10, 92, 202 );
    t( 'F3 workflow: find_open_workflow_booking distingue 201 / 202', $repo->find_open_workflow_booking( 10, 92, 201 ) === $w1 && $repo->find_open_workflow_booking( 10, 92, 202 ) === $w2 && $w1 !== $w2 );
    t( 'F4 workflow: enfant inconnu → 0, autre user → 0', $repo->find_open_workflow_booking( 10, 92, 203 ) === 0 && $repo->find_open_workflow_booking( 11, 92, 201 ) === 0 );
    t( 'F5 legacy find_recent ne réutilise jamais un booking workflow', $svc->pre_create_booking( ctx( 10, 92, 201 ) )['booking_id'] !== $w1 );

    echo "\n== Expiration (helpers) ==\n";
    $e = workflow_booking( $repo, 10, 96, 301 ); update_post_meta( $e, '_rk_created_at', date( 'Y-m-d H:i:s', $GLOBALS['S']['now'] - 6 * 86400 ) );
    t( 'X1 pending_schedule 6 j → non expiré', ! BookingService::is_preconfirmation_expired( $e ) );
    update_post_meta( $e, '_rk_created_at', date( 'Y-m-d H:i:s', $GLOBALS['S']['now'] - 8 * 86400 ) );
    t( 'X2 pending_schedule 8 j → expiré (7 j par défaut, filtrable)', BookingService::is_preconfirmation_expired( $e ) );
    $old_legacy = $svc->pre_create_booking( ctx( 10, 97, 401 ) )['booking_id']; update_post_meta( $old_legacy, '_rk_created_at', date( 'Y-m-d H:i:s', $GLOBALS['S']['now'] - 30 * 86400 ) );
    t( 'X3 helper ignore le legacy pending (ancienne règle 24 h intacte)', ! BookingService::is_preconfirmation_expired( $old_legacy ) );
    $noref = workflow_booking( $repo, 10, 98, 501 ); unset( $GLOBALS['S']['meta'][ $noref ]['_rk_created_at'] );
    t( 'X4 sans date de référence → jamais expiré', ! BookingService::is_preconfirmation_expired( $noref ) );

    echo "\n" . ( $fail ? "ÉCHEC" : "OK" ) . " — $pass PASS / $fail FAIL\n"; exit( $fail ? 1 : 0 );
}
