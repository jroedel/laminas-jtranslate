<?php

declare(strict_types=1);

/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 */

namespace JTranslate\View\Helper\Service;

use JTranslate\View\Helper\NowMessenger;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\View\Helper\EscapeHtml;
use Laminas\View\HelperPluginManager;
use Psr\Container\ContainerInterface;

use function var_dump;

class NowMessengerFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritDoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $serviceLocator               = $container->getServiceLocator();
        $controllerPluginManager      = $serviceLocator->get('ControllerPluginManager');
        $nowMessengerControllerPlugin = $controllerPluginManager->get('nowMessenger');
        /** @var HelperPluginManager $helperPluginManager */
        $helperPluginManager = $serviceLocator->get('ViewHelperManager');
        $escapeHtml          = $helperPluginManager->get(EscapeHtml::class);
        return new NowMessenger($nowMessengerControllerPlugin, $escapeHtml);
    }
}
