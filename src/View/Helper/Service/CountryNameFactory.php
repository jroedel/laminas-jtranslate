<?php
namespace JTranslate\View\Helper\Service;

use Zend\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;
use JTranslate\Model\CountriesInfo;
use JTranslate\View\Helper\CountryName;

/**
 * Factory responsible of priming the CountryName view helper
 *
 * @author Jeff Ro <jeff.roedel.isp@gmail.com>
 */
class CountryNameFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $parentLocator = $container->getServiceLocator();
        /** @var CountriesInfo $countries */
        $countries = $parentLocator->get(CountriesInfo::class);
		$obj = new CountryName($countries);
		return $obj;
    }
}
