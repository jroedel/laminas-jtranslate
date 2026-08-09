<?php

namespace JTranslate\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class NowMessenger extends AbstractPlugin
{
    /**
     * Default messages namespace
     */
    public const NAMESPACE_DEFAULT = 'default';

    /**
     * Success messages namespace
     */
    public const NAMESPACE_SUCCESS = 'success';

    /**
     * Warning messages namespace
     */
    public const NAMESPACE_WARNING = 'warning';

    /**
     * Error messages namespace
     */
    public const NAMESPACE_ERROR = 'error';

    /**
     * Info messages namespace
     */
    public const NAMESPACE_INFO = 'info';

    protected $messages = [];

    /**
     * Instance namespace, default is 'default'
     *
     * @var string
     */
    protected $namespace = self::NAMESPACE_DEFAULT;

    public function __construct()
    {
        $this->messages = [];
    }

    /**
     * Change the namespace messages are added to
     *
     * Useful for per action controller messaging between requests
     *
     * @param  string         $namespace
     * @return self Provides a fluent interface
     */
    public function setNamespace($namespace = 'default')
    {
        $this->namespace = $namespace;

        return $this;
    }

    /**
     * Get the message namespace
     *
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * Add a message
     *
     * @param  string         $message
     * @param  null|string    $namespace
     * @param  null|int       $hops
     * @return self           Provides a fluent interface
     */
    public function addMessage($message, $namespace = null)
    {
        if (is_null($namespace)) {
            $namespace = $this->namespace;
        }
        $this->messages[] = ['message' => $message, 'namespace' => $namespace];
        return $this;
    }

    /**
     * Return the messages pending render
     * @return array
     */
    public function getMessages($namespace = self::NAMESPACE_DEFAULT)
    {
        $return = [];
        foreach ($this->messages as $message) {
            if ($message['namespace'] == $namespace) {
                $return[] = $message['message'];
            }
        }
        return $return;
    }
}
