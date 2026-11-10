<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Lecteur minimal d'un PDF produit par dompdf (LOT 05.5.1) : texte réellement écrit (polices sous-ensembles, table ToUnicode) et images intégrées
 * (pixels sombres ou clairs). Aucune dépendance : il ne comprend QUE ce que dompdf produit (flux Flate, polices Type0 Identity-H, images à palette
 * ou RVB avec prédicteur PNG). Il sert à vérifier le DOCUMENT généré, pas son gabarit HTML.
 */
final class PdfInspector
{
    /** @var array<int, array{dict: string, data: ?string}> */
    private array $objects = [];

    public function __construct(string $pdf)
    {
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new \InvalidArgumentException('Ce n\'est pas un PDF.');
        }
        preg_match_all('/(\d+) 0 obj\s*(.*?)\s*endobj/s', $pdf, $matches, \PREG_SET_ORDER);
        foreach ($matches as $m) {
            $body = $m[2];
            $dict = $body;
            $data = null;
            if (1 === preg_match('/^(.*?)\s*stream\r?\n(.*)\r?\nendstream$/s', $body, $parts)) {
                $dict = $parts[1];
                $data = $parts[2];
                if (str_contains($dict, '/FlateDecode')) {
                    $inflated = @gzuncompress($data);
                    $data = false === $inflated ? null : $inflated;
                }
            }
            $this->objects[(int) $m[1]] = ['dict' => $dict, 'data' => $data];
        }
    }

    /** Texte du document, tous espaces retirés (les retours à la ligne et les espaces de justification ne comptent pas). */
    public function text(): string
    {
        $fonts = [];
        foreach ($this->objects as $number => $object) {
            if (1 === preg_match('#/Type\s*/Font\b.*?/ToUnicode\s+(\d+)\s+0\s+R#s', $object['dict'], $m)) {
                $fonts[$number] = $this->cmap($this->objects[(int) $m[1]]['data'] ?? '');
            }
        }
        $names = [];
        foreach ($this->objects as $object) {
            if (1 === preg_match('#/Font\s*<<(.*?)>>#s', $object['dict'], $block)) {
                preg_match_all('#/(F\d+)\s+(\d+)\s+0\s+R#', $block[1], $refs, \PREG_SET_ORDER);
                foreach ($refs as $r) {
                    $names[$r[1]] = (int) $r[2];
                }
            }
        }
        $text = '';
        foreach ($this->objects as $object) {
            $stream = $object['data'];
            if (null === $stream || !str_contains($stream, 'Tf') || !str_contains($stream, 'BT')) {
                continue;
            }
            $map = [];
            $length = \strlen($stream);
            for ($i = 0; $i < $length;) {
                $char = $stream[$i];
                if ('/' === $char && 1 === preg_match('#\G/(F\d+)\s+[\d.]+\s+Tf#', $stream, $font, 0, $i)) {
                    $map = $fonts[$names[$font[1]] ?? -1] ?? [];
                    $i += \strlen($font[0]);
                } elseif ('(' === $char) {
                    [$bytes, $i] = self::literal($stream, $i);
                    $text .= self::glyphs($bytes, $map);
                } elseif ('<' === $char && '<' !== ($stream[$i + 1] ?? '') && 1 === preg_match('#\G<([0-9A-Fa-f\s]*)>#', $stream, $hex, 0, $i)) {
                    $text .= self::glyphs((string) hex2bin((string) preg_replace('/\s+/', '', $hex[1])), $map);
                    $i += \strlen($hex[0]);
                } else {
                    ++$i;
                }
            }
        }

        return (string) preg_replace('/\s+/u', '', $text);
    }

    /**
     * Chaîne littérale PDF `( ... )` à partir de `$start` (parenthèse ouvrante) : échappements, parenthèses équilibrées.
     *
     * @return array{string, int} octets décodés et position après la parenthèse fermante
     */
    private static function literal(string $stream, int $start): array
    {
        $bytes = '';
        $depth = 0;
        $length = \strlen($stream);
        for ($i = $start; $i < $length; ++$i) {
            $c = $stream[$i];
            if ('\\' === $c) {
                $next = $stream[++$i] ?? '';
                if (1 === preg_match('/[0-7]/', $next)) {
                    $octal = $next;
                    while (\strlen($octal) < 3 && 1 === preg_match('/[0-7]/', $stream[$i + 1] ?? '')) {
                        $octal .= $stream[++$i];
                    }
                    $bytes .= \chr(octdec($octal) & 0xFF);
                } else {
                    $bytes .= match ($next) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        'b' => "\x08",
                        'f' => "\x0C",
                        default => $next,
                    };
                }
            } elseif ('(' === $c) {
                if ($depth++ > 0) {
                    $bytes .= $c;
                }
            } elseif (')' === $c) {
                if (0 === --$depth) {
                    return [$bytes, $i + 1];
                }
                $bytes .= $c;
            } else {
                $bytes .= $c;
            }
        }

        return [$bytes, $length];
    }

    /**
     * @param array<int, string> $map
     */
    private static function glyphs(string $bytes, array $map): string
    {
        $text = '';
        foreach (str_split($bytes, 2) as $pair) {
            $text .= $map[(\ord($pair[0]) << 8) | \ord($pair[1] ?? "\0")] ?? '';
        }

        return $text;
    }

    /** @return array<int, string> glyphe → caractère */
    private function cmap(string $cmap): array
    {
        $map = [];
        if (1 === preg_match_all('#beginbfchar(.*?)endbfchar#s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $pairs, \PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $map[hexdec($p[1])] = (string) mb_convert_encoding((string) hex2bin($p[2]), 'UTF-8', 'UTF-16BE');
                }
            }
        }
        if (1 === preg_match_all('#beginbfrange(.*?)endbfrange#s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $ranges, \PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    for ($g = hexdec($r[1]), $u = hexdec($r[3]); $g <= hexdec($r[2]); ++$g, ++$u) {
                        $map[$g] = (string) mb_convert_encoding(pack('n', $u), 'UTF-8', 'UTF-16BE');
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Images (hors masques) : largeur, hauteur et matrice de pixels SOMBRES (vrai = sombre).
     *
     * @return list<array{width: int, height: int, dark: list<list<bool>>}>
     */
    public function images(): array
    {
        $images = [];
        foreach ($this->objects as $object) {
            if (!str_contains($object['dict'], '/Subtype /Image') && !str_contains($object['dict'], '/Subtype/Image')) {
                continue;
            }
            if (null === $object['data'] || 1 !== preg_match('#/Width\s+(\d+)#', $object['dict'], $w) || 1 !== preg_match('#/Height\s+(\d+)#', $object['dict'], $h) || 1 !== preg_match('#/BitsPerComponent\s+(\d+)#', $object['dict'], $b)) {
                continue;
            }
            $width = (int) $w[1];
            $height = (int) $h[1];
            $bits = (int) $b[1];
            $indexed = str_contains($object['dict'], '/Indexed');
            $colors = $indexed || str_contains($object['dict'], '/DeviceGray') ? 1 : 3;
            $data = $object['data'];
            if (str_contains($object['dict'], '/Predictor')) {
                $data = self::undoPngPredictor($data, $width, $colors, $bits);
            }
            $palette = [];
            if ($indexed && 1 === preg_match('#/Indexed\s*/DeviceRGB\s+\d+\s+(\d+)\s+0\s+R#', $object['dict'], $p)) {
                $raw = $this->objects[(int) $p[1]]['data'] ?? '';
                for ($i = 0; $i + 2 < \strlen($raw); $i += 3) {
                    $palette[] = (\ord($raw[$i]) + \ord($raw[$i + 1]) + \ord($raw[$i + 2])) / 3;
                }
            }
            $rowBytes = (int) ceil($width * $colors * $bits / 8);
            $dark = [];
            for ($y = 0; $y < $height; ++$y) {
                $row = substr($data, $y * $rowBytes, $rowBytes);
                $line = [];
                for ($x = 0; $x < $width; ++$x) {
                    if ($indexed || 1 === $colors) {
                        $sample = self::sample($row, $x, $bits);
                        $luma = $indexed ? ($palette[$sample] ?? 255) : $sample * (255 / ((1 << $bits) - 1));
                    } else {
                        $luma = (\ord($row[$x * 3]) + \ord($row[$x * 3 + 1]) + \ord($row[$x * 3 + 2])) / 3;
                    }
                    $line[] = $luma < 128;
                }
                $dark[] = $line;
            }
            $images[] = ['width' => $width, 'height' => $height, 'dark' => $dark];
        }

        return $images;
    }

    private static function sample(string $row, int $x, int $bits): int
    {
        if (8 === $bits) {
            return \ord($row[$x]);
        }
        $perByte = intdiv(8, $bits);
        $byte = \ord($row[intdiv($x, $perByte)]);
        $shift = 8 - $bits * (($x % $perByte) + 1);

        return ($byte >> $shift) & ((1 << $bits) - 1);
    }

    private static function undoPngPredictor(string $data, int $columns, int $colors, int $bits): string
    {
        $rowBytes = (int) ceil($columns * $colors * $bits / 8);
        $bpp = max(1, (int) ceil($colors * $bits / 8));
        $out = '';
        $previous = str_repeat("\0", $rowBytes);
        for ($offset = 0; $offset + 1 + $rowBytes <= \strlen($data); $offset += 1 + $rowBytes) {
            $filter = \ord($data[$offset]);
            $row = array_values(unpack('C*', substr($data, $offset + 1, $rowBytes)) ?: []);
            $prev = array_values(unpack('C*', $previous) ?: []);
            for ($i = 0; $i < $rowBytes; ++$i) {
                $a = $i >= $bpp ? $row[$i - $bpp] : 0;
                $b = $prev[$i];
                $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                $row[$i] = ($row[$i] + match ($filter) {
                    1 => $a,
                    2 => $b,
                    3 => intdiv($a + $b, 2),
                    4 => self::paeth($a, $b, $c),
                    default => 0,
                }) & 0xFF;
            }
            $previous = pack('C*', ...$row);
            $out .= $previous;
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }
}
