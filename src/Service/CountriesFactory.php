<?php

namespace JTranslate\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Laminas\Json\Json;
use JTranslate\Model\CountriesInfo;

/**
 * Factory responsible of priming the CountriesInfo service
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class CountriesFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @inheritdoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        //data vendored from mledoze/countries, see module/JTranslate/data/countries.README.txt
        $countries = Json::decode(file_get_contents(__DIR__ . '/../../data/countries.json'));
        $obj = new CountriesInfo($countries);
        return $obj;
    }
}
