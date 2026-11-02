<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface UserRepository
{
    public function add(User $user): void;

    public function find(Uuid $id): ?User;

    /**
     * Verrou d'écriture sur l'utilisateur puis rafraîchissement : à appeler dans une transaction,
     * avant de lire son état (actif, superadmin) pour en décider.
     */
    public function lock(User $user): User;

    /**
     * La recherche exige un email déjà normalisé (comparaison binaire).
     */
    public function findByEmail(EmailAddress $email): ?User;

    /**
     * Superadmins actifs, verrouillés en écriture : à appeler dans une transaction.
     *
     * @return list<User>
     */
    public function lockActiveSuperAdmins(): array;
}
