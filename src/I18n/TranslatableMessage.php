<?php

namespace JTranslate\I18n;

use Throwable;

use function array_map;
use function count;
use function vsprintf;

/**
 * A message that is translated as a *template* and only then filled with data.
 *
 * ## The bug this exists for
 *
 * Every messenger in this application translates the finished message at render
 * time, which is also how a phrase enters `trans_phrases`: the translator fires
 * `EVENT_MISSING_TRANSLATION` and JTranslate's listener records whatever string it
 * was handed. So a message built by concatenation registers the *concatenated*
 * string as a phrase, one row per distinct value:
 *
 *     addMessage('Token issued. … we do not store it: ' . $jwt);
 *
 * put four real JWTs into a table that every `sch_api_translator` account can read,
 * on a screen whose own copy says the token is not stored. The same shape put six
 * strangers' mistyped email hostnames there, and turned `'Assignment Id: %d'` into a
 * row per assignment. None of them is translatable either — nobody can render a JWT
 * into Italian.
 *
 * Passing one of these instead keeps the data out of the lookup entirely:
 *
 *     addMessage(new TranslatableMessage('Token issued. … we do not store it: %s', [$jwt]));
 *
 * The template is what reaches `translate()`, so the template is the phrase, and one
 * row covers every token ever issued.
 *
 * ## Where it is understood
 *
 * `JTranslate\View\Helper\NowMessenger` and `JTranslate\View\Helper\FlashMessenger`.
 * Anything else that receives one will stringify it badly, so it is deliberately not
 * `Stringable`: a silent `"Token issued…: %s"` with the data dropped would be worse
 * than a visible error.
 *
 * Flash messages survive a redirect in the session, so instances are serialized.
 * Keep the properties plain for that reason.
 */
final class TranslatableMessage
{
    /** @var string The phrase, with sprintf placeholders where the data goes. */
    private $template;

    /** @var array<int, string> */
    private $parameters;

    /** @var string|null Null means "whatever domain the renderer is rendering in". */
    private $textDomain;

    /**
     * @param string $template
     * @param array<int, scalar|null> $parameters
     * @param string|null $textDomain
     */
    public function __construct($template, array $parameters = [], $textDomain = null)
    {
        $this->template   = (string) $template;
        $this->parameters = array_map(static fn($p): string => (string) $p, array_values($parameters));
        $this->textDomain = null === $textDomain ? null : (string) $textDomain;
    }

    /** @return string */
    public function getTemplate()
    {
        return $this->template;
    }

    /** @return array<int, string> */
    public function getParameters()
    {
        return $this->parameters;
    }

    /** @return string|null */
    public function getTextDomain()
    {
        return $this->textDomain;
    }

    /**
     * Translate the template, escape both halves, then interpolate.
     *
     * The order matters twice. Escaping *after* translation is what lets a
     * translation contain characters that need escaping; escaping the parameters
     * separately is what keeps a moderator's free text — or an exception message —
     * from injecting markup. A placeholder (`%s`, `%1$s`) contains nothing an HTML
     * escaper touches, so escaping the template first leaves it intact.
     *
     * @param callable(string, string|null): string $translate
     * @param callable(string): string|null $escape null disables escaping, matching
     *        the messengers' `autoEscape` flag.
     * @return string
     */
    public function render(callable $translate, ?callable $escape = null): string
    {
        $template   = $translate($this->template, $this->textDomain);
        $parameters = $this->parameters;

        if (null !== $escape) {
            $template   = $escape($template);
            $parameters = array_map($escape, $parameters);
        }

        if (0 === count($parameters)) {
            return $template;
        }

        //A translation is data, and a translator who drops a placeholder or writes
        //`%d` where the key says `%s` would otherwise take the page down with a
        //ValueError. Falling back to the source template keeps the message readable
        //and still shows the data.
        try {
            return vsprintf($template, $parameters);
        } catch (Throwable $e) {
            $source = null === $escape ? $this->template : $escape($this->template);
            try {
                return vsprintf($source, $parameters);
            } catch (Throwable $e) {
                return $source;
            }
        }
    }
}
