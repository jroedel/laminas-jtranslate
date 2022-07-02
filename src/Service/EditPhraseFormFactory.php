<?php
namespace JTranslate\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;
use JTranslate\Model\TranslationsTable;
use JTranslate\Form\EditPhraseForm;

/**
 * Factory responsible of priming the EditPhraseForm
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class EditPhraseFormFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        /** @var TranslationsTable $table **/
		$table = $container->get ( TranslationsTable::class);
		$config = $container->get ( 'JTranslate\Config' );

		$locales = $table->getLocales(true);
		$form = new EditPhraseForm($locales, $config['phrases_table_name'], 
		    $config['translations_table_name']);
		return $form;
    }
}
