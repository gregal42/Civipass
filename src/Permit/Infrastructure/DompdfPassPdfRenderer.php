<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use App\Permit\Application\PassPdfRenderer;
use App\Permit\Application\Read\BeneficiaryPass;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Twig\Environment;

/**
 * PDF A4 par dompdf (DT-050), généré à la demande et jamais stocké. Précautions : ressources distantes DÉSACTIVÉES (aucune requête sortante,
 * donc aucune fuite), PHP et JavaScript du document désactivés, accès aux fichiers limité au dossier des gabarits (chroot) ; le HTML vient d'un
 * gabarit Twig à échappement automatique et le QR est intégré en data-URI : rien de saisi par un utilisateur n'est interprété comme du HTML.
 */
#[AsAlias(PassPdfRenderer::class)]
final readonly class DompdfPassPdfRenderer implements PassPdfRenderer
{
    public function __construct(private Environment $twig, private string $templatesDir = __DIR__.'/../../../templates/permit')
    {
    }

    public function render(BeneficiaryPass $pass, string $qrPng, \DateTimeImmutable $generatedAt): string
    {
        $html = $this->twig->render('permit/pass_pdf.html.twig', [
            'pass' => $pass,
            'qr' => 'data:image/png;base64,'.base64_encode($qrPng),
            'generated_at' => $generatedAt,
        ]);
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [(string) realpath($this->templatesDir)]);
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $dompdf->addInfo('Title', 'Laissez-passer '.$pass->reference);
        $dompdf->addInfo('Creator', 'Civipass');

        return (string) $dompdf->output();
    }
}
