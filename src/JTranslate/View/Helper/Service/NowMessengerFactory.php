<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace JTranslate\View\Helper\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Helper\FlashMessenger;
use JTranslate\View\Helper\NowMessenger;

class NowMessengerFactory implements FactoryInterface
{
    /**
     * Create service
     *
     * @param  ServiceLocatorInterface $serviceLocator
     * @return FlashMessenger
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $serviceLocator = $serviceLocator->getServiceLocator();
        $helper = new NowMessenger();
        $controllerPluginManager = $serviceLocator->get('ControllerPluginManager');
        $flashMessenger = $controllerPluginManager->get('nowMessenger');
        $helper->setPluginNowMessenger($flashMessenger);
//         $config = $serviceLocator->get('Config');
//         if (isset($config['view_helper_config']['flashmessenger'])) {
//             $configHelper = $config['view_helper_config']['flashmessenger'];
//             if (isset($configHelper['message_open_format'])) {
//                 $helper->setMessageOpenFormat($configHelper['message_open_format']);
//             }
//             if (isset($configHelper['message_separator_string'])) {
//                 $helper->setMessageSeparatorString($configHelper['message_separator_string']);
//             }
//             if (isset($configHelper['message_close_string'])) {
//                 $helper->setMessageCloseString($configHelper['message_close_string']);
//             }
//         }

        return $helper;
    }
}
