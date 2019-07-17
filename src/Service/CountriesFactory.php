<?php
namespace JTranslate\Service;

use Zend\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;
use Zend\Json\Json;
use JTranslate\Model\CountriesInfo;

/**
 * Factory responsible of priming the CountriesInfo service
 *
 * @author Jeff Ro <jeff.roedel.isp@gmail.com>
 */
class CountriesFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $countries = Json::decode(file_get_contents("vendor/mledoze/countries/dist/countries.json"));
		$obj = new CountriesInfo($countries);
		return $obj;
    }
}
