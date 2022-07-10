<?php

declare(strict_types=1);

namespace JTranslate\View\Helper\Service;

use JTranslate\Model\CountriesInfo;
use JTranslate\View\Helper\CountryName;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class CountryNameFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        /** @var CountriesInfo $countries */
        $countries = $container->get(CountriesInfo::class);
        return new CountryName($countries);
    }
}
