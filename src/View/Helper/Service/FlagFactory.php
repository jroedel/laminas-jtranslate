<?php
namespace JTranslate\View\Helper\Service;

use Zend\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;
use JTranslate\Model\CountriesInfo;
use JTranslate\View\Helper\Flag;

/**
 * Factory responsible of priming the Flag view helper
 *
 * @author Jeff Ro <jeff.roedel.isp@gmail.com>
 */
class FlagFactory implements FactoryInterface
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
		$obj = new Flag($countries);
		return $obj;
    }
}
