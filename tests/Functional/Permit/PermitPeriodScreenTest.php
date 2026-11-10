<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * Écrans « Prolonger », « Reprendre » et « Raccourcir » (LOT 05.5.2) dans le navigateur simulé : actions proposées selon l'état, aperçu SANS écriture,
 * confirmation, CSRF, motif obligatoire pour une reprise et un raccourcissement, fin immédiate, périodes closes, historique détaillé, rôles et périmètre,
 * événement suspendu. Autorisation de 24 h délivrée à l'instant réel ; l'expiration est simulée en reculant la fin en base.
 */
#[Group('requires-request-schema')]
final class PermitPeriodScreenTest extends PublicRequestTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private User $instructor;
    private User $manager;
    private User $outsider;
    private string $organizerId;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em()->getConnection()->createSchemaManager()->tablesExist(['permit_authorization_period'])) {
            self::markTestSkipped('Schéma des périodes non migré (LOT 05.5.2) : test exécutable après la migration validée.');
        }
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $other = MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
        $this->organizerId = $organizer->id()->toRfc4122();
        [$this->instructor, $this->manager, $this->outsider] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 3));
        $this->save(
            $other,
            $this->instructor,
            $this->manager,
            $this->outsider,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->outsider, $other)->withRoles(Role::Instructor)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', 'camille.secret@exemple.fr', '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT'], [$this->organizerZone->id()->toRfc4122()], true),
            sprintf('period-screen-%016d', random_int(1, 999999)),
        )->requestId;
        static::getContainer()->get(MunicipalInstructionService::class)->approve($this->managed($this->instructor), $this->organizerId, $this->requestId, ApprovalDuration::fixed(DurationMode::H24));
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function as(User $user): void
    {
        $this->client->loginUser($this->managed($user));
    }

    private function url(string $suffix = '', ?string $commune = null): string
    {
        return '/communes/'.($commune ?? $this->organizerId).'/demandes/'.$this->requestId.'/droits'.$suffix;
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function until(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT valid_until FROM permit_authorization');
    }

    /** @return array{int, int} révisions et périodes closes */
    private function counts(): array
    {
        $c = $this->em()->getConnection();

        return [(int) $c->fetchOne('SELECT COUNT(*) FROM permit_revision'), (int) $c->fetchOne('SELECT COUNT(*) FROM permit_authorization_period')];
    }

    private function expire(): void
    {
        $this->sql("UPDATE permit_authorization SET duration_mode = 'CUSTOM', valid_from = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY), valid_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)");
    }

    private function token(string $path): string
    {
        $crawler = $this->client->request('GET', $this->url($path));

        return (string) $crawler->filter('input[name=_token]')->attr('value');
    }

    /** @param array<string, string> $fields */
    private function submitPeriod(string $path, array $fields): void
    {
        $token = $this->token($path);
        $this->client->request('POST', $this->url($path), $fields + ['_token' => $token]);
    }

    // ------------------------------------------------------------------ actions proposées

    public function testTheActionsFollowTheStateOfThePeriod(): void
    {
        $this->as($this->instructor);

        $this->client->request('GET', $this->url());
        self::assertStringContainsString('Prolonger la période', $this->html());
        self::assertStringContainsString('Raccourcir la période', $this->html());
        self::assertStringNotContainsString('Reprendre les droits', $this->html());

        $this->expire();
        $this->client->request('GET', $this->url());
        self::assertStringContainsString('Reprendre les droits', $this->html());
        self::assertStringNotContainsString('Raccourcir la période', $this->html(), 'rien à raccourcir sur une période terminée');
        self::assertStringContainsString('Raccourcissement impossible', $this->html());
    }

    public function testTheFormsAreReadOnlyUntilConfirmedAndPreviewWritesNothing(): void
    {
        $this->as($this->instructor);
        $before = [$this->until(), $this->counts()];

        $this->client->request('GET', $this->url('/periode/prolonger'));
        self::assertResponseIsSuccessful();
        foreach (['Ajouter 24 heures', 'Ajouter 48 heures', 'Ajouter 72 heures', 'Choisir la date et l’heure de fin', 'Jusqu’à la clôture de l’événement', 'Besoin prolongé', 'Voir la période résultante', 'maxlength="200"'] as $expected) {
            self::assertStringContainsString($expected, $this->html(), $expected);
        }
        self::assertStringNotContainsString('Confirmer la prolongation', $this->html(), 'pas de confirmation sans aperçu');

        $this->submitPeriod('/periode/prolonger', ['mode' => 'H48', 'step' => 'preview']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Période qui en résulterait', $this->html());
        self::assertStringContainsString('Confirmer la prolongation', $this->html());
        self::assertSame($before, [$this->until(), $this->counts()], 'l\'aperçu n\'écrit rien');
    }

    // ------------------------------------------------------------------ prolongation, reprise, raccourcissement

    public function testAnExtensionIsAppliedAndShownInTheHistoryWithBeforeAndAfter(): void
    {
        $this->as($this->instructor);
        $old = $this->until();

        $this->submitPeriod('/periode/prolonger', ['mode' => 'H24', 'step' => 'apply', 'reason' => 'OTHER', 'comment' => self::COMMENT]);

        self::assertResponseRedirects($this->url());
        self::assertSame((new \DateTimeImmutable($old, new \DateTimeZone('UTC')))->modify('+24 hours')->format('Y-m-d H:i:s'), $this->until());
        $this->client->followRedirect();
        foreach (['Période prolongée', 'Période : du', 'Autre motif', self::COMMENT] as $expected) {
            self::assertStringContainsString($expected, $this->html(), $expected);
        }
        self::assertSame([1, 0], $this->counts());
    }

    public function testAResumptionNeedsAReasonThenArchivesThePeriodAndNeverRestoresTheInterruption(): void
    {
        $this->as($this->instructor);
        $this->expire();

        $this->submitPeriod('/periode/prolonger', ['mode' => 'H24', 'step' => 'apply']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('motif est obligatoire', $this->html());
        self::assertSame([0, 0], $this->counts());

        $this->submitPeriod('/periode/prolonger', ['mode' => 'H24', 'step' => 'preview']);
        self::assertStringContainsString('aucun droit entre la fin de la période actuelle', $this->html());

        $this->submitPeriod('/periode/prolonger', ['mode' => 'H24', 'step' => 'apply', 'reason' => 'NEED_EXTENDED']);
        self::assertResponseRedirects($this->url());
        self::assertSame([1, 1], $this->counts(), 'une révision et une période close');
        $this->client->followRedirect();
        self::assertStringContainsString('Reprise des droits', $this->html());
        self::assertStringContainsString('aucun droit n’est rétabli pour la période écoulée', $this->html());
    }

    public function testAShorteningNeedsAReasonAndAConfirmationAndAPastDateGivesAnImmediateEnd(): void
    {
        $this->as($this->instructor);
        sleep(2); // une période démarrée à la seconde même ne peut pas être raccourcie sur-le-champ (règle testée à part) : on laisse passer une seconde
        $past = (new \DateTimeImmutable('-2 hours', new \DateTimeZone('Europe/Paris')));

        $this->submitPeriod('/periode/raccourcir', ['mode' => 'CUSTOM', 'end_date' => $past->format('Y-m-d'), 'end_time' => $past->format('H:i'), 'step' => 'apply', 'confirmed' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('motif de raccourcissement', $this->html());

        $this->submitPeriod('/periode/raccourcir', ['mode' => 'CUSTOM', 'end_date' => $past->format('Y-m-d'), 'end_time' => $past->format('H:i'), 'step' => 'apply', 'reason' => 'NEED_ENDED']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Confirmez le raccourcissement', $this->html());
        self::assertSame([0, 0], $this->counts());

        $this->submitPeriod('/periode/raccourcir', ['mode' => 'CUSTOM', 'end_date' => $past->format('Y-m-d'), 'end_time' => $past->format('H:i'), 'step' => 'preview', 'reason' => 'NEED_ENDED', 'confirmed' => '1']);
        self::assertStringContainsString('Fin immédiate', $this->html());

        $this->submitPeriod('/periode/raccourcir', ['mode' => 'CUSTOM', 'end_date' => $past->format('Y-m-d'), 'end_time' => $past->format('H:i'), 'step' => 'apply', 'reason' => 'NEED_ENDED', 'confirmed' => '1']);
        self::assertResponseRedirects($this->url());
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($this->until(), new \DateTimeZone('UTC')))->getTimestamp(), 5, 'jamais antidaté : fin = maintenant');
        $this->client->followRedirect();
        self::assertStringContainsString('Fin immédiate enregistrée', $this->html());

        $this->client->request('GET', $this->url('/periode/raccourcir'));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('déjà terminée', $this->html());
    }

    // ------------------------------------------------------------------ sécurité

    public function testAForgedCsrfTokenChangesNothing(): void
    {
        $this->as($this->instructor);
        $before = [$this->until(), $this->counts()];

        $this->client->request('POST', $this->url('/periode/prolonger'), ['_token' => 'forgé', 'mode' => 'H24', 'step' => 'apply']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le formulaire a expiré', $this->html());
        self::assertSame($before, [$this->until(), $this->counts()]);
    }

    public function testRolesAndPerimeterAreEnforcedWithAUniformRefusal(): void
    {
        $this->as($this->manager);
        $this->client->request('GET', $this->url('/periode/prolonger'));
        self::assertResponseStatusCodeSame(403);

        $this->as($this->outsider);
        foreach (['/periode/prolonger', '/periode/raccourcir'] as $path) {
            $this->client->request('GET', $this->url($path));
            self::assertResponseStatusCodeSame(404);
            $this->client->request('POST', $this->url($path), ['_token' => 'x', 'mode' => 'H24', 'step' => 'apply']);
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame([0, 0], $this->counts());
    }

    public function testASuspendedEventBlocksTheExtensionScreenButNotTheShorteningOne(): void
    {
        $this->as($this->instructor);
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");

        $this->client->request('GET', $this->url('/periode/prolonger'));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('n’est pas actif', $this->html());
        self::assertStringNotContainsString('Voir la période résultante', $this->html());

        $this->client->request('GET', $this->url('/periode/raccourcir'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Voir la période résultante', $this->html());
    }

    public function testAnInvalidInputKeepsTheFormAndExplainsWhy(): void
    {
        $this->as($this->instructor);

        $this->submitPeriod('/periode/prolonger', ['mode' => 'CUSTOM', 'end_date' => '2020-01-01', 'end_time' => '10:00', 'step' => 'preview']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins une minute', $this->html());
        self::assertStringContainsString('2020-01-01', $this->html(), 'la saisie est conservée');
    }

    public function testTheComplementaryPublicPageNeverShowsTheReason(): void
    {
        $this->as($this->instructor);
        $this->submitPeriod('/periode/prolonger', ['mode' => 'H24', 'step' => 'apply', 'reason' => 'OTHER', 'comment' => self::COMMENT]);
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = '1';
        putenv('PUBLIC_VERIFICATION_ENABLED=1');
        try {
            $this->client->request('GET', '/v/'.$this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token'));
            self::assertResponseStatusCodeSame(200);
            foreach ([self::COMMENT, 'Autre motif', 'OTHER', 'Période prolongée'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $this->html());
            }
        } finally {
            $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = '0';
            putenv('PUBLIC_VERIFICATION_ENABLED=0');
        }
    }
}
