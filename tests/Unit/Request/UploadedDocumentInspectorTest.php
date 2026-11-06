<?php

declare(strict_types=1);

namespace App\Tests\Unit\Request;

use App\Request\Application\UploadedDocumentInspector;
use App\Request\Domain\PermitRequestAttachment;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\SampleFiles as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadedDocumentInspectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function acceptedFiles(): iterable
    {
        yield 'PDF' => [S::pdf(), 'attestation.pdf', 'application/pdf'];
        yield 'JPG' => [S::jpeg(), 'carte.jpg', 'image/jpeg'];
        yield 'JPEG en majuscules' => [S::jpeg(), 'CARTE.JPEG', 'image/jpeg'];
        yield 'PNG' => [S::png(), 'photo.png', 'image/png'];
        yield 'DOC' => [S::doc(), 'courrier.doc', 'application/msword'];
    }

    #[DataProvider('acceptedFiles')]
    public function testAcceptsTheValidatedFormatsAndReportsTheRealMimeAndSize(string $content, string $name, string $mime): void
    {
        $document = (new UploadedDocumentInspector())->inspect($content, $name);

        self::assertSame($mime, $document->mimeType);
        self::assertSame(\strlen($content), $document->sizeBytes);
        self::assertSame($name, $document->originalName);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedFiles(): iterable
    {
        yield 'DOCX interdit' => [S::zip(), 'lettre.docx'];
        yield 'archive ZIP renommée en .doc' => [S::zip(), 'lettre.doc'];
        yield 'PNG renommé en .pdf' => [S::png(), 'faux.pdf'];
        yield 'PDF renommé en .png' => [S::pdf(), 'faux.png'];
        yield 'DOC renommé en .jpg' => [S::doc(), 'faux.jpg'];
        yield 'fichier vide' => ['', 'vide.pdf'];
        yield 'PDF tronqué (sans fin de fichier)' => ["%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n", 'tronque.pdf'];
        yield 'PNG corrompu' => [substr(S::png(), 0, 30), 'casse.png'];
        yield 'texte en .pdf' => ['simplement du texte', 'texte.pdf'];
        yield 'script exécutable' => ['<?php echo 1;', 'script.php'];
        yield 'sans extension' => [S::pdf(), 'attestation'];
        yield 'extension seule' => [S::pdf(), '.pdf'];
    }

    #[DataProvider('refusedFiles')]
    public function testRefusesNonCompliantFiles(string $content, string $name): void
    {
        $this->expectException(DomainRuleViolation::class);

        (new UploadedDocumentInspector())->inspect($content, $name);
    }

    public function testSizeLimitIsAppliedToTheRealContentNotTheDeclaredOne(): void
    {
        $limit = PermitRequestAttachment::MAX_SIZE_BYTES;
        $atLimit = "%PDF-1.4\n".str_repeat('0', $limit - 9 - 7)."\n%%EOF\n";
        self::assertSame($limit, (new UploadedDocumentInspector())->inspect($atLimit, 'limite.pdf')->sizeBytes);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('10 Mo');
        (new UploadedDocumentInspector())->inspect($atLimit.'x', 'trop-gros.pdf');
    }

    public function testTheClientNameIsReducedToASafeDisplayName(): void
    {
        $inspector = new UploadedDocumentInspector();

        self::assertSame('passwd.pdf', $inspector->inspect(S::pdf(), '../../etc/passwd.pdf')->originalName);
        self::assertSame('windows.pdf', $inspector->inspect(S::pdf(), 'C:\\dossier\\windows.pdf')->originalName);
        self::assertSame('a b .pdf', $inspector->inspect(S::pdf(), "a\tb\n.pdf")->originalName);
        self::assertLessThanOrEqual(255, mb_strlen($inspector->inspect(S::pdf(), str_repeat('é', 400).'.pdf')->originalName));
    }
}
