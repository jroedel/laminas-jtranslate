<?php

namespace JTranslate\Form;

use Laminas\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;
use Laminas\Validator\Db\RecordExists;

class EditPhraseForm extends Form implements InputFilterProviderInterface
{
    /**
     * Matches trans_translations.translation, varchar(2000) NOT NULL. MariaDB
     * counts varchar length in characters and so does StringLength, so the two
     * bounds are the same number and not an approximation.
     */
    public const TRANSLATION_MAX_LENGTH = 2000;
/**
     * Matches trans_phrases.phrase, varchar(2000) NOT NULL.
     */
    public const PHRASE_MAX_LENGTH = 2000;
/**
     *
     * @var array
     */
    protected $locales;
/**
     *
     * @var string
     */
    protected $phrasesTableName;
/**
     *
     * @var string
     */
    protected $translationsTableName;
/**
     *
     * @var array
     */
    protected $inputFilterSpecification;
    public function __construct($locales, $phrasesTableName, $translationsTableName)
    {
        // we want to ignore the name passed
        parent::__construct('edit_phrase');
        $this->locales = $locales;
        $this->phrasesTableName = $phrasesTableName;
        $this->translationsTableName = $translationsTableName;

        //Filters and validators belong in getInputFilterSpecification() below,
        //not here: Laminas\Form\Factory::configureElement() reads only name,
        //options and attributes from an element definition, so a 'filters' key
        //written at this level is silently discarded. Every element in this form
        //used to declare one that way and none of them ever ran.
        $this->add([
            'name' => 'phraseId',
            'type' => 'Hidden',
        ]);
        $this->add([
            'name' => 'phrase',
            'type' => 'Textarea',
            'options' => [
                'label' => 'Phrase',
            ],
            'attributes' => [
                'rows' => 2,
                'readonly' => true,
            ],
        ]);

        foreach ($locales as $key => $value) {
            $this->add([
                'name' => $key,
                'type' => 'Textarea',
                'options' => [
                    'label' => $value,
                ],
                'attributes' => [
                    'rows' => 2,
                ],
            ]);
            $this->add([
                'name' => $key . 'Id',
                'type' => 'Hidden',
            ]);
        }

        $this->add([
            'name' => 'security',
            'type' => 'csrf',
        ]);
        $this->add([
            'name' => 'submit',
            'type' => 'Submit',
            'attributes' => [
                'value' => 'Submit',
                'id' => 'submit',
                'class' => 'btn-primary'
            ],
        ]);
    }

    public function getLocales()
    {
        return $this->locales;
    }

    /**
     *
     * @param array $locales
     * @return self
     */
    public function setLocales($locales)
    {
        $this->locales = $locales;
        return $this;
    }

    /**
     * Every element of this form is specified here, on purpose.
     *
     * Laminas\Form\Form::attachInputFilterDefaults() builds an input per element
     * first and then lets this specification overwrite by name, so a field named
     * here gets *only* what this method says. The corollary is the reason each
     * locale field is listed: an element that is absent from this specification
     * and whose type does not implement InputProviderInterface — a plain
     * Textarea or Hidden, which is all of them below — is given
     * ['required' => false] and nothing else. No filter, no length bound, raw
     * input straight through. Only phraseId used to be specified here, so the
     * translation textareas accepted any string of any length and wrote it to
     * trans_translations.translation, a varchar(2000) NOT NULL, from where it is
     * rendered on every page of the site in that locale.
     *
     * @see \Laminas\InputFilter\InputFilterProviderInterface::getInputFilterSpecification()
     */
    public function getInputFilterSpecification()
    {
        if ($this->inputFilterSpecification) {
            return $this->inputFilterSpecification;
        }

        $specification = [
            'phraseId' => [
                'required' => true,
                'filters' => [
                    ['name' => 'Laminas\Filter\ToInt'],
                ],
                'validators' => [
                    ['name' => 'Laminas\Validator\Digits'],
                    [
                        'name'    => 'Laminas\Validator\Db\RecordExists',
                        'options' => [
                            'table' => $this->phrasesTableName,
                            'field' => 'translation_phrase_id',
                            'adapter' => \Laminas\Db\TableGateway\Feature\GlobalAdapterFeature::getStaticAdapter(),
                            'messages' => [
                                RecordExists::ERROR_NO_RECORD_FOUND => 'Phrase not found in database'
                            ],
                        ],
                    ],
                ],
            ],
            //Read-only in the browser, which is a hint and not a control: the
            //field is still posted and still has to be bounded. Nothing writes
            //it back, so it is only checked, never trusted.
            'phrase' => [
                'required' => false,
                'allow_empty' => true,
                'filters' => [
                    ['name' => 'Laminas\Filter\StringTrim'],
                ],
                'validators' => [
                    [
                        'name' => 'Laminas\Validator\StringLength',
                        'options' => ['max' => self::PHRASE_MAX_LENGTH],
                    ],
                ],
            ],
        ];
        foreach (array_keys($this->locales) as $key) {
            //An empty translation is how the editor says "leave this locale alone",
            //so empty is allowed and only the length is enforced.
            //
            //There is deliberately **no ToNull filter here, and there must never be
            //one.** TranslationsTable::updatePhrase() reads an explicit null as
            //"retract this translation" and deletes the row; `''` is what it reads as
            //"leave alone". Every locale is rendered as a textarea on every edit, so a
            //translator who fills in one language posts `''` for the other three — and
            //a filter that turned those into null would delete three translations per
            //save. StringTrim alone is what keeps the two meanings apart, which is why
            //whitespace-only input arrives here as `''` and not as null.
            $specification[$key] = [
                'required' => false,
                'allow_empty' => true,
                'filters' => [
                    ['name' => 'Laminas\Filter\StringTrim'],
                ],
                'validators' => [
                    [
                        'name' => 'Laminas\Validator\StringLength',
                        'options' => ['max' => self::TRANSLATION_MAX_LENGTH],
                    ],
                ],
            ];
        //Kept validated although updatePhrase() no longer reads them for
            //anything: they are posted, so leaving them unspecified would leave
            //an unbounded field on the form for the next person to start
            //trusting again.
            $specification[$key . 'Id'] = [
                'required' => false,
                'allow_empty' => true,
                'filters' => [
                    ['name' => 'Laminas\Filter\ToInt'],
                ],
                'validators' => [
                    ['name' => 'Laminas\Validator\Digits'],
                ],
            ];
        }

        return $this->inputFilterSpecification = $specification;
    }

//     protected function getLocaleValidatorConfiguration()
//     {
//        return array(
//             'name'    => 'Laminas\Validator\Db\RecordExists',
//             'options' => array(
//                 'table' => $this->phrasesTranslationName,
//                 'field' => 'phrase',
//                 'adapter' => \Laminas\Db\TableGateway\Feature\GlobalAdapterFeature::getStaticAdapter(),
//                 'messages' => array(
//                     \Laminas\Validator\Db\RecordExists::ERROR_NO_RECORD_FOUND => 'Phrase not found in database'
//                 ),
//             ),
//         );
//  }
}
