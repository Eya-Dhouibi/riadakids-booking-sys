<?php
declare( strict_types=1 );
/**
 * RiadaKids — Tutor Dashboard: مهاراتي / قوتي
 *
 * @package RK_My_Children
 * @since   9.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$child = RK_MC_Tutor_Dashboard::get_child_for_template();
if ( ! $child ) {
    echo '<p dir="rtl">' . esc_html__( 'يرجى تحديد الطفل أولاً.', 'rk-my-children' ) . '</p>';
    return;
}
$child_id = (int) $child->id;

$skills = class_exists( 'RK_MC_Skill_Service' )
    ? RK_MC_Skill_Service::get_skills( $child_id ) : [];

/* ── Historique des compétences ───────────────────────────────────── */
$skill_history = [];
$assessments   = [];
if ( class_exists( 'RK_MC_Assessment_Service' ) ) {
    $assessments = RK_MC_Assessment_Service::get_all( $child_id );  // newest first
    $evals = array_reverse( $assessments );                          // chronological for chart
    foreach ( $evals as $ev ) {
        if ( empty( $ev['skill_scores'] ) || empty( $ev['assessed_at'] ) ) continue;
        $skill_history[] = [
            'label'  => date_i18n( 'M j', strtotime( $ev['assessed_at'] ) ),
            'date'   => $ev['assessed_at'],
            'scores' => (array) $ev['skill_scores'],
        ];
    }
    $skill_history = array_slice( $skill_history, -8 );
}

$skill_colors = [
    'speech'     => '#e8500a',
    'teamwork'   => '#1b4f8c',
    'creativity' => '#8b5cf6',
    'courage'    => '#f59e0b',
    'leadership' => '#10b981',
    'focus'      => '#3b82f6',
];

$skill_names_ar = [
    'speech'     => 'التعبير والكلام',
    'teamwork'   => 'العمل الجماعي',
    'creativity' => 'الإبداع',
    'courage'    => 'الشجاعة',
    'leadership' => 'القيادة',
    'focus'      => 'التركيز',
];

$tier_data = [
    'low'  => [ 'emoji' => '🌱', 'label' => 'مبتدئ',  'bg' => '#f0fdf4', 'color' => '#16a34a' ],
    'mid'  => [ 'emoji' => '🔥', 'label' => 'متطور',   'bg' => '#fff7ed', 'color' => '#ea580c' ],
    'high' => [ 'emoji' => '⭐', 'label' => 'متقدم',   'bg' => '#fefce8', 'color' => '#ca8a04' ],
    'max'  => [ 'emoji' => '👑', 'label' => 'خبير',    'bg' => '#fdf4ff', 'color' => '#9333ea' ],
];

$level_tier = static function( int $level ): string {
    if ( $level === 0 ) return '';
    if ( $level <= 3  ) return 'low';
    if ( $level <= 6  ) return 'mid';
    if ( $level <= 8  ) return 'high';
    return 'max';
};
?>
<style>
/* ── التقييمات ───────────────────────────────────────────────────── */
.rk-evals { margin-top:32px; }
.rk-evals__title,
.rk-remarks__title {
    display:flex; align-items:center; gap:8px;
    font-size:1.05rem; font-weight:700; color:#0D1F35;
    margin:0 0 14px; font-family:Cairo,Tajawal,sans-serif;
}
.rk-evals__title-icon,
.rk-remarks__title-icon { font-size:1.2rem; }
.rk-evals__list { display:flex; flex-direction:column; gap:14px; }
.rk-eval-card {
    background:#fff; border:1.5px solid #e8edf4; border-radius:14px;
    padding:16px 18px; box-shadow:0 2px 8px rgba(0,0,0,.04);
}
.rk-eval-card__header {
    display:flex; align-items:flex-start; justify-content:space-between; gap:10px;
    margin-bottom:10px;
}
.rk-eval-card__meta { display:flex; flex-direction:column; gap:2px; }
.rk-eval-card__date {
    font-size:.78rem; color:#64748b; font-family:Cairo,Tajawal,sans-serif;
}
.rk-eval-card__coach {
    font-size:.82rem; font-weight:600; color:var(--e-global-color-secondary,#1B4F8C);
    font-family:Cairo,Tajawal,sans-serif;
}
.rk-eval-card__stars {
    font-size:1.1rem; color:#f59e0b; letter-spacing:1px; flex-shrink:0; direction:ltr;
}
.rk-eval-card__summary {
    font-size:.88rem; color:#374151; line-height:1.65;
    margin:0 0 10px; font-family:Cairo,Tajawal,sans-serif;
}
.rk-eval-card__block { margin-top:10px; }
.rk-eval-card__block-label {
    display:inline-block; font-size:.8rem; font-weight:700;
    margin-bottom:6px; font-family:Cairo,Tajawal,sans-serif;
}
.rk-eval-card__block--strengths .rk-eval-card__block-label { color:#16a34a; }
.rk-eval-card__block--devs      .rk-eval-card__block-label { color:var(--e-global-color-secondary,#1B4F8C); }
.rk-eval-card__items {
    margin:0; padding:0 16px 0 0; display:flex; flex-direction:column; gap:5px; list-style:disc;
}
.rk-eval-card__block--strengths .rk-eval-card__items { color:#15803d; }
.rk-eval-card__block--devs      .rk-eval-card__items { color:#1e40af; }
.rk-eval-card__items li { font-size:.84rem; line-height:1.5; font-family:Cairo,Tajawal,sans-serif; }
/* ── ملاحظات المدرب ─────────────────────────────────────────────── */
.rk-remarks { margin-top:28px; }
.rk-remarks__list { display:flex; flex-direction:column; gap:12px; }
.rk-remark-card {
    background:linear-gradient(135deg,#f0f7ff 0%,#f8f9ff 100%);
    border:1.5px solid #bfdbfe; border-radius:12px;
    padding:14px 18px; position:relative;
}
.rk-remark-card::before {
    content:''; position:absolute; right:18px; top:-8px;
    width:0; height:0;
    border-left:8px solid transparent; border-right:8px solid transparent;
    border-bottom:8px solid #bfdbfe;
}
.rk-remark-card__meta { margin-bottom:6px; }
.rk-remark-card__date {
    font-size:.76rem; color:#64748b; font-family:Cairo,Tajawal,sans-serif;
}
.rk-remark-card__text {
    font-size:.88rem; color:#1e3a5f; line-height:1.7;
    margin:0; font-family:Cairo,Tajawal,sans-serif; font-style:italic;
}
</style>
<div class="rk-td-page-skills" dir="rtl">

    <h2 class="rk-td-page-title">
        <?php echo wp_kses_post( rk_mc_svg( 'chart', [ 'class' => 'rk-td-page-title__icon' ] ) ); ?>
        <?php esc_html_e( 'مهاراتي / قوتي', 'rk-my-children' ); ?>
    </h2>

    <?php if ( $skills ) : ?>

    <!-- ── Barres de compétences ────────────────────────────────── -->
    <div class="rk-skills-list">
        <?php foreach ( $skills as $idx => $s ) :
            $key   = $s['key']      ?? '';
            $label = $skill_names_ar[ $key ] ?? ( $s['name'] ?? $key );
            $icon  = $s['icon_key'] ?? 'chart';
            $level = (int) ( $s['level'] ?? 0 );
            $max   = (int) ( $s['max']   ?? 10 );
            $pct   = $max ? (int) round( $level / $max * 100 ) : 0;
            $tier  = $level_tier( $level );
            $color = $skill_colors[ $key ] ?? 'var(--e-global-color-secondary,#1B4F8C)';
            $td    = $tier ? ( $tier_data[ $tier ] ?? null ) : null;
            $delay = round( $idx * 0.09, 2 );
        ?>
        <div class="rk-skill">
            <div class="rk-skill__icon" aria-hidden="true"
                 style="background:<?php echo esc_attr( $color ); ?>18;border:2px solid <?php echo esc_attr( $color ); ?>30;">
                <?php echo wp_kses_post( rk_mc_svg( $icon ) ); ?>
            </div>
            <div class="rk-skill__body">
                <div class="rk-skill__header">
                    <span class="rk-skill__name"><?php echo esc_html( $label ); ?></span>
                    <div class="rk-skill__meta-row">
                        <?php if ( $td ) : ?>
                        <span class="rk-skill__tier-badge"
                              style="background:<?php echo esc_attr( $td['bg'] ); ?>;color:<?php echo esc_attr( $td['color'] ); ?>;">
                            <?php echo esc_html( $td['emoji'] . ' ' . $td['label'] ); ?>
                        </span>
                        <?php endif; ?>
                        <span class="rk-skill__score" style="color:<?php echo esc_attr( $color ); ?>;">
                            <?php echo $level; ?><span style="color:#94a3b8;font-size:.75em;">/<?php echo $max; ?></span>
                        </span>
                    </div>
                </div>
                <div class="rk-skill__bar" role="progressbar"
                     aria-valuenow="<?php echo $level; ?>" aria-valuemin="0" aria-valuemax="<?php echo $max; ?>">
                    <div class="rk-skill__fill"
                         style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $color ); ?>;--rk-bar-delay:<?php echo $delay; ?>s;"
                         <?php echo $tier ? 'data-level="' . esc_attr( $tier ) . '"' : ''; ?>></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ( count( $skill_history ) >= 2 ) : ?>

    <!-- ── Graphique évolution dans le temps ────────────────────── -->
    <div class="rk-skills-chart-section">
        <h3 class="rk-skills-chart-title"><?php esc_html_e( 'تطور المهارات عبر الزمن', 'rk-my-children' ); ?></h3>
        <div class="rk-skills-chart-wrap" id="rk-skills-chart">
            <canvas id="rk-skills-canvas" height="200" style="width:100%;max-width:600px;"></canvas>
        </div>
        <div class="rk-skills-chart-legend" id="rk-skills-legend"></div>
    </div>

    <script>
    (function(){
        var history = <?php echo wp_json_encode( $skill_history ); ?>;
        var skillColors = <?php echo wp_json_encode( $skill_colors ); ?>;
        var skillNamesAr = <?php echo wp_json_encode( $skill_names_ar ); ?>;
        var canvas = document.getElementById('rk-skills-canvas');
        if ( ! canvas || ! canvas.getContext ) return;
        canvas.width = canvas.offsetWidth || 560;

        var ctx   = canvas.getContext('2d');
        var W     = canvas.width;
        var H     = canvas.height;
        var pad   = { top: 20, right: 20, bottom: 40, left: 36 };
        var chartW = W - pad.left - pad.right;
        var chartH = H - pad.top - pad.bottom;
        var defaultColors = ['#e8500a','#1b4f8c','#8b5cf6','#f59e0b','#10b981','#3b82f6'];

        var skillKeys = [];
        history.forEach(function(p){ Object.keys(p.scores).forEach(function(k){ if(skillKeys.indexOf(k)===-1) skillKeys.push(k); }); });

        var labels = history.map(function(p){ return p.label; });
        var n      = labels.length;
        var xStep  = n > 1 ? chartW / (n-1) : chartW;

        ctx.clearRect(0,0,W,H);

        ctx.strokeStyle = '#f1f5f9'; ctx.lineWidth = 1;
        for(var gi=0;gi<=5;gi++){
            var gy = pad.top + (chartH/5)*gi;
            ctx.beginPath(); ctx.moveTo(pad.left,gy); ctx.lineTo(W-pad.right,gy); ctx.stroke();
        }

        ctx.fillStyle = '#94a3b8'; ctx.font = '11px Cairo,Tajawal,sans-serif'; ctx.textAlign = 'right';
        for(var yi=0;yi<=10;yi+=2){
            var ypos = pad.top + chartH - (yi/10)*chartH;
            ctx.fillText(yi, pad.left-6, ypos+4);
        }

        ctx.textAlign = 'center'; ctx.fillStyle = '#64748b';
        labels.forEach(function(lbl,i){ ctx.fillText(lbl, pad.left + i*xStep, H-8); });

        skillKeys.forEach(function(key, ki){
            var color = skillColors[key] || defaultColors[ki % defaultColors.length];
            var points = history.map(function(p){ return parseFloat(p.scores[key]||0); });

            ctx.beginPath();
            points.forEach(function(v,i){
                var x = pad.left + i*xStep, y = pad.top + chartH - (v/10)*chartH;
                if(i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
            });
            ctx.lineTo(pad.left+(n-1)*xStep, pad.top+chartH);
            ctx.lineTo(pad.left, pad.top+chartH);
            ctx.closePath();
            ctx.fillStyle = color+'18'; ctx.fill();

            ctx.beginPath(); ctx.strokeStyle = color; ctx.lineWidth = 2.5; ctx.lineJoin = 'round';
            points.forEach(function(v,i){
                var x = pad.left + i*xStep, y = pad.top + chartH - (v/10)*chartH;
                if(i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
            });
            ctx.stroke();

            points.forEach(function(v,i){
                var x = pad.left + i*xStep, y = pad.top + chartH - (v/10)*chartH;
                ctx.beginPath(); ctx.arc(x,y,4,0,Math.PI*2);
                ctx.fillStyle = '#fff'; ctx.fill();
                ctx.strokeStyle = color; ctx.lineWidth = 2; ctx.stroke();
            });
        });

        var legend = document.getElementById('rk-skills-legend');
        if(legend){
            skillKeys.forEach(function(key,ki){
                var color = skillColors[key] || defaultColors[ki%defaultColors.length];
                var name = skillNamesAr[key] || key;
                var span = document.createElement('span');
                span.className = 'rk-skills-legend-item';
                span.innerHTML = '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:'+color+';margin-left:5px;vertical-align:middle;"></span>'
                               + '<span style="font-size:.78rem;color:#475569;">'+name+'</span>';
                legend.appendChild(span);
            });
        }
    })();
    </script>

    <?php endif; ?>

    <?php else : ?>
    <div class="rk-empty rk-empty--skills">
        <?php echo wp_kses_post( rk_mc_svg( 'chart' ) ); ?>
        <p><?php esc_html_e( 'ستظهر مهاراتك هنا بعد أولى تقييماتك', 'rk-my-children' ); ?></p>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $assessments ) ) : ?>

    <!-- ── التقييمات ────────────────────────────────────────────── -->
    <section class="rk-evals" dir="rtl">
        <h3 class="rk-evals__title">
            <span class="rk-evals__title-icon">📋</span>
            <?php esc_html_e( 'التقييمات من المدرب', 'rk-my-children' ); ?>
        </h3>
        <div class="rk-evals__list">
        <?php foreach ( $assessments as $ev ) :
            $rating  = max( 1, min( 5, (int) ( $ev['rating'] ?? 3 ) ) );
            $stars   = str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );
            $date_fmt = ! empty( $ev['assessed_at'] )
                        ? date_i18n( 'j F Y', strtotime( $ev['assessed_at'] ) )
                        : '';
            $summary   = trim( $ev['summary'] ?? '' );
            $strengths = is_array( $ev['strengths'] )    ? array_filter( $ev['strengths'] )    : [];
            $devs      = is_array( $ev['developments'] ) ? array_filter( $ev['developments'] ) : [];
        ?>
        <div class="rk-eval-card">
            <div class="rk-eval-card__header">
                <div class="rk-eval-card__meta">
                    <?php if ( $date_fmt ) : ?>
                    <span class="rk-eval-card__date"><?php echo esc_html( $date_fmt ); ?></span>
                    <?php endif; ?>
                    <?php if ( ! empty( $ev['coach_name'] ) ) : ?>
                    <span class="rk-eval-card__coach"><?php echo esc_html( $ev['coach_name'] ); ?></span>
                    <?php endif; ?>
                </div>
                <span class="rk-eval-card__stars" aria-label="<?php echo esc_attr( $rating . '/5' ); ?>">
                    <?php echo esc_html( $stars ); ?>
                </span>
            </div>
            <?php if ( $summary ) : ?>
            <p class="rk-eval-card__summary"><?php echo esc_html( $summary ); ?></p>
            <?php endif; ?>
            <?php if ( $strengths ) : ?>
            <div class="rk-eval-card__block rk-eval-card__block--strengths">
                <span class="rk-eval-card__block-label">💪 <?php esc_html_e( 'نقاط القوة', 'rk-my-children' ); ?></span>
                <ul class="rk-eval-card__items">
                    <?php foreach ( $strengths as $s ) : ?>
                    <li><?php echo esc_html( $s ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php if ( $devs ) : ?>
            <div class="rk-eval-card__block rk-eval-card__block--devs">
                <span class="rk-eval-card__block-label">📈 <?php esc_html_e( 'محاور التطوير', 'rk-my-children' ); ?></span>
                <ul class="rk-eval-card__items">
                    <?php foreach ( $devs as $d ) : ?>
                    <li><?php echo esc_html( $d ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </section>

    <!-- ── ملاحظات المدرب ────────────────────────────────────────── -->
    <?php
    $notes_evals = array_values( array_filter( $assessments, function( $ev ) {
        return ! empty( trim( $ev['parent_message'] ?? '' ) );
    } ) );
    if ( $notes_evals ) :
    ?>
    <section class="rk-remarks" dir="rtl">
        <h3 class="rk-remarks__title">
            <span class="rk-remarks__title-icon">💬</span>
            <?php esc_html_e( 'ملاحظات المدرب', 'rk-my-children' ); ?>
        </h3>
        <div class="rk-remarks__list">
        <?php foreach ( $notes_evals as $ev ) :
            $date_fmt = ! empty( $ev['assessed_at'] )
                        ? date_i18n( 'j F Y', strtotime( $ev['assessed_at'] ) )
                        : '';
        ?>
        <div class="rk-remark-card">
            <?php if ( $date_fmt ) : ?>
            <div class="rk-remark-card__meta">
                <span class="rk-remark-card__date"><?php echo esc_html( $date_fmt ); ?></span>
            </div>
            <?php endif; ?>
            <p class="rk-remark-card__text"><?php echo esc_html( trim( $ev['parent_message'] ) ); ?></p>
        </div>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php endif; ?>

</div>
