# zf3-jtranslate

Zend Framework 3 module to provide a translation GUI. Uses Laminas\Db. All unknown non-translated phrases are added to the database. This can be used to translate both static and dynamic (ex. Database values) strings. This module can be customized to translate strings into any number of languages (defaults to English, Spanish, German and Portuguese).

This repository is used in a production website, but is far from perfect. Any help to make it more useful for the world is more than welcome!

## Installation

1. Require JTranslate
    ```
    php composer.phar require jroedel/jtranslate
    ```
    
2. Copy `config/jtranslate.config.php.dist` to your Application config folder, and customize the values.

3. Create the two tables with the sql in `config/database.sql.dist`.

4. Enable it in your `application.config.php` file: 
    ```
    <?php
    return [
        'modules' => [
            // ...
            'JTranslate',
        ],
        // ...
    ];
    ```

5. The GUI can be accessed from `admin/translations`. Make sure to only allow administers to access the `jtranslate` and child routes. [bjyoungblood/BjyAuthorize](https://github.com/bjyoungblood/BjyAuthorize) is a great module for route-based access control.

## How it works

1. At the beginning of every php instance, a pattern is added to the `MvcTranslator` including phpArray files in the `/language` folder of every loaded module in the `/module` folder.

2. At dispatch the Text Domain of the `MvcTranslator` is set to the root namespace of the Controller that is recieving the request. This serves to store translations together with the corresponding module.

3. Untranslated phrases are collected in the TranslationsTable by listening to the `EVENT_MISSING_TRANSLATION` event of the Translator. 

4. At the `MvcEvent::EVENT_FINISH` event, we add any new phrases to the database.

5. When a user edits a phrase from the `admin/translations` page, all translations from that module are written to phpArrays in the `/language` folder of the corresponding module. If the `/language` folder of a particular module doesn't exist, it will be created at this time. Phrases that belong to a Text Domain that is not the name of one of the loaded modules will be saved in a subfolder of the `/language` folder in the root.

**NOTE:** Never manually edit a translations php file, as it would be overwritten from the database. If manual changes must be made, make them from the database.
