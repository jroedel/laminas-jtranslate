<?php
namespace JTranslation;

use Zend\Mvc\MvcEvent;
use JTranslation\I18n\Translator\TranslatorEventListener;
use JTranslation\Controller\Plugin\NowMessenger;

class Module
{
    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\ClassMapAutoloader' => array(
                __DIR__ . '/autoload_classmap.php',
            ),
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
    
    public function getControllerPluginConfig()
    {
        return array('factories' => array(
            'nowMessenger' => function ($sm) {
                $controllerPlugin = new NowMessenger();
                return $controllerPlugin;
            },
        ));
    }
    
    public function onBootstrap(MvcEvent $e)
    {
        $sm = $e->getApplication()->getServiceManager();
        $em = $e->getApplication()->getEventManager();
        //auto-set text domain for all view scripts
        $viewRenderer = $sm->get('ViewRenderer');
        $em->getSharedManager()
        ->attach('Zend\Mvc\Controller\AbstractActionController', 'dispatch', function($e) use ($viewRenderer) {
            $controller = $e->getTarget();
            $controllerClass = get_class($controller);
            $moduleNamespace = substr($controllerClass, 0, strpos($controllerClass, '\\'));
            if ($moduleNamespace == "Patres\Controller") {
                var_dump($moduleNamespace);
            }
            $viewRenderer->plugin('translate')->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formLabel()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formText()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formElementErrors()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formInput()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formButton()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formSelect()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formCheckbox()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formRow()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->headTitle()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->flashMessenger()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->navigation()->setTranslatorTextDomain('Application');
        }, 100);
        
        try { //fail silently if we can't get a translator, or something else goes wrong, then log it.
            $translator = $sm->get('translator');
            $translator->enableEventManager();
    //         if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']))
    //             $translator->setLocale(\Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']));
            
            $translator->setLocale(\Locale::getDefault());
            $translator->setFallbackLocale('en_US');
            
            $em->attach( MvcEvent::EVENT_FINISH,
                function ($e) {
                    /** @var \JTranslation\Model\TranslationsTable $table **/
                    $table = $e->getApplication()->getServiceManager()->get('JTranslation\Model\TranslationsTable');
                    $result = $table->writeMissingPhrasesToDb();
                }, 
                -1
            );
            
            //collect the file patterns so we know where to write the arrays to
            $em->attach(MvcEvent::EVENT_ROUTE,
                function ($e) {
                    $sm = $e->getApplication()->getServiceManager();
                    $config = $sm->get('Config')['translator'];
                    $textDomainMap = array();
                    foreach ($config['translation_file_patterns'] as $pattern) {
                        if ($pattern['type'] == 'phpArray') {
                            $textDomainMap[isset($pattern['text_domain']) ? $pattern['text_domain'] : 'default'] = array(
                                'base_dir' => $pattern['base_dir'],
                                'pattern'  => $pattern['pattern'],
                            );
                        }
                    }
                    $sm->get('JTranslation\Model\TranslationsTable')->setArrayFilePatterns($textDomainMap);
                },
                -1
            );
            
            $table = $sm->get('JTranslation\Model\TranslationsTable');
            $listener = new TranslatorEventListener($table, $table->getLocales());
            $listener->attach($translator->getEventManager());
        } catch (\Exception $exception) {
            $service = $sm->get('ApplicationErrorHandling');
            $service->logException($exception);
        }   
    }
}
