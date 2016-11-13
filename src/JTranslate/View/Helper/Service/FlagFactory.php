<?php
namespace JTranslate\View\Helper\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use JTranslate\Model\CountriesInfo;
use Patres\View\Helper\Flag;

/**
 * Factory responsible of priming the Flag view helper
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class FlagFactory implements FactoryInterface
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
		$obj = new Flag($countries);
		return $obj;
    }
}
