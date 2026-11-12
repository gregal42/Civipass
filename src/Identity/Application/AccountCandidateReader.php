<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Rapport de comptes CANDIDATS à suppression ou anonymisation (LOT 05.6.5, décision D1) : LECTURE SEULE, réservé aux super-administrateurs CWS. Il ne modifie ni compte, ni
 * appartenance, ni rôle, et ne persiste pas la liste : seule l'exécution est journalisée (opérateur, effectifs, seuil).
 *
 * Un compte est candidat si : il n'est pas super-administrateur ; il n'a AUCUNE appartenance active (donc n'est le dernier administrateur d'aucune commune) ; sa dernière activité
 * (connexion, ou création s'il ne s'est jamais connecté, ou dernière modification du compte) remonte à `ACCOUNT_INACTIVITY_MONTHS` mois ou plus (hypothèse à valider). Catégories :
 * `DELETABLE` (aucune référence conservée) ou `ANONYMISE_ONLY` (référencé par un historique conservé) : INDICATEURS TECHNIQUES, jamais une autorisation juridique.
 */
final class AccountCandidateReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AccountReferenceCatalog $catalog,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
        #[Autowire('%env(int:ACCOUNT_INACTIVITY_MONTHS)%')] private readonly int $inactivityMonths,
    ) {
    }

    public function months(): int
    {
        return max(1, $this->inactivityMonths);
    }

    /**
     * @return array{lines: list<AccountCandidateLine>, unclassified: list<string>, truncated: bool}
     *
     * @throws OperationNotAllowed hors super-administrateur actif
     */
    public function report(User $operator, int $limit = 500): array
    {
        if (!$operator->isActive() || !$operator->isSuperAdmin()) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $limit = max(1, $limit);
        $threshold = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify(sprintf('-%d months', $this->months()));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.email, u.active, GREATEST(COALESCE(u.last_login_at, u.created_at), u.updated_at) AS last_activity FROM identity_user u'
            .' WHERE u.super_admin = 0 AND NOT EXISTS (SELECT 1 FROM municipality_membership m WHERE m.user_id = u.id AND m.active = 1)'
            .' AND GREATEST(COALESCE(u.last_login_at, u.created_at), u.updated_at) <= :threshold ORDER BY last_activity, u.id LIMIT '.($limit + 1),
            ['threshold' => $threshold->format('Y-m-d H:i:s')],
        );
        $truncated = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);
        $references = $this->catalog->discover();
        $lines = [];
        foreach ($rows as $row) {
            $counts = [];
            foreach ($references as $reference) {
                if (AccountReferenceCatalog::isOwned($reference)) {
                    continue;
                }
                [$table, $column] = explode('.', $reference, 2);
                $n = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = :id', $table, $column), ['id' => (string) $row['id']]);
                if ($n > 0) {
                    $counts[$reference] = $n;
                }
            }
            $lines[] = new AccountCandidateLine(
                Uuid::fromBinary((string) $row['id'])->toRfc4122(),
                self::mask((string) $row['email']),
                [] === $counts ? AccountCandidateLine::DELETABLE : AccountCandidateLine::ANONYMISE_ONLY,
                (bool) $row['active'],
                (new \DateTimeImmutable((string) $row['last_activity'], new \DateTimeZone('UTC')))->format('Y-m'),
                $counts,
            );
        }
        $unclassified = $this->catalog->unclassified();
        $deletable = \count(array_filter($lines, static fn (AccountCandidateLine $l): bool => AccountCandidateLine::DELETABLE === $l->category));
        $this->transaction->run(function () use ($operator, $lines, $deletable, $unclassified): void {
            $this->audit->record(new AuditEvent($this->clock->now(), AuditAction::AccountCandidatesReported, AuditSubjectType::Retention, Uuid::v7(), null, AuditActor::user($operator->id()), null, [
                'candidates' => \count($lines),
                'deletable' => $deletable,
                'anonymise_only' => \count($lines) - $deletable,
                'months' => $this->months(),
                'unclassified_references' => \count($unclassified),
            ]));
        });

        return ['lines' => $lines, 'unclassified' => $unclassified, 'truncated' => $truncated];
    }

    /** `jean.dupont@mairie.example.fr` → `j***@m***.fr` : jamais l'adresse complète. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return '***';
        }
        $local = mb_substr($email, 0, 1);
        $domain = substr($email, $at + 1);
        $dot = strrpos($domain, '.');

        return $local.'***@'.mb_substr($domain, 0, 1).'***'.(false === $dot ? '' : substr($domain, $dot));
    }
}
