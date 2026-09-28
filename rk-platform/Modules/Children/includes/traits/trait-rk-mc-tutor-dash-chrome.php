<?php
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extrait de class-rk-mc-tutor-dashboard.php — factorisation par fonctionnalité.
 * Code déplacé verbatim, aucune modification de logique.
 */
trait RK_MC_Tutor_Dash_Chrome {
    /* ═══════════════════════════════════════════════════════════════════
       BODY CLASS
       ═══════════════════════════════════════════════════════════════════ */

    public static function add_body_class( array $classes ): array {
        $child = self::resolve_child();
        if ( $child ) {
            $classes[] = 'rk-child-active';
            if ( self::is_home() ) $classes[] = 'rk-td-home';

            // UX1 — Classe UI selon tranche d'âge (junior ≤8, standard ≤12, senior 13+)
            if ( function_exists( 'rk_mc_get_child_age' ) ) {
                $age = rk_mc_get_child_age( $child );
                if ( $age !== null ) {
                    if ( $age <= 8 )      $classes[] = 'rk-ui-junior';
                    elseif ( $age <= 12 ) $classes[] = 'rk-ui-standard';
                    else                  $classes[] = 'rk-ui-senior';
                }
            }
        } elseif ( is_user_logged_in() ) {
            // Vue parent (sans contexte enfant) — design RK appliqué
            $classes[] = 'rk-parent-active';
            if ( self::is_home() ) $classes[] = 'rk-td-home';
        }
        return $classes;
    }

    /* ═══════════════════════════════════════════════════════════════════
       HERO SECTION  (before Tutor wrap — full-width)
       ═══════════════════════════════════════════════════════════════════ */

    public static function render_hero(): void {
        $d = self::load_data();
        if ( empty( $d ) ) return;

        $child      = $d['child'];
        $level      = $d['level'];
        $welcome    = $d['welcome'];
        $points     = $d['total_points'];
        $name       = esc_html( $child->first_name ?? __( 'الطفل', 'rk-my-children' ) );
        $level_num  = (int) ( $level['num'] ?? 1 );
        $level_lbl  = esc_html( $level['label'] ?? 'شرارة' );
        $xp_pct     = (int) ( $level['progress'] ?? 0 );
        $pts_next   = (int) ( $level['pts_to_next'] ?? 0 );
        $pts_total  = $pts_next > 0 ? $points + $pts_next : null;
        $ring_color = self::$level_colors[ $level_num ] ?? '#e8500a';
        $msg_text   = esc_html( $welcome['text'] ?? '' );
        $msg_icon   = $welcome['icon_key'] ?? 'star';
        $avatar_url = ! empty( $child->avatar_url ) ? $child->avatar_url : '';
        $initial    = esc_html( mb_substr( $child->first_name ?? 'ط', 0, 1 ) );

        $badge_count   = count( $d['badges'] );
        $done_missions = (int) ( $d['done_missions'] ?? 0 );
        $total_missions = count( $d['missions'] );

        ob_start();
        ?>
        <div class="rk-hero" dir="rtl" role="banner">
            <div class="rk-hero__inner tutor-container">

                <!-- Avatar avec ring XP -->
                <div class="rk-hero__avatar-wrap">
                    <div class="rk-hero__avatar-ring"
                         style="background:conic-gradient(<?php echo esc_attr( $ring_color ); ?> <?php echo $xp_pct; ?>%, rgba(255,255,255,.22) 0)">
                        <div class="rk-hero__avatar-inner">
                            <?php if ( $avatar_url ) : ?>
                                <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo $name; ?>" class="rk-hero__avatar-img" />
                            <?php else : ?>
                                <div class="rk-hero__avatar-initial"><?php echo $initial; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rk-hero__level-chip">
                        <?php echo wp_kses_post( rk_mc_svg( 'trophy', array( 'class' => 'rk-hero__chip-icon' ) ) ); ?>
                        <?php printf( esc_html__( 'مستوى %d', 'rk-my-children' ), $level_num ); ?>
                    </div>
                </div>

                <!-- Info & XP -->
                <div class="rk-hero__body">
                    <h1 class="rk-hero__name"><?php echo $name; ?></h1>
                    <div class="rk-hero__title"><?php echo $level_lbl; ?></div>

                    <?php if ( $msg_text ) : ?>
                        <div class="rk-hero__msg">
                            <?php echo wp_kses_post( rk_mc_svg( $msg_icon, array( 'class' => 'rk-hero__msg-icon' ) ) ); ?>
                            <span><?php echo $msg_text; ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="rk-hero__xp-row">
                        <div class="rk-hero__xp-bar" role="progressbar"
                             aria-valuenow="<?php echo $xp_pct; ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="rk-hero__xp-fill" style="width:<?php echo $xp_pct; ?>%">
                                <div class="rk-hero__xp-glow"></div>
                            </div>
                        </div>
                        <span class="rk-hero__xp-text">
                            <?php echo wp_kses_post( rk_mc_svg( 'lightning', array( 'class' => 'rk-hero__xp-icon' ) ) ); ?>
                            <strong><?php echo number_format( $points ); ?></strong>
                            <?php if ( $pts_total ) : ?>/ <?php echo number_format( $pts_total ); ?> XP<?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Quick stats chips -->
                <?php
                $streak = class_exists( 'RK_Coach_Data' )
                    ? RK_Coach_Data::get_child_streak( $child_id )
                    : ( class_exists( 'RK_MC_Gamification_Service' ) ? 0 : 0 );
                ?>
                <div class="rk-hero__chips">
                    <div class="rk-hero__chip">
                        <?php echo wp_kses_post( rk_mc_svg( 'award', array( 'class' => 'rk-hero__chip-icon' ) ) ); ?>
                        <span><?php echo $badge_count; ?></span>
                        <small><?php esc_html_e( 'شارة', 'rk-my-children' ); ?></small>
                    </div>
                    <div class="rk-hero__chip">
                        <?php echo wp_kses_post( rk_mc_svg( 'graduation-cap', array( 'class' => 'rk-hero__chip-icon' ) ) ); ?>
                        <span><?php echo $d['completed_count']; ?></span>
                        <small><?php esc_html_e( 'درس', 'rk-my-children' ); ?></small>
                    </div>
                    <div class="rk-hero__chip">
                        <?php echo wp_kses_post( rk_mc_svg( 'nav-target', array( 'class' => 'rk-hero__chip-icon' ) ) ); ?>
                        <span><?php echo $done_missions; ?>/<?php echo $total_missions; ?></span>
                        <small><?php esc_html_e( 'مهمة', 'rk-my-children' ); ?></small>
                    </div>
                    <?php if ( $streak > 0 ) : ?>
                    <div class="rk-hero__chip" title="أيام متواصلة">
                        <span style="font-size:1.1rem;">🔥</span>
                        <span><?php echo $streak; ?></span>
                        <small><?php esc_html_e( 'يوم', 'rk-my-children' ); ?></small>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php
        echo ob_get_clean();
    }

    /* ═══════════════════════════════════════════════════════════════════
       SUB-PAGE MAIN WRAPPER
       Ouvre/ferme <div id="rkd3-main"> autour du header + contenu Tutor
       sur les sous-pages. La home page gère son propre #rkd3-main dans
       dashboard.php.  Priorité 0 → avant ob_start (priorité 1).
       ═══════════════════════════════════════════════════════════════════ */

    public static function render_subpage_main_open(): void {
        if ( self::is_home() ) return;
        echo '<div id="rkd3-main">';
    }

    public static function render_subpage_main_close(): void {
        if ( self::is_home() ) return;
        echo '</div>';
    }


    /* ═══════════════════════════════════════════════════════════════════
       WIDGET CLOCHE NOTIFICATIONS — composant réutilisable (v2.8.2)
       Utilisé par : header desktop des sous-pages (render_topbar), header
       home (dashboard.php) et les DEUX topbars mobiles (#rkd3-mobtop).
       Un seul panneau (#rk-notif-panel) partagé par tous les déclencheurs
       (.rk-notif-toggle) ; badges (.rk-notif-count) synchronisés.
       ═══════════════════════════════════════════════════════════════════ */

  /** Bouton cloche (déclencheur). Plusieurs instances possibles par page. */
    public static function notif_bell_trigger( int $unread, string $extra_class = '' ): string {
        $badge = $unread > 0
            ? '<span class="rk-notif-count rkd3-icon-btn__badge">' . min( $unread, 9 ) . ( $unread > 9 ? '+' : '' ) . '</span>'
            : '<span class="rk-notif-count rkd3-icon-btn__badge" hidden></span>';

        // v2.9.2 — <a> au lieu de <button> : les thèmes/Elementor stylent les
        // <button> globalement. href="#" + role="button" ; le JS du widget
        // fait déjà e.preventDefault() au clic.
        return '<a href="#" role="button" class="rkd3-icon-btn rk-notif-toggle ' . esc_attr( $extra_class ) . '"'
            . ' aria-label="الإشعارات" aria-haspopup="true" aria-expanded="false">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round" aria-hidden="true">'
            . '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'
            . $badge
            . '</a>';
    }

    /** Panneau + JS. À imprimer UNE fois par page, après le header. */
    /**
     * @param string $nonce Ignore depuis 6b-3 (conserve pour compatibilite des
     *                      4 appelants). Le nonce est desormais derive du
     *                      CONTEXTE, calcule cote serveur au rendu.
     */
    public static function notif_bell_widget( string $nonce ): string {
        /*
         * ── CONTEXTE DES NOTIFICATIONS (phase 6b-3) ──────────────────────
         *
         * Les actions rk_mc_get_notifications / rk_mc_mark_notifs_read sont
         * appelees depuis 4 endroits, dont bookings.php qui est une surface
         * PARENT et dashboard.php qui est child_app : meme action, meme
         * nonce, identites opposees. Le handler n'avait aucun moyen de les
         * distinguer (blocage signale en 6b-2).
         *
         * Le contexte est determine ICI, au rendu, ou la surface est connue
         * sans ambiguite -- jamais devine a la reception, ni via Referer, ni
         * via get_current_user_id().
         *
         * Le NONCE est dedie au contexte. Un simple parametre serait
         * falsifiable (un client enverrait context=child depuis une page
         * parent) ; le nonce lie le contexte a la surface qui l'a emis, et
         * la falsification echoue cote serveur avec un 403.
         */
        $context = ( class_exists( 'RK_Identity_Context' ) && RK_Identity_Context::is_parent_surface() )
            ? 'parent'
            : 'child';
        $nonce   = wp_create_nonce( 'rk_mc_notifs_' . $context );

        ob_start();
        ?>
        <div id="rk-toast-zone" aria-live="polite" aria-atomic="false"></div>
        <div id="rk-notif-panel" hidden class="rkv2-notif-panel rkv2-notif-panel--fixed" role="dialog" aria-label="الإشعارات">
            <div class="rkv2-notif-panel-head">
                <strong>الإشعارات</strong>
                <a id="rk-notif-mark-read" href="#" role="button" class="rkv2-notif-mark">تحديد كمقروء</a>
            </div>
            <div id="rk-notif-list" class="rkv2-notif-list">
                <p class="rkv2-notif-loading">جاري التحميل…</p>
            </div>
        </div>
        <script>
      (function(){
    var cfg={ajaxUrl:<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,nonce:<?php echo wp_json_encode( $nonce ); ?>,context:<?php echo wp_json_encode( $context ); ?>},
        panel=document.getElementById('rk-notif-panel'),
        list=document.getElementById('rk-notif-list'),
        loaded=false;
    if(!panel)return;

    /* ── Helpers ─────────────────────────────────────────────── */
    function triggers(){return Array.prototype.slice.call(document.querySelectorAll('.rk-notif-toggle'));}
    function counts(){return Array.prototype.slice.call(document.querySelectorAll('.rk-notif-count'));}
    function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

    function doFetch(cb){
        var fd=new FormData();fd.append('action','rk_mc_get_notifications');fd.append('nonce',cfg.nonce||'');fd.append('context',cfg.context||'');
        fetch(cfg.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(res){cb(res&&res.success?(res.data||[]):null);})
            .catch(function(){cb(null);});
    }

    function renderList(items){
        if(!items||!items.length){list.innerHTML='<p class="rkv2-notif-loading">لا توجد إشعارات</p>';return;}
        var html='';
        items.forEach(function(n){
            var dot=n.is_read=='1'?'':'<span class="rkv2-notif-unread-dot"></span>';
            var lo=n.link?'<a href="'+esc(n.link)+'" style="text-decoration:none;color:inherit;">':'<span>';
            var lc=n.link?'</a>':'</span>';
            html+='<div class="rkv2-notif-item">'+dot+lo+'<div><p class="rkv2-notif-title">'+esc(n.title)+'</p><p class="rkv2-notif-msg">'+esc(n.message)+'</p><time>'+esc(n.date_fmt)+'</time></div>'+lc+'</div>';
        });
        list.innerHTML=html;
    }

    function fetchNotifs(){
        if(loaded)return;loaded=true;
        doFetch(function(items){
            if(items===null){list.innerHTML='<p class="rkv2-notif-loading">تعذّر التحميل</p>';return;}
            renderList(items);
        });
    }

    function markRead(){
        var fd=new FormData();fd.append('action','rk_mc_mark_notifs_read');fd.append('nonce',cfg.nonce||'');fd.append('context',cfg.context||'');
        fetch(cfg.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'});
        counts().forEach(function(c){c.hidden=true;c.textContent='';});
        panel.querySelectorAll('.rkv2-notif-unread-dot').forEach(function(el){el.remove();});
    }

    function setOpen(open){
        panel.hidden=!open;
        triggers().forEach(function(b){b.setAttribute('aria-expanded',open?'true':'false');});
    }

    /* ── TOASTS — alerte en bas de page à chaque nouvel événement ── */
    var toastZone=document.getElementById('rk-toast-zone'),lastMaxId=-1;
    function showToast(n){
        if(!toastZone)return;
        while(toastZone.children.length>=3)toastZone.removeChild(toastZone.firstChild);
        var el=document.createElement('div');
        el.className='rk-toast';
        el.setAttribute('role','status');
        el.innerHTML='<div class="rk-toast__body"><p class="rk-toast__title">'+esc(n.title)+'</p>'
            +'<p class="rk-toast__msg">'+esc(n.message)+'</p></div>'
            +'<button class="rk-toast__close" aria-label="إغلاق">✕</button>'
            +'<span class="rk-toast__bar"></span>';
        function kill(){ if(!el.parentNode)return; el.classList.add('rk-toast--out'); setTimeout(function(){el.remove();},280); }
        el.querySelector('.rk-toast__close').addEventListener('click',function(e){e.stopPropagation();kill();});
        el.addEventListener('click',function(){ kill(); setOpen(true); fetchNotifs(); });
        toastZone.appendChild(el);
        setTimeout(kill,7000);
    }

    function pollNotifs(){
        doFetch(function(items){
            if(items===null)return;
            var maxId=lastMaxId,unread=0;
            items.forEach(function(n){
                var id=parseInt(n.id,10)||0;
                if(n.is_read!='1')unread++;
                if(lastMaxId>=0&&id>lastMaxId)showToast(n);
                if(id>maxId)maxId=id;
            });
            lastMaxId=maxId;
            counts().forEach(function(c){
                if(unread>0){c.hidden=false;c.textContent=unread>9?'9+':String(unread);}
                else{c.hidden=true;c.textContent='';}
            });
            if(!panel.hidden)renderList(items);
        });
    }
    pollNotifs();                       /* 1er appel = point de départ, aucun toast */
    var notifTimer=setInterval(pollNotifs,60000);   /* puis toast pour tout id nouveau */

    /* 4.18.14 — session perdue : on coupe le polling. Sans cela, la cloche
       continuait d'interroger admin-ajax avec un nonce émis pour un parent
       déconnecté, une fois par minute, indéfiniment. */
    window.addEventListener('rk:session-lost',function(){clearInterval(notifTimer);});

    /* ── BINDING v2.9.2 — délégation : UN listener document gère toutes
       les cloches, quel que soit le moment où elles apparaissent dans
       le DOM (règle le toggle inactif). Garde anti double-inclusion. ── */
    if(window.__rkNotifBound)return;
    window.__rkNotifBound=true;

    document.addEventListener('click',function(e){
        var trigger=e.target.closest('.rk-notif-toggle');

        /* 1) Clic sur une cloche → toggle */
        if(trigger){
            e.preventDefault();e.stopPropagation();
            var opening=panel.hidden;
            setOpen(opening);
            if(opening){fetchNotifs();markRead();}
            return;
        }
        /* 2) Clic sur « تحديد كمقروء » */
        if(e.target.closest('#rk-notif-mark-read')){
            e.preventDefault();e.stopPropagation();
            markRead();
            return;
        }
        /* 3) Clic ailleurs, panneau ouvert → fermer */
        if(!panel.hidden&&!panel.contains(e.target))setOpen(false);
    });

    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!panel.hidden)setOpen(false);});
})();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /* Burger JS géré directement dans render_topbar() — identique à dashboard.php home. */

    /* ═══════════════════════════════════════════════════════════════════
       TOPBAR — sous-pages uniquement (home retourne tôt — voir ligne ~587)
       Rendu à tutor_dashboard/before/wrap priorité 0, avant ob_start.
       Position:fixed sur desktop (CSS).
       ═══════════════════════════════════════════════════════════════════ */

    public static function render_topbar(): void {
        $d = self::load_data();
        if ( empty( $d ) ) return;

        $child        = $d['child'];
        $level        = $d['level'];
        $child_xp     = (int) $d['total_points'];
        $child_level  = (int) ( $level['num'] ?? 1 );
        $child_name   = esc_html( $child->first_name ?? __( 'الطفل', 'rk-my-children' ) );
        $avatar_url   = $child->avatar_url ?? '';
        $notif_unread = (int) $d['notif_unread'];
        $notif_nonce    = wp_create_nonce( 'rk_mc_notifs' );
        $level_label    = $level['label']    ?? '';
        $level_progress = (int) ( $level['progress'] ?? 0 );
        $bm_unread      = (int) ( $d['bm_unread'] ?? 0 );
        $img_ilu        = RK_MC_URL . 'assets/img/illustrations/';

        $cp  = self::child_param();
        $nb  = home_url( '/dashboard/' );
        $nav = array(
            array( 'key' => 'index',           'label' => 'الرئيسية',    'emoji' => '🏠',  'url' => $nb . $cp ),
            array( 'key' => 'enrolled',        'label' => 'مغامراتي',    'emoji' => '🗺️', 'url' => $nb . 'enrolled-courses/' . $cp ),
            array( 'key' => 'rk-sessions',     'label' => 'لقاءات',     'emoji' => '📅',  'url' => $nb . 'rk-sessions/' . $cp ),
            array( 'key' => 'rk-messages',     'label' => 'الرسائل',     'emoji' => '💬',  'url' => $nb . 'rk-messages/' . $cp, 'badge' => $bm_unread ),
            array( 'key' => 'rk-badges',       'label' => 'الشارات',     'emoji' => '🏆',  'url' => $nb . 'rk-badges/' . $cp ),
            array( 'key' => 'question-answer', 'label' => 'سؤال وجواب', 'emoji' => '❓',  'url' => $nb . 'question-answer/' . $cp ),
            array( 'key' => 'rk-skills',       'label' => 'مهاراتي',     'emoji' => '⚡',  'url' => $nb . 'rk-skills/' . $cp ),
            array( 'key' => 'quiz-attempts',   'label' => 'الاختبارات',  'emoji' => '📝',  'url' => $nb . 'my-quiz-attempts/' . $cp ),
            array( 'key' => 'certificates',    'label' => 'شهاداتي',     'emoji' => '🎖️',  'url' => $nb . 'certificates/' . $cp ),
        );

        global $wp_query;
        $current_slug = $wp_query->query_vars['tutor_dashboard_page'] ?? '';
        $slug_to_key  = array(
            ''                 => 'index',
            'enrolled-courses' => 'enrolled',
            'rk-sessions'      => 'rk-sessions',
            'rk-messages'      => 'rk-messages',
            'rk-badges'        => 'rk-badges',
            'question-answer'  => 'question-answer',
            'rk-skills'        => 'rk-skills',
            'my-quiz-attempts'  => 'quiz-attempts',
            'certificates'     => 'certificates',
        );
        $active_key = $slug_to_key[ $current_slug ] ?? '';

        // La home (#rkd4) assemble son propre chrome dans dashboard.php.
        if ( '' === $current_slug && ! empty( $d['child'] ) ) {
            return;
        }

        /* v8.4 — Sous-pages enfant : meme chrome v4 que la home
         * (#rkd4-mobtop en haut, #rkd4-side a droite, #rkd4-botnav en bas).
         * L'ancien chrome v3 ci-dessous n'est conserve que pour le contexte
         * parent, qui n'a pas encore ete porte sur le design v4. */
        if ( ! empty( $d['child'] ) && class_exists( 'RK_MC_Dashboard_Chrome_V4' ) ) {
            /* v8.5.1 — N'imprime QUE la topbar + l'ouverture de la grille.
             * Le contenu Tutor natif doit s'imprimer entre l'ouverture et
             * la fermeture (voir render_subpage_chrome_close(), hookee sur
             * tutor_dashboard/after/wrap dans trait-rk-mc-tutor-dash-bootstrap.php). */
            RK_MC_Dashboard_Chrome_V4::render_subpage_chrome_open();
            return;
        }
        ?>
        <script>document.body.classList.add('rk-child-game-page');</script>
        <?php echo self::build_rk_nav_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <div id="rkd3-mobtop">
            <div class="rkd3-mobtop__brand">⭐ ريادة كيدز</div>
            <div class="rkd3-mobtop__actions">
                <?php echo self::notif_bell_trigger( $notif_unread, 'rkd3-mobtop__bell' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <button class="rkd3-burger" aria-label="القائمة" aria-expanded="false">☰</button>
            </div>
        </div>
        <header class="rkd3-header rkd3-header--fixed" dir="rtl">
            <button id="rkd3-burger" class="rkd3-burger" aria-label="فتح القائمة" aria-expanded="false" aria-controls="rkd3-sidebar">
                <span></span><span></span><span></span>
            </button>
            <div class="rkd3-header__greet">
                <h1 class="rkd3-header__title"><?php echo esc_html( $child_name ); ?> <span class="rkd3-wave">👋</span></h1>
                <p class="rkd3-header__sub"><?php echo esc_html( $level_label ); ?></p>
            </div>
            <div class="rkd3-header__stats">
                <div class="rkd3-stat-pill">
                    <span class="rkd3-stat-pill__icon">⭐</span>
                    <div>
                        <div class="rkd3-stat-pill__val"><?php echo number_format( $child_xp ); ?></div>
                        <div class="rkd3-stat-pill__label">نقاط XP</div>
                    </div>
                </div>
                <div class="rkd3-stat-pill rkd3-stat-pill--level">
                    <span class="rkd3-stat-pill__icon">🎖️</span>
                    <div style="flex:1">
                        <div class="rkd3-stat-pill__val"><?php echo (int) $child_level; ?></div>
                        <div class="rkd3-stat-pill__label">المستوى</div>
                        <div class="rkd3-mini-bar"><div class="rkd3-mini-bar__fill" style="width:<?php echo (int) $level_progress; ?>%"></div></div>
                    </div>
                </div>
            </div>
            <div class="rkd3-header__right">
                <?php echo self::notif_bell_trigger( $notif_unread ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <div class="rkd3-header__avatar">
                    <?php if ( ! empty( $avatar_url ) ) : ?>
                    <img src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="44" height="44">
                    <?php else : ?>
                    <img src="<?php echo esc_url( $img_ilu . 'character-banana.png' ); ?>" alt="" width="44" height="44">
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <?php echo self::notif_bell_widget( $notif_nonce ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <div id="rkd3-drawer-overlay" hidden aria-hidden="true"></div>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
            var sidebar=document.getElementById('rkd3-sidebar'),
                overlay=document.getElementById('rkd3-drawer-overlay');
            if(!sidebar)return;
            function open(){sidebar.classList.add('is-open');if(overlay){overlay.hidden=false;overlay.removeAttribute('aria-hidden');}document.body.style.overflow='hidden';document.querySelectorAll('.rkd3-burger').forEach(function(b){b.setAttribute('aria-expanded','true');});}
            function close(){sidebar.classList.remove('is-open');if(overlay){overlay.hidden=true;overlay.setAttribute('aria-hidden','true');}document.body.style.overflow='';document.querySelectorAll('.rkd3-burger').forEach(function(b){b.setAttribute('aria-expanded','false');});}
            document.querySelectorAll('.rkd3-burger').forEach(function(b){b.addEventListener('click',function(){sidebar.classList.contains('is-open')?close():open();});});
            overlay&&overlay.addEventListener('click',close);
            document.addEventListener('keydown',function(e){if(e.key==='Escape'&&sidebar.classList.contains('is-open'))close();});
        });
        </script>
        <?php
    }

}
