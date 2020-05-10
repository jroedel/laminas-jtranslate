<?php
namespace JTranslate\Form;

use Zend\Form\Form;
use Zend\InputFilter\InputFilterProviderInterface;

class EditPhraseForm extends Form implements InputFilterProviderInterface
{
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
		
		$this->add(array(
		    'name' => 'phraseId',
		    'type' => 'Hidden',
		    'filters' => array(
		        array('name' => 'Int'),
		    ),
		));
		$this->add(array(
			'name' => 'phrase',
			'type' => 'Textarea',
			'options' => array(
				'label' => 'Phrase',
			),
			'attributes' => array(
			    'rows' => 2,
			    'readonly' => true,
			),
		));
	
		foreach ($locales as $key => $value) {
    		$this->add(array(
    			'name' => $key,
    			'type' => 'Textarea',
    			'options' => array(
    				'label' => $value,
    			),
    		    'attributes' => array(
    		        'rows' => 2,
    			),
    		));

    		$this->add(array(
    		    'name' => $key.'Id',
    		    'type' => 'Hidden',
    		    'filters' => array(
    		        array('name' => 'Int'),
    		    ),
    		));
		}
		
		$this->add(array(
			'name' => 'security',
			'type' => 'csrf',
		));
		$this->add(array(
			'name' => 'submit',
			'type' => 'Submit',
			'attributes' => array(
				'value' => 'Submit',
				'id' => 'submit',
				'class' => 'btn-primary'
			),
		));
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
	 * @todo Add validators for each of the pairs of elements of the different locales
	 * (non-PHPdoc)
	 * @see \Zend\InputFilter\InputFilterProviderInterface::getInputFilterSpecification()
	 */
	public function getInputFilterSpecification()
	{
	    if ($this->inputFilterSpecification) {
	        return $this->inputFilterSpecification;
	    } else {
    		return $this->inputFilterSpecification = array(
    			'phraseId' => array(
    	            'validators' => array(
    	                array(
    	                    'name'    => 'Zend\Validator\Db\RecordExists',
    	                    'options' => array(
    	                        'table' => $this->phrasesTableName,
    	                        'field' => 'translation_phrase_id',
    	                        'adapter' => \Zend\Db\TableGateway\Feature\GlobalAdapterFeature::getStaticAdapter(),
    	                        'messages' => array(
    	                            \Zend\Validator\Db\RecordExists::ERROR_NO_RECORD_FOUND => 'Phrase not found in database' 
    	                        ),
    	                    ),
    	                ),
    	            ),
    			)
    		);
	    }
	}
	
// 	protected function getLocaleValidatorConfiguration()
// 	{
// 	    return array(
//             'name'    => 'Zend\Validator\Db\RecordExists',
//             'options' => array(
//                 'table' => $this->phrasesTranslationName,
//                 'field' => 'phrase',
//                 'adapter' => \Zend\Db\TableGateway\Feature\GlobalAdapterFeature::getStaticAdapter(),
//                 'messages' => array(
//                     \Zend\Validator\Db\RecordExists::ERROR_NO_RECORD_FOUND => 'Phrase not found in database' 
//                 ),
//             ),
//         );
// 	}
}