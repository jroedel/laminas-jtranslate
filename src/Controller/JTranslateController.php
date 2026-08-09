<?php
namespace JTranslate\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Mvc\Plugin\FlashMessenger\FlashMessenger;
use JTranslate\Model\TranslationsTable;
use JTranslate\Controller\Plugin\NowMessenger;
use JTranslate\Form\EditPhraseForm;
use JTranslate\Form\DeletePhraseForm;
use Laminas\View\Model\ViewModel;

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
                    //getData(), not $data: the raw post has been through no
                    //filter at all, so passing it on discards the trimming and
                    //length checks isValid() just performed and writes exactly
                    //what the browser sent.
                    $table->updatePhrase ( $id, $form->getData() );
                } catch (\Exception $e) {
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage ( 'Error in form submission, please review.' );
                    $form->setAttribute('action', $this->getRequest()->getRequestUri());
                    return new ViewModel([
                        'phrase' => $phrase,
                        'phraseId' => $id,
                        'form' => $form,
                        'locales' => $locales,
                    ]);
                }

                //A separate try, because by this point the database write has
                //already committed. Compiling the php arrays is what makes the
                //new translation visible to the site, and when it fails the
                //translator has to be told precisely that — the save worked, the
                //site will keep showing the old text. Reporting it as 'Error in
                //form submission, please review' would send them to re-edit a
                //phrase that is already correct in the database; reporting it as
                //success, which is what this action did until now because every
                //write return value was discarded, told them the opposite of the
                //truth. Both were wrong in the same direction.
                try {
                    $table->writePhpTranslationArrays();
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_SUCCESS )->addMessage ( 'Translations successfully updated.' );
                } catch (\Exception $e) {
                    $this->flashMessenger ()->setNamespace ( FlashMessenger::NAMESPACE_ERROR )->addMessage (
                        'The translation was saved to the database, but the compiled translation '
                        . 'files could not be written, so the site will keep showing the old text '
                        . 'until that is fixed. ' . $e->getMessage()
                    );
                }
                return $this->redirect ()->toUrl ( $this->url ()->fromRoute ( 'jtranslate' ) );
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
     * @return \Laminas\View\Model\ViewModel|\Laminas\Stdlib\ResponseInterface
     */
    public function deleteAction()
    {
        $id = $this->params()->fromRoute('phrase_id');
        
        $request = $this->getRequest();
        
        /** @var TranslationsTable $table **/
        $table = $this->translationsTable;
        
        //make sure our entity exists
        if (!$table->existsPhrase($id)) {
            $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
            ->addMessage('The entity you\'re trying to delete doesn\'t exists.');
            //This branch used to call $this->redirectAfterDelete(false), which
            //is not a method of this controller or of any of its parents, so
            //asking to delete a phrase that no longer exists — a stale delete
            //link, or a double submit — was an uncaught Error rather than the
            //message above. The status code that was set here went with it: a
            //redirect response replaces it, so it never reached the client
            //even on the happy path this was modelled on.
            return $this->redirect()->toRoute('jtranslate');
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
