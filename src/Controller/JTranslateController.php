<?php
namespace JTranslate\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\Mvc\Plugin\FlashMessenger\FlashMessenger;
use JTranslate\Model\TranslationsTable;
use JTranslate\Controller\Plugin\NowMessenger;
use JTranslate\Form\EditPhraseForm;
use JTranslate\Form\DeletePhraseForm;
use Zend\View\Model\ViewModel;

/**
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class JTranslateController extends AbstractActionController
{
    /**
     * @var TranslationsTable $translationsTable
     */
    protected $translationsTable;
    
    /**
     * @var EditPhraseForm $editPhraseForm
     */
    protected $editPhraseForm;
    
    public function __construct(TranslationsTable $translationsTable, EditPhraseForm $editPhraseForm)
    {
        $this->translationsTable = $translationsTable;
        $this->editPhraseForm = $editPhraseForm;
    }
    
    public function indexAction()
    {
        /** @var \JTranslate\Model\TranslationsTable $table */
        $table = $this->translationsTable;
        $showAll = $this->params()->fromQuery('showAll') == "true";
        $localesSelected = $this->params()->fromQuery('locale');
        $translations = $table->getTranslations();
        $locales = $table->getLocales();
        $finalLocales = [];
        if (is_string($localesSelected) && isset($locales[$localesSelected])) {
            $finalLocales[$localesSelected] = $locales[$localesSelected];
        } elseif (is_array($localesSelected)) {
            foreach ($localesSelected as $locale) {
                if (isset($locales[$locale])) {
                    $finalLocales[$locale] = $locales[$locale];
                }
            }
        }
        if (empty($finalLocales)) {
            $finalLocales = $locales;
        }
        return new ViewModel([
            'translations'  => $translations,
            'locales'       => $finalLocales,
            'showAll'       => $showAll,
        ]);
    }

    public function editAction() {
        /** @var TranslationsTable $table **/
        $table = $this->translationsTable;
        $id = ( int ) $this->params ()->fromRoute ( 'phrase_id' );
        if (! $id) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Phrase not found.' );
            return $this->redirect ()->toRoute ( 'jtranslate', array (
                'action' => 'index'
            ) );
        }
        $phrase = $table->getPhrase ( $id );
        if (! $phrase) {
            $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Phrase not found.' );
            return $this->redirect ()->toRoute ( 'jtranslate' );
        }
        $locales = $table->getLocales(true);
        $form = $this->editPhraseForm;
        
        $request = $this->getRequest ();
        if ($request->isPost ()) {
            $data = $request->getPost ()->toArray ();
            $form->setData($data);
            if ($data ['phraseId'] != $id) { // make sure the user is trying to update the right phrase
                $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
                return [
                    'phrase' => $phrase,
                    'phraseId' => $id,
                    'locales' => $locales,
                    'form' => $form,
                ];
            }
            if ($form->isValid()) {
                try {
                    $table->updatePhrase ( $id, $data );
                    //update the translation files
                    $table->writePhpTranslationArrays();
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )->addMessage ( 'Translations successfully updated.' );
                    return $this->redirect ()->toUrl ( $this->url ()->fromRoute ( 'jtranslate' ) );
                } catch (\Exception $e) {
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
                }
            } else {
                $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
            }
        } else {
            $form->setData($phrase);
        }
        $form->setAttribute('action', $this->getRequest()->getRequestUri());
        return new ViewModel([
            'phrase' => $phrase,
            'phraseId' => $id,
            'form' => $form,
            'locales' => $locales
        ]);
    }
    
    /**
     * If the form has been posted, confirm the CSRF. If all is well, delete the entity.
     * If the request is a GET, ask the user to confirm the deletion
     * @return \Zend\View\Model\ViewModel|\Zend\Stdlib\ResponseInterface
     */
    public function deleteAction()
    {
        $id = $this->params()->fromRoute('phrase_id');
        
        $request = $this->getRequest();
        
        /** @var TranslationsTable $table **/
        $table = $this->translationsTable;
        
        //make sure our entity exists
        if (!$table->existsPhrase($id)) {
            $this->getResponse()->setStatusCode(401);
            $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
            ->addMessage('The entity you\'re trying to delete doesn\'t exists.');
            return $this->redirectAfterDelete(false);
        }
        
        $form = new DeletePhraseForm();
        if ($request->isPost()) {
            $data = $request->getPost();
            $form->setData($data);
            if ($form->isValid()) {
                $table->deletePhrase($id);
                $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_SUCCESS)
                ->addMessage('Entity successfully deleted.');
                return $this->redirect ()->toUrl ( $this->url ()->fromRoute ( 'jtranslate' ) );
            } else {
                $this->nowMessenger()->setNamespace(NowMessenger::NAMESPACE_ERROR)->addMessage('Error in form submission, please review.');
                $this->getResponse()->setStatusCode(401); //exists, but either didn't match params or bad csrf
            }
        }
        
        //set the form action url
        $form->setAttribute('action', $this->getRequest()->getRequestUri());
        $entityObject = $table->getPhrase($id);
        
        $view = new ViewModel([
            'form' => $form,
            'entity' => 'translation-phrase',
            'entityId' => $id,
            'entityObject' => $entityObject,
        ]);
        return $view;
    }
}
