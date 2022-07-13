<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Model\TranslationsTable;
use JUser\Model\UserTable;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\Factory\FactoryInterface;
use LmcUser\Service\User;
use Psr\Container\ContainerInterface;
use SionModel\Service\SionCacheService;

class TranslationsTableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        /** @var Adapter $adapter */
        $adapter               = $container->get(Adapter::class);
        $config                = $container->get('JTranslate\Config');
        $translationsTableName = $config['translations_table_name'] ?: 'trans_translations';
        $phrasesTableName      = $config['phrases_table_name'] ?: 'trans_phrases';
        $translationsGateway   = new TableGateway($translationsTableName, $adapter);
        $phrasesGateway        = new TableGateway($phrasesTableName, $adapter);

        /** @var User $userService */
        $userService      = $container->get('lmcuser_user_service');
        $user             = $userService->getAuthService()->getIdentity();
        $userId           = isset($user) ? (int) $user->id : null;
        $userTable        = $container->get(UserTable::class);
        $sionCacheService = $container->get(SionCacheService::class);
        $table            = new TranslationsTable(
            adapter: $adapter,
            phrasesGateway: $phrasesGateway,
            translationsGateway: $translationsGateway,
            config: $config,
            userTable: $userTable,
            sionCacheService: $sionCacheService,
            actingUserId: $userId
        );
        $em               = $container->get('Application')->getEventManager();
        $em->attach(MvcEvent::EVENT_FINISH, [$table, 'finishUp'], -1);
        return $table;
    }
}
