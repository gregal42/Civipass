<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\PhoneNumberNormalizer;
use App\Request\Application\Command\CorrectIdentityCommand;
use App\Request\Application\Read\IdentityCorrectionPage;
use App\Request\Application\Read\IdentityLookupResult;
use App\Request\Application\Result\IdentityCorrectionResult;
use App\Request\Domain\PartLocator;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Correction de l'identité et des coordonnées du bénéficiaire (LOT 04.4, DM-111, DT-045) : nom, prénom, adresse, e-mail, téléphone, et RIEN
 * d'autre. Ce n'est ni une décision ni un transfert : parts, décisions, fenêtres, référence et point d'intégration LOT 05 ne sont pas touchés.
 *
 * Droits : adhésion effective et ACTIVE à la commune (écriture), rôle INSTRUCTOR ou MUNICIPALITY_ADMIN (EVENT_MANAGER seul : refus 403), dossier
 * ayant une part dans CETTE commune (autre commune ou inexistant : même refus uniforme, 404). Ils sont relus sous verrou. La commune de l'URL et
 * l'identifiant de la demande ne sont jamais crus. Aucun droit d'instruction n'en découle : l'administrateur voit l'identité, la référence et le
 * statut de sa part, pas la fiche.
 *
 * Identité UNIQUE par demande (option A) : la correction est vue de toutes les communes concernées ; une ligne d'audit est écrite pour CHACUNE
 * (champs modifiés, commune auteure, jamais les valeurs). Verrou : la ligne de la demande SEULE, en exclusif, sans autre verrou : aucun cycle avec
 * l'ordre DT-024. Concurrence : la version lue à l'ouverture du formulaire est comparée sous verrou (StaleState si changée) ; une soumission sans
 * changement n'écrit ni version ni audit.
 */
final readonly class RequestIdentityCorrectionService
{
    public function __construct(
        private EventTransactions $transactions,
        private ClockInterface $clock,
        private MunicipalityContext $context,
        private PermitRequestRepository $requests,
        private PhoneNumberNormalizer $phones,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Point d'entrée « Corrections d'identité » (LOT 04.5) : retrouve, par sa référence publique, une demande ayant une part dans CETTE commune.
     * Même contrôle de droits que la correction ; référence inconnue, mal formée ou d'une autre commune : même réponse (`null`), aucune
     * énumération. Lecture seule : ce n'est ni la liste ni la fiche d'instruction.
     *
     * @throws OperationNotAllowed      commune inaccessible
     * @throws IdentityCorrectionDenied rôle INSTRUCTOR ou MUNICIPALITY_ADMIN absent
     */
    public function find(User $agent, string $municipalityId, string $reference): ?IdentityLookupResult
    {
        $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
        $this->assertRole($membership);
        $reference = strtoupper(trim($reference));
        if (1 !== preg_match('/^[A-Z0-9-]{1,20}$/', $reference)) {
            return null;
        }
        $part = $this->requests->findPartByReference($reference, $membership->municipality()->id());
        if (null === $part) {
            return null;
        }
        $request = $part->request();

        return new IdentityLookupResult($request->id()->toRfc4122(), $request->reference()->value, $request->event()->name(), $part->status(), $request->lastName(), $request->firstName());
    }

    /**
     * @throws OperationNotAllowed      commune ou demande inaccessible
     * @throws IdentityCorrectionDenied rôle INSTRUCTOR ou MUNICIPALITY_ADMIN absent
     */
    public function page(User $agent, string $municipalityId, string $requestId): IdentityCorrectionPage
    {
        [$membership, $locator] = $this->authorize($agent, $municipalityId, $requestId);
        $municipality = $membership->municipality()->id();
        $part = $this->requests->findPart($locator->partId, $municipality) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $request = $part->request();

        return new IdentityCorrectionPage(
            $request->id()->toRfc4122(),
            $request->reference()->value,
            $request->event()->name(),
            $part->status(),
            $request->lastName(),
            $request->firstName(),
            $request->address() ?? '',
            $request->email() ?? '',
            $request->phone() ?? '',
            $request->identityVersion(),
            \count($request->municipalities()) - 1,
            $membership->hasRole(MunicipalityRole::Instructor),
        );
    }

    /**
     * @throws OperationNotAllowed      commune ou demande inaccessible
     * @throws IdentityCorrectionDenied rôle absent
     * @throws DomainRuleViolation      attestation manquante, nom/prénom/adresse/coordonnées invalides (rien n'est écrit)
     * @throws OperationConflict        identité corrigée entre-temps (StaleState) ou conflit technique
     */
    public function correct(User $agent, string $municipalityId, string $requestId, CorrectIdentityCommand $command): IdentityCorrectionResult
    {
        [$membership] = $this->authorize($agent, $municipalityId, $requestId);
        if (!$command->sameBeneficiary) {
            throw new DomainRuleViolation('Confirmez qu\'il s\'agit bien de la même personne : une correction ne permet pas de changer de bénéficiaire.');
        }
        $email = '' === trim($command->email) ? null : EmailAddress::fromString($command->email);
        $phone = '' === trim($command->phone) ? null : $this->phones->normalize($command->phone);
        $own = $membership->municipality()->id();

        return $this->transactions->run(function () use ($agent, $municipalityId, $requestId, $own, $command, $email, $phone): IdentityCorrectionResult {
            $request = $this->requests->lockRequest(Uuid::fromString($requestId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            // Droits relus SOUS verrou (adhésion ou rôles modifiés depuis le contrôle initial).
            $this->assertRole($this->context->requireMembership($agent, $municipalityId, write: true));
            if ($request->identityVersion() !== $command->expectedVersion) {
                throw new OperationConflict(ConflictCause::StaleState);
            }

            $others = \count($request->municipalities()) - 1;
            $changed = $request->correctIdentity($command->lastName, $command->firstName, '' === trim($command->address) ? null : $command->address, $email, $phone);
            if ([] === $changed) {
                return new IdentityCorrectionResult($request->reference()->value, [], $others);
            }
            $this->record($request, $agent, $own, $changed);

            return new IdentityCorrectionResult($request->reference()->value, $changed, $others);
        });
    }

    /**
     * Une ligne d'audit PAR commune ayant une part : chacune constate la correction, son auteur (agent et commune) et la date. Noms de champs
     * seulement : jamais d'ancienne ni de nouvelle valeur (DT-037).
     *
     * @param list<string> $changed
     */
    private function record(PermitRequest $request, User $agent, Uuid $own, array $changed): void
    {
        $now = $this->clock->now(); // pris sous verrou (DT-038)
        $communes = $request->municipalities();
        foreach ($communes as $part) {
            $municipality = $part->municipality()->id();
            $this->audit->record(new AuditEvent(
                $now,
                AuditAction::RequestIdentityCorrected,
                AuditSubjectType::PermitRequest,
                $request->id(),
                $municipality,
                AuditActor::user($agent->id()),
                null,
                [
                    'fields' => $changed,
                    'reference' => $request->reference()->value,
                    'correctedByMunicipality' => $own->toRfc4122(),
                    'ownMunicipality' => $municipality->equals($own),
                    'identityVersion' => $request->identityVersion(),
                    'municipalities' => \count($communes),
                ],
            ));
        }
    }

    /**
     * @return array{MunicipalityMembership, PartLocator}
     */
    private function authorize(User $agent, string $municipalityId, string $requestId): array
    {
        $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
        $this->assertRole($membership);
        $locator = Uuid::isValid($requestId) ? $this->requests->locatePart(Uuid::fromString($requestId), $membership->municipality()->id()) : null;
        if (null === $locator) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return [$membership, $locator];
    }

    private function assertRole(MunicipalityMembership $membership): void
    {
        if (!$membership->hasRole(MunicipalityRole::Instructor) && !$membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            throw new IdentityCorrectionDenied('Rôle INSTRUCTOR ou MUNICIPALITY_ADMIN requis.');
        }
    }
}
