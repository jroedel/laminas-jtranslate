<?php
namespace JTranslation\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Mvc\Controller\Plugin\FlashMessenger;
use JTranslation\Model\TranslationsTable;

/**
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class JTranslationController extends AbstractActionController
{
    public function indexAction()
    {
        /** @var \JTranslation\Model\TranslationsTable $table */
        $table = $this->getServiceLocator()->get('JTranslation\Model\TranslationsTable');
        $showAll = $this->params()->fromQuery('showAll') == "true";
        $translations = $table->getTranslations();
        $locales = $table->getLocales();
        return new ViewModel(array(
            'translations'  => $translations,
            'locales'       => $locales,
            'showAll'       => $showAll,
        ));
    }

    public function editAction() {
        $sm = $this->getServiceLocator ();
        /** @var TranslationsTable $table **/
        $table = $sm->get ( 'JTranslation\Model\TranslationsTable' );
        $id = ( int ) $this->params ()->fromRoute ( 'phrase_id' );
        if (! $id) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Phrase not found.' );
            return $this->redirect ()->toRoute ( 'jtranslation', array (
                'action' => 'index'
            ) );
        }
        $phrase = $table->getPhrase ( $id );
        if (! $phrase) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Phrase not found.' );
            return $this->redirect ()->toRoute ( 'jtranslation' );
        }
        $locales = $table->getLocales(true);
        $form = $sm->get('JTranslation\Form\EditPhraseForm');
        
        $request = $this->getRequest ();
        if ($request->isPost ()) {
            $data = $request->getPost ()->toArray ();
            $form->setData($data);
            if ($data ['phraseId'] != $id) { // make sure the user is trying to update the right phrase
                $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
                return array (
                    'phrase' => $phrase,
                    'phraseId' => $id,
                    'locales' => $locales,
                    'form' => $form,
                );
            }
            if ($form->isValid()) {
                try {
                    $table->updatePhrase ( $id, $data );
                    //update the translation files
                    $table->writePhpTranslationArrays();
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )->addMessage ( 'Translations successfully updated.' );
                    $this->redirect ()->toUrl ( $this->url ()->fromRoute ( 'jtranslation' ) );
                } catch (\Exception $e) {
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
                }
            } else {
                $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
            }
        } else {
            $form->setData($phrase);
        }
        return array (
            'phrase' => $phrase,
            'phraseId' => $id,
            'form' => $form,
            'locales' => $locales
        );
    }
}
