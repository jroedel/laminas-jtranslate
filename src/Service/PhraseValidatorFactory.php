<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Form\PhraseValidator;
use JTranslate\Model\TranslationsTable;
use Laminas\Db\Adapter\Adapter;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

use function array_keys;
use function is_array;
use function is_string;

/**
 * Builds {@see PhraseValidator} from the same three inputs
 * {@see EditPhraseFormFactory} uses, so the headless filter and the rendered form
 * cannot be configured differently.
 *
 * The locales come from `TranslationsTable::getLocales(true)` rather than from the
 * config directly: that method applies the key-locale rule and drops any code
 * `getLocaleNames()` does not know, and a caller reading `locales_to_translate` itself
 * would silently disagree with what `updatePhrase()` actually iterates.
 *
 * `Adapter::class` and not `AdapterInterface::class`: applications register the
 * concrete class and nothing here aliases the interface.
 */
class PhraseValidatorFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName = PhraseValidator::class,
        ?array $options = null
    ): PhraseValidator {
        /** @var TranslationsTable $table */
        $table = $container->get(TranslationsTable::class);
        /** @var array<string, mixed>|\Traversable<string, mixed> $config */
        $config = $container->get('JTranslate\Config');
        /** @var Adapter $adapter */
        $adapter = $container->get(Adapter::class);

        $settings = is_array($config) ? $config : iterator_to_array($config);

        return new PhraseValidator(
            array_keys($table->getLocales(true)),
            is_string($settings['phrases_table_name'] ?? null)
                ? $settings['phrases_table_name']
                : 'trans_phrases',
            is_string($settings['translations_table_name'] ?? null)
                ? $settings['translations_table_name']
                : 'trans_translations',
            $adapter
        );
    }
}
