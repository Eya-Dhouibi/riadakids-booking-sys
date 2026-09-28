<?php
declare( strict_types=1 );
/**
 * RK_MC_Skill_Service  (v6.0.0 — Sprint 3)
 * Quwwa al-tifl (les "pouvoirs" de l'enfant) — gérés par le coach.
 *
 * @package RK_My_Children
 * @since   6.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class RK_MC_Skill_Service {

    public static function definitions(): array {
        return [
            'speech'     => [ 'icon_key' => 'skill-speech',    'name' => __( 'قوة الكلام',   'rk-my-children' ), 'max' => 10 ],
            'teamwork'   => [ 'icon_key' => 'users',           'name' => __( 'قوة التعاون',  'rk-my-children' ), 'max' => 10 ],
            'creativity' => [ 'icon_key' => 'skill-creativity','name' => __( 'قوة الأفكار',  'rk-my-children' ), 'max' => 10 ],
            'courage'    => [ 'icon_key' => 'trophy',          'name' => __( 'قوة الشجاعة',  'rk-my-children' ), 'max' => 10 ],
            'leadership' => [ 'icon_key' => 'rocket',          'name' => __( 'قوة القيادة',  'rk-my-children' ), 'max' => 10 ],
            'focus'      => [ 'icon_key' => 'nav-target',      'name' => __( 'قوة التركيز',  'rk-my-children' ), 'max' => 10 ],
        ];
    }

    public static function get_skills( int $child_id ): array {
        $map = class_exists( 'RKP_SkillRepository' )
            ? RKP_SkillRepository::get_skills( $child_id )
            : [];
        $out = [];
        foreach ( self::definitions() as $key => $def ) {
            $level = isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
            $out[] = array_merge( $def, [
                'key'   => $key,
                'level' => $level,
                'pct'   => (int) round( $level / $def['max'] * 100 ),
            ] );
        }
        return $out;
    }

    public static function set_skill( int $child_id, string $skill_key, int $level ): bool {
        if ( ! isset( self::definitions()[ $skill_key ] ) ) return false;
        $level = max( 0, min( 10, $level ) );
        if ( ! class_exists( 'RKP_SkillRepository' ) ) return false;
        $ok = RKP_SkillRepository::set_skill( $child_id, $skill_key, $level );
        if ( $ok ) {
            do_action( 'rk_mc_skill_updated', $child_id, $skill_key, $level );
        }
        return $ok;
    }

    /** Retourne les données pour un graphique radar (0-100) */
    public static function get_radar_data( int $child_id ): array {
        return array_map( fn($s) => [ 'label' => $s['name'], 'value' => $s['pct'], 'icon' => $s['icon'] ],
            self::get_skills( $child_id ) );
    }
}

