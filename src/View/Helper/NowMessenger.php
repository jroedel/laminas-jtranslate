<?php

declare(strict_types=1);

namespace JTranslate\View\Helper;

use JTranslate\Controller\Plugin\NowMessenger as PluginNowMessenger;
use Laminas\I18n\View\Helper\AbstractTranslatorHelper;
use Laminas\View\Helper\EscapeHtml;

use function array_walk_recursive;
use function call_user_func_array;
use function implode;
use function sprintf;

/**
 * Helper to proxy the plugin flash messenger
 */
class NowMessenger extends AbstractTranslatorHelper
{
    /**
     * Default attributes for the open format tag
     *
     * @var array
     */
    protected $classMessages = [PluginNowMessenger::NAMESPACE_INFO => ['alert', 'alert-dismissable', 'alert-info'], PluginNowMessenger::NAMESPACE_ERROR => ['alert', 'alert-dismissable', 'alert-danger'], PluginNowMessenger::NAMESPACE_SUCCESS => ['alert', 'alert-dismissable', 'alert-success'], PluginNowMessenger::NAMESPACE_DEFAULT => ['alert', 'alert-dismissable', 'alert-default'], PluginNowMessenger::NAMESPACE_WARNING => ['alert', 'alert-dismissable', 'alert-warning']];
/**
 * Templates for the open/close/separators for message tags
 */
    protected string $messageCloseString     = '</div>';
    protected string $messageOpenFormat      = '<div%s>
     <button type="button" class="close" data-dismiss="alert" aria-hidden="true">
         &times;
     </button>
     ';
    protected string $messageSeparatorString = '</br>';
/**
 * Flag whether to escape messages
 */
    protected bool $autoEscape = true;

    public function __construct(
        protected PluginNowMessenger $pluginNowMessenger,
        protected EscapeHtml $escapeHtmlHelper
    ) {
    }

    /**
    *  Returns the flash messenger plugin controller
    */
    public function __invoke(): string
    {
        $nowMessenger = $this->getPluginNowMessenger();
        $markup       = $this->renderMessages(
            PluginNowMessenger::NAMESPACE_ERROR,
            $nowMessenger->getMessages(PluginNowMessenger::NAMESPACE_ERROR)
        );
        $markup      .= $this->renderMessages(
            PluginNowMessenger::NAMESPACE_WARNING,
            $nowMessenger->getMessages(PluginNowMessenger::NAMESPACE_WARNING)
        );
        $markup      .= $this->renderMessages(
            PluginNowMessenger::NAMESPACE_INFO,
            $nowMessenger->getMessages(PluginNowMessenger::NAMESPACE_INFO)
        );
        $markup      .= $this->renderMessages(
            PluginNowMessenger::NAMESPACE_SUCCESS,
            $nowMessenger->getMessages(PluginNowMessenger::NAMESPACE_SUCCESS)
        );
        return $markup;
    }

    /**
     * Proxy the flash messenger plugin controller
     *
     * @param  string $method
     * @param  array  $argv
     * @return mixed
     */
    public function __call($method, $argv)
    {
        $flashMessenger = $this->getPluginNowMessenger();
        return call_user_func_array([$flashMessenger, $method], $argv);
    }

    /**
     * Render Messages
     *
     * @param string    $namespace
     * @param bool|null $autoEscape
     */
    protected function renderMessages($namespace, array $messages = [], array $classes = [], $autoEscape = null): string
    {
        // Prepare classes for opening tag
        if (empty($classes)) {
            if (isset($this->classMessages[$namespace])) {
                $classes = $this->classMessages[$namespace];
            } else {
                $classes = $this->classMessages[PluginNowMessenger::NAMESPACE_DEFAULT];
            }
//             $classes = array($classes);
        }

        if (null === $autoEscape) {
            $autoEscape = $this->getAutoEscape();
        }

        // Flatten message array
        $escapeHtml           = $this->getEscapeHtmlHelper();
        $messagesToPrint      = [];
        $translator           = $this->getTranslator();
        $translatorTextDomain = $this->getTranslatorTextDomain();
        array_walk_recursive(
            $messages,
            function ($item) use (&$messagesToPrint, $escapeHtml, $autoEscape, $translator, $translatorTextDomain) {
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

        // Generate markup
        $markup  = sprintf($this->getMessageOpenFormat(), ' class="' . implode(' ', $classes) . '"');
        $markup .= implode(
            sprintf(
                $this->getMessageSeparatorString(),
                ' class="' . implode(' ', $classes) . '"'
            ),
            $messagesToPrint
        );
        $markup .= $this->getMessageCloseString();
        return $markup;
    }

    /**
     * Set whether or not auto escaping should be used
     *
     * @return self
     */
    public function setAutoEscape(bool $autoEscape = true)
    {
        $this->autoEscape = (bool) $autoEscape;
        return $this;
    }

    /**
     *  Return whether auto escaping is enabled or disabled
     *
     *  return bool
     */
    public function getAutoEscape(): bool
    {
        return $this->autoEscape;
    }

    /**
     *  Set the string used to close message representation
     *
     * @param string $messageCloseString
     */
    public function setMessageCloseString($messageCloseString): static
    {
        $this->messageCloseString = (string) $messageCloseString;
        return $this;
    }

    /**
     * Get the string used to close message representation
     *
     * @return string
     */
    public function getMessageCloseString()
    {
        return $this->messageCloseString;
    }

    /**
     *  Set the formatted string used to open message representation
     *
     * @param string $messageOpenFormat
     */
    public function setMessageOpenFormat($messageOpenFormat): static
    {
        $this->messageOpenFormat = (string) $messageOpenFormat;
        return $this;
    }

    /**
     * Get the formatted string used to open message representation
     *
     * @return string
     */
    public function getMessageOpenFormat()
    {
        return $this->messageOpenFormat;
    }

    /**
     *  Set the string used to separate messages
     */
    public function setMessageSeparatorString(string $messageSeparatorString): static
    {
        $this->messageSeparatorString = (string) $messageSeparatorString;
        return $this;
    }

    /**
     * Get the string used to separate messages
     *
     * @return string
     */
    public function getMessageSeparatorString()
    {
        return $this->messageSeparatorString;
    }

    /**
     *  Get the flash messenger plugin
     */
    public function getPluginNowMessenger(): PluginNowMessenger
    {
        return $this->pluginNowMessenger;
    }

    /**
     * Retrieve the escapeHtml helper
     *
     * @return EscapeHtml
     */
    protected function getEscapeHtmlHelper()
    {
        return $this->escapeHtmlHelper;
    }
}
