# zf2-jtranslate
Zend Framework 2 module to provide a translation GUI. Uses Zend\Db. All unknown 

##Installation
1. Require jtranslation
```
./composer.phar require jroedel/jtranslation
```

2. Copy `config/jtranslate.config.php.dist` to your Application config folder.

3. Create the two tables with the sql in `config/database.sql.dist`.

4. Enable it in your `application.config.php` file:
```
<?php
return [
    'modules' => [
        // ...
        'ZfcUser',
    ],
    // ...
];
```