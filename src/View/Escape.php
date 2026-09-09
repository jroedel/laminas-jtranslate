<?php

declare(strict_types=1);

namespace JTranslate\View;

use Twig\Runtime\EscaperRuntime;

use function hexdec;
use function mb_convert_encoding;
use function preg_match;
use function preg_replace_callback;
use function sprintf;

/**
 * Attribute escaping for the two JTranslate view helpers that build markup by hand.
 *
 * ## Why this is a copy of SionModel\View\Escape rather than a call to it
 *
 * JTranslate does not require SionModel and must not start: they are two independent
 * libraries, both consumed by two applications, and neither is the other's dependency.
 * `Flag` and `CountryName` used to reach the escaper through the renderer
 * (`$this->view->escapeHtmlAttr()`), which cost JTranslate nothing because laminas-view
 * supplied it. Removing that base class leaves exactly two calls needing an escaper, and
 * the choice is between a twelve-line duplicate and a new inter-library dependency for
 * twelve lines. The duplicate is the smaller commitment.
 *
 * The algorithm is therefore reproduced **verbatim**, including the two details that are
 * easy to lose and impossible to notice:
 *
 *  - the invalid-UTF-8 guard, because Twig *throws* on a string that is not valid UTF-8
 *    while `Laminas\Escaper\Escaper::escapeHtmlAttr()` converted and returned something.
 *    A country name is database text; one bad byte must not take a page down;
 *  - the entity-width normalisation, because Twig writes every numeric entity four digits
 *    wide and laminas wrote two below U+0100. Both are the same character to a browser,
 *    but this application's country names are full of non-ASCII, so without it every
 *    `title="…"` on the site changes bytes on the deploy that ports these helpers — and
 *    byte-identical output is what makes a diff against the live site mean anything.
 *
 * If a third library ever needs this, that is the moment to extract it into a package.
 * Two copies is not yet that moment.
 */
final class Escape
{
    /** The encoding laminas-escaper defaulted to, and the only one either application serves. */
    public const ENCODING = 'utf-8';

    private static ?EscaperRuntime $escaper = null;

    /**
     * Escape a value going into an HTML **attribute**: everything outside `[a-z0-9,.\-_]`
     * becomes a numeric entity, so the value stays inert even unquoted.
     */
    public static function htmlAttr(?string $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }
        if (1 !== preg_match('//u', $value)) {
            //not valid UTF-8: replace the invalid sequences rather than raise
            $value = (string) mb_convert_encoding($value, self::ENCODING, self::ENCODING);
        }

        self::$escaper ??= new EscaperRuntime(self::ENCODING);

        return self::laminasEntityWidth((string) self::$escaper->escape($value, 'html_attr'));
    }

    /** Twig writes `&#x00E9;`, laminas wrote `&#xE9;`; below U+0100 the width was two. */
    private static function laminasEntityWidth(string $escaped): string
    {
        return (string) preg_replace_callback(
            '/&#x([0-9A-F]+);/',
            static function (array $m): string {
                $ord = (int) hexdec($m[1]);

                return sprintf($ord > 255 ? '&#x%04X;' : '&#x%02X;', $ord);
            },
            $escaped
        );
    }
}
