<?php

namespace JTranslate\View\Helper;

use JTranslate\I18n\TranslatableMessage;
use Laminas\Mvc\Plugin\FlashMessenger\View\Helper\FlashMessenger as BaseFlashMessenger;

use function array_walk_recursive;
use function implode;
use function sprintf;

/**
 * The laminas flash-messenger view helper, taught to render a TranslatableMessage.
 *
 * The parent translates every message it is handed, which is also how a phrase is
 * discovered — so a message that was built by concatenating data registered the
 * *data* as a phrase. See TranslatableMessage's docblock for what that cost.
 *
 * This subclass changes exactly one thing: a message that is a TranslatableMessage
 * is rendered from its template and parameters instead of being handed to
 * `translate()` whole. Plain strings behave as they always did, byte for byte,
 * which matters because both front controllers render flash messages through this
 * helper — the laminas layout directly, and the Symfony side through
 * `App\Twig\LaminasExtension::flashMessages()`.
 *
 * It is registered by overriding the *factory* for the parent's service id rather
 * than by re-aliasing `flashMessenger`, so every one of the parent module's five
 * aliases resolves here without JTranslate having to restate them. That works only
 * because `config/modules.config.php` loads JTranslate after
 * `Laminas\Mvc\Plugin\FlashMessenger`.
 */
class FlashMessenger extends BaseFlashMessenger
{
    /**
     * @param string    $namespace
     * @param array<array-key, mixed> $messages
     * @param array<array-key, string> $classes
     * @param bool|null $autoEscape
     * @return string
     */
    protected function renderMessages(
        $namespace = 'default',
        array $messages = [],
        array $classes = [],
        $autoEscape = null
    ) {
        if (empty($messages)) {
            return '';
        }

        if (empty($classes)) {
            //The parent resolves this through a private getClasses()/getNamespace()
            //pair, so it cannot be called from here. Reproduced rather than reached:
            //the namespaces list is only ever populated from
            //`view_helper_config.flashmessenger`, which this application does not
            //set, and every call site in it passes $classes explicitly anyway.
            $classes = [$this->classMessages[$namespace] ?? ''];
        }

        $autoEscape ??= $this->autoEscape;

        $escapeHtml           = $this->getEscapeHtmlHelper();
        $messagesToPrint      = [];
        $translator           = $this->getTranslator();
        $translatorTextDomain = $this->getTranslatorTextDomain();
        array_walk_recursive(
            $messages,
            function ($item) use (&$messagesToPrint, $escapeHtml, $autoEscape, $translator, $translatorTextDomain) {
                if ($item instanceof TranslatableMessage) {
                    $messagesToPrint[] = $item->render(
                        static fn(string $message, ?string $domain): string => null === $translator
                            ? $message
                            : $translator->translate($message, $domain ?? $translatorTextDomain),
                        $autoEscape ? static fn(string $text): string => $escapeHtml($text) : null
                    );
                    return;
                }

                if ($translator !== null) {
                    $item = $translator->translate($item, $translatorTextDomain);
                }

                if ($autoEscape) {
                    $messagesToPrint[] = $escapeHtml($item);
                    return;
                }

                $messagesToPrint[] = $item;
            }
        );

        if (empty($messagesToPrint)) {
            return '';
        }

        $markup  = sprintf($this->getMessageOpenFormat($namespace), ' class="' . implode(' ', $classes) . '"');
        $markup .= implode(
            sprintf($this->getMessageSeparatorString($namespace), ' class="' . implode(' ', $classes) . '"'),
            $messagesToPrint
        );
        $markup .= $this->getMessageCloseString($namespace);
        return $markup;
    }
}
