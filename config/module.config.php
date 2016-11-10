<?php
namespace JTranslation;
return [
    'jtranslation' => [
        'phrases_table_name' => 'trans_phrases',
        'translations_table_name' => 'trans_translations',
        'project_name' => 'application', //change this value for each project
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
                    'namespace' => 'JTranslation'
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
            [
                'type'     => 'phpArray',
                'base_dir' => __DIR__ . '/../../../language',
                'pattern'  => '%s.lang.php',
                'text_domain' => 'default',
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'jtranslation' => [
                'type'    => 'Literal',
                'options' => [
                    'route'    => '/admin/translations',
                    'defaults' => [
                        'controller' => 'JTranslation\Controller\JTranslation',
                        'action'     => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'clear-cache' => [
                        'type'    => 'Literal',
                        'options' => [
                            'route'    => '/clear-cache',
                            'defaults' => [
                                'controller' => 'JTranslation\Controller\JTranslation',
                                'action'     => 'clearCache',
                            ],
                        ],
                     ],
                    'phrase' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => '/:phrase_id/edit',
                            'constraints' => [
                                'course_id' => '[0-9]{1,5}',
                            ],
                            'defaults' => [
                                'controller' => 'JTranslation\Controller\JTranslation',
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
            'JTranslation\Controller\JTranslation' => 'JTranslation\Controller\JTranslationController',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            'jtranslation' => __DIR__ . '/../view',
        ],
    ],

    'service_manager' => [
        'factories' => [
            'JTranslation\Cache'                    => 'JTranslation\Service\CacheFactory',
            'JTranslation\Config'                   => 'JTranslation\Service\ConfigServiceFactory',
            'JTranslation\Model\TranslationsTable'  => 'JTranslation\Service\TranslationsTableFactory',
            'JTranslation\Form\EditPhraseForm'      => 'JTranslation\Service\EditPhraseFormFactory',
            'CountriesInfo'                         => 'JTranslation\Service\CountriesFactory',
        ],
        'aliases' => [
            'jtranslation_db_adapter' => 'Zend\Db\Adapter\Adapter',
        ],
    ],
];
