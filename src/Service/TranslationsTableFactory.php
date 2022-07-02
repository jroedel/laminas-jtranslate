<?php
namespace JTranslate\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
use JTranslate\Model\TranslationsTable;
use Laminas\Db\TableGateway\TableGateway;
use JUser\Model\UserTable;

/**
 * Factory responsible of building the {@see TranslationsTable} service
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class TranslationsTableFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        /** @var Adapter $adapter */
        $adapter = $container->get(Adapter::class);
        $cache = $container->get('JTranslate\Cache');
        $em = $container->get('Application')->getEventManager();
        $config = $container->get('JTranslate\Config');
        $translationsTableName = $config['translations_table_name'] ? $config['translations_table_name'] : 'trans_translations';
        $phrasesTableName = $config['phrases_table_name'] ? $config['phrases_table_name'] : 'trans_phrases';
        $translationsGateway = new TableGateway($translationsTableName, $adapter);
        $phrasesGateway = new TableGateway($phrasesTableName , $adapter);
        $rootDirectory = isset($config['root_directory']) ? $config['root_directory'] : getcwd();

        /** @var $userService \LmcUser\Service\User */
        $userService = $container->get('lmcuser_user_service');
        $user = $userService->getAuthService()->getIdentity();
        $userTable = $container->get(UserTable::class);
        $table = new TranslationsTable($phrasesGateway, $translationsGateway, $cache, $config, $user, $userTable, $rootDirectory, $em);
        return $table;
    }
}
