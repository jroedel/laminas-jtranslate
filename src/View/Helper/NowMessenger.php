<?php

/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace JTranslate\View\Helper;

use JTranslate\Controller\Plugin\NowMessenger as PluginNowMessenger;
use JTranslate\I18n\TranslatableMessage;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\I18n\View\Helper\AbstractTranslatorHelper;
use Laminas\View\Helper\EscapeHtml;
use Laminas\Mvc\Plugin\FlashMessenger\FlashMessenger;
use Laminas\View\Helper\AbstractHelper;

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
    protected $classMessages = [
        PluginNowMessenger::NAMESPACE_INFO => ['alert', 'alert-dismissable', 'alert-info'],
        PluginNowMessenger::NAMESPACE_ERROR => ['alert', 'alert-dismissable', 'alert-danger'],
        PluginNowMessenger::NAMESPACE_SUCCESS => ['alert', 'alert-dismissable', 'alert-success'],
        PluginNowMessenger::NAMESPACE_DEFAULT => ['alert', 'alert-dismissable', 'alert-default'],
        PluginNowMessenger::NAMESPACE_WARNING => ['alert', 'alert-dismissable', 'alert-warning'],
    ];
/**
     * Templates for the open/close/separators for message tags
     *
     * @var string
     */
    protected $messageCloseString     = '</div>';
    protected $messageOpenFormat      = '<div%s>
     <button type="button" class="close" data-dismiss="alert" aria-hidden="true">
         &times;
     </button>
     ';
    protected $messageSeparatorString = '</br>';
/**
     * Flag whether to escape messages
     *
     * @var bool
     */
    protected $autoEscape = true;
/**
     * Html escape helper
     *
     * @var EscapeHtml
     */
    protected $escapeHtmlHelper;
/**
     * Flash messenger plugin
     *
     * @var FlashMessenger
     */
    protected $pluginFlashMessenger;
/**
     * Returns the flash messenger plugin controller
     *
     * @param  string|null $namespace
     * @return FlashMessenger|PluginNowMessenger
     */
    public function __invoke()
    {
        $nowMessenger = $this->getPluginNowMessenger();
        $markup = '';
        $markup .= $this->renderMessages(
            PluginNowMessenger::NAMESPACE_ERROR,
            $nowMessenger->getMessages(PluginNowMessenger::NAMESPACE_ERROR)
        );
        $markup .= $this->renderMessages(
            PluginNowMessenger::NAMESPACE_WARNING,
            $nowMessenger->getMessages(PluginNowMessenger::NAMESPACE_WARNING)
        );
        $markup .= $this->renderMessages(
            PluginNowMessenger::NAMESPACE_INFO,
            $nowMessenger->getMessages(PluginNowMessenger::NAMESPACE_INFO)
        );
        $markup .= $this->renderMessages(
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
     * @param array     $messages
     * @param array     $classes
     * @param bool|null $autoEscape
     * @return string
     */
    protected function renderMessages($namespace, array $messages = [], array $classes = [], $autoEscape = null)
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
        $escapeHtml      = $this->getEscapeHtmlHelper();
        $messagesToPrint = [];
        $translator = $this->getTranslator();
        $translatorTextDomain = $this->getTranslatorTextDomain();
        $walk = function ($item) use (&$messagesToPrint, $escapeHtml, $autoEscape, $translator, $translatorTextDomain) {

            //A message carrying data translates its template and interpolates
            //afterwards, so the data never reaches translate() and never becomes a
            //phrase. See JTranslate\I18n\TranslatableMessage.
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
        };
        array_walk_recursive($messages, $walk);

        if (empty($messagesToPrint)) {
            return '';
        }

        // Generate markup
        $markup  = sprintf($this->getMessageOpenFormat(), ' class="' . implode(' ', $classes) . '"');
        $separator = sprintf($this->getMessageSeparatorString(), ' class="' . implode(' ', $classes) . '"');
        $markup   .= implode($separator, $messagesToPrint);
        $markup .= $this->getMessageCloseString();
        return $markup;
    }

    /**
     * Set whether or not auto escaping should be used
     *
     * @param  bool $autoEscape
     * @return self
     */
    public function setAutoEscape($autoEscape = true)
    {
        $this->autoEscape = (bool) $autoEscape;
        return $this;
    }

    /**
     * Return whether auto escaping is enabled or disabled
     *
     * return bool
     */
    public function getAutoEscape()
    {
        return $this->autoEscape;
    }

    /**
     * Set the string used to close message representation
     *
     * @param  string $messageCloseString
     * @return FlashMessenger
     */
    public function setMessageCloseString($messageCloseString)
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
     * Set the formatted string used to open message representation
     *
     * @param  string $messageOpenFormat
     * @return FlashMessenger
     */
    public function setMessageOpenFormat($messageOpenFormat)
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
     * Set the string used to separate messages
     *
     * @param  string $messageSeparatorString
     * @return FlashMessenger
     */
    public function setMessageSeparatorString($messageSeparatorString)
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
     * Set the flash messenger plugin
     *
     * @param  PluginNowMessenger $pluginFlashMessenger
     * @return FlashMessenger
     */
    public function setPluginNowMessenger(PluginNowMessenger $pluginFlashMessenger)
    {
        $this->pluginFlashMessenger = $pluginFlashMessenger;
        return $this;
    }

    /**
     * Get the flash messenger plugin
     *
     * @return PluginNowMessenger
     */
    public function getPluginNowMessenger()
    {
        if (null === $this->pluginFlashMessenger) {
//was setPluginFlashMessenger(), a method this class never had, so
            //this lazy-init branch could only ever raise "undefined method"
            $this->setPluginNowMessenger(new PluginNowMessenger());
        }

        return $this->pluginFlashMessenger;
    }

    /**
     * Retrieve the escapeHtml helper
     *
     * @return EscapeHtml
     */
    protected function getEscapeHtmlHelper()
    {
        if ($this->escapeHtmlHelper) {
            return $this->escapeHtmlHelper;
        }

        if (method_exists($this->getView(), 'plugin')) {
            $this->escapeHtmlHelper = $this->view->plugin('escapehtml');
        }

        if (! $this->escapeHtmlHelper instanceof EscapeHtml) {
            $this->escapeHtmlHelper = new EscapeHtml();
        }

        return $this->escapeHtmlHelper;
    }
}
