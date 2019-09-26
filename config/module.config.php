<?php
namespace JTranslate;

use Zend\Router\Http\Segment;
use Zend\Router\Http\Literal;
use Zend\Db\Adapter\Adapter;
use Zend\Mvc\I18n\Translator;
use Zend\Serializer\Adapter\Json;

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
                    'ttl'       => 3600*24, //1 day
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
                'child_routes' => [
                    'clear-cache' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/clear-cache',
                            'defaults' => [
                                'controller' => Controller\JTranslateController::class,
                                'action'     => 'clearCache',
                            ],
                        ],
                     ],
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
            'flag'					=> View\Helper\Service\FlagFactory::class,
            'countryName'			=> View\Helper\Service\CountryNameFactory::class,
            'nowMessenger'	        => View\Helper\Service\NowMessengerFactory::class,
        ],
        'invokables' => [
            'languageName'          => View\Helper\LanguageName::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'JTranslate\Cache'          => Service\CacheFactory::class,
            'JTranslate\Config'         => Service\ConfigServiceFactory::class,
            Model\TranslationsTable::class    => Service\TranslationsTableFactory::class,
            Form\EditPhraseForm::class       => Service\EditPhraseFormFactory::class,
            Model\CountriesInfo::class        => Service\CountriesFactory::class,
        ],
        'aliases' => [
            'jtranslate_db_adapter' => Adapter::class,
            'jtranslate_translator' => Translator::class,
        ],
    ],
];

