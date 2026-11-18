<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Event\Application\ClosureCounters;
use App\Event\Application\Result\EventClosureResult;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Domain\Event;
use App\Event\Domain\EventRepository;
use App\Identity\Domain\CwsPermission;
use App\Identity\Domain\CwsPermissionGrantRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Clôture EXCEPTIONNELLE d'un événement par un opérateur CWS habilité (LOT 08.6, DM-065, DM-139). Opération rare, jamais un raccourci : habilitation
 * `CWS_FORCE_CLOSE_EVENT` persistée et relue SOUS VERROU, ressaisie du mot de passe, motif obligatoire, référence exacte de l'événement saisie en confirmation,
 * limitation des tentatives, et le MÊME moteur que la clôture normale (toutes les conséquences métier, atomicité). Aucun droit communal n'est créé et aucun
 * dossier n'est lu (DM-005) : l'aperçu ne contient que des compteurs.
 */
final readonly class ForcedEventClosure
{
    public const REASON_MIN_LENGTH = 15;
    public const REASON_MAX_LENGTH = 300;

    public function __construct(
        private EventLifecycleService $lifecycle,
        private EventRepository $events,
        private CwsPermissionGrantRepository $grants,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private ClosureCounters $counters,
        private Connection $connection,
        private ForcedClosureNotifier $notifier,
        private LoggerInterface $logger,
        #[Autowire(service: 'limiter.cws_forced_closure')]
        private RateLimiterFactory $limiter,
    ) {
    }

    /**
     * @throws OperationNotAllowed opérateur non habilité, événement inaccessible ou déjà clôturé
     */
    public function preview(User $operator, string $eventId): ForcedClosurePreview
    {
        $this->assertGranted($operator);
        $event = $this->open($eventId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.name AS municipality, p.status AS status, (p.municipality_id = e.organizer_municipality_id) AS organizer FROM event_participation p'
            .' JOIN event_event e ON e.id = p.event_id JOIN municipality m ON m.id = p.municipality_id'
            .' WHERE p.event_id = :event AND p.status <> \'CLOSED\' ORDER BY organizer DESC, m.name',
            ['event' => $event->id()->toBinary()],
        );
        $statistics = $this->counters->count($event->id());
        $pending = 0;
        $authorizations = 0;
        foreach ($statistics->municipalities as $m) {
            $pending += $m->pending;
            $authorizations += $m->authorizations;
        }

        return new ForcedClosurePreview(
            $event->id()->toRfc4122(),
            $event->publicReference()->value,
            $event->name(),
            $event->status()->value,
            $event->organizerMunicipality()->name(),
            array_map(static fn (array $r): array => ['municipality' => (string) $r['municipality'], 'status' => (string) $r['status'], 'organizer' => (bool) $r['organizer']], $rows),
            $statistics->requests,
            $pending,
            $authorizations,
        );
    }

    /**
     * @throws OperationNotAllowed  opérateur non habilité (ou plus), événement inaccessible ou déjà clôturé
     * @throws DomainRuleViolation  mot de passe, motif ou référence invalides, trop de tentatives
     */
    public function close(User $operator, string $eventId, #[\SensitiveParameter] string $password, string $reason, string $typedReference): EventClosureResult
    {
        $this->assertGranted($operator);
        if (!$this->limiter->create($operator->id()->toRfc4122())->consume()->isAccepted()) {
            throw new DomainRuleViolation('Trop de tentatives. Réessayez dans quelques minutes.');
        }
        $reason = trim($reason);
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN_LENGTH || $length > self::REASON_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le motif de clôture est obligatoire (%d à %d caractères).', self::REASON_MIN_LENGTH, self::REASON_MAX_LENGTH));
        }
        if ('' === $password || !$this->hasher->isPasswordValid($operator, $password)) {
            // Jamais le mot de passe : l'opérateur seulement.
            $this->logger->warning('Clôture exceptionnelle refusée : mot de passe invalide.', ['operator' => $operator->id()->toRfc4122()]);
            throw new DomainRuleViolation('Mot de passe incorrect.');
        }
        $event = $this->open($eventId);
        $reference = $event->publicReference()->value;
        if (!self::sameReference($typedReference, $reference)) {
            throw new DomainRuleViolation('La référence saisie ne correspond pas à l’événement.');
        }
        $notice = $this->preview($operator, $eventId);

        $result = $this->lifecycle->closeAsSupport($operator, $eventId, $reason, function (Event $locked) use ($operator, $reference): void {
            // Sous verrou de l'événement et des participations : l'opérateur et son habilitation sont relus, une révocation concurrente l'emporte.
            $current = $this->users->lock($operator);
            if (!$current->isActive() || !$current->isSuperAdmin() || null === $this->grants->lockActive($current->id(), CwsPermission::ForceCloseEvent)) {
                throw new OperationNotAllowed('Habilitation de clôture exceptionnelle absente ou révoquée.');
            }
            if ($locked->status()->isClosed()) {
                throw new OperationNotAllowed('Cet événement est déjà clôturé.');
            }
            if ($locked->publicReference()->value !== $reference) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
        });
        $this->notifier->notify($notice->eventId, $notice->reference, $notice->name, array_map(static fn (array $p): string => $p['municipality'], $notice->participations));

        return $result;
    }

    /** @throws OperationNotAllowed */
    public function assertGranted(User $operator): void
    {
        if (!$operator->isActive() || !$operator->isSuperAdmin() || null === $this->grants->findActive($operator->id(), CwsPermission::ForceCloseEvent)) {
            throw new OperationNotAllowed('Fonction réservée aux opérateurs CWS habilités.');
        }
    }

    private function open(string $eventId): Event
    {
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        if (null === $event) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        if ($event->status()->isClosed()) {
            throw new OperationNotAllowed('Cet événement est déjà clôturé.');
        }

        return $event;
    }

    private static function sameReference(string $typed, string $reference): bool
    {
        return hash_equals($reference, trim($typed));
    }
}
