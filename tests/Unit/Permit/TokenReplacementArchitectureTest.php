<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationType;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\RevisionType;
use App\Permit\Domain\TokenReplacementReason;
use App\Permit\Domain\VerificationToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Garde-fous du LOT 05.5.5 : le jeton d'un laissez-passer ne change que par le service d'opération ; un jeton retiré ne redevient jamais actif ni n'est supprimé ; la valeur
 * d'un jeton n'est jamais écrite dans l'audit, une révision, l'outbox, un journal, un gabarit de message ; la notification est prioritaire ; motifs sans jeton.
 */
final class TokenReplacementArchitectureTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function testOnlyTheReplacementServiceRetiresOrAddsATokenAndNoRowIsEverDeleted(): void
    {
        $callers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || str_contains($file->getPathname(), 'Migrations')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (1 === preg_match('/->(addToken)\(/', $source) || (1 === preg_match('/->retire\(\$now\)/', $source) && str_contains($source, 'VerificationToken'))) {
                $callers[] = str_replace(self::root().'/', '', $file->getPathname());
            }
            self::assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+permit_verification_token|UPDATE\s+permit_verification_token\s+SET\s+active_permit_id/i', $source, 'suppression ou réactivation directe d\'un jeton : '.$file->getPathname());
        }
        sort($callers);

        self::assertSame(['src/Permit/Application/PermitTokenReplacementService.php'], $callers);
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(VerificationToken::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach ($methods as $name) {
            self::assertDoesNotMatchRegularExpression('/^(set|reactivate|restore|reinstate|delete|purge|renew|rotate)/i', $name, 'mutateur interdit : '.$name);
        }
        self::assertContains('retire', $methods);
    }

    public function testTheTokenValueNeverReachesTheAuditTheRevisionTheOutboxOrTheLog(): void
    {
        $service = explode("\n", (string) file_get_contents(self::root().'/src/Permit/Application/PermitTokenReplacementService.php'));
        foreach ($service as $number => $line) {
            if (1 === preg_match('/->value\(\)/', $line) && !str_contains($line, 'tokenGenerator')) {
                self::assertStringContainsString('str_contains(strtolower', $line, 'la valeur du jeton n\'est lue que pour la détecter dans un commentaire (ligne '.($number + 1).')');
                self::assertStringNotContainsString('audit', strtolower($line));
            }
        }
        $source = implode("\n", $service);
        self::assertDoesNotMatchRegularExpression('/\'(token|jeton)\'\s*=>\s*\$(candidate|new|old|active)->value\(\)/', $source);
        $parameters = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionClass(NotificationIntent::class))->getConstructor()?->getParameters() ?? []);
        self::assertSame(['kind', 'permitId', 'authorizationId', 'occurredAt', 'hasRecipient', 'revisionId'], $parameters, 'l\'intention ne porte que des identifiants');
        foreach (['qr_replaced.txt', 'qr_replaced.html'] as $template) {
            $text = (string) file_get_contents(self::root().'/templates/notification/'.$template.'.twig');
            self::assertDoesNotMatchRegularExpression('/\{\{\s*(url|token|secret|link|plates?|reason|comment|municipality)/i', $text, 'aucune variable sensible dans '.$template);
            self::assertStringNotContainsString('/v/', $text);
            self::assertStringNotContainsString('http', $text);
        }
        self::assertDoesNotMatchRegularExpression('/registration|->value\(\)/', (string) file_get_contents(self::root().'/src/Permit/Domain/PermitRevision.php'));
    }

    public function testTheQrNotificationIsPriorityAndTheRevisionTypeFitsItsColumn(): void
    {
        self::assertSame(ChangeCategory::Revocation, NotificationType::QrReplaced->category());
        self::assertLessThanOrEqual(30, \strlen(NotificationType::QrReplaced->value));
        self::assertLessThanOrEqual(32, \strlen(RevisionType::TokenReplaced->value));
        self::assertSame(NotificationType::QrReplaced->value, NotificationKind::QrReplaced->value);
        $revision = Uuid::v7();
        $a = new NotificationIntent(NotificationKind::QrReplaced, Uuid::v7(), null, new \DateTimeImmutable(), true, $revision);
        $b = new NotificationIntent(NotificationKind::QrReplaced, $a->permitId, null, new \DateTimeImmutable(), true, Uuid::v7());

        self::assertNotSame($a->deduplicationKey(), $b->deduplicationKey(), 'une clé par révision : deux remplacements, deux intentions');
        self::assertStringStartsWith('QR_REPLACED:', $a->deduplicationKey());
        self::assertTrue((new \ReflectionMethod(PermitRevision::class, 'token'))->isStatic());
        self::assertSame('QR code remplacé', RevisionType::TokenReplaced->neutralLabel(), 'ligne neutre : le type seulement');
    }

    public function testTheReasonCatalogueIsComplete(): void
    {
        self::assertSame(['LOST', 'SHARED_OR_PUBLISHED', 'SUSPECTED_MISUSE', 'DEVICE_COMPROMISED', 'OTHER'], array_map(static fn (TokenReplacementReason $r): string => $r->value, TokenReplacementReason::cases()));
        foreach (TokenReplacementReason::cases() as $case) {
            self::assertLessThanOrEqual(30, \strlen($case->value));
            self::assertSame('OTHER' === $case->value, $case->needsComment());
        }
    }
}
