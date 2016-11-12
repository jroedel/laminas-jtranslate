<?php
namespace JTranslate\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Db\Adapter\Adapter;
use JTranslate\Model\TranslationsTable;
use Zend\Db\TableGateway\TableGateway;

/**
 * Factory responsible of building the {@see TranslationsTable} service
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class TranslationsTableFactory implements FactoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @return TranslationsTable
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        /** @var Adapter $adapter */ 
        $adapter = $serviceLocator->get('Zend\Db\Adapter\Adapter');
        $cache = $serviceLocator->get('JTranslate\Cache');
        $config = $serviceLocator->get('JTranslate\Config');
        $translationsTableName = $config['translations_table_name'] ? $config['translations_table_name'] : 'trans_translations';
        $phrasesTableName = $config['phrases_table_name'] ? $config['phrases_table_name'] : 'trans_phrases';
        $translationsGateway = new TableGateway($translationsTableName, $adapter);
        $phrasesGateway = new TableGateway($phrasesTableName , $adapter);

        /** @var  User $userService **/
        $userService = $serviceLocator->get('zfcuser_user_service');
        $user = $userService->getAuthService()->getIdentity();
        $userTable = $serviceLocator->get('SamUser\Model\UserTable');
        $table = new TranslationsTable($phrasesGateway, $translationsGateway, $cache, $config, $user, $userTable);
        return $table;
    }
}
