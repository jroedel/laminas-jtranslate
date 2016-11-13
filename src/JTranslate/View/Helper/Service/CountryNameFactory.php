<?php
namespace JTranslate\View\Helper\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use JTranslate\Model\CountriesInfo;
use JTranslate\View\Helper\CountryName;

/**
 * Factory responsible of priming the CountryName view helper
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class CountryNameFactory implements FactoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @return CreateTimelineEventForm
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $parentLocator = $serviceLocator->getServiceLocator();
        /** @var CountriesInfo $countries */
        $countries = $parentLocator->get('CountriesInfo');
		$obj = new CountryName($countries);
		return $obj;
    }
}
