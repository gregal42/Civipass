<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use App\Permit\Application\QrCodeRenderer;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Rendu des QR par endroid/qr-code (DT-050) : noir sur blanc, marge de lecture (zone calme) de 4 modules au minimum, correction d'erreurs
 * moyenne (l'URL de vérification est courte : peu de modules, donc lisible de loin sur un écran). Rien n'est écrit sur disque.
 */
#[AsAlias(QrCodeRenderer::class)]
final readonly class EndroidQrCodeRenderer implements QrCodeRenderer
{
    public function svg(string $content): string
    {
        return $this->builder($content, 400, new SvgWriter(), [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true])->getString();
    }

    public function png(string $content, int $size = 480): string
    {
        return $this->builder($content, $size, new PngWriter(), [])->getString();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function builder(string $content, int $size, \Endroid\QrCode\Writer\WriterInterface $writer, array $options): \Endroid\QrCode\Writer\Result\ResultInterface
    {
        return (new Builder(
            writer: $writer,
            writerOptions: $options,
            data: $content,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 24,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();
    }
}
