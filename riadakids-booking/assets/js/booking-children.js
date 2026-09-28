/**
 * RiadaKids Booking Wizard — Module 6: Children Management
 * v8.0 — Enfant unique par réservation (1 réservation = 1 enfant)
 */
(function ($) {
    'use strict';

    window.RKChildren = {
        init: function () {
            // Nettoyage des namespaces pour éviter l'accumulation d'écouteurs AJAX
            $(document).off('change.rkChildSel', '.rk-child-radio, input[name="rk_child"]');
            $(document).off('click.rkAddChild', '#rk-add-child-btn');
            $(document).off('click.rkAddChildModal', '#rk-open-add-child, #rk-close-add-child, #rk-add-child-modal');
            $(document).off('keydown.rkAddChildModal');

            $(document).off('click.rkAvatar', '#rk-child-avatar-btn');
            $(document).off('change.rkAvatar', '#rk-child-avatar-file');

            this.bindChildrenSelection();
            this.bindAddChildModal();
            this.bindAddChildForm();
            this.bindAvatarPicker();
        },

        /**
         * Ouverture / fermeture du popup d'ajout d'enfant.
         * La carte "طفل جديد" reste toujours visible en tête de grille ;
         * seul le formulaire est déplacé dans une modale.
         */
        bindAddChildModal: function () {
            const $modal = $('#rk-add-child-modal');

            // NOTE (bug signalé) — le thème (main.css) définit .rk-modal-overlay
            // avec "display:grid !important" pour le design-system partagé
            // (rk-modal-card/animate-popup), et ne sait masquer cette classe
            // que via l'attribut HTML [hidden] { display:none !important }.
            // Un simple $modal.css('display','none') ne peut PAS vaincre ce
            // !important : le popup restait visuellement ouvert après clic
            // sur ×. On pose désormais l'attribut hidden en plus du style
            // inline, pour rester compatible avec les deux design-systems.
            $(document).on('click.rkAddChildModal', '#rk-open-add-child', function (e) {
                e.preventDefault();
                $modal.css('display', 'flex').attr('aria-hidden', 'false').removeAttr('hidden');
            });

            $(document).on('click.rkAddChildModal', '#rk-close-add-child', function (e) {
                e.preventDefault();
                $modal.css('display', 'none').attr('aria-hidden', 'true').attr('hidden', 'hidden');
            });

            // Fermeture au clic sur l'overlay (en dehors de la boîte)
            $(document).on('click.rkAddChildModal', '#rk-add-child-modal', function (e) {
                if (e.target === this) {
                    $modal.css('display', 'none').attr('aria-hidden', 'true').attr('hidden', 'hidden');
                }
            });

            // Fermeture avec la touche Échap — cohérent avec le modal.js du thème
            $(document).on('keydown.rkAddChildModal', function (e) {
                if (e.key === 'Escape' && $modal.attr('aria-hidden') === 'false') {
                    $modal.css('display', 'none').attr('aria-hidden', 'true').attr('hidden', 'hidden');
                }
            });
        },

        /**
         * Écoute le changement de sélection d'un seul enfant (radio button)
         */
        bindChildrenSelection: function () {
            const self = this;
            $(document).on('change.rkChildSel', '.rk-child-radio, input[name="rk_child"]', function () {
                // Retirer la sélection de toutes les cartes
                $('.rk-child-card').removeClass('rk-selected-card');
                // Marquer uniquement la carte sélectionnée
                $(this).closest('.rk-option-card').addClass('rk-selected-card');

                self.refreshChildSelection();

                if (window.RKCredits && typeof window.RKCredits.refreshCreditPreview === 'function') {
                    window.RKCredits.refreshCreditPreview();
                }
            });
        },

        /**
         * Écoute le clic sur le bouton "+ إضافة"
         */
        bindAddChildForm: function () {
            const self = this;

            $(document).on('click.rkAddChild', '#rk-add-child-btn', function (e) {
                e.preventDefault();

                const $btn = $(this);
                const $msgContainer = $('#rk-add-child-msg');

                if ($btn.prop('disabled')) {
                    return false;
                }

                const childName     = $.trim($('#rk-child-name').val());
                const childFamily   = $.trim($('#rk-child-family').val());
                const childUsername = $.trim($('#rk-child-username').val());
                const childAge      = parseInt($('#rk-child-age').val(), 10);
                const avatarUrl     = $.trim($('#rk-child-avatar').val());

                if (!childName) {
                    self.showFormMessage('اسم الطفل مطلوب', 'error');
                    return;
                }
                if (!childFamily) {
                    self.showFormMessage('اسم العائلة مطلوب', 'error');
                    return;
                }
                if (!childUsername) {
                    self.showFormMessage('اسم المستخدم مطلوب', 'error');
                    return;
                }
                if (isNaN(childAge) || childAge < 2 || childAge > 18) {
                    self.showFormMessage('العمر يجب أن يكون بين 2 و 18 سنة', 'error');
                    return;
                }

                $btn.prop('disabled', true).text('جاري الإضافة...');
                $msgContainer.hide().removeClass('rk-msg-success rk-msg-error').empty();

                const formData = {
                    action:     'rk_add_child',
                    nonce:      typeof rkConfig !== 'undefined' ? rkConfig.nonce : '',
                    child_name:        childName,
                    child_family_name: childFamily,
                    child_username:    childUsername,
                    child_age:         childAge,
                    avatar_url:        avatarUrl
                };

                const ajaxUrl = typeof rkConfig !== 'undefined' ? rkConfig.ajaxUrl : '/wp-admin/admin-ajax.php';

                $.post(ajaxUrl, formData, function (resp) {
                    if (resp.success) {
                        self.showFormMessage('تمت إضافة الطفل بنجاح.', 'success');

                        const cId     = resp.data.child_id;
                        const cName   = resp.data.child_name;
                        const cFamily = resp.data.child_family_name || '';
                        const cFull   = resp.data.child_full_name || cName;
                        const cAge    = resp.data.child_age;
                        const cAvatar = resp.data.avatar_url || '';

                        const esc = (window.RiadaKidsWizard && window.RiadaKidsWizard.Utils)
                            ? window.RiadaKidsWizard.Utils.escHtml
                            : function (s) { return String(s); };

                        const safeName   = esc(cName);
                        const safeFamily = esc(cFamily);
                        const safeFull   = esc(cFull);
                        const safeAvatar = esc(cAvatar);

                        // Photo si fournie, sinon la silhouette par défaut
                        const iconHtml = cAvatar
                            ? '<img src="' + safeAvatar + '" alt="' + safeFull + '" width="36" height="36" loading="lazy" decoding="async">'
                            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none">'
                              + '<circle cx="12" cy="7" r="3" stroke="#4C95D7" stroke-width="1.8"/>'
                              + '<path d="M6.5 19C6.5 15.96 8.96 13.5 12 13.5C15.04 13.5 17.5 15.96 17.5 19" stroke="#4C95D7" stroke-width="1.8" stroke-linecap="round"/>'
                              + '</svg>';

                        // Nouvelle carte avec radio button (sélection unique)
                        // Pas de pastille de compteur : un enfant tout juste créé n'a aucune réservation.
                        const newCardHtml = `
                            <label class="rk-option-card rk-child-card rk-selected-card"
                                   data-child-id="${cId}"
                                   data-child-name="${safeName}"
                                   data-child-family="${safeFamily}"
                                   data-child-avatar="${safeAvatar}"
                                   data-child-age="${cAge}">
                                <input type="radio" name="rk_child" value="${cId}" data-label="${safeFull}" class="rk-child-radio" checked>
                                <div class="rk-child-card-inner">
                                    <div class="rk-child-photo-wrap">
                                        <div class="rk-option-icon rk-child-avatar">${iconHtml}</div>
                                    </div>
                                    <strong class="rk-child-fullname">${safeFull}</strong>
                                    <small class="rk-child-age-pill">${cAge} سنوات</small>
                                    <span class="rk-check-indicator">${RKIcon('check')}</span>
                                </div>
                            </label>
                        `;

                        // Décocher les autres cartes avant d'ajouter la nouvelle
                        $('.rk-child-card').removeClass('rk-selected-card');
                        $('.rk-child-radio').prop('checked', false);

                        // La carte "طفل جديد" reste toujours en tête ; on insère juste après.
                        $('#rk-open-add-child').after(newCardHtml);

                        // Réinitialisation complète du formulaire
                        $('#rk-child-name').val('');
                        $('#rk-child-family').val('');
                        $('#rk-child-username').val('');
                        $('#rk-child-age').val('');
                        $('#rk-child-avatar').val('');
                        $('#rk-child-avatar-file').val('');
                        $('#rk-child-avatar-img').attr('src', '').prop('hidden', true);
                        $('#rk-child-avatar-initial').text('؟').prop('hidden', false);
                        $('#rk-child-avatar-state').text('');

                        self.refreshChildSelection();
                        if (window.RKCredits && typeof window.RKCredits.refreshCreditPreview === 'function') {
                            window.RKCredits.refreshCreditPreview();
                        }

                        // Fermer le popup une fois l'enfant ajouté et sélectionné
                        // (voir bindAddChildModal — hidden requis pour vaincre le
                        // !important de .rk-modal-overlay défini par le thème)
                        $('#rk-add-child-modal').css('display', 'none').attr('aria-hidden', 'true').attr('hidden', 'hidden');

                    } else {
                        const errorMsg = resp.data && resp.data.msg ? resp.data.msg : 'خطأ أثناء إضافة الطفل';
                        self.showFormMessage(errorMsg, 'error');
                    }
                }).fail(function () {
                    self.showFormMessage('خطأ في الاتصال بالخادم.', 'error');
                }).always(function () {
                    $btn.prop('disabled', false).text('+ إضافة');
                });
            });
        },

        /**
         * Sélecteur de photo : prévisualisation locale immédiate,
         * puis upload vers la médiathèque. L'URL retournée est stockée
         * dans #rk-child-avatar et envoyée avec le reste du formulaire.
         */
        bindAvatarPicker: function () {
            const self = this;

            $(document).on('click.rkAvatar', '#rk-child-avatar-btn', function (e) {
                e.preventDefault();
                $('#rk-child-avatar-file').trigger('click');
            });

            $(document).on('change.rkAvatar', '#rk-child-avatar-file', function () {
                const file = this.files && this.files[0];
                if (!file) return;

                const $state = $('#rk-child-avatar-state');
                const $btn   = $('#rk-child-avatar-btn');

                // Contrôles côté client — le serveur revalide de toute façon.
                const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (allowed.indexOf(file.type) === -1) {
                    $state.text('صيغة غير مدعومة').css('color', '#b91c1c');
                    this.value = '';
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    $state.text('الحجم يتجاوز 2MB').css('color', '#b91c1c');
                    this.value = '';
                    return;
                }

                // Aperçu instantané, sans attendre le serveur
                const reader = new FileReader();
                reader.onload = function (ev) {
                    $('#rk-child-avatar-img').attr('src', ev.target.result).prop('hidden', false);
                    $('#rk-child-avatar-initial').prop('hidden', true);
                };
                reader.readAsDataURL(file);

                const fd = new FormData();
                fd.append('action', 'rk_upload_child_avatar');
                fd.append('nonce', typeof rkConfig !== 'undefined' ? rkConfig.nonce : '');
                fd.append('avatar_file', file);
                fd.append('child_name', $.trim($('#rk-child-name').val()));

                const ajaxUrl = typeof rkConfig !== 'undefined' ? rkConfig.ajaxUrl : '/wp-admin/admin-ajax.php';

                $state.text('جاري الرفع...').css('color', '');
                $btn.prop('disabled', true);

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false
                }).done(function (resp) {
                    if (resp && resp.success && resp.data && resp.data.url) {
                        $('#rk-child-avatar').val(resp.data.url);
                        $state.html(RKIcon('check') + ' تم الرفع').css('color', '#166534');
                    } else {
                        const msg = (resp && resp.data && resp.data.msg) ? resp.data.msg : 'تعذر رفع الصورة';
                        $state.text(msg).css('color', '#b91c1c');
                        // Aperçu retiré : garder une image non enregistrée induirait en erreur.
                        $('#rk-child-avatar').val('');
                        $('#rk-child-avatar-img').attr('src', '').prop('hidden', true);
                        $('#rk-child-avatar-initial').prop('hidden', false);
                    }
                }).fail(function () {
                    $state.text('خطأ في الاتصال بالخادم').css('color', '#b91c1c');
                    $('#rk-child-avatar').val('');
                    $('#rk-child-avatar-img').attr('src', '').prop('hidden', true);
                    $('#rk-child-avatar-initial').prop('hidden', false);
                }).always(function () {
                    $btn.prop('disabled', false);
                });
            });

            // Initiale affichée dans l'aperçu tant qu'aucune photo n'est choisie
            $(document).on('input.rkAvatar', '#rk-child-name', function () {
                if ($('#rk-child-avatar').val()) return;
                const v = $.trim($(this).val());
                $('#rk-child-avatar-initial').text(v ? v.charAt(0).toUpperCase() : '؟');
            });
        },

        showFormMessage: function (text, type) {
            const $msg = $('#rk-add-child-msg');
            $msg.removeClass('rk-msg-success rk-msg-error')
                .addClass(type === 'success' ? 'rk-msg-success' : 'rk-msg-error')
                .html(text)
                .fadeIn(200);
        },

        /**
         * Mise à jour du state avec l'enfant unique sélectionné
         */
        refreshChildSelection: function () {
            const $checked = $('.rk-child-radio:checked, input[name="rk_child"]:checked');

            if ($checked.length > 0) {
                const childId   = parseInt($checked.val(), 10);
                const childName = $checked.data('label') || $checked.attr('data-label') || '';

                if (window.RKBookingState) {
                    // Un seul enfant — child_ids contient toujours 1 élément maximum
                    window.RKBookingState.child_ids = [childId];

                    // CORRECTION (bug signalé) — children_names n'était
                    // jamais alimenté ici (seul child_ids l'était), alors
                    // que booking-summary.js::renderFinalSummary() lit
                    // s.children_names.join(', ') pour la carte "الطفل" de
                    // l'étape 6. Résultat : cette carte affichait toujours
                    // "—" au lieu du vrai nom de l'enfant sélectionné.
                    window.RKBookingState.children_names = childName ? [childName] : [];
                }

                $('#sum-child .rk-sum-val').text(childName || '—');
                $('#sum-sep-3, #sum-child').show();
                $('#rk-step5-hint').show();

                // BUGFIX : le bouton restait disabled même après sélection d'un enfant.
                $('#rk-next-4').prop('disabled', false);
            } else {
                if (window.RKBookingState) {
                    window.RKBookingState.child_ids     = [];
                    window.RKBookingState.children_names = [];
                }

                $('#sum-child .rk-sum-val').text('—');
                $('#sum-sep-3, #sum-child').hide();
                $('#rk-step5-hint').hide();

                // BUGFIX : reverrouiller le bouton si plus aucun enfant n'est sélectionné.
                $('#rk-next-4').prop('disabled', true);
            }
        }
    };

    $(document).ready(function () {
        window.RKChildren.init();
    });

})(jQuery);