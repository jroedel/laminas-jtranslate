<?php
namespace JTranslation\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Json\Json;
use JTranslation\Model\CountriesInfo;

/**
 * Factory responsible of priming the PatresTable service
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class CountriesFactory implements FactoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @return CreateTimelineEventForm
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $countries = Json::decode(file_get_contents("vendor/mledoze/countries/dist/countries.json"));
		$obj = new CountriesInfo($countries);
		return $obj;
    }
}
