<?php

declare(strict_types=1);

namespace JTranslate\Service;

use JTranslate\Form\EditPhraseForm;
use JTranslate\Model\TranslationsTable;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class EditPhraseFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        /** @var TranslationsTable $table **/
        $table   = $container->get(TranslationsTable::class);
        $config  = $container->get('JTranslate\Config');
        $locales = $table->getLocales(true);
        return new EditPhraseForm(
            $locales,
            $config['phrases_table_name'],
            $config['translations_table_name']
        );
    }
}
