# zf2-jtranslate
Zend Framework 2 module to provide a translation GUI. Uses Zend\Db. All unknown non-translated phrases are added to the database. This can be used to translate both static and dynamic (ex. Database values) strings.

This repository is used in a production website, but is far from perfect. Any help to make it more useful for the world is more than welcome!

##Installation
1. Require jtranslation
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
        'JTranslation',
    ],
    // ...
];
```

5. The GUI can be accessed from `/translations`.