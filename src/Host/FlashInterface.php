<?php

declare(strict_types=1);

namespace JTranslate\Host;

use JTranslate\I18n\TranslatableMessage;

/**
 * Tells the visitor something, on a page this module is not rendering the chrome of.
 *
 * Both methods exist because the **lifetime** differs, and the difference is not
 * cosmetic: {@see self::flash()} is read by the *next* page and {@see self::now()} by the
 * response being returned. A redirect that flashes nothing is silent; a re-rendered form
 * that flashes instead of showing the message now puts "please review" on whatever page
 * the translator opens next, while the field-level errors sit unexplained on the form in
 * front of them.
 *
 * The rule that follows is worth stating as one, because this module's GUI got it wrong
 * in one place and right in the other: **a message belongs in the flash bag exactly when
 * the response carrying it is a redirect.** The delete action's failed-CSRF branch
 * re-renders and used `nowMessenger`; the edit action's failed-validation branch also
 * re-renders and used the flash messenger.
 *
 * ## What it replaces
 *
 * `Laminas\Mvc\Plugin\FlashMessenger` and this module's own `NowMessenger` controller
 * plugin, both deleted with the laminas-mvc layer. The host keeps the messages
 * (schoenstatt.link: `SionModel\Messaging\FlashMessages` and `NowMessages`) and renders
 * them through {@see \JTranslate\I18n\MessageRenderer}.
 *
 * ## One implementation instance per request, and the host must ensure it
 *
 * `FlashMessenger::addMessage()` calls `getMessagesFromContainer()` on first use, which
 * moves every namespace out of the session container into that instance and unsets it
 * from the container. A *second* instance doing that after the first has written takes
 * the first's message out of the session and holds it in an object discarded at the end
 * of the request — so two messages silently become one.
 *
 * On a host that also serves JUser this is sharper than it sounds: the two modules have
 * separate contracts and separate adapters, and both wrap the same laminas messenger. One
 * instance means one for the *request*, not one per module.
 */
interface FlashInterface
{
    /**
     * Survives a redirect; rendered by the next page in this session.
     *
     * @param string|TranslatableMessage $message A `TranslatableMessage` is how data
     *        reaches a message without becoming part of the phrase key. That matters more
     *        on this surface than anywhere else: a message rendered through the translator
     *        files a phrase row for whatever it says, so interpolating a filesystem error
     *        or a phrase's own text into one writes a new, permanent, untranslatable
     *        phrase per distinct value. It is deliberately not `Stringable`, so an
     *        implementation that narrows this parameter to `string` turns a correct call
     *        into a fatal rather than into a bad translation.
     */
    public function flash(Severity $severity, string|TranslatableMessage $message): void;

    /**
     * Rendered by the response being returned now, and gone afterwards.
     *
     * @param string|TranslatableMessage $message see {@see self::flash()}
     */
    public function now(Severity $severity, string|TranslatableMessage $message): void;
}
