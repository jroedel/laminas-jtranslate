<?php
namespace JTranslate;
use JTranslate\Controller\JTranslateController;
use Zend\Router\Http\Segment;
use Zend\Router\Http\Literal;
use JTranslate\View\Helper\Service\FlagFactory;
use JTranslate\View\Helper\Service\CountryNameFactory;
use JTranslate\View\Helper\Service\NowMessengerFactory;
use JTranslate\Service\CacheFactory;
use JTranslate\Service\ConfigServiceFactory;
use JTranslate\Service\TranslationsTableFactory;
use JTranslate\Model\TranslationsTable;
use JTranslate\Form\EditPhraseForm;
use JTranslate\Service\EditPhraseFormFactory;
use JTranslate\Service\CountriesFactory;
use JTranslate\Model\CountriesInfo;
use Zend\Db\Adapter\Adapter;

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
        
        // cache options have to be compatible with Zend\Cache\StorageFactory::factory
        'cache_options' => [
            'adapter' => [
                'name'    => 'filesystem',
                // With a namespace we can indicate the same type of items
                // -> So we can simple use the db id as cache key
                'options' => [
                    'namespace' => 'JTranslate'
                ],
            ],
            'plugins' => [
                // Don't throw exceptions on cache errors
                //'exception_handler' => [
                //    'throw_exceptions' => false
                //],
                'Serializer'
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
                        'controller' => JTranslateController::class,
                        'action'     => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'clear-cache' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/clear-cache',
                            'defaults' => [
                                'controller' => JTranslateController::class,
                                'action'     => 'clearCache',
                            ],
                        ],
                     ],
                    'phrase' => [
                        'type'    => Segment::class,
                        'options' => [
                            'route'    => '/:phrase_id/edit',
                            'constraints' => [
                                'course_id' => '[0-9]{1,5}',
                            ],
                            'defaults' => [
                                'controller' => JTranslateController::class,
                                'action'     => 'edit',
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
    'view_manager' => [
        'template_map' => include __DIR__ . '/template_map.config.php',
        'template_path_stack' => [
            'jtranslate' => __DIR__ . '/../view',
        ],
    ],

    'view_helpers' => [
        'factories' => [
            'flag'					=> FlagFactory::class,
            'countryName'			=> CountryNameFactory::class,
            'nowMessenger'	        => NowMessengerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'JTranslate\Cache'          => CacheFactory::class,
            'JTranslate\Config'         => ConfigServiceFactory::class,
            TranslationsTable::class    => TranslationsTableFactory::class,
            EditPhraseForm::class       => EditPhraseFormFactory::class,
            CountriesInfo::class        => CountriesFactory::class,
        ],
        'aliases' => [
            'jtranslate_db_adapter' => Adapter::class,
            'jtranslate_translator' => 'MvcTranslator',
        ],
    ],
];
