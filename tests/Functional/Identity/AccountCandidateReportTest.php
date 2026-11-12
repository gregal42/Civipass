<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\AccountCandidateLine;
use App\Identity\Application\AccountCandidateReader;
use App\Identity\Application\AccountReferenceCatalog;
use App\Identity\Domain\User;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ArchiveScenarioTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\UserBuilder;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rapport de comptes candidats (LOT 05.6.5, D1) : sélection exacte, catégories techniques, lecture seule, adresses masquées, accès réservé aux super-administrateurs CWS, exécution
 * journalisée sans la liste, catalogue des clés étrangères maintenu.
 */
final class AccountCandidateReportTest extends ArchiveScenarioTestCase
{
    private function reader(): AccountCandidateReader
    {
        return static::getContainer()->get(AccountCandidateReader::class);
    }

    private function age(User $user, int $months, bool $withMembership = false): void
    {
        $x = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('-%d months', $months))->format('Y-m-d H:i:s');
        $this->sql('UPDATE identity_user SET created_at = :x, updated_at = :x, last_login_at = :x WHERE id = UNHEX(:u)', ['x' => $x, 'u' => str_replace('-', '', $user->id()->toRfc4122())]);
        if (!$withMembership) {
            $this->sql('UPDATE municipality_membership SET active = 0 WHERE user_id = UNHEX(:u)', ['u' => str_replace('-', '', $user->id()->toRfc4122())]);
        }
    }

    /** @return array<string, AccountCandidateLine> par identifiant */
    private function lines(): array
    {
        $out = [];
        foreach ($this->reader()->report($this->managed($this->superAdmin))['lines'] as $line) {
            $out[$line->userId] = $line;
        }
        $this->em()->clear();

        return $out;
    }

    public function testOnlyInactiveAccountsWithoutActiveMembershipAreCandidatesWithTheirTechnicalCategory(): void
    {
        $organizer = $this->em()->find(Municipality::class, $this->organizerId) ?? self::fail('Commune absente.');
        [$free, $recent, $disabledRecently, $withMembership] = [UserBuilder::aUser()->build(), UserBuilder::aUser()->build(), UserBuilder::aUser()->build(), UserBuilder::aUser()->build()];
        $this->save($free, $recent, $disabledRecently, $withMembership, MembershipBuilder::aMembership($withMembership, $organizer)->withRoles(Role::Instructor)->build());

        // Référencé par une décision conservée : ANONYMISE_ONLY (l'instructeur du scénario a approuvé le dossier).
        $this->age($this->instructor, 30);
        $this->age($free, 30);
        $this->age($recent, 3);
        $this->age($withMembership, 30, withMembership: true);
        $this->age($this->superAdmin, 30);
        $this->sql('UPDATE identity_user SET active = 0, updated_at = UTC_TIMESTAMP() WHERE id = UNHEX(:u)', ['u' => str_replace('-', '', $disabledRecently->id()->toRfc4122())]);
        $this->sql('UPDATE municipality_membership SET active = 0 WHERE user_id = UNHEX(:u)', ['u' => str_replace('-', '', $disabledRecently->id()->toRfc4122())]);

        $lines = $this->lines();
        $id = static fn (User $u): string => $u->id()->toRfc4122();

        self::assertSame(AccountCandidateLine::ANONYMISE_ONLY, $lines[$id($this->instructor)]->category);
        self::assertArrayHasKey('request_permit_request_decision.decided_by_user_id', $lines[$id($this->instructor)]->references);
        self::assertSame(AccountCandidateLine::DELETABLE, $lines[$id($free)]->category, 'seulement un indicateur technique');
        self::assertArrayNotHasKey($id($recent), $lines, 'connexion récente');
        self::assertArrayNotHasKey($id($disabledRecently), $lines, 'désactivé récemment');
        self::assertArrayNotHasKey($id($withMembership), $lines, 'appartenance active');
        self::assertArrayNotHasKey($id($this->superAdmin), $lines, 'super-administrateur CWS');
        self::assertArrayNotHasKey($id($this->admin), $lines, 'administrateur actif (les autres comptes du scénario sont récents)');
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^.\*\*\*@.\*\*\*\.[a-z]+$/', $line->maskedEmail);
        }
    }

    public function testTheReportNeverModifiesAnAccountAMembershipOrARoleAndIsJournaledWithoutTheList(): void
    {
        $this->age($this->instructor, 30);
        $snapshot = fn (): array => [
            $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM identity_user ORDER BY id'),
            $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM municipality_membership ORDER BY id'),
        ];
        $before = $snapshot();

        $this->reader()->report($this->managed($this->superAdmin));

        self::assertSame($before, $snapshot(), 'aucun compte, appartenance ou rôle modifié');
        $audit = $this->em()->getConnection()->fetchAllAssociative('SELECT new_value FROM audit_log WHERE action = \'ACCOUNT_CANDIDATES_REPORTED\'');
        self::assertCount(1, $audit);
        $json = (string) $audit[0]['new_value'];
        self::assertStringContainsString('"candidates"', $json);
        self::assertStringNotContainsString('@', $json);
        self::assertStringNotContainsString($this->instructor->id()->toRfc4122(), $json, 'la liste des candidats n\'est pas enregistrée');
        self::assertStringNotContainsString(str_replace('-', '', $this->instructor->id()->toRfc4122()), $json);
    }

    public function testOnlyAnActiveCwsSuperAdministratorMayRunTheReport(): void
    {
        $this->sql('UPDATE identity_user SET active = 0 WHERE id = UNHEX(:u)', ['u' => str_replace('-', '', $this->organizerAdmin2->id()->toRfc4122())]);
        foreach ([$this->admin, $this->manager, $this->instructor, $this->partnerAdmin, $this->outsider, $this->organizerAdmin2] as $user) {
            try {
                $this->reader()->report($this->managed($user));
                self::fail('Rapport servi à un non super-administrateur.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        $this->sql('UPDATE identity_user SET active = 0 WHERE id = UNHEX(:u)', ['u' => str_replace('-', '', $this->superAdmin->id()->toRfc4122())]);
        $this->expectException(OperationNotAllowed::class);
        $this->reader()->report($this->managed($this->superAdmin));
    }

    public function testTheCommandRefusesAnUnknownOrNonSuperAdminOperatorAndPrintsMaskedAddressesOnly(): void
    {
        $this->age($this->instructor, 30);
        $command = new CommandTester((new Application(static::$kernel))->find('app:accounts:candidates'));

        foreach ([[], ['--operator' => 'pas-un-uuid'], ['--operator' => $this->admin->id()->toRfc4122()], ['--operator' => '0190a000-0000-7000-8000-000000000000']] as $input) {
            self::assertSame(1, $command->execute($input));
            self::assertStringNotContainsString($this->instructor->id()->toRfc4122(), $command->getDisplay());
        }
        self::assertSame(0, $command->execute(['--operator' => $this->superAdmin->id()->toRfc4122()]));
        $out = $command->getDisplay();
        self::assertStringContainsString($this->instructor->id()->toRfc4122(), $out);
        self::assertStringNotContainsString($this->instructor->email(), $out, 'adresse masquée');
        self::assertStringContainsString('aucune autorisation juridique', $out);
    }

    // ------------------------------------------------------------------ catalogue des clés étrangères

    public function testEveryForeignKeyToAnAccountIsClassifiedAndTheCatalogHasNoStaleEntry(): void
    {
        $catalog = static::getContainer()->get(AccountReferenceCatalog::class);
        $discovered = $catalog->discover();

        self::assertSame([], $catalog->unclassified(), 'nouvelle clé étrangère vers identity_user : la classer dans AccountReferenceCatalog (OWNED ou RETAINED)');
        self::assertSame([], array_values(array_diff([...AccountReferenceCatalog::OWNED, ...AccountReferenceCatalog::RETAINED], $discovered)), 'entrée du catalogue sans clé correspondante');
    }

    public function testANewForeignKeyIsDiscoveredAndTreatedAsRetainedUntilClassified(): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS zz_tmp_account_ref');
        $connection->executeStatement('CREATE TABLE zz_tmp_account_ref (id INT AUTO_INCREMENT PRIMARY KEY, author BINARY(16) NOT NULL, CONSTRAINT fk_zz_tmp FOREIGN KEY (author) REFERENCES identity_user (id) ON DELETE RESTRICT)');
        try {
            $this->age($this->instructor, 30);
            $this->age($this->partnerAdmin, 30);
            $connection->executeStatement('INSERT INTO zz_tmp_account_ref (author) VALUES (UNHEX(:u))', ['u' => str_replace('-', '', $this->partnerAdmin->id()->toRfc4122())]);

            $catalog = static::getContainer()->get(AccountReferenceCatalog::class);
            self::assertContains('zz_tmp_account_ref.author', $catalog->unclassified());
            $report = $this->reader()->report($this->managed($this->superAdmin));
            $byId = [];
            foreach ($report['lines'] as $line) {
                $byId[$line->userId] = $line;
            }
            self::assertSame(AccountCandidateLine::ANONYMISE_ONLY, $byId[$this->partnerAdmin->id()->toRfc4122()]->category, 'référence inconnue : jamais « supprimable »');
            self::assertContains('zz_tmp_account_ref.author', $report['unclassified']);
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS zz_tmp_account_ref');
        }
    }

    public function testTheMaskNeverRevealsTheAddress(): void
    {
        self::assertSame('j***@m***.fr', AccountCandidateReader::mask('jean.dupont@mairie.example.fr'));
        self::assertSame('***', AccountCandidateReader::mask('pas-une-adresse'));
        self::assertSame('a***@b***', AccountCandidateReader::mask('a@b'));
    }
}
