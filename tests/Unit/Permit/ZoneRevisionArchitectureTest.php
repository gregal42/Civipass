<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationType;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\PermitAuthorization;
use App\Permit\Domain\PermitAuthorizationZone;
use App\Permit\Domain\RevisionType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Garde-fous du LOT 05.5.3 : les zones d'une autorisation ne changent que par le service d'opération ; aucun renommage libre ; retrait logique seulement ; notifications
 * de zones (retrait prioritaire, ajout ordinaire) et libellés de révision neutres pour les autres communes.
 */
final class ZoneRevisionArchitectureTest extends TestCase
{
    public function testOnlyTheZoneServiceMutatesTheZonesOfAnAuthorization(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS));
        $callers = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && 1 === preg_match('/->(relabelZone|synchronizeLabel|retireZone|reinstateZone|addZone)\(/', (string) file_get_contents($file->getPathname()))) {
                $callers[] = str_replace(\dirname(__DIR__, 3).'/', '', $file->getPathname());
            }
        }
        sort($callers);

        self::assertSame(['src/Permit/Application/AuthorizationZoneService.php', 'src/Permit/Domain/PermitAuthorization.php'], $callers);
    }

    public function testThereIsNoWayToRenameAZoneWithFreeTextNorToDeleteARow(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(PermitAuthorizationZone::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        foreach ($methods as $name) {
            self::assertDoesNotMatchRegularExpression('/^(set|rename|delete|purge|remove(?!dAt))/i', $name, 'mutateur interdit : '.$name);
        }
        self::assertContains('synchronizeLabel', $methods);
        $parameters = (new \ReflectionMethod(PermitAuthorization::class, 'relabelZone'))->getParameters();
        self::assertSame(['zoneId', 'currentLabel'], array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters), 'le libellé vient du référentiel : le service passe le libellé COURANT de la zone');
    }

    public function testZoneNotificationsFollowTheValidatedPriorities(): void
    {
        self::assertSame(ChangeCategory::Ordinary, NotificationType::ZonesChanged->category(), 'ajout ou réintégration : ordinaire');
        self::assertSame(ChangeCategory::Revocation, NotificationType::ZonesRemoved->category(), 'retrait : prioritaire, jamais regroupé');
        self::assertLessThanOrEqual(30, \strlen(NotificationType::ZonesRemoved->value));
        $revision = Uuid::v7();
        $a = new NotificationIntent(NotificationKind::ZonesRemoved, Uuid::v7(), Uuid::v7(), new \DateTimeImmutable(), true, $revision);
        $b = new NotificationIntent(NotificationKind::ZonesChanged, $a->permitId, $a->authorizationId, new \DateTimeImmutable(), true, $revision);

        self::assertNotSame($a->deduplicationKey(), $b->deduplicationKey(), 'une clé par type et par révision');
        self::assertStringStartsWith('ZONES_REMOVED:', $a->deduplicationKey());
    }

    public function testTheNeutralLabelsOfZoneRevisionsNeverNameAZone(): void
    {
        foreach ([RevisionType::ZoneAdded, RevisionType::ZoneRemoved, RevisionType::ZoneReinstated] as $type) {
            self::assertSame('Zones modifiées', $type->neutralLabel());
        }
        self::assertSame('Libellé de zone corrigé', RevisionType::ZoneLabelCorrected->neutralLabel());
        self::assertLessThanOrEqual(32, \strlen(RevisionType::ZoneLabelCorrected->value));
    }
}
