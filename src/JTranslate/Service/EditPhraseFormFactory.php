<?php
namespace JTranslate\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
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
     * {@inheritDoc}
     *
     * @return CreateTimelineEventForm
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        /** @var TranslationsTable $table **/
		$table = $serviceLocator->get ( 'JTranslate\Model\TranslationsTable' );
		$config = $serviceLocator->get ( 'JTranslate\Config' );

		$locales = $table->getLocales(true);
		$form = new EditPhraseForm($locales, $config['phrases_table_name'], 
		    $config['translations_table_name']);
		return $form;
    }
}
