<?php

declare(strict_types=1);

namespace JTranslate;

use JTranslate\I18n\Translator\TranslatorEventListener;
use JTranslate\Model\TranslationsTable;
use Laminas\EventManager\EventInterface;
use Laminas\ModuleManager\Feature\BootstrapListenerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Mvc\I18n\Translator;
use Locale;

use function array_key_exists;
use function file_exists;
use function getcwd;
use function glob;
use function str_replace;
use function strpos;
use function substr;

use const GLOB_ONLYDIR;

class Module implements BootstrapListenerInterface
{
    public function getConfig(): array
    {
        return include __DIR__ . '/../config/module.config.php';
    }

    public function onBootstrap(EventInterface $e)
    {
        /** @var ApplicationInterface $app */
        $app = $e->getTarget();
        $sm  = $app->getServiceManager();
        $em  = $app->getEventManager();
//auto-set text domain for all view scripts
        $viewRenderer = $sm->get('ViewRenderer');
        $em->getSharedManager()->attach(AbstractActionController::class, 'dispatch', function ($e) use ($viewRenderer) {
            $controller      = $e->getTarget();
            $controllerClass = $controller::class;
            $moduleNamespace = substr($controllerClass, 0, strpos($controllerClass, '\\'));
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
        //@todo make this configurable
        }, 100);
//         try { //fail silently if we can't get a translator, or something else goes wrong, then log it.
            /** @var Translator $translator */
            $translator = $sm->get('jtranslate_translator');
        $translator->enableEventManager();
        $translator->setLocale(Locale::getDefault());
        $translator->setFallbackLocale('en_US');
//@todo make this a configurable value

            //attach the translator listener
            $table = $sm->get(TranslationsTable::class);
        $listener  = new TranslatorEventListener($table, $table->getLocales());
        $listener->attach($translator->getEventManager());
//add patterns to the translator
            $manager   = $sm->get(ModuleManager::class);
        $loadedModules = $manager->getLoadedModules();
        $modules       = [];
        foreach (glob('module/*', GLOB_ONLYDIR) as $dir) {
            $dir = str_replace('module/', '', $dir);
            if (array_key_exists($dir, $loadedModules)) {
                $modules[$dir] = getcwd() . '/module/' . $dir . '/language';
            }
        }
            $table->setUserModules($modules);
        $pattern = '%s.lang.php';
        foreach ($modules as $module => $directory) {
            if (file_exists($directory)) {
                $translator->addTranslationFilePattern('phpArray', $directory, $pattern, $module);
            }
        }
            //add a pattern for folders in '/language' if it exists
            $directory = getcwd() . '/language';
        if (file_exists($directory)) {
            foreach (glob('language/*', GLOB_ONLYDIR) as $dir) {
                $dir = str_replace('language/', '', $dir);
                $translator->addTranslationFilePattern('phpArray', $directory . '/' . $dir, $pattern, $dir);
            }
        }
//         } catch (\Exception $exception) {
 // //             $service = $sm->get('ApplicationErrorHandling');
 // //             $service->logException($exception);
 //         }
    }
}
