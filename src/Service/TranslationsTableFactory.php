<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Model\TranslationsTable;
use JUser\Model\UserTable;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\ServiceManager\Factory\FactoryInterface;
use LmcUser\Service\User;
use Psr\Container\ContainerInterface;

use function getcwd;

class TranslationsTableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        /** @var Adapter $adapter */
        $adapter               = $container->get(Adapter::class);
        $cache                 = $container->get('JTranslate\Cache');
        $em                    = $container->get('Application')->getEventManager();
        $config                = $container->get('JTranslate\Config');
        $translationsTableName = $config['translations_table_name'] ?: 'trans_translations';
        $phrasesTableName      = $config['phrases_table_name'] ?: 'trans_phrases';
        $translationsGateway   = new TableGateway($translationsTableName, $adapter);
        $phrasesGateway        = new TableGateway($phrasesTableName, $adapter);
        $rootDirectory         = $config['root_directory'] ?? getcwd();

        /** @var User $userService */
        $userService = $container->get('lmcuser_user_service');
        $user        = $userService->getAuthService()->getIdentity();
        $userId      = isset($user) ? (int) $user->id : null;
        $userTable   = $container->get(UserTable::class);
        return new TranslationsTable(
            adapter: $adapter,
            phrasesGateway: $phrasesGateway,
            translationsGateway: $translationsGateway,
            cache: $cache,
            config: $config,
            userTable: $userTable,
            rootDirectory: $rootDirectory,
            eventManager: $em,
            actingUserId: $userId
        );
    }
}
