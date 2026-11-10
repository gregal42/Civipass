<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationType;
use App\Permit\Application\Read\NotificationFacts;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\PermitVehicle;
use App\Permit\Domain\RevisionType;
use App\Permit\Domain\VehicleAddReason;
use App\Permit\Domain\VehicleChangeKind;
use App\Permit\Domain\VehicleReinstatementReason;
use App\Permit\Domain\VehicleRemovalReason;
use App\Permit\Domain\VehicleReplacementReason;
use App\Shared\Domain\AuditAction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Garde-fous du LOT 05.5.4 : les plaques d'un laissez-passer ne changent que par le service d'opération ; retrait logique seulement ; la plaque n'est écrite que dans
 * `permit_revision_vehicle` (jamais dans l'audit, les états de révision, l'outbox ni un message) ; notifications de plaques (retrait et remplacement prioritaires, ajout
 * ordinaire) ; un remplacement se reconstitue par le type de ses révisions ; libellés neutres pour les autres communes ; motifs sans plaque.
 */
final class VehicleRevisionArchitectureTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function testOnlyTheVehicleServiceMutatesThePlatesOfAPermit(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src', \FilesystemIterator::SKIP_DOTS));
        $callers = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && 1 === preg_match('/->(addVehicle|retire|reinstate)\(/', (string) file_get_contents($file->getPathname())) && str_contains((string) file_get_contents($file->getPathname()), 'PermitVehicle')) {
                $callers[] = str_replace(self::root().'/', '', $file->getPathname());
            }
        }
        sort($callers);

        self::assertSame(['src/Permit/Application/PermitVehicleService.php'], $callers);
    }

    public function testAPlateRowCanNeverBeDeletedOrRewritten(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(PermitVehicle::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        foreach ($methods as $name) {
            self::assertDoesNotMatchRegularExpression('/^(set|rename|delete|purge|remove(?!dAt))/i', $name, 'mutateur interdit : '.$name);
        }
        self::assertContains('retire', $methods);
        self::assertContains('reinstate', $methods);
        foreach (['src', 'tests/Support'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && !str_contains($file->getPathname(), 'Migrations')) {
                    self::assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+permit_(vehicle|revision_vehicle)|->remove\(\s*\$\w*vehicle/i', (string) file_get_contents($file->getPathname()), 'suppression physique d\'une plaque : '.$file->getPathname());
                }
            }
        }
    }

    public function testNoStateAuditOrMessageStructureCanCarryAPlate(): void
    {
        $service = (string) file_get_contents(self::root().'/src/Permit/Application/PermitVehicleService.php');
        // l'audit, les états de révision et l'intention n'utilisent jamais la plaque : identifiants, position, compteurs et codes de motif seulement
        self::assertDoesNotMatchRegularExpression('/\'(registration|plate|plaque)\'\s*=>/i', $service, 'une clé de plaque dans un état ou un audit');
        $parameters = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionClass(NotificationIntent::class))->getConstructor()?->getParameters() ?? []);
        self::assertSame(['kind', 'permitId', 'authorizationId', 'occurredAt', 'hasRecipient', 'revisionId'], $parameters, 'l\'intention ne porte que des identifiants');
        foreach ((new \ReflectionClass(NotificationFacts::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            self::assertDoesNotMatchRegularExpression('/plate|plaque|registration/i', $parameter->getName(), 'les faits de notification ne contiennent aucune plaque');
        }
        foreach (['vehicles_changed', 'vehicles_removed'] as $template) {
            foreach (['txt', 'html'] as $format) {
                $source = (string) file_get_contents(self::root().'/templates/notification/'.$template.'.'.$format.'.twig');
                self::assertDoesNotMatchRegularExpression('/plate|plaque\b|registration|reason|motif|comment/i', str_replace(['plaques', 'plaque qui'], '', $source), 'ni plaque ni motif dans le gabarit '.$template.'.'.$format);
            }
        }
        // la plaque n'a qu'un seul domicile en dehors du laissez-passer : permit_revision_vehicle
        self::assertDoesNotMatchRegularExpression('/registration/', (string) file_get_contents(self::root().'/src/Permit/Domain/PermitRevision.php'));
    }

    public function testVehicleNotificationsFollowTheValidatedPriorities(): void
    {
        self::assertSame(ChangeCategory::Ordinary, NotificationType::VehiclesChanged->category(), 'ajout ou réactivation : ordinaire');
        self::assertSame(ChangeCategory::Revocation, NotificationType::VehiclesRemoved->category(), 'retrait ou remplacement : prioritaire, jamais regroupé');
        self::assertLessThanOrEqual(30, \strlen(NotificationType::VehiclesRemoved->value));
        $revision = Uuid::v7();
        $a = new NotificationIntent(NotificationKind::VehiclesRemoved, Uuid::v7(), null, new \DateTimeImmutable(), true, $revision);
        $b = new NotificationIntent(NotificationKind::VehiclesChanged, $a->permitId, null, new \DateTimeImmutable(), true, $revision);

        self::assertNotSame($a->deduplicationKey(), $b->deduplicationKey(), 'une clé par type et par révision');
        self::assertStringStartsWith('VEHICLES_REMOVED:', $a->deduplicationKey());
        foreach (NotificationKind::cases() as $kind) {
            self::assertContains($kind->value, array_map(static fn (NotificationType $t): string => $t->value, NotificationType::cases()), 'chaque intention a son type de message : '.$kind->value);
        }
    }

    public function testAReplacementIsReconstitutedByTheTypeOfItsRevisionsNotByTheirTimestamp(): void
    {
        self::assertSame(RevisionType::VehicleReplacedOut, VehicleChangeKind::ReplacedOut->revisionType());
        self::assertSame(RevisionType::VehicleReplacedIn, VehicleChangeKind::ReplacedIn->revisionType());
        self::assertTrue(VehicleChangeKind::ReplacedOut->removesPlate());
        self::assertFalse(VehicleChangeKind::ReplacedIn->removesPlate());
        self::assertSame('REMOVED', VehicleChangeKind::ReplacedOut->action());
        self::assertSame('ADDED', VehicleChangeKind::ReplacedIn->action());
        foreach (RevisionType::cases() as $type) {
            self::assertLessThanOrEqual(32, \strlen($type->value), 'la valeur tient dans permit_revision.type');
        }
        $factory = new \ReflectionMethod(PermitRevision::class, 'vehicle');
        self::assertContains('batchId', array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $factory->getParameters()), 'les révisions d\'une opération partagent un batch_id');
    }

    public function testTheNeutralLabelsOfVehicleRevisionsNeverNameAPlateOrAnAction(): void
    {
        foreach ([RevisionType::VehicleAdded, RevisionType::VehicleRemoved, RevisionType::VehicleReinstated, RevisionType::VehicleReplacedOut, RevisionType::VehicleReplacedIn] as $type) {
            self::assertSame('Véhicules modifiés', $type->neutralLabel());
            self::assertTrue($type->isVehicle());
        }
        self::assertFalse(RevisionType::ZoneAdded->isVehicle());
        foreach (AuditAction::cases() as $action) {
            self::assertLessThanOrEqual(60, \strlen($action->value), 'la valeur tient dans audit_log.action');
        }
    }

    public function testTheReasonCataloguesAreCoherentWithTheValidatedRules(): void
    {
        $catalogues = [VehicleAddReason::class, VehicleRemovalReason::class, VehicleReplacementReason::class, VehicleReinstatementReason::class];
        foreach ($catalogues as $enum) {
            $cases = $enum::cases();
            self::assertContains('OTHER', array_map(static fn ($c): string => $c->value, $cases));
            foreach ($cases as $case) {
                self::assertLessThanOrEqual(30, \strlen($case->value), 'tient dans permit_revision.reason_code');
                self::assertSame('OTHER' === $case->value, $case->needsComment(), 'seul « Autre » exige un commentaire');
                self::assertDoesNotMatchRegularExpression('/plaque\s+[A-Z]{2}|\d{3}/', $case->label());
            }
        }
    }
}
