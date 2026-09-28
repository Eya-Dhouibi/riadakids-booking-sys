<?php
/**
 * RiadaKids\Frontend\BookingForm — Version corrigée v6
 * Mises à jour : Correction du calcul des crédits pour n'afficher que les enfants sélectionnés.
 *
 * @package RiadaKids\Frontend
 */

namespace RiadaKids\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

class BookingForm {

    public static function render_static(
        int   $user_id,
        int   $credits,
        bool  $has_credits,
        array $programs,
        array $children,
        array $user_bookings
    ): void {
        $last_booking_id = ! empty( $user_bookings ) ? (int) $user_bookings[0]->id : 0;
        self::render( $user_id, $credits, $has_credits, $programs, $children, $user_bookings, $last_booking_id );
    }

    public static function render(
        int   $user_id,
        int   $credits,
        bool  $has_credits,
        array $programs,
        array $children,
        array $user_bookings,
        int   $last_booking_id
    ): void {
        /*
         * BUG #1 CORRIGÉ : Le bloc <script>window.rkConfig = {...}</script>
         * qui se trouvait ici a été SUPPRIMÉ.
         * Toute la configuration est désormais gérée exclusivement par Assets.php.
         */
        $rk_uid_token = wp_create_nonce( 'rk_uid_' . $user_id );
        ?>
        <div class="rk-booking-page" dir="rtl">

            <?php self::render_hero( $credits, $has_credits ); ?>

            <div class="rk-steps">
                <div class="rk-step active" data-step="1">
                    <span class="rk-step-icon">
                        <svg width="26" height="26" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M10.5002 2.09998V10.5L13.6502 7.34998L16.8002 10.5V2.09998" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M4.2002 20.475V4.72498C4.2002 4.02878 4.47676 3.3611 4.96904 2.86882C5.46132 2.37654 6.129 2.09998 6.8252 2.09998H19.9502C20.2287 2.09998 20.4957 2.2106 20.6927 2.40751C20.8896 2.60443 21.0002 2.8715 21.0002 3.14998V22.05C21.0002 22.3285 20.8896 22.5955 20.6927 22.7924C20.4957 22.9894 20.2287 23.1 19.9502 23.1H6.8252C6.129 23.1 5.46132 22.8234 4.96904 22.3311C4.47676 21.8388 4.2002 21.1712 4.2002 20.475ZM4.2002 20.475C4.2002 19.7788 4.47676 19.1111 4.96904 18.6188C5.46132 18.1265 6.129 17.85 6.8252 17.85H21.0002" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="rk-step-label">الخطوة 1</span>
                    <strong>البرنامج</strong>
                </div>
                <div class="rk-step" data-step="2">
                    <span class="rk-step-icon">
                        <svg width="26" height="26" viewBox="0 0 26 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M12.6001 5.24982V22.0498" stroke="currentColor" stroke-width="1.575" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M16.8003 13.65H18.9003" stroke="currentColor" stroke-width="1.575" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M16.8003 9.44989H18.9003" stroke="currentColor" stroke-width="1.575" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M21.0011 19.9499C21.5579 19.9496 22.0918 19.7283 22.4854 19.3345C22.879 18.9407 23.1001 18.4067 23.1001 17.8499V5.2499C23.1001 4.69313 22.879 4.15915 22.4854 3.76535C22.0918 3.37155 21.5579 3.15018 21.0011 3.1499L16.8001 3.152C15.9852 3.15176 15.1815 3.3412 14.4525 3.70532C13.7236 4.06944 13.0893 4.59826 12.6001 5.2499C12.1111 4.59787 11.477 4.06865 10.748 3.70416C10.019 3.33966 9.21513 3.1499 8.4001 3.1499H4.2001C3.64314 3.1499 3.109 3.37115 2.71517 3.76498C2.32135 4.1588 2.1001 4.69295 2.1001 5.2499V17.8499C2.1001 18.4067 2.3212 18.9407 2.7148 19.3345C3.1084 19.7283 3.64228 19.9496 4.19905 19.9499H8.4001C9.21513 19.9499 10.019 20.1397 10.748 20.5042C11.477 20.8687 12.1111 21.3979 12.6001 22.0499C13.0891 21.3979 13.7232 20.8687 14.4522 20.5042C15.1812 20.1397 15.9851 19.9499 16.8001 19.9499H21.0011Z" stroke="currentColor" stroke-width="1.575" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M6.30005 13.65H8.40005" stroke="currentColor" stroke-width="1.575" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M6.30005 9.44989H8.40005" stroke="currentColor" stroke-width="1.575" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="rk-step-label">الخطوة 2</span>
                    <strong>الدورة</strong>
                </div>
                <div class="rk-step" data-step="3">
                    <span class="rk-step-icon">
                        <svg width="27" height="27" viewBox="0 0 27 27" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M18 14.6251L23.8759 18.5424C23.9606 18.5987 24.059 18.6311 24.1606 18.6359C24.2623 18.6408 24.3633 18.618 24.453 18.57C24.5427 18.5219 24.6177 18.4505 24.67 18.3632C24.7223 18.2759 24.7499 18.1761 24.75 18.0744V8.85386C24.75 8.75489 24.7239 8.65766 24.6744 8.57199C24.6248 8.48633 24.5535 8.41525 24.4677 8.36595C24.3819 8.31665 24.2846 8.29087 24.1856 8.2912C24.0866 8.29154 23.9895 8.31798 23.904 8.36787L18 11.8126" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M15.75 6.75006H4.5C3.25736 6.75006 2.25 7.75742 2.25 9.00006V18.0001C2.25 19.2427 3.25736 20.2501 4.5 20.2501H15.75C16.9926 20.2501 18 19.2427 18 18.0001V9.00006C18 7.75742 16.9926 6.75006 15.75 6.75006Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="rk-step-label">الخطوة 3</span>
                    <strong>اللقاء</strong>
                </div>
                <div class="rk-step" data-step="4">
                    <span class="rk-step-icon">
                        <svg width="27" height="27" viewBox="0 0 27 27" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M11.25 18.0001C11.8125 18.3376 12.6 18.5626 13.5 18.5626C14.4 18.5626 15.1875 18.3376 15.75 18.0001" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M16.875 13.5001H16.8855" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M21.8026 7.66469C22.5913 8.81151 23.1351 10.1086 23.4001 11.4751C23.7805 11.6593 24.1013 11.947 24.3258 12.3051C24.5502 12.6633 24.6693 13.0774 24.6693 13.5001C24.6693 13.9227 24.5502 14.3369 24.3258 14.695C24.1013 15.0532 23.7805 15.3408 23.4001 15.5251C22.9144 17.7902 21.6666 19.8203 19.865 21.2767C18.0633 22.733 15.8167 23.5274 13.5001 23.5274C11.1834 23.5274 8.93682 22.733 7.13516 21.2767C5.3335 19.8203 4.08573 17.7902 3.60006 15.5251C3.21965 15.3408 2.89883 15.0532 2.67435 14.695C2.44987 14.3369 2.33081 13.9227 2.33081 13.5001C2.33081 13.0774 2.44987 12.6633 2.67435 12.3051C2.89883 11.947 3.21965 11.6593 3.60006 11.4751C4.06611 9.19188 5.30544 7.13933 7.10896 5.66372C8.91249 4.18811 11.1698 3.37976 13.5001 3.37506C15.7501 3.37506 17.4376 4.61256 17.4376 6.18756C17.4376 7.76256 16.4251 9.00006 15.1876 9.00006C14.2876 9.00006 13.5001 8.55006 13.5001 7.87506" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M10.125 13.5001H10.1355" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="rk-step-label">الخطوة 4</span>
                    <strong>الطفل</strong>
                </div>
                <div class="rk-step" data-step="5">
                    <span class="rk-step-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M15.6001 13.6501V15.7951L17.1601 16.7701" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M15.6001 1.95007V4.87507" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M20.475 7.15454V4.87499C20.475 4.35782 20.2696 3.86183 19.9039 3.49613C19.5382 3.13043 19.0422 2.92499 18.525 2.92499H4.87505C4.35788 2.92499 3.86189 3.13043 3.49619 3.49613C3.13049 3.86183 2.92505 4.35782 2.92505 4.87499V18.525C2.92505 19.0422 3.13049 19.5381 3.49619 19.9038C3.86189 20.2695 4.35788 20.475 4.87505 20.475H7.1546" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M2.92505 8.77499H8.63757" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M7.7998 1.95007V4.87507" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M15.6 21.4499C18.8309 21.4499 21.45 18.8308 21.45 15.5999C21.45 12.3691 18.8309 9.74994 15.6 9.74994C12.3691 9.74994 9.75 12.3691 9.75 15.5999C9.75 18.8308 12.3691 21.4499 15.6 21.4499Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="rk-step-label">الخطوة 5</span>
                    <strong>الموعد</strong>
                </div>
            </div>

            <div class="rk-summary-bar" id="rk-summary-bar" style="display:none;">
                <div class="rk-summary-item" id="sum-program" style="display:none;"><span class="rk-sum-label">البرنامج:</span> <span class="rk-sum-val"></span></div>
                <div class="rk-summary-sep"  id="sum-sep-1"  style="display:none;">›</div>
                <div class="rk-summary-item" id="sum-course"  style="display:none;"><span class="rk-sum-label">الدورة:</span> <span class="rk-sum-val"></span></div>
                <div class="rk-summary-sep"  id="sum-sep-2"  style="display:none;">›</div>
                <div class="rk-summary-item" id="sum-session" style="display:none;"><span class="rk-sum-label">اللقاء:</span> <span class="rk-sum-val"></span></div>
                <div class="rk-summary-sep"  id="sum-sep-3"  style="display:none;">›</div>
                <div class="rk-summary-item" id="sum-child"   style="display:none;"><span class="rk-sum-label">الطفل:</span> <span class="rk-sum-val"></span></div>
            </div>

            <div class="rk-booking-grid">

                <div class="rk-card rk-step-content" id="rk-step-1">
                    <h3 class="rk-program-heading">أي مغامرة يريد طفلك أن يكتشف؟</h3>
                    <div class="rk-card-list rk-program-grid">
                        <?php
                        /*
                         * Image de fond + badge dynamiques par catégorie (course-category).
                         * L'image reprend le même mapping slug → image que le shortcode
                         * rk_course_categories_slider existant ailleurs sur le site,
                         * pour rester visuellement cohérent. $default_program_img sert
                         * de repli si le slug n'est pas dans la liste.
                         *
                         * Le badge est un SVG inline (stroke="currentColor", couleur
                         * pilotée en CSS) — même pattern que Icons::get() ailleurs dans
                         * le plugin, plutôt qu'un fichier externe.
                         */
                        $rk_badge_leader = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12.002 15.0027V20.0036C12.002 20.0036 15.0325 19.4535 16.0027 18.0033C17.0829 16.383 16.0027 13.0024 16.0027 13.0024" stroke="currentColor" stroke-width="1.50027" stroke-linecap="round" stroke-linejoin="round"/><path d="M2.50037 21.5038C2.50037 21.5038 3.00046 17.7631 4.50072 16.5029C4.91167 16.1566 5.43605 15.9742 5.97321 15.9909C6.51038 16.0075 7.02248 16.2219 7.41125 16.5929C8.20139 17.3731 8.21139 18.6633 7.50126 19.5035C6.24104 21.0037 2.50037 21.5038 2.50037 21.5038Z" stroke="currentColor" stroke-width="1.50027" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.00146 12.0022C9.5337 10.6213 10.2039 9.29774 11.0018 8.05145C12.1672 6.18811 13.7899 4.65389 15.7157 3.59475C17.6414 2.5356 19.8061 1.98674 22.0038 2.00037C22.0038 4.72086 21.2237 9.50171 16.0027 13.0023C14.7392 13.8009 13.399 14.471 12.002 15.0027L9.00146 12.0022Z" stroke="currentColor" stroke-width="1.50027" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.00151 12.0021H4.00061C4.00061 12.0021 4.55071 8.97156 6.00097 8.00139C7.62126 6.9212 11.0019 8.0514 11.0019 8.0514" stroke="currentColor" stroke-width="1.50027" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                        $rk_badge_langue = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 19.5V4.5C4 3.83696 4.26339 3.20107 4.73223 2.73223C5.20107 2.26339 5.83696 2 6.5 2H19C19.2652 2 19.5196 2.10536 19.7071 2.29289C19.8946 2.48043 20 2.73478 20 3V21C20 21.2652 19.8946 21.5196 19.7071 21.7071C19.5196 21.8946 19.2652 22 19 22H6.5C5.83696 22 5.20107 21.7366 4.73223 21.2678C4.26339 20.7989 4 20.163 4 19.5ZM4 19.5C4 18.837 4.26339 18.2011 4.73223 17.7322C5.20107 17.2634 5.83696 17 6.5 17H20" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 13L12 6L16 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.09998 11H14.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                        $rk_badge_software = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M18 16L22 12L18 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 8L2 12L6 16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.5 4L9.50003 20" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                        $rk_badge_ai = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 4.99999C12.0012 4.60002 11.9224 4.20385 11.7682 3.83479C11.614 3.46572 11.3876 3.13122 11.1023 2.85093C10.8169 2.57065 10.4784 2.35026 10.1067 2.20272C9.73491 2.05518 9.33739 1.98347 8.93751 1.9918C8.53762 2.00014 8.14344 2.08835 7.77815 2.25126C7.41286 2.41416 7.08383 2.64847 6.81041 2.94039C6.537 3.23232 6.32472 3.57597 6.18606 3.95114C6.04741 4.32631 5.98517 4.72542 6.00301 5.12499C5.41521 5.27613 4.86952 5.55904 4.40724 5.9523C3.94497 6.34556 3.57825 6.83886 3.33486 7.39484C3.09146 7.95081 2.97777 8.55488 3.0024 9.1613C3.02703 9.76772 3.18933 10.3606 3.47701 10.895C2.97119 11.3059 2.57344 11.8342 2.31835 12.4339C2.06327 13.0336 1.95857 13.6866 2.01338 14.336C2.06818 14.9854 2.28083 15.6115 2.63282 16.16C2.98481 16.7085 3.46548 17.1626 4.03301 17.483C3.96293 18.0252 4.00475 18.5761 4.1559 19.1015C4.30705 19.627 4.56431 20.1158 4.9118 20.5379C5.25929 20.9601 5.68962 21.3065 6.17623 21.5557C6.66284 21.805 7.19539 21.9519 7.74099 21.9873C8.28659 22.0227 8.83365 21.9459 9.3484 21.7616C9.86315 21.5773 10.3346 21.2894 10.7338 20.9157C11.1329 20.5421 11.4512 20.0906 11.669 19.5891C11.8868 19.0876 11.9994 18.5467 12 18V4.99999Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 13C9.83956 12.7047 10.5727 12.167 11.1067 11.455C11.6407 10.743 11.9515 9.88867 12 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.00299 5.125C6.02277 5.60873 6.15932 6.0805 6.40099 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.47699 10.896C3.65993 10.747 3.85569 10.6145 4.06199 10.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.99999 18C5.31082 18.0003 4.63326 17.8226 4.03299 17.484" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 13H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 18H18C18.5304 18 19.0391 18.2107 19.4142 18.5858C19.7893 18.9609 20 19.4696 20 20V21" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8H20" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 8V5C16 4.46957 16.2107 3.96086 16.5858 3.58579C16.9609 3.21071 17.4696 3 18 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 13.5C16.2761 13.5 16.5 13.2761 16.5 13C16.5 12.7239 16.2761 12.5 16 12.5C15.7239 12.5 15.5 12.7239 15.5 13C15.5 13.2761 15.7239 13.5 16 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 3.5C18.2761 3.5 18.5 3.27614 18.5 3C18.5 2.72386 18.2761 2.5 18 2.5C17.7239 2.5 17.5 2.72386 17.5 3C17.5 3.27614 17.7239 3.5 18 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 21.5C20.2761 21.5 20.5 21.2761 20.5 21C20.5 20.7239 20.2761 20.5 20 20.5C19.7239 20.5 19.5 20.7239 19.5 21C19.5 21.2761 19.7239 21.5 20 21.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 8.5C20.2761 8.5 20.5 8.27614 20.5 8C20.5 7.72386 20.2761 7.5 20 7.5C19.7239 7.5 19.5 7.72386 19.5 8C19.5 8.27614 19.7239 8.5 20 8.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

                        $program_visuals = [
                            'kids-training' => [
                                'image' => 'https://riadakids.com/wp-content/uploads/2026/03/غلاف-1-1.jpeg',
                                'badge' => $rk_badge_leader,
                            ],
                            'tech-makers' => [
                                'image' => 'https://riadakids.com/wp-content/uploads/2026/04/kids-software-1.webp',
                                'badge' => $rk_badge_software,
                            ],
                            'future-innovators' => [
                                'image' => 'https://riadakids.com/wp-content/uploads/2026/04/robot-ai.webp',
                                'badge' => $rk_badge_ai,
                            ],
                            'kids-languages' => [
                                'image' => 'https://riadakids.com/wp-content/uploads/2026/04/kids-languages-1.webp',
                                'badge' => $rk_badge_langue,
                            ],
                        ];
                        $default_program_img = 'https://riadakids.com/wp-content/uploads/2026/04/Banner-1.webp';
                        ?>
                        <?php foreach ( $programs as $p ) :
                            $rk_visual  = $program_visuals[ $p->slug ] ?? [];
                            $rk_img     = ! empty( $rk_visual['image'] ) ? $rk_visual['image'] : $default_program_img;
                            $rk_badge   = $rk_visual['badge'] ?? '';
                        ?>
                        <label class="rk-option-card rk-program-card">
                            <input type="radio" name="rk_program"
                                   value="<?php echo esc_attr( $p->term_id ); ?>"
                                   data-label="<?php echo esc_attr( $p->name ); ?>">
                            <div class="rk-program-media">
                                <img src="<?php echo esc_url( $rk_img ); ?>" alt="" class="rk-program-photo" loading="lazy" decoding="async">
                                <?php if ( $rk_badge ) : ?>
                                <span class="rk-program-badge" aria-hidden="true">
                                    <?php echo $rk_badge; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG interne de confiance ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="rk-option-content rk-program-content">
                                <div class="rk-program-text">
                                    <strong><?php echo esc_html( $p->name ); ?></strong>
                                    <?php if ( ! empty( $p->description ) ) : ?>
                                        <small><?php echo wp_kses_post( $p->description ); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="rk-nav-bar">
                        <span></span>
                        <button class="rk-btn rk-btn-primary" id="rk-next-1" disabled>التالي ›</button>
                    </div>
                </div>

                <div class="rk-card rk-step-content" id="rk-step-2" style="display:none;">
                    <h3 class="rk-course-heading">اختر الدورة التي تناسب اهتمام طفلك</h3>
                    <div id="rk-courses" class="rk-course-list">اختر البرنامج أولاً</div>
                    <div class="rk-nav-bar">
                        <button class="rk-btn rk-btn-secondary" data-goto="1">‹ السابق</button>
                        <button class="rk-btn rk-btn-primary" id="rk-next-2" disabled>التالي ›</button>
                    </div>
                </div>

                <div class="rk-card rk-step-content" id="rk-step-3" style="display:none;">
                    <h3 class="rk-session-heading">أي مغامرة يبدأ بها طفلك؟</h3>
                    <div id="rk-sessions" class="rk-session-list">اختر الدورة أولاً</div>
                    <div class="rk-nav-bar">
                        <button class="rk-btn rk-btn-secondary" data-goto="2">‹ السابق</button>
                        <button class="rk-btn rk-btn-primary" id="rk-next-3" disabled>التالي ›</button>
                    </div>
                </div>

                <div class="rk-card rk-step-content" id="rk-step-4" style="display:none;">
                    <h3 class="rk-session-heading">
                        لمن نحجز هذه المغامرة؟
                    </h3>

                    <div class="rk-card-grid rk-child-grid" id="rk-child-grid">
                        <!-- Carte "طفل جديد" — toujours visible en premier, ouvre le popup d'ajout -->
                        <button type="button" class="rk-child-card-add" id="rk-open-add-child">
                            <span class="rk-child-add-icon"><?php echo \RiadaKids\Core\Icons::get( 'user', 26 ); ?></span>
                            <strong>طفل جديد</strong>
                            <small>أضف طفلًا آخر لحجز مغامرته</small>
                            <span class="rk-btn rk-btn-primary rk-child-add-btn">أضف طفلاً ←</span>
                        </button>

                        <?php if ( ! empty( $children ) ) : ?>
                        <?php foreach ( $children as $child ) : ?>
                        <?php
                        $rk_family = trim( (string) ( $child->child_family_name ?? '' ) );
                        $rk_full   = trim( $child->child_name . ' ' . $rk_family );
                        $rk_avatar = trim( (string) ( $child->avatar_url ?? '' ) );
                        $rk_count  = (int) ( $child->booking_count ?? 0 );
                        ?>
                        <label class="rk-option-card rk-child-card"
                               data-child-id="<?php echo esc_attr( $child->id ); ?>"
                               data-child-name="<?php echo esc_attr( $child->child_name ); ?>"
                               data-child-family="<?php echo esc_attr( $rk_family ); ?>"
                               data-child-avatar="<?php echo esc_attr( $rk_avatar ); ?>"
                               data-child-age="<?php echo esc_attr( $child->child_age ); ?>">
                            <input type="radio" name="rk_child"
                                   value="<?php echo esc_attr( $child->id ); ?>"
                                   data-label="<?php echo esc_attr( $rk_full ); ?>"
                                   class="rk-child-radio">
                            <div class="rk-child-card-inner">
                                <div class="rk-child-photo-wrap">
                                    <div class="rk-option-icon rk-child-avatar">
                                        <?php if ( $rk_avatar ) : ?>
                                        <img src="<?php echo esc_url( $rk_avatar ); ?>"
                                             alt="<?php echo esc_attr( $rk_full ); ?>"
                                             width="64" height="64" loading="lazy" decoding="async">
                                        <?php else : ?>
                                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                                            <circle cx="12" cy="7" r="3" stroke="#4C95D7" stroke-width="1.8"/>
                                            <path d="M6.5 19C6.5 15.96 8.96 13.5 12 13.5C15.04 13.5 17.5 15.96 17.5 19" stroke="#4C95D7" stroke-width="1.8" stroke-linecap="round"/>
                                        </svg>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $rk_count > 0 ) : ?>
                                    <span class="rk-child-count-bubble"><?php echo esc_html( $rk_count ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <strong class="rk-child-fullname"><?php echo esc_html( $rk_full ); ?></strong>
                                <small class="rk-child-age-pill"><?php echo esc_html( $child->child_age ); ?> سنوات</small>
                                <span class="rk-check-indicator"><?php echo \RiadaKids\Core\Icons::get( 'check', 14 ); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Popup d'ajout d'un enfant — même formulaire (rk-child-form), affiché en modale -->
                    <?php // Attribut "hidden" requis en plus du style inline : le thème force
                    // .rk-modal-overlay en display:grid !important et ne sait masquer cette
                    // classe que via le sélecteur [hidden] (voir booking-children.js). ?>
                    <div class="rk-modal-overlay" id="rk-add-child-modal" style="display:none;" aria-hidden="true" hidden>
                        <div class="rk-modal-box" role="dialog" aria-modal="true" aria-labelledby="rk-add-child-modal-title">
                            <button type="button" class="rk-modal-close" id="rk-close-add-child" aria-label="إغلاق">&times;</button>
                            <div class="rk-child-form">
                                <h4 id="rk-add-child-modal-title" style="display:flex;align-items:center;gap:7px;"><?php echo \RiadaKids\Core\Icons::get( 'plus', 16 ); ?> إضافة طفل جديد</h4>
                                <div class="rk-child-form-fields">
                                    <div class="rk-field-group">
                                        <label>اسم الطفل <span class="rk-req">*</span></label>
                                        <input type="text" id="rk-child-name" placeholder="أدخل إسم الطفل" autocomplete="off">
                                    </div>
                                    <div class="rk-field-group">
                                        <label>اسم العائلة <span class="rk-req">*</span></label>
                                        <input type="text" id="rk-child-family" placeholder="أدخل اسم العائلة" autocomplete="off" maxlength="255" required>
                                    </div>
                                    <div class="rk-field-group">
                                        <label>اسم المستخدم <span class="rk-req">*</span></label>
                                        <input type="text" id="rk-child-username" placeholder="أدخل اسم المستخدم" autocomplete="off" maxlength="60" required>
                                    </div>
                                    <div class="rk-field-group">
                                        <label>العمر (7–16)</label>
                                        <input type="number" id="rk-child-age" min="7" max="16" step="1" placeholder="أدخل العمر">
                                    </div>
                                    <div class="rk-field-group rk-field-avatar">
                                        <label>صورة الطفل <small style="opacity:.6;font-weight:400;">(اختياري)</small></label>
                                        <div class="rk-avatar-picker">
                                            <span class="rk-avatar-preview" id="rk-child-avatar-preview" aria-hidden="true">
                                                <img id="rk-child-avatar-img" src="" alt="" hidden>
                                                <span id="rk-child-avatar-initial">؟</span>
                                            </span>
                                            <input type="file" id="rk-child-avatar-file"
                                                   accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                                            <input type="hidden" id="rk-child-avatar">
                                            <button type="button" class="rk-btn rk-btn-outline" id="rk-child-avatar-btn"><?php echo \RiadaKids\Core\Icons::get( 'camera', 15 ); ?> اختر صورة</button>
                                            <span class="rk-avatar-state" id="rk-child-avatar-state" aria-live="polite"></span>
                                        </div>
                                        <small style="opacity:.6;">JPG أو PNG، بحد أقصى 2MB</small>
                                    </div>
                                    <div class="rk-field-group rk-field-submit">
                                        <button type="button" class="rk-btn rk-btn-primary" id="rk-add-child-btn">+ إضافة</button>
                                    </div>
                                </div>
                                <div id="rk-add-child-msg" style="display:none;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Alerte crédit insuffisant — masquée tant qu'aucun enfant n'est sélectionné,
                         togglée par RKCredits.refreshCreditPreview() (booking-credits.js) -->
                    <div class="rk-credit-preview" id="rk-credit-preview" style="display:none;">
                        <div class="rk-alert-danger" id="rk-credit-insufficient" style="display:none;">
                            <span class="rk-alert-icon"><?php echo \RiadaKids\Core\Icons::get( 'alert', 20 ); ?></span>
                            <div>
                                <strong>لا يوجد رصيد كافٍ لإتمام هذا الحجز.</strong>
                                <div>يرجى شحن رصيدك أولاً قبل المتابعة إلى الخطوة التالية.</div>
                            </div>
                        </div>
                    </div>

                    <div class="rk-nav-bar">
                        <button class="rk-btn rk-btn-secondary" data-goto="3">‹ السابق</button>
                        <button class="rk-btn rk-btn-primary" id="rk-next-4" disabled>التالي ›</button>
                    </div>
                </div>

                <div class="rk-card rk-step-content" id="rk-step-5" style="display:none;">
                    <h3 class="rk-session-heading">من سيرافق طفلك في هذه المغامرة؟</h3>

                    <?php if ( $has_credits ) :

                        $ssa_nonce   = wp_create_nonce( 'wp_rest' );
                        $current_url = function_exists( 'wc_get_account_endpoint_url' )
                            ? wc_get_account_endpoint_url( 'book-session' )
                            : '';

                        $user_email = wp_get_current_user()->user_email;

                        $iframe_args = [
                            'ssa_locale'   => 'ar',
                            'ssa_is_rtl'   => '1',
                            'rk_uid'       => $user_id,
                            'rk_token'     => $rk_uid_token,
                            'rk_email'     => rawurlencode( $user_email ),
                            '_wpnonce'     => $ssa_nonce,
                            'booking_url'  => rawurlencode( $current_url ),
                        ];

                        $iframe_base = rest_url( 'ssa/v1/embed-inner' );
                        $iframe_url  = add_query_arg( $iframe_args, $iframe_base ) . '#/';
                    ?>

                    <div class="rk-ssa-widget-wrap" id="rk-ssa-widget">
                        <iframe
                            id="rk-ssa-iframe"
                            src="<?php echo esc_url( $iframe_url ); ?>"
                            height="600px"
                            width="100%"
                            name="ssa_booking"
                            loading="eager"
                            frameborder="0"
                            data-skip-lazy="1"
                            class="ssa_booking_iframe skip-lazy"
                            title="حجز وقت"
                            scrolling="no"
                            style="overflow:hidden;min-height:600px;border:0;display:block;"
                            data-rk-uid="<?php echo (int) $user_id; ?>"
                            data-rk-token="<?php echo esc_attr( $rk_uid_token ); ?>"
                        ></iframe>
                    </div>

                    <?php else : ?>

                    <div class="rk-alert rk-alert-warning">
                        <span class="rk-alert-icon"><?php echo \RiadaKids\Core\Icons::get( 'alert', 18 ); ?></span>
                        <div>
                            <strong>رصيد غير كافٍ</strong>
                            <p>يجب أن يكون لديك لقاء واحدة على الأقل لإتمام الحجز.</p>
                        </div>
                        <a href="https://riadakids.com/subscription/" class="rk-btn rk-btn-primary">
                            اشترِ باقة
                        </a>
                    </div>

                    <?php endif; ?>

                    <div class="rk-nav-bar" style="margin-top:20px;">
                        <button class="rk-btn rk-btn-secondary" data-goto="4">
                            ‹ السابق
                        </button>

                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">

                            <span class="rk-deduct-hint" id="rk-step5-hint">
                                سيُخصم 
                                <strong id="rk-step5-credit-count">0</strong> 
                                رصيد عند تأكيد الحجز
                            </span>
                        </div>
                    </div>
                </div>

                <div class="rk-card rk-step-content rk-step6" id="rk-step-6" style="display:none;">
                    <h3 class="rk-step6-title">كل شيء جاهز، لنؤكد الحجز</h3>
                    <p class="rk-step6-subtitle">راجع التفاصيل قبل التأكيد</p>

                    <div id="rk-final-summary" class="rk-final-summary">
                        </div>

                    <div class="rk-step6-customer-fields">
                        <label class="rk-step6-field" for="rk-customer-name">
                            <span class="rk-step6-field__label">الاسم</span>
                            <input type="text" id="rk-customer-name" class="rk-step6-field__input" autocomplete="name">
                        </label>
                        <label class="rk-step6-field" for="rk-customer-email">
                            <span class="rk-step6-field__label">البريد الإلكتروني</span>
                            <input type="email" id="rk-customer-email" class="rk-step6-field__input" autocomplete="email">
                        </label>
                    </div>
                    <div class="rk-step6-field__error" id="rk-customer-fields-error" style="display:none;"></div>

                    <div class="rk-step6-notice">
                        <?php echo \RiadaKids\Core\Icons::get( 'info', 16 ); ?>
                        يمكن إلغاء الموعد أو تعديله قبل 24 ساعة
                    </div>

                    <div id="rk-booking-result" style="display:none;margin-top:16px;"></div>

                    <div class="rk-nav-bar rk-step6-nav">
                        <button class="rk-btn rk-btn-primary rk-btn-confirm" id="rk-confirm-booking">
                            تأكيد الحجز
                        </button>
                        <button class="rk-btn rk-btn-secondary" data-goto="5">‹ رجوع</button>
                    </div>
                </div>

            </div></div><?php
    }

    /**
     * PHASE 2 — Hero banner (structure générale uniquement).
     *
     * Purement présentationnel : aucune logique métier, aucun state,
     * aucun contrat AJAX. Le compteur de crédits réutilise la même
     * variable $credits déjà transmise à render() — jamais hardcodé —
     * et l'ID rk-credits-display existant reste la seule source
     * synchronisée par booking-credits.js ; le hero en affiche une
     * COPIE lecture-seule via un ID dédié pour ne pas dupliquer de
     * cible d'update JS.
     *
     * Image du robot : assets/images/rk-hero-robot.png (fournie par
     * l'export Figma), chargée via RK_PLUGIN_URL comme les autres
     * assets du plugin.
     */
    private static function render_hero( int $credits, bool $has_credits ): void {
        $robot_url        = RK_PLUGIN_URL . 'assets/images/rk-hero-robot.png';
        $robot_mobile_url = RK_PLUGIN_URL . 'assets/images/rk-hero-robot-mobile.svg';
        $badge_url        = RK_PLUGIN_URL . 'assets/images/rk-hero-credit-icon.svg';
        ?>
        <div class="rk-hero" dir="rtl">
            <span class="rk-hero-deco rk-hero-deco-1" aria-hidden="true"></span>
            <span class="rk-hero-deco rk-hero-deco-2" aria-hidden="true"></span>

            <div class="rk-hero-visual" aria-hidden="true">
                <img class="rk-hero-robot-desktop" src="<?php echo esc_url( $robot_url ); ?>" alt="" loading="eager" decoding="async">
                <img class="rk-hero-robot-mobile" src="<?php echo esc_url( $robot_mobile_url ); ?>" alt="" loading="eager" decoding="async">
            </div>
            <div class="rk-hero-body">
                <h3 class="rk-hero-title">اختر مغامرة جديدة لطفلك!</h3>
                <p class="rk-hero-sub">خطوة جديدة في رحلة تعلم، نختارها معًا.</p>
                <div class="rk-hero-actions">
                    <a class="rk-hero-credit-btn" href="https://riadakids.com/subscription/">
                        اشحن رصيدك >
                    </a>
                    <span class="rk-hero-credit-pill">
                        <img class="rk-hero-credit-icon" src="<?php echo esc_url( $badge_url ); ?>" alt="" aria-hidden="true" width="24" height="24">
                        <strong id="rk-hero-credits-display"><?php echo esc_html( number_format( $credits, 0 ) ); ?></strong>
                        لقاءات متبقية
                    </span>
                </div>
            </div>
        </div>
        <?php
    }
}