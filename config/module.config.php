<?php

namespace JTranslate;

use Laminas\Router\Http\Segment;
use Laminas\Router\Http\Literal;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\I18n\Translator;
use Laminas\Serializer\Adapter\Json;

return [
    'jtranslate' => [
        'phrases_table_name' => 'trans_phrases',
        'translations_table_name' => 'trans_translations',
        'root_directory' => getcwd(),
        'locales_to_translate' => [
            'es_ES',
            'de_DE',
            'pt_BR'
        ],
        'key_locale' => 'en_US',

        //The compiled catalog filename, '%s' being the locale. Must agree with the
        //`translator.translation_file_patterns` pattern below — this key controls the
        //writing, that one controls the reading.
        'catalog_file_pattern' => '%s.lang.php',

        //The text domain the navigation helper renders in. Unlike every other view
        //helper, the menu is not translated in the dispatched controller's namespace: it
        //is one tree shown on every page, so its strings belong to whichever domain owns
        //the menu. 'Application' is the host application's conventional name for it.
        'navigation_text_domain' => 'Application',

        // cache options have to be compatible with Laminas\Cache\StorageFactory::factory
        'cache_options' => [
            'adapter' => [
                'name'    => 'filesystem',
                // With a namespace we can indicate the same type of items
                // -> So we can simple use the db id as cache key
                'options' => [
                    'ttl'       => 3600 * 24, //1 day
                    'namespace' => 'JTranslate'
                ],
            ],
            'plugins' => [
                [
                    'name' => 'serializer',
                    'options' => [
                        'serializer' => Json::class,
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type'     => 'phpArray',
                'base_dir' => __DIR__ . '/../language',
                'pattern'  => '%s.lang.php',
                'text_domain' => __NAMESPACE__,
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'jtranslate' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/admin/translations',
                    'defaults' => [
                        'controller' => Controller\JTranslateController::class,
                        'action'     => 'index',
                    ],
                ],
                'may_terminate' => true,
                //There is no 'clear-cache' child route. It used to declare
                //`'action' => 'clearCache'`, and JTranslateController has never had a
                //clearCacheAction() — only index, edit and delete — so reaching
                ///admin/translations/clear-cache was a guaranteed dispatch failure. It
                //also carried no bjyauthorize guard, which is the only reason it never
                //surfaced: it was unreachable in practice and a fatal in principle.
                //Whatever it was meant to do is served by SionModel's
                ///sm/clear-persistent-cache and by `bin/console cache:flush-persistent`.
                'child_routes' => [
                    'phrase' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/:phrase_id',
                            'constraints' => [
                                'phrase_id' => '[0-9]{1,5}',
                            ],
                        ],
                        'may_terminate' => false,
                        'child_routes' => [
                            'edit' => [
                                'type'    => Literal::class,
                                'options' => [
                                    'route'    => '/edit',
                                    'defaults' => [
                                        'action'     => 'edit',
                                    ],
                                ],
                            ],
                            'delete' => [
                                'type'    => Literal::class,
                                'options' => [
                                    'route'    => '/delete',
                                    'defaults' => [
                                        'action'     => 'delete',
                                    ],
                                ],
                            ],
                        ],
                     ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'invokables' => [
            //JTranslateController::class => JTranslateController::class,
        ],
        'abstract_factories' => [
            \JTranslate\Controller\LazyControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'nowMessenger' => Controller\Plugin\NowMessenger::class,
        ],
    ],
    'view_manager' => [
        'template_map' => include __DIR__ . '/template_map.config.php',
        'template_path_stack' => [
            'jtranslate' => __DIR__ . '/../view',
        ],
    ],

    'view_helpers' => [
        'factories' => [
            'flag'                  => View\Helper\Service\FlagFactory::class,
            'countryName'           => View\Helper\Service\CountryNameFactory::class,
            'nowMessenger'          => View\Helper\Service\NowMessengerFactory::class,
        ],
        'invokables' => [
            'languageName'          => View\Helper\LanguageName::class,
        ],
    ],
    /**
     * Registered lazily by service id, so declaring them costs nothing until one is
     * the command being run. See src/Console/Command for what each is for; the short
     * version is that jtranslate:migrate brings the schema and the GUI's own phrases
     * up to date, and jtranslate:export-catalogs renders them to disk — which is what
     * makes the compiled catalogs a derived artifact rather than something checked in.
     */
    'console' => [
        'commands' => [
            'jtranslate:migrate'          => Console\Command\MigrateCommand::class,
            'jtranslate:export-catalogs'  => Console\Command\ExportCatalogsCommand::class,
            'jtranslate:retire'           => Console\Command\RetirePhrasesCommand::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Cache\PhraseCache::class    => Service\PhraseCacheFactory::class,
            'JTranslate\Config'         => Service\ConfigServiceFactory::class,
            Model\TranslationsTable::class    => Service\TranslationsTableFactory::class,
            Form\EditPhraseForm::class       => Service\EditPhraseFormFactory::class,
            //The edit form's rules with no browser behind them, for a caller that
            //validates a translation outside the GUI. Registered as a service rather
            //than left to callers because building it by hand means reproducing three
            //internals of this module — see Form\PhraseValidator.
            Form\PhraseValidator::class      => Service\PhraseValidatorFactory::class,
            Model\CountriesInfo::class        => Service\CountriesFactory::class,
            Migration\MigrationRunner::class => Service\MigrationRunnerFactory::class,
            Console\Command\MigrateCommand::class
                => Service\MigrateCommandFactory::class,
            Console\Command\ExportCatalogsCommand::class
                => Service\ExportCatalogsCommandFactory::class,
            Console\Command\RetirePhrasesCommand::class
                => Service\RetirePhrasesCommandFactory::class,
        ],
        'aliases' => [
            'jtranslate_db_adapter' => Adapter::class,
            'jtranslate_translator' => Translator::class,
        ],
    ],
];
