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
            //One query for the whole listing, not one per row — see
            //TranslationsTable::getHistoryCounts(). Phrases with no history are absent
            //from the map rather than zero, so the view's test is an isset().
            'historyCounts' => $table->getHistoryCounts(),
        ]);
    }

    public function editAction()
    {
        /** @var TranslationsTable $table **/
        $table = $this->translationsTable;
        $id = (int) $this->params()->fromRoute('phrase_id');
        if (! $id) {
            $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
                ->addMessage('Phrase not found.');
            return $this->redirect()->toRoute('jtranslate', [
                'action' => 'index'
            ]);
        }
        $phrase = $table->getPhrase($id);
        if (! $phrase) {
            $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
                ->addMessage('Phrase not found.');
            return $this->redirect()->toRoute('jtranslate');
        }
        $locales = $table->getLocales(true);
        //A translator deciding whether to replace a translation needs to see what
        //replacing it last time cost, and why. Read here rather than at each return
        //because every one of them re-renders the same form. A successful save
        //redirects to the listing, so there is no path where this is shown stale.
        $history = $table->getTranslationHistory($id);
        $form = $this->editPhraseForm;
        $request = $this->getRequest();
        if ($request->isPost()) {
            $data = $request->getPost()->toArray();
            $form->setData($data);
            if ($data ['phraseId'] != $id) {
        // make sure the user is trying to update the right phrase
                $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
                    ->addMessage('Error in form submission, please review.');
                return [
                    'phrase' => $phrase,
                    'phraseId' => $id,
                    'locales' => $locales,
                    'form' => $form,
                    'history' => $history,
                ];
            }
            if ($form->isValid()) {
                try {
                //getData(), not $data: the raw post has been through no
                    //filter at all, so passing it on discards the trimming and
                    //length checks isValid() just performed and writes exactly
                    //what the browser sent.
                    $table->updatePhrase($id, $form->getData());
                } catch (\Exception $e) {
                    $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
                        ->addMessage('Error in form submission, please review.');
                    $form->setAttribute('action', $this->getRequest()->getRequestUri());
                    return new ViewModel([
                        'phrase' => $phrase,
                        'phraseId' => $id,
                        'form' => $form,
                        'locales' => $locales,
                        'history' => $history,
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
                    $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_SUCCESS)
                        ->addMessage('Translations successfully updated.');
                } catch (\Exception $e) {
                    //The exception text is logged, not flashed. Flash messages are run
                    //through the translator when they render, so a message carrying
                    //$e->getMessage() would report itself as a *missing translation*
                    //and be written to the phrase table — a new, permanent phrase row
                    //for every distinct filesystem error, each one unique and none of
                    //them translatable. The message a translator sees has to be a
                    //fixed sentence for the same reason every other one here is.
                    error_log('JTranslate: could not compile translation files after an '
                        . 'edit: ' . $e->getMessage());
                    $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
                        ->addMessage('The translation was saved to the database, but the compiled translation '
                        . 'files could not be written, so the site will keep showing the old text '
                        . 'until that is fixed.');
                }
                return $this->redirect()->toUrl($this->url()->fromRoute('jtranslate'));
            } else {
                $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
                    ->addMessage('Error in form submission, please review.');
            }
        } else {
            $form->setData($phrase);
        }
        $form->setAttribute('action', $this->getRequest()->getRequestUri());
        return new ViewModel([
            'phrase' => $phrase,
            'phraseId' => $id,
            'form' => $form,
            'locales' => $locales,
            'history' => $history,
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
        if (! $table->existsPhrase($id)) {
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

                //Deleting only the database row is not enough, and this is the half
                //that was missing. The site does not read translations from the
                //database — it reads the compiled catalogs — so a phrase deleted here
                //went on being served indefinitely, until some unrelated edit happened
                //to rewrite the files. The GUI reported success while the phrase was
                //still visible on the site, which is the same shape of lie the edit
                //action used to tell about failed writes.
                //
                //Same split as editAction(), for the same reason: the delete has
                //already committed by the time this runs, so an export failure must
                //not be reported as a failed delete.
                try {
                    $table->writePhpTranslationArrays();
                    $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_SUCCESS)
                        ->addMessage('Entity successfully deleted.');
                } catch (\Exception $e) {
                    //logged, not flashed: flash messages go through the translator when
                    //they render, so a message carrying $e->getMessage() would record
                    //itself as a missing translation and add a permanent, untranslatable
                    //phrase row for every distinct filesystem error
                    error_log('JTranslate: could not compile translation files after a '
                        . 'deletion: ' . $e->getMessage());
                    $this->flashMessenger()->setNamespace(FlashMessenger::NAMESPACE_ERROR)
                        ->addMessage('The phrase was deleted from the database, but the compiled '
                        . 'translation files could not be rewritten, so the site will go on showing '
                        . 'it until that is fixed.');
                }
                return $this->redirect()->toUrl($this->url()->fromRoute('jtranslate'));
            } else {
                $this->nowMessenger()->setNamespace(NowMessenger::NAMESPACE_ERROR)
                    ->addMessage('Error in form submission, please review.');
                $this->getResponse()->setStatusCode(401);
    //exists, but either didn't match params or bad csrf
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
