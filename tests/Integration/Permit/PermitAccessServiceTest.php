<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\BeneficiaryPassReader;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessService;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\Read\AccessLinkView;
use App\Permit\Domain\AccessSecret;
use App\Permit\Domain\Permit;
use App\Permit\Domain\RightState;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Liens d'accès du bénéficiaire (LOT 05.2, DM-114) : secret unique stocké sous forme d'empreinte, plusieurs liens actifs, révocation individuelle
 * et explicite, expiration (fin effective de l'événement + 30 jours), QR stable, permissions des agents, isolation entre communes, audit sans secret.
 * Horloge : 12 juin 2026 09:00 UTC ; événement du 10 au 20 juin 2026 (heure de Paris), donc expiration le 20 juillet 2026.
 */
#[Group('requires-request-schema')]
final class PermitAccessServiceTest extends MunicipalDecisionTestCase
{
    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function access(?\DateTimeImmutable $now = null): PermitAccessService
    {
        // Horloge figée (le conteneur utilise la vraie horloge, hors de la période de l'événement de test).
        $clock = null === $now ? $this->clock : new \Symfony\Component\Clock\MockClock($now->format('Y-m-d H:i:s').' UTC');

        return new PermitAccessService(
            self::getContainer()->get(\App\Event\Application\Support\EventTransactions::class),
            self::getContainer()->get(\App\Event\Application\Support\EventLocker::class),
            $clock,
            self::getContainer()->get(\App\Municipality\Application\MunicipalityContext::class),
            self::getContainer()->get(\App\Permit\Domain\PermitRepository::class),
            self::getContainer()->get(\App\Permit\Domain\PermitAccessTokenRepository::class),
            self::getContainer()->get(\App\Shared\Application\AuditTrail::class),
            self::getContainer()->get(\App\Permit\Application\NotificationStatusProvider::class),
            self::getContainer()->get(\App\Permit\Application\ManualNotificationRequester::class),
            self::getContainer()->get(\App\Permit\Application\SystemAccessLinks::class),
        );
    }

    private function reader(): BeneficiaryPassReader
    {
        return self::getContainer()->get(BeneficiaryPassReader::class);
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    /**
     * Demande approuvée par la commune organisatrice (zone organisatrice seule par défaut).
     *
     * @param list<\App\Event\Domain\EventZone>|null $zones
     */
    private function approved(?array $zones = null): string
    {
        $id = $this->submit($zones ?? [$this->organizerZone]);
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));

        return $id;
    }

    private function issue(string $requestId, ?User $agent = null, ?Municipality $commune = null): string
    {
        return $this->access()->issue($this->managed($agent ?? $this->instructor), ($commune ?? $this->organizer)->id()->toRfc4122(), $requestId)->secret;
    }

    /** @return list<array<string, mixed>> */
    private function tokenRows(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, secret_hash, revoked_at, expires_at FROM permit_access_token ORDER BY created_at, id');

        return array_map(fn (array $r): array => ['id' => $this->uuid((string) $r['id'])] + $r, $rows);
    }

    public function testASecretIsRandomAndOnlyItsSha256DigestIsStored(): void
    {
        $request = $this->approved();

        $secret = $this->issue($request);
        $rows = $this->tokenRows();

        self::assertSame(43, \strlen($secret));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret);
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $secret), $rows[0]['secret_hash']);
        $dump = json_encode($this->em->getConnection()->fetchAllAssociative('SELECT secret_hash, revoked_at, created_at, expires_at FROM permit_access_token'), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $dump, 'le secret n\'est jamais écrit en clair');
        self::assertStringNotContainsString('/laissez-passer/', $dump, 'aucune URL complète n\'est stockée');
    }

    public function testSecretsAreUniqueAcrossIssues(): void
    {
        $request = $this->approved();

        $secrets = [];
        for ($i = 0; $i < 25; ++$i) {
            $secrets[] = AccessSecret::generate()->value;
        }
        $secrets[] = $this->issue($request);
        $secrets[] = $this->issue($request);

        self::assertCount(\count($secrets), array_unique($secrets));
    }

    public function testSeveralLinksCoexistAndANewLinkDoesNotRevokeThePreviousOnes(): void
    {
        $request = $this->approved();

        $first = $this->issue($request);
        $second = $this->issue($request);

        self::assertNotNull($this->reader()->find($first, $this->at('2026-06-12 10:00:00')));
        self::assertNotNull($this->reader()->find($second, $this->at('2026-06-12 10:00:00')));
        $overview = $this->access()->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request);
        self::assertSame(2, $overview->activeCount());
        self::assertSame([null, null], array_column($this->tokenRows(), 'revoked_at'));
    }

    public function testRevokingOneLinkLeavesTheOthersWorking(): void
    {
        $request = $this->approved();
        $first = $this->issue($request);
        $second = $this->issue($request);
        $firstId = (string) $this->tokenRows()[0]['id'];

        $done = $this->access()->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, $firstId);

        self::assertTrue($done);
        self::assertNull($this->reader()->find($first, $this->at('2026-06-12 10:00:00')), 'lien révoqué : même réponse qu\'un lien inconnu');
        self::assertNotNull($this->reader()->find($second, $this->at('2026-06-12 10:00:00')));
    }

    public function testRevokingTwiceIsIdempotentWithASingleAudit(): void
    {
        $request = $this->approved();
        $this->issue($request);
        $id = (string) $this->tokenRows()[0]['id'];
        $commune = $this->organizer->id()->toRfc4122();

        self::assertTrue($this->access()->revoke($this->managed($this->instructor), $commune, $request, $id));
        self::assertFalse($this->access()->revoke($this->managed($this->instructor), $commune, $request, $id));

        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_REVOKED\''));
    }

    public function testRevocationHasPriorityOverTheExpirationDate(): void
    {
        $request = $this->approved();
        $secret = $this->issue($request);
        $this->access()->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, (string) $this->tokenRows()[0]['id']);

        self::assertNull($this->reader()->find($secret, $this->at('2026-06-12 10:00:00')), 'révoqué avant l\'expiration');
        self::assertNull($this->reader()->find($secret, $this->at('2026-09-01 10:00:00')), 'révoqué et expiré');
    }

    public function testTheLinkExpiresThirtyDaysAfterTheEffectiveEndOfTheEvent(): void
    {
        $request = $this->approved();
        $secret = $this->issue($request);
        $end = $this->event->endsAt() ?? self::fail('Fin attendue.');
        $limit = $end->modify('+30 days');

        self::assertNotNull($this->reader()->find($secret, $this->at('2026-06-12 10:00:00')));
        self::assertNotNull($this->reader()->find($secret, $limit->modify('-1 second')));
        self::assertNull($this->reader()->find($secret, $limit), 'expiré à l\'instant exact');
        self::assertNull($this->reader()->find($secret, $limit->modify('+1 day')));
        self::assertSame($limit->format('Y-m-d H:i:s'), $this->tokenRows()[0]['expires_at'], 'instantané informatif enregistré à l\'émission');
    }

    public function testAnEarlyClosureShortensTheLinkToTheClosurePlusThirtyDays(): void
    {
        $secret = $this->issue($this->approved());
        $closure = $this->at('2026-06-14 12:00:00'); // avant la fin planifiée (20 juin)
        $this->sql('UPDATE event_event SET status = \'CLOSED\', closed_at = :c WHERE id = :id', ['c' => $closure->format('Y-m-d H:i:s'), 'id' => $this->event->id()->toBinary()]);

        self::assertNotNull($this->reader()->find($secret, $closure->modify('+30 days')->modify('-1 second')));
        self::assertNull($this->reader()->find($secret, $closure->modify('+30 days')));
    }

    public function testALateAdministrativeClosureNeverExtendsTheLink(): void
    {
        $secret = $this->issue($this->approved());
        $end = $this->event->endsAt() ?? self::fail('Fin attendue.');
        $lateClosure = $end->modify('+20 days'); // clôture administrative tardive
        $this->sql('UPDATE event_event SET status = \'CLOSED\', closed_at = :c WHERE id = :id', ['c' => $lateClosure->format('Y-m-d H:i:s'), 'id' => $this->event->id()->toBinary()]);

        self::assertNotNull($this->reader()->find($secret, $end->modify('+30 days')->modify('-1 second')));
        self::assertNull($this->reader()->find($secret, $end->modify('+30 days')), 'la fin planifiée fait foi : +30 jours, pas clôture + 30 jours');
        self::assertNull($this->reader()->find($secret, $lateClosure->modify('+29 days')));
    }

    public function testAnExplicitlyPostponedPlannedEndIsTheOneThatCountsAndALaterClosureDoesNotExtendIt(): void
    {
        $secret = $this->issue($this->approved());
        $this->sql('UPDATE event_event SET ends_at = :e WHERE id = :id', ['e' => '2026-07-10 00:00:00', 'id' => $this->event->id()->toBinary()]);
        $newEnd = $this->at('2026-07-10 00:00:00');
        self::assertNotNull($this->reader()->find($secret, $newEnd->modify('+30 days')->modify('-1 second')), 'fin planifiée repoussée : le lien suit');

        // Clôture ensuite tardive par rapport à la nouvelle fin : la nouvelle fin planifiée reste la référence.
        $this->sql('UPDATE event_event SET status = \'CLOSED\', closed_at = :c WHERE id = :id', ['c' => '2026-09-01 00:00:00', 'id' => $this->event->id()->toBinary()]);
        self::assertNull($this->reader()->find($secret, $newEnd->modify('+30 days')));
        // Repousser la fin après coup (impossible via Event::reschedule une fois clôturé) ne rattraperait pas non plus une clôture plus tôt : min().
        $this->sql('UPDATE event_event SET closed_at = :c WHERE id = :id', ['c' => '2026-07-01 00:00:00', 'id' => $this->event->id()->toBinary()]);
        self::assertNull($this->reader()->find($secret, $this->at('2026-07-01 00:00:00')->modify('+30 days')));
    }

    public function testWithoutAPlannedEndTheClosureMakesTheEnd(): void
    {
        $secret = $this->issue($this->approved());
        $this->sql('UPDATE event_event SET ends_at = NULL WHERE id = :id', ['id' => $this->event->id()->toBinary()]);
        self::assertNotNull($this->reader()->find($secret, $this->at('2030-01-01 00:00:00')), 'ni fin planifiée ni clôture : pas d\'expiration encore');

        $this->sql('UPDATE event_event SET status = \'CLOSED\', closed_at = :c WHERE id = :id', ['c' => '2026-07-01 00:00:00', 'id' => $this->event->id()->toBinary()]);
        self::assertNull($this->reader()->find($secret, $this->at('2026-07-31 00:00:00')));
        self::assertNotNull($this->reader()->find($secret, $this->at('2026-07-30 23:59:59')));
    }

    public function testPostponingTheEndOfAnOpenEventExtendsTheLinksWithIt(): void
    {
        $request = $this->approved();
        $secret = $this->issue($request);
        $this->sql('UPDATE event_event SET ends_at = :e WHERE id = :id', ['e' => '2026-12-31 00:00:00', 'id' => $this->event->id()->toBinary()]);

        self::assertNotNull($this->reader()->find($secret, $this->at('2026-08-15 00:00:00')), 'événement prolongé : le lien suit la nouvelle fin');
        self::assertNull($this->reader()->find($secret, $this->at('2027-02-01 00:00:00')));
    }

    public function testNoLinkCanBeIssuedOnceTheEffectiveExpiryHasPassed(): void
    {
        $request = $this->approved();

        $this->expectException(DomainRuleViolation::class);
        $this->access($this->at('2027-01-01 00:00:00'))->issue($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request);
    }

    public function testMalformedAndUnknownSecretsGetTheSameAnswerWithoutLeak(): void
    {
        $this->issue($this->approved());

        foreach (['', 'abc', str_repeat('A', 43), str_repeat('A', 44), str_repeat('é', 43), '../../etc/passwd', str_repeat('A', 4000)] as $candidate) {
            self::assertNull($this->reader()->find($candidate, $this->at('2026-06-12 10:00:00')));
        }
    }

    public function testTheVerificationTokenIsNeverAnAccessSecret(): void
    {
        $request = $this->approved();
        $this->issue($request);
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.');

        self::assertNull($this->reader()->find($permit->activeToken()->value(), $this->at('2026-06-12 10:00:00')), 'le jeton du QR n\'ouvre pas la page bénéficiaire');
    }

    public function testTheQrTokenIsStableAcrossNewLinksAndASecondApproval(): void
    {
        $request = $this->approved([$this->organizerZone, $this->partnerZone]);
        $before = $this->em->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
        $first = $this->issue($request);
        $this->issue($request);

        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H24));
        $this->issue($request, $this->partnerInstructor, $this->partner);

        self::assertSame($before, $this->em->getConnection()->fetchOne('SELECT token FROM permit_verification_token'));
        self::assertSame(1, $this->rows('permit_verification_token'));
        $pass = $this->reader()->find($first, $this->at('2026-06-12 10:00:00')) ?? self::fail('Lien attendu.');
        self::assertSame($before, $pass->verificationToken);
        self::assertCount(2, $pass->rights->authorizations, 'la seconde commune apparaît sur le même laissez-passer, sans nouveau lien');
    }

    public function testThePassCarriesIdentityPlatesAndOneStatePerZoneButNoPersonalContact(): void
    {
        $request = $this->approved([$this->organizerZone, $this->partnerZone]);
        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H24));
        $secret = $this->issue($request);

        $pass = $this->reader()->find($secret, $this->at('2026-06-12 10:00:00')) ?? self::fail('Lien attendu.');

        self::assertSame('LP-2026-0001', $pass->reference);
        self::assertSame('Martin', $pass->lastName);
        self::assertSame('Camille', $pass->firstName);
        self::assertSame([], $pass->plates, 'à pied : aucune plaque');
        self::assertSame('foot_bike', $pass->travelMode);
        self::assertCount(2, $pass->rights->authorizations);
        foreach ($pass->rights->zones() as $zone) {
            self::assertSame(RightState::Valid, $zone->state);
        }
        self::assertStringNotContainsString('camille@exemple.fr', var_export($pass, true));
    }

    public function testRightsEvolveOnTheSamePassWithoutANewLink(): void
    {
        $request = $this->approved([$this->organizerZone, $this->partnerZone]);
        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H24));
        $secret = $this->issue($request);

        $valid = $this->reader()->find($secret, $this->at('2026-06-12 10:00:00')) ?? self::fail('Lien attendu.');
        $afterH24 = $this->reader()->find($secret, $this->at('2026-06-13 09:00:00')) ?? self::fail('Lien attendu.');
        $this->sql('UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizerZone->id()->toBinary()]);
        $suspended = $this->reader()->find($secret, $this->at('2026-06-12 10:00:00')) ?? self::fail('Lien attendu.');

        $byCommune = static fn ($pass): array => array_map(static fn ($a): array => array_map(static fn ($z): string => $z->state->value, $a->zones), $pass->rights->authorizations);
        self::assertSame([['VALID'], ['VALID']], $byCommune($valid));
        self::assertContains('EXPIRED', array_merge(...$byCommune($afterH24)), 'la commune à 24 h a expiré, l\'autre reste valide');
        self::assertContains('VALID', array_merge(...$byCommune($afterH24)));
        self::assertContains('ZONE_SUSPENDED', array_merge(...$byCommune($suspended)), 'une zone suspendue reste affichée, jamais comme inexistante');
    }

    public function testIssueAndRevokeAreAuditedWithoutSecretOrDigest(): void
    {
        $request = $this->approved();
        $secret = $this->issue($request);
        $this->access()->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, (string) $this->tokenRows()[0]['id']);

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT action, LOWER(HEX(municipality_id)) AS municipality, LOWER(HEX(actor_id)) AS actor, new_value FROM audit_log WHERE action LIKE \'PERMIT_ACCESS_LINK_%\' ORDER BY occurred_at, id');

        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing(['PERMIT_ACCESS_LINK_ISSUED', 'PERMIT_ACCESS_LINK_REVOKED'], array_column($rows, 'action'));
        foreach ($rows as $row) {
            self::assertSame($this->organizer->id()->toRfc4122(), $this->uuid((string) $row['municipality']));
            self::assertSame($this->instructor->id()->toRfc4122(), $this->uuid((string) $row['actor']));
            $payload = (string) $row['new_value'];
            self::assertStringContainsString('LP-2026-0001', $payload);
            self::assertStringNotContainsString($secret, $payload);
            self::assertStringNotContainsString(hash('sha256', $secret), $payload);
            self::assertStringNotContainsString('/laissez-passer/', $payload);
        }
    }

    private function uuid(string $hex): string
    {
        return \Symfony\Component\Uid\Uuid::fromBinary((string) hex2bin(str_replace('-', '', $hex)))->toRfc4122();
    }

    public function testOnlyInstructorsAndMunicipalityAdminsMayHandleLinks(): void
    {
        $request = $this->approved();
        $commune = $this->organizer->id()->toRfc4122();

        self::assertSame(0, $this->access()->overview($this->managed($this->adminOnly), $commune, $request)->activeCount(), 'MUNICIPALITY_ADMIN autorisé');
        $this->access()->issue($this->managed($this->adminOnly), $commune, $request);
        $this->expectException(PermitAccessDenied::class);
        $this->access()->issue($this->managed($this->managerOnly), $commune, $request);
    }

    public function testAnEventManagerHandlesTheLinksOfTheDirectIssuanceHeIssuedAndOnlyThose(): void
    {
        $commune = $this->organizer->id()->toRfc4122();
        $direct = $this->issuer()->issueDirectly($this->managed($this->managerOnly), $commune, $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $this->key())->requestId;
        $instructed = $this->approved();

        $secret = $this->issue($direct, $this->managerOnly);
        self::assertNotNull($this->reader()->find($secret, $this->at('2026-06-12 10:00:00')));
        self::assertSame(1, $this->access()->overview($this->managed($this->managerOnly), $commune, $direct)->activeCount());
        self::assertTrue($this->access()->revoke($this->managed($this->managerOnly), $commune, $direct, (string) $this->tokenRows()[0]['id']));
        $this->issue($instructed); // l'instructeur gère le laissez-passer instruit
        foreach ([
            fn () => $this->access()->overview($this->managed($this->managerOnly), $commune, $instructed),
            fn () => $this->access()->issue($this->managed($this->managerOnly), $commune, $instructed),
            fn () => $this->access()->revoke($this->managed($this->managerOnly), $commune, $instructed, (string) $this->tokenRows()[1]['id']),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Refus attendu : le laissez-passer n\'a pas été délivré directement par cet agent.');
            } catch (PermitAccessDenied) {
                $this->addToAssertionCount(1);
            }
        }
        // Un MUNICIPALITY_ADMIN gère aussi ce laissez-passer (droit commun de la commune).
        self::assertSame(0, $this->access()->overview($this->managed($this->adminOnly), $commune, $direct)->activeCount(), 'lien révoqué plus haut : l\'administrateur le constate');
    }

    public function testAnEventManagerOnlyCannotEvenSeeTheLinks(): void
    {
        $request = $this->approved();

        $this->expectException(PermitAccessDenied::class);
        $this->access()->overview($this->managed($this->managerOnly), $this->organizer->id()->toRfc4122(), $request);
    }

    public function testACommuneWithoutAnAuthorizationOnThePermitGetsTheUniformRefusal(): void
    {
        $request = $this->approved(); // organisatrice seule : le partenaire n'a aucune autorisation

        foreach ([
            [$this->partnerInstructor, $this->partner],      // commune de l'agent concernée par la demande... mais pas par le laissez-passer
            [$this->outsiderInstructor, $this->outsider],    // commune sans participation
        ] as [$agent, $commune]) {
            try {
                $this->access()->overview($this->managed($agent), $commune->id()->toRfc4122(), $request);
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed $e) {
                self::assertSame('Ressource inaccessible.', $e->getMessage());
            }
        }
        $this->expectException(OperationNotAllowed::class);
        $this->access()->issue($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $request);
    }

    public function testForgedIdentifiersGetTheUniformRefusal(): void
    {
        $request = $this->approved();
        $commune = $this->organizer->id()->toRfc4122();
        $agent = $this->managed($this->instructor);
        $this->issue($request);
        $foreign = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();

        foreach ([
            fn () => $this->access()->overview($agent, $commune, $foreign),
            fn () => $this->access()->overview($agent, $commune, 'pas-un-uuid'),
            fn () => $this->access()->overview($agent, $foreign, $request),
            fn () => $this->access()->issue($agent, $commune, $foreign),
            fn () => $this->access()->revoke($agent, $commune, $request, $foreign),
            fn () => $this->access()->revoke($agent, $commune, $request, 'pas-un-uuid'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testALinkOfAnotherPermitCannotBeRevokedThroughThisOne(): void
    {
        $first = $this->approved();
        $second = $this->approved();
        $this->issue($second);
        $linkOfSecond = (string) $this->tokenRows()[0]['id'];

        $this->expectException(OperationNotAllowed::class);
        $this->access()->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $first, $linkOfSecond);
    }

    public function testASuspendedParticipationKeepsTheLinksReadableButFrozen(): void
    {
        $request = $this->approved();
        $secret = $this->issue($request);
        $this->sql('UPDATE event_participation SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizerParticipation->id()->toBinary()]);
        $commune = $this->organizer->id()->toRfc4122();

        $overview = $this->access()->overview($this->managed($this->instructor), $commune, $request);

        self::assertFalse($overview->canManage);
        self::assertNotSame('', $overview->readOnlyReason);
        self::assertSame(AccessLinkView::ACTIVE, $overview->links[0]->state);
        try {
            $this->access()->issue($this->managed($this->instructor), $commune, $request);
            self::fail('Émission refusée attendue.');
        } catch (PermitAccessUnavailable) {
            self::assertSame(1, $this->rows('permit_access_token'));
        }
        $this->expectException(PermitAccessUnavailable::class);
        $this->access()->revoke($this->managed($this->instructor), $commune, $request, (string) $this->tokenRows()[0]['id']);
        unset($secret);
    }

    public function testAnotherCommuneOfThePermitCanIssueItsOwnLinkAndSeesAllLinksWithoutSecrets(): void
    {
        $request = $this->approved([$this->organizerZone, $this->partnerZone]);
        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H24));
        $this->issue($request);
        $this->issue($request, $this->partnerInstructor, $this->partner);

        $overview = $this->access()->overview($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $request);

        self::assertCount(2, $overview->links);
        self::assertEqualsCanonicalizing([true, false], array_map(static fn (AccessLinkView $l): bool => $l->issuedByOwnMunicipality, $overview->links));
        self::assertStringNotContainsString('secret', strtolower(var_export($overview, true)));
    }
}
