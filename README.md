# zf2-jtranslate
Zend Framework 2 module to provide a translation GUI. Uses Zend\Db. All unknown non-translated phrases are added to the database. This can be used to translate both static and dynamic (ex. Database values) strings. This module can be customized to translate strings into any number of languages (defaults to English, Spanish, German and Portuguese).

This repository is used in a production website, but is far from perfect. Any help to make it more useful for the world is more than welcome!

##Installation

1. Require JTranslate
    ```
    ./composer.phar require jroedel/jtranslate
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

5. The GUI can be accessed from `/translations`. Make sure to only allow administers to access the `jtranslation` and child routes. [bjyoungblood/BjyAuthorize](https://github.com/bjyoungblood/BjyAuthorize) is a great module for route-based access control.

## How it works

1. Untranslated phrases are collected in the TranslationsTable by listening to the `EVENT_MISSING_TRANSLATION` event of the Translator.

2. At the `MvcEvent::EVENT_FINISH` event, we add any new phrases to the database.

3. When a user edits a phrase from the `/translations` page, all translations from that module are written to phpArrays in the `/language` folder of the corresponding module. NOTE: Never manually edit a translations php file, as it would be overwritten from the database. If manual changes must be made, make them from the database.
