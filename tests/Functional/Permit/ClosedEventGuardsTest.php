<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Permit\Application\PermitAccessService;
use App\Permit\Application\PermitRevisionGuard;
use App\Request\Application\Command\AttachmentReference;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\DirectIssuanceReader;
use App\Request\Application\MunicipalRequestReader;
use App\Request\Application\RequestIdentityCorrectionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Application\TemporaryUploadService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Shared\Domain\ClosedEventAccess;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ClosedEventScenarioTestCase;
use App\Tests\Support\SampleFiles as S;
use Symfony\Component\Uid\Uuid;

/**
 * Gardes MÉTIER de la clôture (LOT 05.6.2), sans passer par HTTP : chaque lecteur et service qui touche un dossier, une autorisation, des plaques, un
 * justificatif ou un lien refuse un événement clos APRÈS le contrôle des habilitations (un utilisateur non habilité reçoit toujours le refus uniforme).
 * Faille N1 : aucun rejeu d'idempotence ne renvoie une référence, une décision ou une information après la clôture (dépôt public avec ou sans pièces
 * jointes, délivrance directe). Les invitations non consommées sont révoquées.
 */
final class ClosedEventGuardsTest extends ClosedEventScenarioTestCase
{
    private const DIRECT_KEY = 'direct-issuance-key-0000000001';

    private function svc(string $class): object
    {
        return static::getContainer()->get($class);
    }

    private function assertClosed(callable $call, string $label): void
    {
        try {
            $call();
            self::fail($label.' : un événement clos ne doit plus rien servir.');
        } catch (ClosedEventAccess) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertUniformRefusal(callable $call, string $label): void
    {
        try {
            $call();
            self::fail($label.' : refus attendu.');
        } catch (ClosedEventAccess) {
            self::fail($label.' : un utilisateur non habilité ne doit JAMAIS apprendre que l\'événement est clos.');
        } catch (OperationNotAllowed) {
            $this->addToAssertionCount(1);
        }
    }

    /** Dépôt public (sans pièce jointe, ou avec une pièce neuve) sous une clé d'idempotence donnée. */
    private function deposit(string $key, bool $withAttachment): \App\Request\Application\Result\SubmittedRequest
    {
        $attachments = [];
        if ($withAttachment) {
            $stored = $this->svc(TemporaryUploadService::class)->store(S::temporaryFile(S::pdf()), 'justificatif.pdf');
            $attachments[] = new AttachmentReference($stored->temporaryKey, 'justificatif.pdf');
        }

        return $this->svc(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Durand', 'Alice', null, 'alice.rejeu@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, ['AB-123-CD'], [$this->organizerZone->id()->toRfc4122()], true, null, $attachments),
            $key,
        );
    }

    private function directCommand(): SubmitRequestCommand
    {
        return new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Bernard', 'Louis', null, 'louis.direct@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, ['ZX-987-YW'], [$this->organizerZone->id()->toRfc4122()], true);
    }

    // ------------------------------------------------------------------ lecteurs et services

    public function testEveryReaderAndServiceRefusesAClosedEventAfterTheEntitlementCheck(): void
    {
        $agent = $this->managed($this->instructor);
        $admin = $this->managed($this->admin);
        $m = $this->organizerId;
        $r = $this->requestId;
        $reference = (string) $this->em()->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request');
        // Contrôle préalable : tout est servi tant que l'événement est actif.
        self::assertNotNull($this->svc(MunicipalRequestReader::class)->detail($agent, $m, $r));
        self::assertNotNull($this->svc(RequestIdentityCorrectionService::class)->find($agent, $m, $reference));
        $this->svc(PermitAccessService::class)->overview($agent, $m, $r);

        $this->close();
        $agent = $this->managed($this->instructor);
        $admin = $this->managed($this->admin);

        $reader = $this->svc(MunicipalRequestReader::class);
        $this->assertClosed(fn () => $reader->detail($agent, $m, $r), 'détail du dossier');
        $this->assertClosed(fn () => $reader->decisionPage($agent, $m, $r), 'page de décision');
        $this->assertClosed(fn () => $reader->attachment($agent, $m, $r, $this->attachmentId), 'justificatif');
        $this->assertClosed(fn () => $reader->listing($agent, $m, $this->event->id()->toRfc4122(), new MunicipalRequestQuery()), 'liste de l\'événement clos');
        $this->assertClosed(fn () => $this->svc(RequestIdentityCorrectionService::class)->find($agent, $m, $reference), 'recherche d\'identité');
        $this->assertClosed(fn () => $this->svc(RequestIdentityCorrectionService::class)->page($agent, $m, $r), 'fiche d\'identité');
        $this->assertClosed(fn () => $this->svc(\App\Permit\Application\PermitRightsOverviewReader::class)->overview($agent, $m, $r), 'droits, plaques, zones, QR');
        $guard = $this->svc(PermitRevisionGuard::class);
        $this->assertClosed(fn () => $guard->locate($admin, $m, $r, write: false, adminOnly: true), 'portée d\'une révision (remplacement du QR)');
        $access = $this->svc(PermitAccessService::class);
        $this->assertClosed(fn () => $access->overview($agent, $m, $r), 'liens bénéficiaires');
        $this->assertClosed(fn () => $access->issue($agent, $m, $r), 'émission d\'un lien');
        $this->assertClosed(fn () => $access->requestNotification($agent, $m, $r, Uuid::v7()->toRfc4122()), 'notification manuelle');
        $this->assertClosed(fn () => $access->revoke($agent, $m, $r, $this->linkId), 'révocation d\'un lien');
        // La liste ne propose plus l'événement clos et ne le sélectionne jamais.
        $listing = $reader->listing($agent, $m, null, new MunicipalRequestQuery());
        self::assertNull($listing->page);
        self::assertSame([], $listing->events);
    }

    public function testAnUnauthorisedUserStillGetsTheUniformRefusalNeverTheClosedAnswer(): void
    {
        $this->close();
        $outsider = $this->managed($this->outsider);
        $foreign = $this->outsiderCommuneId;
        $reader = $this->svc(MunicipalRequestReader::class);
        $access = $this->svc(PermitAccessService::class);

        $this->assertUniformRefusal(fn () => $reader->detail($outsider, $this->organizerId, $this->requestId), 'commune d\'autrui');
        $this->assertUniformRefusal(fn () => $reader->detail($outsider, $foreign, $this->requestId), 'dossier d\'autrui');
        $this->assertUniformRefusal(fn () => $reader->attachment($outsider, $foreign, $this->requestId, $this->attachmentId), 'justificatif d\'autrui');
        $this->assertUniformRefusal(fn () => $access->overview($outsider, $foreign, $this->requestId), 'liens d\'autrui');
        $this->assertUniformRefusal(fn () => $this->svc(PermitRevisionGuard::class)->locate($outsider, $foreign, $this->requestId, write: false), 'droits d\'autrui');
        $this->assertUniformRefusal(fn () => $this->svc(RequestIdentityCorrectionService::class)->page($outsider, $foreign, $this->requestId), 'identité d\'autrui');
    }

    public function testADirectIssuanceConfirmationIsNoLongerServedOnAClosedEvent(): void
    {
        $agent = $this->managed($this->instructor);
        $issued = $this->svc(RequestSubmissionService::class)->issueDirectly($agent, $this->organizerId, $this->directCommand(), ApprovalDuration::untilClosure(), self::DIRECT_KEY);
        $reader = $this->svc(DirectIssuanceReader::class);
        self::assertNotNull($reader->confirmation($agent, $this->organizerId, $issued->requestId), 'servie tant que l\'événement est actif');

        $this->close();

        $this->assertClosed(fn () => $reader->confirmation($this->managed($this->instructor), $this->organizerId, $issued->requestId), 'confirmation de délivrance directe');
    }

    // ------------------------------------------------------------------ faille N1 : rejeu d'idempotence

    public function testNoIdempotencyReplayReturnsAnythingOnAClosedEventForAPublicDepositWithoutAttachments(): void
    {
        $first = $this->deposit('replay-public-key-000000000001', false);
        self::assertSame($first->requestId, $this->deposit('replay-public-key-000000000001', false)->requestId, 'avant la clôture, le rejeu est servi (double envoi)');

        $this->close();

        $this->expectException(DomainRuleViolation::class);
        $this->deposit('replay-public-key-000000000001', false);
    }

    public function testNoIdempotencyReplayReturnsAnythingOnAClosedEventForAPublicDepositWithAttachments(): void
    {
        $first = $this->deposit('replay-public-key-000000000002', true);
        self::assertSame($first->requestId, $this->deposit('replay-public-key-000000000002', true)->requestId, 'avant la clôture, le rejeu avec pièces est servi');
        $rows = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM request_permit_request');

        $this->close();

        // La pièce jointe est jointe à la tentative : le contrôle SANS verrou (avant l'inspection des fichiers) doit déjà refuser.
        try {
            $this->deposit('replay-public-key-000000000002', true);
            self::fail('Le rejeu ne doit rien renvoyer.');
        } catch (DomainRuleViolation $e) {
            self::assertSame('Les demandes ne sont pas ouvertes pour cet événement.', $e->getMessage());
            self::assertStringNotContainsString($first->reference, $e->getMessage());
        }
        self::assertSame($rows, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM request_permit_request'), 'aucune demande créée');
    }

    public function testNoIdempotencyReplayReturnsTheIssuedDecisionOnAClosedEventForADirectIssuance(): void
    {
        $agent = $this->managed($this->instructor);
        $submissions = $this->svc(RequestSubmissionService::class);
        $first = $submissions->issueDirectly($agent, $this->organizerId, $this->directCommand(), ApprovalDuration::untilClosure(), self::DIRECT_KEY);
        self::assertNotNull($first->decision, 'décision délivrée');
        self::assertTrue($submissions->issueDirectly($agent, $this->organizerId, $this->directCommand(), ApprovalDuration::untilClosure(), self::DIRECT_KEY)->replayed, 'avant la clôture, le rejeu est servi');

        $this->close();

        try {
            $submissions->issueDirectly($this->managed($this->instructor), $this->organizerId, $this->directCommand(), ApprovalDuration::untilClosure(), self::DIRECT_KEY);
            self::fail('Le rejeu d\'une délivrance directe ne doit plus rien renvoyer.');
        } catch (DomainRuleViolation|OperationNotAllowed $e) {
            self::assertStringNotContainsString($first->reference, $e->getMessage());
        }
    }

    public function testTheIdempotencyKeysAreLeftUntouchedByTheClosure(): void
    {
        $before = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM request_idempotency_key');
        self::assertGreaterThan(0, $before);

        $this->close();

        self::assertSame($before, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM request_idempotency_key'), 'Q4 : aucune suppression systématique à la clôture');
    }

    // ------------------------------------------------------------------ invitations et lien de provisionnement


}
