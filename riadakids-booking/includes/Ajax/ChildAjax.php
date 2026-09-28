<?php
/**
 * RiadaKids\Ajax\ChildAjax
 *
 * Handlers AJAX pour la gestion des profils enfants.
 *
 * @package RiadaKids\Ajax
 */

namespace RiadaKids\Ajax;

use RiadaKids\Core\Security;
use RiadaKids\Children\ChildRepository;
use RiadaKids\Children\ChildValidator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ChildAjax {

    /**
     * Constructeur - Enregistrement des hooks AJAX de WordPress.
     */
    public function __construct() {
        add_action( 'wp_ajax_rk_add_child', [ $this, 'add_child' ] );
        add_action( 'wp_ajax_nopriv_rk_add_child', [ $this, 'add_child' ] );

        // Upload de la photo de l'enfant (réservé aux utilisateurs connectés :
        // un visiteur anonyme n'a aucune raison d'écrire dans la médiathèque).
        add_action( 'wp_ajax_rk_upload_child_avatar', [ $this, 'upload_avatar' ] );
    }

    /**
     * Crée un profil enfant et retourne ses données pour l'injection dynamique.
     *
     * @return void
     */
    public function add_child(): void {
        // Validation de sécurité (Nonce et intégrité de la requête)
        Security::verify_booking_request();

        $user_id = get_current_user_id();

        // ── TRACE DEBUG ── inspection des données brutes reçues par le serveur
        error_log( '[RK-DEBUG] execution de add_child | POST raw: ' . print_r( $_POST, true ) );

        // Récupération et assainissement strict
        $name     = sanitize_text_field( $_POST['child_name'] ?? $_POST['name'] ?? '' );
        $family   = sanitize_text_field( $_POST['child_family_name'] ?? '' );
        $username = sanitize_user( $_POST['child_username'] ?? '', true );
        $age      = absint( $_POST['child_age']  ?? $_POST['age']  ?? 0 );
        $avatar   = esc_url_raw( $_POST['avatar_url'] ?? '' );

        // L'URL doit provenir de CE site : un lien externe fourni par le client
        // serait affiché tel quel dans le tableau de bord.
        if ( $avatar && 0 !== strpos( $avatar, content_url() ) ) {
            $avatar = '';
        }

        error_log( "[RK-DEBUG] Valeurs filtres — Name: '$name', Family: '$family', Age: $age" );

        // Validation des règles métier
        $validator = new ChildValidator();
        if ( ! $validator->validate( $name, $age, $family, $username ) ) {
            error_log( '[RK-DEBUG] Echec de validation : ' . $validator->get_first_error() );
            wp_send_json_error( [ 'msg' => $validator->get_first_error() ] );
        }

        // Insertion en base de données via le Repository
        $child_id = ChildRepository::create( $user_id, $name, $age, $family, $avatar, $username );

        if ( ! $child_id ) {
            error_log( '[RK-DEBUG] Echec critique : DB::insert_child a retourne 0 ou false' );
            wp_send_json_error( [ 'msg' => 'خطأ في إنشاء ملف الطفل' ] );
        }

        error_log( "[RK-DEBUG] Succes ! Enfant cree avec l'ID: " . $child_id );

        // Déclenche la création du wp_user enfant + génération du PIN côté
        // rk-platform (RK_MC_Child_User::on_child_created), au lieu d'attendre
        // qu'une réservation soit confirmée (rk_booking_confirmed). Sans cet
        // appel, un enfant ajouté ici n'a ni رمز الطفل ni accès à فضاء الطفل
        // tant qu'aucune réservation n'a été validée pour lui.
        do_action( 'rk_mc_child_inserted', $child_id, $user_id );

        wp_send_json_success( [
            'child_id'          => $child_id,
            'child_name'        => $name,
            'child_family_name' => $family,
            'child_username'    => $username,
            'child_full_name'   => trim( $name . ' ' . $family ),
            'child_age'         => $age,
            'avatar_url'        => $avatar,
        ] );
    }

    /**
     * Upload de la photo de l'enfant vers la médiathèque WordPress.
     *
     * Renvoie l'URL taille 'medium', que le formulaire stocke dans un champ
     * caché avant la création du profil.
     *
     * @return void
     */
    public function upload_avatar(): void {
        Security::verify_booking_request();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'msg' => 'يجب تسجيل الدخول أولاً.' ], 401 );
        }

        if ( empty( $_FILES['avatar_file'] ) ) {
            wp_send_json_error( [ 'msg' => 'لم يتم اختيار أي صورة.' ], 400 );
        }

        $file = $_FILES['avatar_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        // Garde-fous AVANT toute écriture disque.
        $allowed = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $type    = (string) ( $file['type'] ?? '' );
        $size    = (int) ( $file['size'] ?? 0 );

        if ( ! in_array( $type, $allowed, true ) ) {
            wp_send_json_error( [ 'msg' => 'الملف يجب أن يكون صورة (JPG, PNG, GIF, WebP).' ], 415 );
        }
        if ( $size <= 0 || $size > 2 * 1024 * 1024 ) {
            wp_send_json_error( [ 'msg' => 'حجم الصورة يجب ألا يتجاوز 2 ميغابايت.' ], 413 );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'avatar_file', 0, [
            'post_title' => sanitize_text_field( $_POST['child_name'] ?? 'صورة طفل' ),
        ] );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'msg' => $attachment_id->get_error_message() ], 500 );
        }

        // Traçabilité dans la médiathèque.
        update_post_meta( $attachment_id, '_rk_child_avatar', 1 );
        update_post_meta( $attachment_id, '_rk_parent_user', get_current_user_id() );

        $url = wp_get_attachment_image_url( $attachment_id, 'medium' )
            ?: wp_get_attachment_url( $attachment_id );

        wp_send_json_success( [
            'url'           => esc_url_raw( (string) $url ),
            'attachment_id' => (int) $attachment_id,
        ] );
    }
}