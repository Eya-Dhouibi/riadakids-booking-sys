<?php
/**
 * RiadaKids\Children\ChildValidator
 *
 * Validation des données pour les profils enfants.
 *
 * @package RiadaKids\Children
 */

namespace RiadaKids\Children;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ChildValidator {

    /** * @var string[] Liste des messages d'erreur accumulés
     */
    private array $errors = [];

    /**
     * Valide les données d'un enfant avant insertion.
     *
     * @param string $name  Nom de l'enfant
     * @param int    $age   Âge de l'enfant
     * @return bool True si toutes les règles métier sont respectées, sinon False.
     */
    public function validate( string $name, int $age, string $family = '', string $username = '' ): bool {
        $this->errors = [];

        // 1. Validation du Nom
        if ( empty( trim( $name ) ) ) {
            $this->errors[] = 'اسم الطفل مطلوب';
        } elseif ( mb_strlen( $name ) > 255 ) {
            $this->errors[] = 'اسم الطفل طويل جداً';
        }

        // 1b. Nom de famille — obligatoire, borné
        if ( empty( trim( $family ) ) ) {
            $this->errors[] = 'اسم العائلة مطلوب';
        } elseif ( mb_strlen( $family ) > 255 ) {
            $this->errors[] = 'اسم العائلة طويل جداً';
        }

        // 1c. Nom d'utilisateur — obligatoire, borné
        if ( empty( trim( $username ) ) ) {
            $this->errors[] = 'اسم المستخدم مطلوب';
        } elseif ( mb_strlen( $username ) > 60 ) {
            $this->errors[] = 'اسم المستخدم طويل جداً';
        }

        // 2. Validation de l'âge
        if ( $age < 2 || $age > 18 ) {
            $this->errors[] = 'العمر يجب أن يكون بين 2 و 18 سنة';
        }

        return empty( $this->errors );
    }

    /**
     * Récupère l'ensemble des erreurs de validation.
     *
     * @return array
     */
    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * Récupère la première erreur survenue (idéal pour les retours AJAX rapides).
     *
     * @return string
     */
    public function get_first_error(): string {
        return $this->errors[0] ?? '';
    }
}