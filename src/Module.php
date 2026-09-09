<?php

namespace JTranslate;

use JTranslate\I18n\Translator\TranslatorEventListener;
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
        //the ModuleManager's bootstrap event targets the application, whatever class
        //the host gives it; only its service manager is used here
        $app = $e->getTarget();
        $sm = $app->getServiceManager();

        $config = $sm->get('JTranslate\Config');
        //The translator's fallback locale. It was hardcoded to 'en_US', which is wrong for
        //any installation whose source language is not English; the default is what was
        //hardcoded, so nothing changes without being asked for.
        //
        //(The per-dispatch listener that set every view helper's text domain from the
        //controller's namespace lived here until 2026-09. Nothing dispatches a laminas
        //controller any more; a Twig host passes the domain to translate() itself.)
        $fallbackLocale = $config['key_locale'] ?? 'en_US';

//         try { //fail silently if we can't get a translator, or something else goes wrong, then log it.
            /** @var \Laminas\I18n\Translator\Translator $translator */
            $translator = $sm->get(\Laminas\I18n\Translator\TranslatorInterface::class);
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
