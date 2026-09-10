<?php

namespace JTranslate\Form;

use SionModel\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;
use SionModel\Form\CsrfSpec;

class DeletePhraseForm extends Form implements InputFilterProviderInterface
{
    public function __construct()
    {
        parent::__construct('entity_delete');

        $this->add([
            'name' => 'security',
            'type' => 'csrf',
            'options' => [
                'csrf_options' => [
                     'timeout' => 900,
                ],
            ],
        ]);
        $this->add([
            'name' => 'submit',
            'type' => 'Submit',
            'attributes' => [
                'value' => 'Delete',
                'id' => 'submit',
                'class' => 'btn-danger'
            ],
        ]);
        //`Button`, for the reason SionModel\Form\DeleteEntityForm records at length: a
        //`name` with no `type` is a plain Laminas\Form\Element, and FormButton renders an
        //element with no `type` attribute as `type="submit"`. So Cancel submitted the
        //delete form, and JTranslateController::deleteAction() validates the CSRF token
        //without looking at which button was pressed — clicking Cancel deleted the phrase.
        //
        //Worse here than there, because a phrase is not one record: deletePhrase() destroys
        //every translation of it in every language, and the export that follows rewrites the
        //catalogs, so the site stops serving those strings. See docs/translation.md on why a
        //phrase is retired rather than deleted in the first place.
        //
        //Laminas\Form\Element\Button carries `type => button` in its own $attributes, so
        //naming the type is the whole fix on the browser's side; deleteAction() carries the
        //server-side half for a hand-crafted POST.
        $this->add([
            'name' => 'cancel',
            'type' => 'Button',
            'attributes' => [
                'value' => 'Cancel',
                //The duplicated `id="submit"` is left as it was: invalid HTML, not what
                //deleted anything, and not for a data-loss fix to change.
                'id' => 'submit',
                'data-dismiss' => 'modal'
            ],
        ]);
    }

    /**
     * Only the CSRF token: `submit` is a button rather than data, and this form carries
     * no fields of its own.
     *
     * `security` is stated here even though `Laminas\Form\Element\Csrf` supplies the
     * validator itself. That was the reasoning this docblock used to give for returning
     * an empty array, and it stops being safe at step 5:
     * `SionModel\Form\Validation\InputFilter` reads the specification and nothing else,
     * so a check that exists only on the element is a check the cutover removes. See
     * SionModel\Form\CsrfSpec.
     *
     * @return array<string, mixed>
     */
    public function getInputFilterSpecification()
    {
        return [
            'security' => CsrfSpec::forElement($this->get('security')),
        ];
    }
}
