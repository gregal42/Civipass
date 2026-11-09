<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Identity\Domain\User;
use App\Request\Application\Command\CorrectIdentityCommand;
use App\Request\Application\IdentityCorrectionDenied;
use App\Request\Application\RequestIdentityCorrectionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\IdentityCorrectionFactory;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Correction d'identité du bénéficiaire (LOT 04.4, DM-111) : cinq champs, jamais une décision ni un transfert ; droits, multicommunal,
 * concurrence (version), audit sans valeurs personnelles, atomicité.
 */
#[Group('requires-request-schema')]
final class RequestIdentityCorrectionTest extends MunicipalDecisionTestCase
{
    private function corrector(?AuditTrail $audit = null): RequestIdentityCorrectionService
    {
        return IdentityCorrectionFactory::create(self::getContainer(), $this->clock, $audit);
    }

    private function correction(int $version = 0, string $last = 'Martin', string $first = 'Camille', string $address = '', string $email = 'camille@exemple.fr', string $phone = '', bool $same = true): CorrectIdentityCommand
    {
        return new CorrectIdentityCommand($version, $last, $first, $address, $email, $phone, $same);
    }

    /** @return array<string, mixed> */
    private function row(string $requestId): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT last_name, first_name, address, email, phone, identity_version, public_reference FROM request_permit_request WHERE id = :id', ['id' => (string) hex2bin(str_replace('-', '', $requestId))]);
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function correctionAudits(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT LOWER(HEX(municipality_id)) AS municipality, LOWER(HEX(actor_id)) AS actor, previous_value, new_value, occurred_at FROM audit_log WHERE action = 'REQUEST_IDENTITY_CORRECTED' ORDER BY municipality");

        return array_map(static fn (array $r): array => ['municipality' => $r['municipality'], 'actor' => $r['actor'], 'previous' => $r['previous_value'], 'raw' => $r['new_value'], 'new' => json_decode((string) $r['new_value'], true), 'at' => $r['occurred_at']], $rows);
    }

    private function mid(\App\Municipality\Domain\Municipality $m): string
    {
        return $m->id()->toRfc4122();
    }

    private function correctAs(User $agent, \App\Municipality\Domain\Municipality $commune, string $requestId, CorrectIdentityCommand $c): \App\Request\Application\Result\IdentityCorrectionResult
    {
        return $this->corrector()->correct($agent, $this->mid($commune), $requestId, $c);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function fields(): iterable
    {
        yield 'nom' => ['lastName', 'last_name', 'Dupont-Durand'];
        yield 'prénom' => ['firstName', 'first_name', 'Camillia'];
        yield 'adresse' => ['address', 'address', '12 rue des Lilas, 33000 Bordeaux'];
        yield 'e-mail' => ['email', 'email', 'nouvelle@exemple.fr'];
        yield 'téléphone' => ['phone', 'phone', '06 12 34 56 78'];
    }

    #[DataProvider('fields')]
    public function testEachOfTheFiveFieldsIsCorrectedAloneAndOnlyThatFieldIsReportedAndAudited(string $field, string $column, string $value): void
    {
        $id = $this->submit([$this->organizerZone]);
        $before = $this->row($id);
        $args = ['last' => 'Martin', 'first' => 'Camille', 'address' => '', 'email' => 'camille@exemple.fr', 'phone' => ''];
        $map = ['lastName' => 'last', 'firstName' => 'first', 'address' => 'address', 'email' => 'email', 'phone' => 'phone'];
        $args[$map[$field]] = $value;

        $result = $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, $args['last'], $args['first'], $args['address'], $args['email'], $args['phone']));

        self::assertSame([$field], $result->changedFields);
        $after = $this->row($id);
        self::assertSame(1, $after['identity_version']);
        self::assertSame($before['public_reference'], $after['public_reference']);
        self::assertNotSame($before[$column], $after[$column]);
        if ('phone' === $field) {
            self::assertSame('+33612345678', $after['phone'], 'même normalisation E.164 que le dépôt');
        }
        $audits = $this->correctionAudits();
        self::assertCount(1, $audits);
        self::assertSame([$field], $audits[0]['new']['fields']);
        self::assertNull($audits[0]['previous']);
    }

    public function testASubmissionWithoutChangeWritesNothingNeitherVersionNorAudit(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $before = $this->row($id);

        $result = $this->correctAs($this->instructor, $this->organizer, $id, $this->correction());

        self::assertFalse($result->changed());
        self::assertSame($before, $this->row($id));
        self::assertSame([], $this->correctionAudits());
    }

    public function testSurroundingSpacesAndAnEquivalentPhoneFormatAreNotAChange(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, 'Martin', 'Camille', '', 'camille@exemple.fr', '0612345678'));
        $audits = \count($this->correctionAudits());

        $result = $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(1, '  Martin ', 'Camille', '', 'camille@exemple.fr', '06 12 34 56 78'));

        self::assertFalse($result->changed());
        self::assertSame($audits, \count($this->correctionAudits()));
        self::assertSame(1, $this->row($id)['identity_version']);
    }

    public function testTheBeneficiaryConfirmationIsMandatory(): void
    {
        $id = $this->submit([$this->organizerZone]);

        $this->expectException(DomainRuleViolation::class);
        try {
            $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, 'Autre', 'Personne', same: false));
        } finally {
            self::assertSame('Martin', $this->row($id)['last_name']);
            self::assertSame([], $this->correctionAudits());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'nom vide' => ['', 'Camille', 'camille@exemple.fr', ''];
        yield 'prénom trop long' => ['Martin', str_repeat('a', 121), 'camille@exemple.fr', ''];
        yield 'e-mail invalide' => ['Martin', 'Camille', 'pas-un-email', ''];
        yield 'téléphone invalide' => ['Martin', 'Camille', 'camille@exemple.fr', '123'];
        yield 'aucun moyen de contact' => ['Martin', 'Camille', '', ''];
    }

    #[DataProvider('invalid')]
    public function testAnInvalidInputIsRefusedAndNothingIsPartiallyWritten(string $last, string $first, string $email, string $phone): void
    {
        $id = $this->submit([$this->organizerZone]);
        $before = $this->row($id);

        try {
            $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, $last, $first, '', $email, $phone));
            self::fail('Une saisie invalide doit être refusée.');
        } catch (DomainRuleViolation) {
        }

        self::assertSame($before, $this->row($id));
        self::assertSame([], $this->correctionAudits());
    }

    public function testPermissionsInstructorAndAdminMayCorrectAnEventManagerMayNotAndNeitherMayAForeignCommune(): void
    {
        $id = $this->submit([$this->organizerZone]);

        try {
            $this->correctAs($this->managerOnly, $this->organizer, $id, $this->correction(0, 'Nouveau'));
            self::fail('EVENT_MANAGER seul ne corrige pas une identité.');
        } catch (IdentityCorrectionDenied) {
        }
        try {
            $this->corrector()->page($this->managerOnly, $this->mid($this->organizer), $id);
            self::fail('EVENT_MANAGER seul ne voit pas non plus l\'écran.');
        } catch (IdentityCorrectionDenied) {
        }
        foreach ([[$this->outsiderInstructor, $this->outsider], [$this->partnerInstructor, $this->partner], [$this->instructor, $this->outsider]] as [$agent, $commune]) {
            try {
                $this->correctAs($agent, $commune, $id, $this->correction(0, 'Intrus'));
                self::fail('Une commune sans part (ou un agent étranger) est refusée.');
            } catch (OperationNotAllowed) {
            }
        }
        self::assertSame('Martin', $this->row($id)['last_name']);

        self::assertTrue($this->correctAs($this->adminOnly, $this->organizer, $id, $this->correction(0, 'ParAdmin'))->changed());
        self::assertTrue($this->correctAs($this->instructor, $this->organizer, $id, $this->correction(1, 'ParInstructeur'))->changed());
    }

    public function testAnUnknownOrMalformedRequestIsTheSameUniformRefusal(): void
    {
        foreach (['01a1bd3b-17af-736a-bfb5-d3888718b577', 'pas-un-uuid'] as $bad) {
            try {
                $this->corrector()->correct($this->instructor, $this->mid($this->organizer), $bad, $this->correction(0, 'X'));
                self::fail('Demande inexistante : refus.');
            } catch (OperationNotAllowed $e) {
                self::assertSame('Ressource inaccessible.', $e->getMessage());
            }
        }
    }

    public function testTheCorrectionOpensNoInstructionRightForTheAdministrator(): void
    {
        $id = $this->submit([$this->organizerZone]);

        $page = $this->corrector()->page($this->adminOnly, $this->mid($this->organizer), $id);

        self::assertFalse($page->consultable, 'aucun lien vers la fiche d\'instruction pour un administrateur sans INSTRUCTOR');
        self::assertTrue($this->corrector()->page($this->instructor, $this->mid($this->organizer), $id)->consultable);
    }

    public function testAPendingRequestStaysPendingAndGainsNoDecision(): void
    {
        $id = $this->submit([$this->organizerZone]);

        $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, 'Nouveau'));

        self::assertSame('PENDING', $this->partStatus($id, $this->organizer));
        self::assertNull($this->decisionRow($id, $this->organizer));
        self::assertSame(0, $this->rows('request_permit_request_decision'));
    }

    public function testAnApprovedDecisionItsWindowAndItsReferenceAreUntouched(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $this->instruction()->approve($this->instructor, $this->mid($this->organizer), $id, ApprovalDuration::fixed(DurationMode::H72));
        $decision = $this->decisionRow($id, $this->organizer);
        $reference = $this->row($id)['public_reference'];
        $audits = $this->rows('audit_log WHERE action = \'REQUEST_APPROVED\'');

        $this->correctAs($this->adminOnly, $this->organizer, $id, $this->correction(0, 'Corrigé'));

        self::assertSame('Corrigé', $this->row($id)['last_name']);
        self::assertSame('APPROVED', $this->partStatus($id, $this->organizer));
        self::assertSame($decision, $this->decisionRow($id, $this->organizer), 'décision, fenêtre et agent décisionnaire inchangés');
        self::assertSame($reference, $this->row($id)['public_reference']);
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        self::assertSame($audits, $this->rows('audit_log WHERE action = \'REQUEST_APPROVED\''));
    }

    public function testARefusedRequestCanBeCorrectedAndStaysRefused(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $this->instruction()->refuse($this->instructor, $this->mid($this->organizer), $id, 'duplicate', null);
        $decision = $this->decisionRow($id, $this->organizer);

        $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, 'Martin', 'Camille', '', 'nouveau@exemple.fr'));

        self::assertSame('REFUSED', $this->partStatus($id, $this->organizer));
        self::assertSame($decision, $this->decisionRow($id, $this->organizer));
    }

    public function testADirectIssuanceIsCorrectedWithoutReissuingNorTriggeringTheLot05Port(): void
    {
        $listener = new class () implements \App\Request\Application\ApprovedPartListener {
            public int $calls = 0;

            public function partApproved(\App\Request\Domain\PermitRequestDecision $decision): void
            {
                ++$this->calls;
            }
        };
        $issued = $this->issuer(null, $listener)->issueDirectly($this->instructor, $this->mid($this->organizer), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $this->key());
        self::assertSame(1, $listener->calls);
        $decision = $this->decisionRow($issued->requestId, $this->organizer);
        $reference = $issued->reference;

        $this->correctAs($this->adminOnly, $this->organizer, $issued->requestId, $this->correction(0, 'Corrigé'));

        self::assertSame(1, $listener->calls, 'la correction ne réémet ni ne notifie rien');
        self::assertSame($decision, $this->decisionRow($issued->requestId, $this->organizer));
        self::assertSame($reference, $this->row($issued->requestId)['public_reference']);
        self::assertSame(1, $this->rows('request_permit_request_decision'));
    }

    public function testAMulticommunalCorrectionIsAuditedForEachCommuneAndMentionsOthers(): void
    {
        $id = $this->submit();
        $this->instruction()->refuse($this->partnerInstructor, $this->mid($this->partner), $id, 'duplicate', null);

        $result = $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, 'Dupont', 'Camille', '', 'camille@exemple.fr', '0611223344'));

        self::assertSame(1, $result->otherMunicipalities);
        self::assertSame(['lastName', 'phone'], $result->changedFields);
        $audits = $this->correctionAudits();
        self::assertCount(2, $audits, 'une ligne par commune ayant une part');
        $byCommune = array_column($audits, null, 'municipality');
        foreach ([$this->organizer, $this->partner] as $commune) {
            $key = str_replace('-', '', $this->mid($commune));
            self::assertArrayHasKey($key, $byCommune);
            self::assertSame(str_replace('-', '', $this->instructor->id()->toRfc4122()), $byCommune[$key]['actor']);
            self::assertSame($this->mid($this->organizer), $byCommune[$key]['new']['correctedByMunicipality']);
            self::assertSame(2, $byCommune[$key]['new']['municipalities']);
        }
        self::assertTrue($byCommune[str_replace('-', '', $this->mid($this->organizer))]['new']['ownMunicipality']);
        self::assertFalse($byCommune[str_replace('-', '', $this->mid($this->partner))]['new']['ownMunicipality']);
        self::assertSame('Dupont', $this->row($id)['last_name'], 'identité unique : visible de toutes les communes');
        self::assertSame('REFUSED', $this->partStatus($id, $this->partner), 'la décision de l\'autre commune est intacte');
        self::assertSame('PENDING', $this->partStatus($id, $this->organizer));
    }

    public function testThePartnerCommuneMayAlsoCorrectAMulticommunalRequest(): void
    {
        $id = $this->submit();

        self::assertTrue($this->correctAs($this->partnerInstructor, $this->partner, $id, $this->correction(0, 'ParPartenaire'))->changed());
        self::assertSame('ParPartenaire', $this->row($id)['last_name']);
        self::assertSame(1, $this->corrector()->page($this->instructor, $this->mid($this->organizer), $id)->otherMunicipalities);
    }

    public function testTheAuditNeverContainsPersonalValues(): void
    {
        $id = $this->submit();

        $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, 'Dupont-Secret', 'Zoé', '1 rue Privée', 'secret@exemple.fr', '0611223344'));

        foreach ($this->correctionAudits() as $audit) {
            foreach (['Dupont-Secret', 'Zoé', 'Martin', 'Camille', 'Privée', 'secret@exemple.fr', 'camille@exemple.fr', '0611223344', '+33611223344'] as $secret) {
                self::assertStringNotContainsString($secret, $audit['raw']);
            }
            self::assertNull($audit['previous']);
            self::assertSame(['lastName', 'firstName', 'address', 'email', 'phone'], $audit['new']['fields']);
        }
    }

    public function testAStaleVersionIsRefusedAndNeverOverwritesTheEarlierCorrection(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $firstOpen = $this->corrector()->page($this->instructor, $this->mid($this->organizer), $id)->version;
        $secondOpen = $this->corrector()->page($this->adminOnly, $this->mid($this->organizer), $id)->version;
        self::assertSame($firstOpen, $secondOpen);

        $this->correctAs($this->instructor, $this->organizer, $id, $this->correction($firstOpen, 'PremierAgent'));
        try {
            $this->correctAs($this->adminOnly, $this->organizer, $id, $this->correction($secondOpen, 'SecondAgent'));
            self::fail('La seconde correction, basée sur une version périmée, doit être refusée.');
        } catch (OperationConflict $conflict) {
            self::assertSame(ConflictCause::StaleState, $conflict->cause);
        }

        self::assertSame('PremierAgent', $this->row($id)['last_name']);
        self::assertSame(1, $this->row($id)['identity_version']);
        self::assertCount(1, $this->correctionAudits());
    }

    public function testAfterReopeningWithTheCurrentVersionTheSecondAgentMayCorrectDeliberately(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, 'PremierAgent'));

        $fresh = $this->corrector()->page($this->adminOnly, $this->mid($this->organizer), $id)->version;

        self::assertSame(1, $fresh);
        self::assertTrue($this->correctAs($this->adminOnly, $this->organizer, $id, $this->correction($fresh, 'SecondAgent'))->changed());
        self::assertSame(2, $this->row($id)['identity_version']);
    }

    public function testAnAuditFailureRollsTheWholeCorrectionBack(): void
    {
        $id = $this->submit();
        $failing = new class () implements AuditTrail {
            public function record(AuditEvent $event): void
            {
                throw new \RuntimeException('audit indisponible');
            }
        };
        $before = $this->row($id);

        try {
            $this->corrector($failing)->correct($this->instructor, $this->mid($this->organizer), $id, $this->correction(0, 'Perdu'));
            self::fail('Un échec d\'audit doit annuler la correction.');
        } catch (\RuntimeException $e) {
            self::assertSame('audit indisponible', $e->getMessage());
        }

        self::assertSame($before, $this->row($id));
        self::assertSame([], $this->correctionAudits());
    }

    public function testTheCorrectionNeverChangesOtherPartsOfTheRequest(): void
    {
        $id = $this->submit();
        $counts = fn (): array => [$this->rows('request_permit_request'), $this->rows('request_permit_request_municipality'), $this->rows('request_permit_request_zone'), $this->rows('request_permit_request_vehicle'), $this->rows('request_permit_request_attachment'), $this->rows('request_idempotency_key')];
        $before = $counts();

        $this->correctAs($this->instructor, $this->organizer, $id, $this->correction(0, 'Corrigé'));

        self::assertSame($before, $counts());
    }
}
