<?php
/**
 * RiadaKids\Children\ChildRepository
 *
 * Accès aux données des profils enfants.
 */

namespace RiadaKids\Children;

use RiadaKids\Database\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

class ChildRepository {

    public static function create(
        int $user_id,
        string $name,
        int $age,
        string $family = '',
        string $avatar_url = '',
        string $username = ''
    ): int {
        return DB::insert_child( [
            'user_id'           => $user_id,
            'child_name'        => $name,
            'child_family_name' => $family,
            'child_username'    => $username,
            'child_age'         => $age,
            'avatar_url'        => $avatar_url,
        ] );
    }

    public static function get_by_user( int $user_id ): array {
        return DB::get_user_children( $user_id );
    }
}