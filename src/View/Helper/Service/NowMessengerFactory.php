<?php

declare(strict_types=1);

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
        $controllerPluginManager      = $container->get('ControllerPluginManager');
        $nowMessengerControllerPlugin = $controllerPluginManager->get('nowMessenger');
        /** @var HelperPluginManager $helperPluginManager */
        $helperPluginManager = $container->get('ViewHelperManager');
        $escapeHtml          = $helperPluginManager->get(EscapeHtml::class);
        return new NowMessenger($nowMessengerControllerPlugin, $escapeHtml);
    }
}
