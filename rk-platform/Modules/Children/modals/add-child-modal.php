<?php
/**
 * Modal : Ajout et Modification d'un enfant  (v11.0)
 *
 * @since 5.0.0
 * @updated 5.1.0 — champ avatar_url + prévisualisation live
 * @updated 5.2.0 — champ « اسم العائلة » (child_family_name)
 *                — <button> remplacés par <a role="button"> + classe .rk-btn
 *                  pour échapper aux styles globaux d'Elementor
 * @updated 11.0.0 — Restyling aligné sur le formulaire « إضافة طفل جديد »
 *                    de l'étape 4 du wizard de réservation (riadakids-booking) :
 *                    même disposition (sélecteur de photo en ligne, champs
 *                    groupés, bouton pleine largeur). IDs inchangés — le JS
 *                    existant (child-add.js, child-edit.js,
 *                    child-avatar-upload.js, child-modal.js) fonctionne
 *                    sans aucune modification.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="rk-modal-overlay" id="rk-modal-overlay"
     role="dialog" aria-modal="true" aria-labelledby="rk-modal-title" hidden>

    <div class="rk-modal-box rk-modal-box--booking-style" id="rk-modal-box">

        <a href="#" role="button" tabindex="0" class="rk-btn rk-modal-close" id="rk-modal-close"
           aria-label="<?php esc_attr_e( 'إغلاق', 'rk-my-children' ); ?>">
            <?php echo rk_mc_svg( 'close' ); ?>
        </a>

        <div class="rk-child-form">
            <h4 id="rk-modal-title">
                <?php echo rk_mc_svg( 'plus' ); ?>
                <?php esc_html_e( 'إضافة طفل جديد', 'rk-my-children' ); ?>
            </h4>
            <p class="rk-modal-subtitle" id="rk-modal-subtitle">
                <?php esc_html_e( 'قم بتسجيل بيانات طفلك لتتمكن من حجز الحصص له.', 'rk-my-children' ); ?>
            </p>

            <input type="hidden" id="rk-edit-child-id" value="">

            <div id="rk-add-form-wrap">
                <div id="rk-form-error" class="rk-form-error" hidden></div>

                <div class="rk-child-form-fields">

                    <!-- ── صورة الطفل — sélecteur en ligne (photo + bouton), style booking ── -->
                    <div class="rk-field-group rk-field-avatar">
                        <label for="rk-field-avatar-file">
                            <?php esc_html_e( 'صورة الطفل', 'rk-my-children' ); ?>
                            <small style="opacity:.6;font-weight:400;"><?php esc_html_e( '(اختياري)', 'rk-my-children' ); ?></small>
                        </label>
                        <div class="rk-avatar-picker">
                            <span class="rk-avatar-preview" id="rk-avatar-preview" aria-hidden="true">
                                <img id="rk-avatar-img" class="rk-avatar-field__img" src="" alt="" hidden>
                                <span id="rk-avatar-initials">؟</span>
                            </span>
                            <input type="file" id="rk-field-avatar-file" accept="image/jpeg,image/png,image/gif,image/webp" hidden/>
                            <input type="hidden" id="rk-field-avatar" name="avatar_url" maxlength="500"/>
                            <a href="#" role="button" tabindex="0" class="rk-btn rk-btn-outline" id="rk-avatar-upload-btn">
                                📷 <?php esc_html_e( 'اختر صورة', 'rk-my-children' ); ?>
                            </a>
                            <span class="rk-avatar-upload__state" id="rk-avatar-upload-state" aria-live="polite"></span>
                        </div>
                        <small style="opacity:.6;"><?php esc_html_e( 'JPG أو PNG، بحد أقصى 2MB', 'rk-my-children' ); ?></small>
                        <span class="rk-field-error" id="rk-error-avatar" hidden></span>
                    </div>

                    <div class="rk-field-group">
                        <label for="rk-field-name">
                            <?php esc_html_e( 'اسم الطفل', 'rk-my-children' ); ?>
                            <span class="rk-req" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="rk-field-name" name="child_name" maxlength="255"
                               autocomplete="off"
                               placeholder="<?php esc_attr_e( 'أدخل اسم الطفل', 'rk-my-children' ); ?>"/>
                        <span class="rk-field-error" id="rk-error-name" hidden></span>
                    </div>

                    <div class="rk-field-group">
                        <label for="rk-field-family">
                            <?php esc_html_e( 'اسم العائلة', 'rk-my-children' ); ?>
                            <span class="rk-req" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="rk-field-family" name="child_family_name" maxlength="255"
                               autocomplete="off"
                               placeholder="<?php esc_attr_e( 'أدخل اسم العائلة', 'rk-my-children' ); ?>"/>
                        <span class="rk-field-hint"><?php esc_html_e( 'يساعد على تمييز الأطفال الذين يحملون نفس الاسم', 'rk-my-children' ); ?></span>
                        <span class="rk-field-error" id="rk-error-family" hidden></span>
                    </div>

                    <div class="rk-field-group">
                        <label for="rk-field-username">
                            <?php esc_html_e( 'اسم المستخدم', 'rk-my-children' ); ?>
                            <span class="rk-req" aria-hidden="true">*</span>
                        </label>
                        <input type="text" id="rk-field-username" name="child_username" maxlength="60"
                               autocomplete="off" dir="ltr"
                               placeholder="<?php esc_attr_e( 'مثال: adam_k', 'rk-my-children' ); ?>"/>
                        <span class="rk-field-hint"><?php esc_html_e( 'أحرف لاتينية وأرقام و _ فقط، بدون مسافات', 'rk-my-children' ); ?></span>
                        <span class="rk-field-error" id="rk-error-username" hidden></span>
                    </div>

                    <div class="rk-field-group">
                        <label for="rk-field-age">
                            <?php esc_html_e( 'عمر الطفل', 'rk-my-children' ); ?>
                        </label>
                        <input type="text" id="rk-field-age" name="child_age" maxlength="20"
                               autocomplete="off" inputmode="numeric"
                               placeholder="<?php esc_attr_e( 'مثال: 7', 'rk-my-children' ); ?>"/>
                    </div>

                    <div class="rk-field-group rk-field-submit">
                        <a href="#" role="button" tabindex="0" id="rk-save-child" class="rk-btn rk-btn-save">
                            <span class="rk-btn-label"><?php esc_html_e( 'حفظ', 'rk-my-children' ); ?></span>
                            <span class="rk-btn-spinner" hidden aria-hidden="true">
                                <?php echo rk_mc_svg( 'spin' ); ?>
                            </span>
                        </a>
                        <a href="#" role="button" tabindex="0" id="rk-cancel-modal" class="rk-btn rk-btn-cancel">
                            <?php esc_html_e( 'إلغاء', 'rk-my-children' ); ?>
                        </a>
                    </div>

                </div><!-- /.rk-child-form-fields -->
            </div><!-- /#rk-add-form-wrap -->
        </div><!-- /.rk-child-form -->

    </div><!-- /.rk-modal-box -->
</div><!-- /#rk-modal-overlay -->
