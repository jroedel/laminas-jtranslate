<?php
namespace JTranslation\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use JTranslation\Model\TranslationsTable;
use JTranslation\Form\EditPhraseForm;

/**
 * Factory responsible of priming the PatresTable service
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
		$table = $serviceLocator->get ( 'JTranslation\Model\TranslationsTable' );
		$config = $serviceLocator->get ( 'JTranslation\Config' );

		$locales = $table->getLocales(true);
		$form = new EditPhraseForm($locales, $config['phrases_table_name'], 
		    $config['translations_table_name']);
		return $form;
    }
}
