<?php

declare(strict_types=1);

namespace JTranslate;

use JTranslate\Controller\LazyControllerFactory;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\I18n\Translator;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\Serializer\Adapter\Json;

use function getcwd;

return [
    'jtranslate'         => [
        'phrases_table_name'      => 'trans_phrases',
        'translations_table_name' => 'trans_translations',
        'root_directory'          => getcwd(),
        'locales_to_translate'    => ['es_ES', 'de_DE', 'pt_BR'],
        'key_locale'              => 'en_US',
        'cache_options'           => [
            'adapter' => [
                'name'    => 'filesystem',
                'options' => [
                    'ttl'       => 3600 * 24,
                    'namespace' => 'JTranslate',
                ],
            ],
            'plugins' => [
                ['name' => 'serializer', 'options' => ['serializer' => Json::class]],
            ],
        ],
    ],
    'translator'         => [
        'translation_file_patterns' => [
            [
                'type'        => 'phpArray',
                'base_dir'    => __DIR__ . '/../language',
                'pattern'     => '%s.lang.php',
                'text_domain' => __NAMESPACE__,
            ],
        ],
    ],
    'router'             => [
        'routes' => [
            'jtranslate' => [
                'type'          => Literal::class,
                'options'       => [
                    'route'    => '/admin/translations',
                    'defaults' => ['controller' => Controller\JTranslateController::class, 'action' => 'index'],
                ],
                'may_terminate' => true,
                'child_routes'  => [
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
                    'phrase'      => [
                        'type'          => Segment::class,
                        'options'       => [
                            'route'       => '/:phrase_id',
                            'constraints' => [
                                'phrase_id' => '[0-9]{1,5}',
                            ],
                        ],
                        'may_terminate' => false,
                        'child_routes'  => [
                            'edit'   => [
                                'type'    => Literal::class,
                                'options' => [
                                    'route'    => '/edit',
                                    'defaults' => [
                                        'action' => 'edit',
                                    ],
                                ],
                            ],
                            'delete' => [
                                'type'    => Literal::class,
                                'options' => [
                                    'route'    => '/delete',
                                    'defaults' => [
                                        'action' => 'delete',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'controllers'        => ['invokables' => [], 'abstract_factories' => [LazyControllerFactory::class]],
    'controller_plugins' => ['invokables' => ['nowMessenger' => Controller\Plugin\NowMessenger::class]],
    'view_manager'       => [
        'template_map'        => include __DIR__ . '/template_map.config.php',
        'template_path_stack' => [
            'jtranslate' => __DIR__ . '/../view',
        ],
    ],
    'view_helpers'       => [
        'factories'  => [
            'flag'         => View\Helper\Service\FlagFactory::class,
            'countryName'  => View\Helper\Service\CountryNameFactory::class,
            'nowMessenger' => View\Helper\Service\NowMessengerFactory::class,
        ],
        'invokables' => [
            // cache options have to be compatible with Laminas\Cache\StorageFactory::factory
            'languageName' => View\Helper\LanguageName::class,
 // With a namespace we can indicate the same type of items
 // -> So we can simply use the db id as cache key
 //1 day
 //JTranslateController::class => JTranslateController::class,
        ],
    ],
    'service_manager'    => [
        'factories' => [
            'JTranslate\Cache'             => Service\CacheFactory::class,
            'JTranslate\Config'            => Service\ConfigServiceFactory::class,
            Model\TranslationsTable::class => Service\TranslationsTableFactory::class,
            Form\EditPhraseForm::class     => Service\EditPhraseFormFactory::class,
            Model\CountriesInfo::class     => Service\CountriesFactory::class,
        ],
        'aliases'   => [
            'jtranslate_db_adapter' => Adapter::class,
            'jtranslate_translator' => Translator::class,
        ],
    ],
];
