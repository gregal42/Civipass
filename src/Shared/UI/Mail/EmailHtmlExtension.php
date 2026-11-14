<?php

declare(strict_types=1);

namespace App\Shared\UI\Mail;

use Twig\Attribute\AsTwigFilter;

/**
 * Filtre `email_html` : applique la charte Civipass (design-system/tokens.json, thème clair ; le sombre est géré par les classes `cp-*` du gabarit
 * d'e-mail) au contenu HTML simple d'un message, en styles EN LIGNE (les clients de messagerie ignorent ou réécrivent les feuilles de style).
 * Éléments pris en charge : h2, p, p.note, p.cta (bouton), ul/ol/li, strong, a, pre. Le HTML d'entrée est celui déjà échappé par Twig : le
 * filtre ne crée aucun contenu, il ajoute seulement des attributs `style` et `class`.
 */
final class EmailHtmlExtension
{
    private const FONT = "'Atkinson Hyperlegible Next',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif";
    private const MONO = "'Atkinson Hyperlegible Mono',ui-monospace,'SF Mono',Menlo,Consolas,monospace";

    #[AsTwigFilter('email_html', isSafe: ['html'])]
    public function style(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="cp-root">'.$html.'</div>', \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('cp-root');
        if (!$root instanceof \DOMElement) {
            return $html;
        }

        // Boutons d'abord : le paragraphe est remplacé par un tableau, ses liens ne sont ensuite plus restylés.
        foreach (iterator_to_array($doc->getElementsByTagName('p')) as $p) {
            if (str_contains(' '.$p->getAttribute('class').' ', ' cta ')) {
                $this->button($doc, $p);
            }
        }
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            $this->decorate($el);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private function button(\DOMDocument $doc, \DOMElement $p): void
    {
        $link = $p->getElementsByTagName('a')->item(0);
        if (!$link instanceof \DOMElement) {
            return;
        }
        $href = htmlspecialchars($link->getAttribute('href'), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $label = htmlspecialchars($link->textContent, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;"><tr>'
            .'<td class="cp-btn-cell" align="center" bgcolor="#1d3f73" style="background-color:#1d3f73;border-radius:6px;">'
            .'<a class="cp-btn" href="'.$href.'" style="display:inline-block;padding:14px 24px;min-height:20px;font-family:'.self::FONT.';font-size:16px;line-height:20px;font-weight:700;color:#ffffff;background-color:#1d3f73;text-decoration:none;border-radius:6px;">'.$label.'</a>'
            .'</td></tr></table>';
        $fragment = new \DOMDocument('1.0', 'UTF-8');
        $fragment->loadHTML('<?xml encoding="utf-8"?>'.$html, \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD);
        $table = $fragment->getElementsByTagName('table')->item(0);
        if (null !== $table && null !== $p->parentNode) {
            $p->parentNode->replaceChild($doc->importNode($table, true), $p);
        }
    }

    private function decorate(\DOMElement $el): void
    {
        $tag = strtolower($el->tagName);
        if ('a' === $tag && str_contains($el->getAttribute('class'), 'cp-btn')) {
            return;
        }
        $isNote = 'p' === $tag && str_contains(' '.$el->getAttribute('class').' ', ' note ');
        [$class, $style] = match (true) {
            'h2' === $tag => ['cp-h2', 'margin:0 0 16px 0;font-family:'.self::FONT.';font-size:26px;line-height:1.2;font-weight:800;letter-spacing:-0.015em;color:#0f1e36;'],
            $isNote => ['cp-note', 'margin:0 0 16px 0;font-family:'.self::FONT.';font-size:14px;line-height:1.5;color:#56637a;'],
            'p' === $tag => ['cp-p', 'margin:0 0 16px 0;font-family:'.self::FONT.';font-size:16px;line-height:1.55;color:#0f1e36;'],
            'ul' === $tag, 'ol' === $tag => ['cp-p', 'margin:0 0 16px 0;padding:0 0 0 22px;font-family:'.self::FONT.';font-size:16px;line-height:1.55;color:#0f1e36;'],
            'li' === $tag => ['cp-li', 'margin:0 0 6px 0;color:#0f1e36;'],
            'a' === $tag => ['cp-a', 'color:#1d3f73;text-decoration:underline;font-weight:700;word-break:break-all;'],
            'strong' === $tag => ['cp-ink', 'font-weight:700;color:#0f1e36;'],
            'pre' === $tag => ['cp-pre', 'margin:0 0 16px 0;padding:12px 14px;background-color:#e9edf2;border:1px solid #d2d9e2;border-radius:6px;font-family:'.self::MONO.';font-size:13px;line-height:1.5;color:#0f1e36;white-space:pre-wrap;word-break:break-all;'],
            default => [null, null],
        };
        if (null === $class || null === $style) {
            return;
        }
        $el->setAttribute('class', trim($el->getAttribute('class').' '.$class));
        $el->setAttribute('style', $style);
    }
}
