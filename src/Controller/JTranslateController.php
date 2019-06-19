<?php
namespace JTranslate\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Mvc\Plugin\FlashMessenger\FlashMessenger;
use JTranslate\Model\TranslationsTable;
use JTranslate\Form\EditPhraseForm;

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
        return [
            'translations'  => $translations,
            'locales'       => $finalLocales,
            'showAll'       => $showAll,
        ];
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
                    $this->redirect ()->toUrl ( $this->url ()->fromRoute ( 'jtranslate' ) );
                } catch (\Exception $e) {
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
                }
            } else {
                $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
            }
        } else {
            $form->setData($phrase);
        }
        return [
            'phrase' => $phrase,
            'phraseId' => $id,
            'form' => $form,
            'locales' => $locales
        ];
    }
}
