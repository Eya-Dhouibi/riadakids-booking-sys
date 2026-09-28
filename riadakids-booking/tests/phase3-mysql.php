<?php
/**
 * R11 — requêtes SQL RÉELLES sur un vrai moteur (MariaDB/MySQL), pas le faux $wpdb.
 * Usage : RK_DB_HOST=127.0.0.1 RK_DB_USER=root RK_DB_PASS= php tests/phase3-mysql.php
 * Schéma wp_posts / wp_postmeta fidèle à WordPress (types, index). N'exécute PAS
 * WordPress lui-même : get_post/get_post_meta sont des shims fins au-dessus des tables.
 */
namespace {
    define( 'ABSPATH', '/' ); define( 'DAY_IN_SECONDS', 86400 );
    mysqli_report( MYSQLI_REPORT_OFF );
    $db = new mysqli( getenv( 'RK_DB_HOST' ) ?: '127.0.0.1', getenv( 'RK_DB_USER' ) ?: 'root', getenv( 'RK_DB_PASS' ) ?: '' );
    if ( $db->connect_errno ) { fwrite( STDERR, "Connexion DB impossible: {$db->connect_error}\n" ); exit( 2 ); }
    $db->query( 'DROP DATABASE IF EXISTS rk_test' ); $db->query( 'CREATE DATABASE rk_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' ); $db->select_db( 'rk_test' ); $db->set_charset( 'utf8mb4' );
    foreach ( [
        "CREATE TABLE wp_posts ( ID bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_author bigint(20) unsigned NOT NULL DEFAULT 0, post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00', post_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00', post_content longtext NOT NULL, post_title text NOT NULL, post_excerpt text NOT NULL, post_status varchar(20) NOT NULL DEFAULT 'publish', comment_status varchar(20) NOT NULL DEFAULT 'open', ping_status varchar(20) NOT NULL DEFAULT 'open', post_password varchar(255) NOT NULL DEFAULT '', post_name varchar(200) NOT NULL DEFAULT '', to_ping text NOT NULL, pinged text NOT NULL, post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00', post_modified_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00', post_content_filtered longtext NOT NULL, post_parent bigint(20) unsigned NOT NULL DEFAULT 0, guid varchar(255) NOT NULL DEFAULT '', menu_order int(11) NOT NULL DEFAULT 0, post_type varchar(20) NOT NULL DEFAULT 'post', post_mime_type varchar(100) NOT NULL DEFAULT '', comment_count bigint(20) NOT NULL DEFAULT 0, PRIMARY KEY (ID), KEY post_name (post_name(191)), KEY type_status_date (post_type,post_status,post_date,ID), KEY post_parent (post_parent), KEY post_author (post_author) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        "CREATE TABLE wp_postmeta ( meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_id bigint(20) unsigned NOT NULL DEFAULT 0, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id), KEY post_id (post_id), KEY meta_key (meta_key(191)) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        "CREATE TABLE wp_rk_children ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_id bigint(20) unsigned NOT NULL, child_name varchar(100) NOT NULL, child_family_name varchar(100) NOT NULL DEFAULT '', child_age int NOT NULL DEFAULT 0, created_at datetime NULL, PRIMARY KEY (id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $ddl ) { if ( ! $db->query( $ddl ) ) { fwrite( STDERR, "DDL: {$db->error}\n" ); exit( 2 ); } }
    $GLOBALS['db'] = $db;

    function rk_log( ...$a ) {} function __( $s ) { return $s; }
    function current_time( $t ) { return $t === 'timestamp' ? time() : date( 'Y-m-d H:i:s' ); }
    function apply_filters( $t, $v ) { return $v; } function do_action( ...$a ) {}
    function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
    function is_wp_error( $x ) { return false; }
    function get_term( $i, $t ) { return (object) [ 'name' => 'الريادة' ]; } function get_the_title( $i ) { return 'T' . $i; }
    function get_userdata( $i ) { $n = [ 3 => 'Coord', 10 => 'Parent', 55 => 'Coach A', 56 => 'Coach B' ][ $i ] ?? null; return $n ? (object) [ 'ID' => $i, 'display_name' => $n ] : false; }
    function get_users( $a ) { return [ (object) [ 'ID' => 55, 'display_name' => 'Coach A' ], (object) [ 'ID' => 56, 'display_name' => 'Coach B' ] ]; }
    function is_serialized_rk( $v ) { return is_string( $v ) && preg_match( '/^(a|i|s|b):/', $v ) === 1; }
    function update_post_meta( $id, $k, $v ) { global $db; $val = is_array( $v ) ? serialize( $v ) : (string) $v;
        $st = $db->prepare( 'DELETE FROM wp_postmeta WHERE post_id=? AND meta_key=?' ); $st->bind_param( 'is', $id, $k ); $st->execute();
        $st = $db->prepare( 'INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (?,?,?)' ); $st->bind_param( 'iss', $id, $k, $val ); $st->execute(); return true; }
    function get_post_meta( $id, $k, $single = true ) { global $db; $st = $db->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key=? LIMIT 1' ); $st->bind_param( 'is', $id, $k ); $st->execute();
        $r = $st->get_result()->fetch_row(); if ( ! $r ) return ''; return is_serialized_rk( $r[0] ) && ( $u = @unserialize( $r[0] ) ) !== false ? $u : $r[0]; }
    function get_post( $id ) { global $db; $r = $db->query( 'SELECT * FROM wp_posts WHERE ID=' . (int) $id ); $o = $r ? $r->fetch_object() : null; return $o ?: null; }

    class RealWpdb {
        public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $queries = [];
        function prepare( $q, ...$a ) { global $db; $i = 0;
            return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $a, $db ) { $v = $a[ $i++ ]; return $m[0] === '%d' ? (string) (int) $v : "'" . $db->real_escape_string( (string) $v ) . "'"; }, $q ); }
        private function run( $q ) { global $db; $this->queries[] = $q; $r = $db->query( $q ); if ( $r === false ) throw new \RuntimeException( "SQL ERROR: {$db->error}\n$q" ); return $r; }
        function get_var( $q ) { $r = $this->run( $q )->fetch_row(); return $r ? $r[0] : null; }
        function get_col( $q ) { $out = []; $r = $this->run( $q ); while ( $x = $r->fetch_row() ) $out[] = $x[0]; return $out; }
        function get_results( $q ) { $out = []; $r = $this->run( $q ); while ( $x = $r->fetch_object() ) $out[] = $x; return $out; }
    }
    $GLOBALS['wpdb'] = new RealWpdb();
}
namespace RiadaKids\Booking { class BookingNotifier {} }
namespace {
    $root = dirname( __DIR__ ) . '/includes/';
    foreach ( [ 'Booking/BookingStatus', 'Booking/BookingContext', 'Booking/BookingRepository', 'Booking/BookingService', 'Coordinator/CoordinatorService' ] as $f ) require $root . $f . '.php';
    use RiadaKids\Booking\{BookingStatus as St, BookingRepository, BookingService, BookingContext};
    use RiadaKids\Coordinator\CoordinatorService;

    $pass = 0; $fail = 0;
    function t( string $n, bool $ok, string $d = '' ) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $n" . ( $ok || ! $d ? '' : "  → $d" ) . "\n"; }
    function post( int $author, string $status, string $biz, array $meta, string $age = '0 MINUTE', string $type = 'rk_booking' ): int {
        global $db;
        $db->query( "INSERT INTO wp_posts (post_author,post_date,post_status,post_type,post_content,post_title,post_excerpt,to_ping,pinged,post_content_filtered) VALUES ($author, NOW() - INTERVAL $age, '" . $db->real_escape_string( $status ) . "', '$type', '', 't', '', '', '', '')" );
        $id = $db->insert_id; if ( $biz !== '' ) update_post_meta( $id, '_rk_status', $biz ); foreach ( $meta as $k => $v ) update_post_meta( $id, $k, $v ); return $id; }
    $repo = new BookingRepository(); $svc = new BookingService( $repo, new \RiadaKids\Booking\BookingNotifier() );
    $recent = new ReflectionMethod( $svc, 'find_recent_pending' );
    $db->query( "INSERT INTO wp_rk_children (id,user_id,child_name,child_family_name,child_age) VALUES (101,10,'أحمد','العلي',9),(102,10,'سارة','العلي',11),(201,11,'ياسمين','نور',8)" );
    $W = fn( $u, $s, $c, $st = 'pending_schedule', $ex = [], $age = '0 MINUTE' ) => post( $u, St::wp_post_status( $st ), $st, [ '_rk_child_ids' => [ $c ], '_rk_session_id' => $s, '_rk_program_id' => 5, '_rk_adventure_id' => 77, '_rk_created_at' => date( 'Y-m-d H:i:s' ) ] + $ex, $age );
    $ver = $db->query( 'SELECT VERSION()' )->fetch_row()[0]; echo "Moteur : $ver\n";

    echo "\n== Fixtures ==\n";
    $w1 = $W( 10, 92, 101 ); $w2 = $W( 10, 92, 102 ); $w3 = $W( 11, 92, 201 );
    $p1 = $W( 10, 93, 101, 'pending_parent_confirmation', [ '_rk_appointment_id' => 900, '_rk_proposed_coach_id' => 55, '_rk_proposed_datetime' => '2026-10-15 17:00:00', '_rk_scheduled_by' => 3, '_rk_scheduled_at' => '2026-10-01 09:00:00' ] );
    $p2 = $W( 10, 93, 102, 'pending_parent_confirmation', [ '_rk_appointment_id' => 901, '_rk_proposed_coach_id' => 55, '_rk_proposed_datetime' => '2026-10-15 18:00:00', '_rk_scheduled_by' => 3 ] );
    $c1 = $W( 10, 94, 101, 'change_requested', [ '_rk_appointment_id' => 902, '_rk_change_note' => 'ملاحظة', '_rk_change_requested_at' => '2026-10-02 08:00:00' ] );
    $lp_a = post( 10, 'pending', 'pending', [ '_rk_child_ids' => [ 101 ], '_rk_session_id' => 92 ], '5 MINUTE' );      // legacy récent
    $lp_b = post( 10, 'pending', 'pending', [ '_rk_child_ids' => [ 102 ], '_rk_session_id' => 92 ], '5 MINUTE' );      // legacy récent, autre enfant
    $lp_old = post( 10, 'pending', 'pending', [ '_rk_child_ids' => [ 101 ], '_rk_session_id' => 50 ], '30 MINUTE' );    // legacy > 10 min
    $lp_single = post( 10, 'pending', 'pending', [ '_rk_child_id' => 103, '_rk_session_id' => 60 ], '1 MINUTE' );       // ancien format _rk_child_id
    $lc = post( 10, 'publish', 'confirmed', [ '_rk_child_ids' => [ 101 ], '_rk_session_id' => 96, '_rk_appointment_id' => 500 ], '2 DAY' );
    $lx = post( 10, 'cancelled', 'cancelled', [ '_rk_child_ids' => [ 101 ], '_rk_session_id' => 96, '_rk_appointment_id' => 500 ], '3 DAY' );
    $lp = post( 10, 'pending', 'pending', [ '_rk_child_ids' => [ 101 ], '_rk_session_id' => 96, '_rk_appointment_id' => 500 ], '1 DAY' ); // même appt, statut pending
    $noise = post( 10, 'publish', '', [ '_rk_status' => 'pending_schedule' ], '0 MINUTE', 'post' ); // autre post type portant un _rk_status trompeur
    echo "  " . $db->query( 'SELECT COUNT(*) FROM wp_posts' )->fetch_row()[0] . " posts, " . $db->query( 'SELECT COUNT(*) FROM wp_postmeta' )->fetch_row()[0] . " métas\n";

    echo "\n== Phase 3 — find_ids_by_business_status ==\n";
    t( 'Q1 pending_schedule → w1,w2,w3 (SQL valide, jointure méta + post_status)', ( function () use ( $repo, $w1, $w2, $w3 ) { $r = $repo->find_ids_by_business_status( St::PENDING_SCHEDULE ); sort( $r ); return $r === [ $w1, $w2, $w3 ]; } )() );
    t( 'Q2 pending_parent_confirmation → p1,p2 ; change_requested → c1', ( function () use ( $repo, $p1, $p2, $c1 ) { $a = $repo->find_ids_by_business_status( St::PENDING_PARENT_CONFIRMATION ); sort( $a ); return $a === [ $p1, $p2 ] && $repo->find_ids_by_business_status( St::CHANGE_REQUESTED ) === [ $c1 ]; } )() );
    t( 'Q3 exclut le post_type "post" portant _rk_status=pending_schedule (bruit)', ! in_array( $noise, $repo->find_ids_by_business_status( St::PENDING_SCHEDULE ), true ) );
    t( 'Q4 legacy pending/confirmed/cancelled jamais listés', ( function () use ( $repo, $lp_a, $lc, $lx ) { $all = array_merge( $repo->find_ids_by_business_status( St::PENDING_SCHEDULE ), $repo->find_ids_by_business_status( St::PENDING_PARENT_CONFIRMATION ), $repo->find_ids_by_business_status( St::CHANGE_REQUESTED ) ); return ! array_intersect( $all, [ $lp_a, $lc, $lx ] ); } )() );
    t( 'Q5 LIMIT respecté', count( $repo->find_ids_by_business_status( St::PENDING_SCHEDULE, 2 ) ) === 2 );
    t( 'Q6 ordre : plus récent d\'abord (date DESC, ID DESC)', ( function () use ( $repo, $w1, $w2, $w3 ) { return $repo->find_ids_by_business_status( St::PENDING_SCHEDULE ) === [ $w3, $w2, $w1 ]; } )() );
    t( 'Q7 injection dans le statut (\' OR 1=1 --) : refusé avant SQL', $repo->find_ids_by_business_status( "pending_schedule' OR '1'='1" ) === [] );

    echo "\n== Phase 2 (R11) — requêtes du Repository sur MySQL réel ==\n";
    t( 'S1 find_open_workflow_booking(10,92,101) = w1 (jointure méta + désérialisation _rk_child_ids)', $repo->find_open_workflow_booking( 10, 92, 101 ) === $w1 );
    t( 'S2 find_open_workflow_booking(10,92,102) = w2 ≠ w1 (deux enfants, même user+session : jamais fusionnés)', $repo->find_open_workflow_booking( 10, 92, 102 ) === $w2 && $w1 !== $w2 );
    t( 'S3 autre user (11) / enfant inconnu / session inconnue → 0', $repo->find_open_workflow_booking( 11, 92, 101 ) === 0 && $repo->find_open_workflow_booking( 10, 92, 999 ) === 0 && $repo->find_open_workflow_booking( 10, 777, 101 ) === 0 );
    t( 'S4 find_open_workflow_booking ne voit pas les legacy (session 50, enfant 101)', $repo->find_open_workflow_booking( 10, 50, 101 ) === 0 );
    t( 'S5 find_pre_confirmation_by_appointment_id(900)=p1, (901)=p2, (500 legacy)=0, (0)=0', $repo->find_pre_confirmation_by_appointment_id( 900 ) === $p1 && $repo->find_pre_confirmation_by_appointment_id( 901 ) === $p2 && $repo->find_pre_confirmation_by_appointment_id( 500 ) === 0 && $repo->find_pre_confirmation_by_appointment_id( 0 ) === 0 );
    t( 'S6 get_child_ids : tableau sérialisé, ancien _rk_child_id (repli), absent', $repo->get_child_ids( $w1 ) === [ 101 ] && $repo->get_child_ids( $lp_single ) === [ 103 ] && $repo->get_child_ids( 999999 ) === [] );
    $ctx = fn( $u, $s, $c ) => ( function () use ( $u, $s, $c ) { $x = new BookingContext(); $x->user_id = $u; $x->session_id = $s; $x->child_ids = $c; return $x; } )();
    t( 'S7 find_recent_pending (legacy, fenêtre 10 min MySQL NOW()) : enfant 101 → lp_a ; enfant 102 → lp_b (jamais fusionnés)', $recent->invoke( $svc, $ctx( 10, 92, [ 101 ] ) ) === $lp_a && $recent->invoke( $svc, $ctx( 10, 92, [ 102 ] ) ) === $lp_b );
    t( 'S8 find_recent_pending : booking de 30 min → 0 ; enfants [101,102] (ensemble différent) → 0', $recent->invoke( $svc, $ctx( 10, 50, [ 101 ] ) ) === 0 && $recent->invoke( $svc, $ctx( 10, 92, [ 101, 102 ] ) ) === 0 );
    t( 'S9 find_recent_pending ne réutilise jamais un rk_awaiting (session 93 enfant 101 = p1 existe mais ignoré)', $recent->invoke( $svc, $ctx( 10, 93, [ 101 ] ) ) === 0 );
    t( 'S10 find_recent_pending : ancien format _rk_child_id (103) retrouvé', $recent->invoke( $svc, $ctx( 10, 60, [ 103 ] ) ) === $lp_single );
    t( 'S11 find_by_appointment_id(500) : ORDER BY FIELD → le publish (confirmed) gagne sur pending et cancelled', $repo->find_by_appointment_id( 500 ) === $lc );
    t( 'S12 find_by_appointment_id(900) (appt d\'un rk_awaiting) → 0 : l\'ancien flux ne voit pas le workflow', $repo->find_by_appointment_id( 900 ) === 0 );
    t( 'S13 finders legacy event : find_pending_booking_by_event_and_user / _by_event / count — SQL valide et n\'incluent pas rk_awaiting', ( function () use ( $repo, $w1, $w2, $w3, $p1, $c1 ) { $a = $repo->find_pending_booking_by_event_and_user( 7, 10 ); $b = $repo->find_pending_booking_by_event( 7 ); $n = $repo->count_pending_bookings_by_event( 7 ); return ! in_array( $a, [ $w1, $w2, $w3, $p1, $c1 ], true ) && ! in_array( $b, [ $w1, $w2, $w3, $p1, $c1 ], true ) && $n === 5; } )(), (string) $repo->count_pending_bookings_by_event( 7 ) );
    t( 'S14 find_duplicate (SQL valide, event/datetime inexistants → 0)', $repo->find_duplicate( 101, 7, '2026-10-15 17:00:00' ) === 0 );

    echo "\n== Phase 3 — CoordinatorService sur MySQL réel (IN(...) enfants, describe) ==\n";
    $cs = new CoordinatorService( $repo );
    $new = $cs->list_by_status( St::PENDING_SCHEDULE ); $by = []; foreach ( $new as $r ) $by[ $r['booking_id'] ] = $r;
    t( 'C1 list_by_status(new) : 3 lignes, noms d\'enfants résolus par UNE requête IN(...)', count( $new ) === 3 && $by[ $w1 ]['children'][0]['name'] === 'أحمد العلي' && $by[ $w2 ]['children'][0]['name'] === 'سارة العلي' && $by[ $w3 ]['children'][0]['name'] === 'ياسمين نور' );
    $pend = $cs->list_by_status( St::PENDING_PARENT_CONFIRMATION ); $pp = []; foreach ( $pend as $r ) $pp[ $r['booking_id'] ] = $r;
    t( 'C2 en attente : coach, datetime, coordinateur', $pp[ $p1 ]['proposal']['coach_name'] === 'Coach A' && $pp[ $p1 ]['proposal']['datetime'] === '2026-10-15 17:00:00' && $pp[ $p1 ]['proposal']['scheduled_by_name'] === 'Coord' );
    $ch = $cs->list_by_status( St::CHANGE_REQUESTED );
    t( 'C3 changement : note + date de demande', count( $ch ) === 1 && $ch[0]['change']['note'] === 'ملاحظة' && $ch[0]['change']['requested_at'] === '2026-10-02 08:00:00' );
    t( 'C4 get_booking : workflow OK ; legacy pending / confirmed / autre post_type / inexistant → refus', $cs->get_booking( $w1 )['ok'] && ! $cs->get_booking( $lp_a )['ok'] && ! $cs->get_booking( $lc )['ok'] && ! $cs->get_booking( $noise )['ok'] && ! $cs->get_booking( 999999 )['ok'] );
    $co = $cs->coaches(); $cm = []; foreach ( $co as $c ) $cm[ $c['id'] ] = $c;
    t( 'C5 coaches() : propositions en attente par coach (55→2, 56→0)', $cm[55]['open_proposals'] === 2 && $cm[56]['open_proposals'] === 0 );
    $before = [ $db->query( 'SELECT COUNT(*),SUM(ID),COUNT(DISTINCT post_status) FROM wp_posts' )->fetch_row(), $db->query( 'SELECT COUNT(*),SUM(meta_id) FROM wp_postmeta' )->fetch_row() ];
    $cs->list_by_status( St::PENDING_SCHEDULE ); $cs->coaches(); $cs->get_booking( $p1 );
    t( 'C6 lecture seule : aucune ligne posts/postmeta ajoutée ou modifiée par le service', $before === [ $db->query( 'SELECT COUNT(*),SUM(ID),COUNT(DISTINCT post_status) FROM wp_posts' )->fetch_row(), $db->query( 'SELECT COUNT(*),SUM(meta_id) FROM wp_postmeta' )->fetch_row() ] );
    $sel = array_filter( $GLOBALS['wpdb']->queries, fn( $q ) => preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE)/i', $q ) );
    t( 'C7 aucune requête d\'écriture émise par le Repository/Service (' . count( $GLOBALS['wpdb']->queries ) . ' requêtes SELECT vérifiées)', ! $sel );
    echo "\n" . ( $fail ? 'ÉCHEC' : 'OK' ) . " — $pass PASS / $fail FAIL\n"; exit( $fail ? 1 : 0 );
}
