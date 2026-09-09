<?php

declare(strict_types=1);

namespace JTranslate\I18n;

use Closure;

use function array_walk_recursive;
use function htmlspecialchars;
use function implode;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Renders one namespace of user messages as markup, understanding a
 * {@see TranslatableMessage}.
 *
 * This is the rendering half of the laminas flash-messenger view helper and of the
 * `NowMessenger` helper this module carried, with the storage taken out: the host keeps
 * the messages (a session-backed store for flashes, a per-request one for "now") and hands
 * a list here. What the two helpers did, and this reproduces byte for byte, is
 *
 *   sprintf($openFormat, ' class="…"') . implode($separator, $messages) . $close
 *
 * with every message translated in the given text domain and HTML-escaped, and a
 * TranslatableMessage rendered from its template and parameters instead — so its data
 * never reaches `translate()` and never becomes a phrase (see that class).
 *
 * The formats are the caller's, because the two helpers shipped different indentation
 * inside the same `<div>`, and a host diffing its pages across the port wants each as it
 * was.
 */
final class MessageRenderer
{
    /**
     * @param Closure(string, string): string $translate `fn (message, textDomain)`
     * @param string $openFormat a sprintf format with one `%s`, which receives
     *        ` class="…"` — the helpers' convention
     */
    public function __construct(
        private readonly Closure $translate,
        private readonly string $openFormat,
        private readonly string $separator,
        private readonly string $close,
        private readonly bool $escape = true
    ) {
    }

    /**
     * @param array<array-key, mixed> $messages strings and TranslatableMessages, nested
     *        arrays allowed (the helpers flattened them)
     * @param list<string> $classes the `<div>`'s classes
     * @param string $textDomain where a plain string, or a TranslatableMessage naming no
     *        domain of its own, is translated
     */
    public function render(array $messages, array $classes, string $textDomain): string
    {
        if ([] === $messages) {
            return '';
        }
        $translate = $this->translate;
        $escape    = $this->escape
            ? static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : null;

        $rendered = [];
        array_walk_recursive(
            $messages,
            static function ($item) use (&$rendered, $translate, $escape, $textDomain): void {
                if ($item instanceof TranslatableMessage) {
                    $rendered[] = $item->render(
                        static fn (string $message, ?string $domain): string
                            => $translate($message, $domain ?? $textDomain),
                        $escape
                    );

                    return;
                }
                $text       = $translate((string) $item, $textDomain);
                $rendered[] = null === $escape ? $text : $escape($text);
            }
        );
        if ([] === $rendered) {
            return '';
        }

        $attribute = ' class="' . implode(' ', $classes) . '"';

        return sprintf($this->openFormat, $attribute)
            . implode(sprintf($this->separator, $attribute), $rendered)
            . $this->close;
    }
}
