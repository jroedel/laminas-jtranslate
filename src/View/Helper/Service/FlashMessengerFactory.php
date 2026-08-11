<?php

namespace JTranslate\View\Helper\Service;

use JTranslate\View\Helper\FlashMessenger;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds JTranslate's FlashMessenger view helper in place of the laminas one.
 *
 * The parent module's factory reads `view_helper_config.flashmessenger` to preset
 * the open/close/separator formats. This application does not set that key — the
 * layout and `App\Twig\LaminasExtension::flashMessages()` both set the formats on
 * the helper imperatively, identically — so that whole branch is omitted rather
 * than reproduced. Add it here if the config key ever appears.
 */
class FlashMessengerFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     * @return FlashMessenger
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $helper = new FlashMessenger();
        $helper->setPluginFlashMessenger($container->get('ControllerPluginManager')->get('flashmessenger'));

        return $helper;
    }
}
