<?php

namespace JTranslate;

use JTranslate\I18n\Translator\TranslatorEventListener;
use Laminas\Mvc\Controller\AbstractActionController;
use JTranslate\Model\TranslationsTable;
use Laminas\ModuleManager\ModuleManager;
use Laminas\EventManager\EventInterface;
use Laminas\ModuleManager\Feature\BootstrapListenerInterface;
use Laminas\Validator\AbstractValidator;

class Module implements BootstrapListenerInterface
{
    public function getConfig()
    {
        return include __DIR__ . '/../config/module.config.php';
    }

    public function onBootstrap(EventInterface $e)
    {
        /** @var $app \Laminas\Mvc\ApplicationInterface */
        $app = $e->getTarget();
        $sm = $app->getServiceManager();
        $em = $app->getEventManager();

        $config = $sm->get('JTranslate\Config');
        //The text domain the navigation helper renders in, and the translator's fallback
        //locale. Both were hardcoded — 'Application' and 'en_US' — which is wrong for any
        //installation whose menu strings live elsewhere or whose source language is not
        //English. They default to what was hardcoded, so nothing changes without being
        //asked for.
        //
        //The navigation domain is *not* the dispatched module's namespace like every
        //other helper below: the menu is one tree rendered on every page, so its strings
        //belong to whichever domain owns the menu rather than to whatever controller
        //happens to be answering.
        $navigationTextDomain = $config['navigation_text_domain'] ?? 'Application';
        $fallbackLocale       = $config['key_locale'] ?? 'en_US';

//auto-set text domain for all view scripts
        $viewRenderer = $sm->get('ViewRenderer');
        $em->getSharedManager()
        ->attach(AbstractActionController::class, 'dispatch', function ($e) use (
            $viewRenderer,
            $navigationTextDomain
        ) {

            $controller = $e->getTarget();
            $controllerClass = get_class($controller);
            $moduleNamespace = substr($controllerClass, 0, strpos($controllerClass, '\\'));
            $viewRenderer->plugin('translate')->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formLabel()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formText()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formElementErrors()->setTranslatorTextDomain($moduleNamespace);
            //A validation message arrives here already interpolated — laminas
            //substitutes %value%, %hostname% and friends inside the validator — so
            //translating it now registers the *user's input* as a phrase. Six
            //strangers' mistyped email hostnames reached the table that way. The
            //templates are translated instead, by the default validator translator
            //set below, which happens before interpolation.
            $viewRenderer->formElementErrors()->setTranslateMessages(false);
            $viewRenderer->formInput()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formButton()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formSelect()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formCheckbox()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->formRow()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->headTitle()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->flashMessenger()->setTranslatorTextDomain($moduleNamespace);
            $viewRenderer->navigation()->setTranslatorTextDomain($navigationTextDomain);
        }, 100);
//         try { //fail silently if we can't get a translator, or something else goes wrong, then log it.
            /** @var \Laminas\Mvc\I18n\Translator $translator */
            $translator = $sm->get('jtranslate_translator');
        $translator->enableEventManager();
        $translator->setLocale(\Locale::getDefault());
        //The key locale is the language the phrases themselves are written in, so it is
        //by definition the only sensible fallback: a lookup that misses every catalog
        //falls back to the text the phrase table is keyed by.
        $translator->setFallbackLocale($fallbackLocale);

        //attach the translator listener.
        //
        //getLocales(TRUE) — the argument includes the key locale, without which an
        //English page view can never discover a phrase. See the class docblock on
        //TranslatorEventListener; App\Laminas\TranslatorConfigurator has to match.
        $table    = $sm->get(TranslationsTable::class);
        $listener = new TranslatorEventListener($table, $table->getLocales(true));
        $listener->attach($translator->getEventManager());

        //Validator messages are translated as *templates*, before laminas fills in
        //%value%/%hostname%/%min%. Without this the only translation happened at
        //render time, on the finished string, so every distinct bad input became its
        //own permanent phrase — unbounded, untranslatable, and in the email case a
        //record of what a real person typed into a registration form. The renderers
        //that used to do that translation are switched off in step with this: the
        //formElementErrors line above, and SionModel\Form\BootstrapFormRenderer on the
        //Symfony side. Enabling one without the other translates twice.
        //
        //'default' is laminas' own default text domain and the right home: these
        //strings come from the framework, not from a module, and are identical
        //wherever they appear. App\Laminas\TranslatorConfigurator has to match.
        AbstractValidator::setDefaultTranslator($translator, 'default');
//add patterns to the translator
            $manager        = $sm->get(ModuleManager::class);
        $loadedModules  = $manager->getLoadedModules();
        $modules        = [];
        foreach (glob('module/*', GLOB_ONLYDIR) as $dir) {
            $dir = str_replace('module/', '', $dir);
            if (key_exists($dir, $loadedModules)) {
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
