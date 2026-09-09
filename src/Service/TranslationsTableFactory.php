<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Cache\PhraseCache;
use JTranslate\Model\TranslationsTable;
use JTranslate\Service\Adapter\CallableActingUserProvider;
use JTranslate\Service\Adapter\CallableUserDirectory;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

use function getcwd;
use function is_string;

/**
 * Builds {@see TranslationsTable} from the container.
 *
 * This factory is the module's framework adapter, and that is deliberate: it is the
 * one place allowed to know about the ServiceManager, JUser and SionModel. The model
 * behind it knows about none of them, which is what makes it constructible from a
 * Symfony request, a console command or a test. The end-of-request `flush()` is the
 * host's to call — schoenstatt.link arms it from its translator delegator and runs it on
 * `kernel.terminate`.
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class TranslationsTableFactory implements FactoryInterface
{
    /**
     * Service ids resolved reflectively so that a deployment without SionModel or
     * JUser still boots. Both feed optional behaviour — stamping `modified_by` and
     * attributing a translation in the admin listing — and neither is worth a hard
     * dependency from a translation library.
     */
    private const ACTING_USER_SERVICE = 'SionModel\Service\ActingUserProviderInterface';

    private const USER_TABLE_SERVICE = 'JUser\Model\UserTable';

    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName = TranslationsTable::class,
        ?array $options = null
    ): TranslationsTable {
        /** @var Adapter $adapter */
        $adapter = $container->get(Adapter::class);
        /** @var array<string, mixed> $config */
        $config = $container->get('JTranslate\Config');

        $translationsTableName = is_string($config['translations_table_name'] ?? null)
            ? $config['translations_table_name']
            : 'trans_translations';
        $phrasesTableName = is_string($config['phrases_table_name'] ?? null)
            ? $config['phrases_table_name']
            : 'trans_phrases';
        $rootDirectory = is_string($config['root_directory'] ?? null)
            ? $config['root_directory']
            : (string) getcwd();

        $table = new TranslationsTable(
            new TableGateway($phrasesTableName, $adapter),
            new TableGateway($translationsTableName, $adapter),
            $container->get(PhraseCache::class),
            $config,
            $this->actingUserProvider($container),
            $this->userDirectory($container),
            $rootDirectory
        );


        return $table;
    }

    private function actingUserProvider(ContainerInterface $container): ?ActingUserProviderInterface
    {
        if (! $container->has(self::ACTING_USER_SERVICE)) {
            return null;
        }

        /** @var object $provider */
        $provider = $container->get(self::ACTING_USER_SERVICE);
        if ($provider instanceof ActingUserProviderInterface) {
            return $provider;
        }

        //SionModel's provider satisfies the same one-method contract without declaring
        //our interface, so it is adapted rather than required to know about us
        return new CallableActingUserProvider(
            /** @return int|null */
            static fn() => $provider->getActingUserId()
        );
    }

    private function userDirectory(ContainerInterface $container): ?UserDirectoryInterface
    {
        if (! $container->has(self::USER_TABLE_SERVICE)) {
            return null;
        }

        /** @var object $userTable */
        $userTable = $container->get(self::USER_TABLE_SERVICE);
        if ($userTable instanceof UserDirectoryInterface) {
            return $userTable;
        }

        return new CallableUserDirectory(
            /** @return array<int, array<string, mixed>> */
            static fn() => $userTable->getUsers()
        );
    }
}
