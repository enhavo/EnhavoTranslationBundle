<?php

/*
 * This file is part of the enhavo package.
 *
 * (c) WE ARE INDEED GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Enhavo\Bundle\TranslationBundle\Translator\Route;

use Doctrine\ORM\EntityRepository;
use Enhavo\Bundle\RoutingBundle\Model\RouteInterface;
use Enhavo\Bundle\TranslationBundle\Entity\TranslationRoute;
use Enhavo\Bundle\TranslationBundle\Translator\AbstractTranslator;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Class RouteTranslator
 */
class RouteTranslator extends AbstractTranslator
{
    public function setTranslation($entity, $property, $locale, $value): void
    {
        if ($locale == $this->localeProvider->getDefaultLocale()) {
            return;
        }

        $translationRoute = $this->buffer->load($entity, $property, $locale)
            ?? $this->load($entity, $property, $locale);

        if (null === $translationRoute) {
            $translationRoute = $this->createTranslationRoute($entity, $property, $locale, $value);
        } else {
            if ($translationRoute->getRoute() !== $value) {
                $route = $translationRoute->getRoute();
                $this->entityManager->remove($route);
                $translationRoute->setRoute($value);
            }
        }

        $this->buffer->store($entity, $property, $locale, $translationRoute);
    }

    public function getTranslation($entity, $property, $locale): ?RouteInterface
    {
        if ($locale == $this->localeProvider->getDefaultLocale()) {
            return null;
        }

        $translationRoute = $this->buffer->load($entity, $property, $locale);
        if (null !== $translationRoute) {
            return $translationRoute->getRoute();
        }

        $translationRoute = $this->load($entity, $property, $locale);
        if (null !== $translationRoute) {
            $this->buffer->store($entity, $property, $locale, $translationRoute);

            return $translationRoute->getRoute();
        }

        return null;
    }

    public function delete($entity, string $property)
    {
        $this->deleteTranslationData($entity);
    }

    private function createTranslationRoute($entity, $property, $locale, ?RouteInterface $route): ?TranslationRoute
    {
        if (null === $route || empty($route->getStaticPrefix())) {
            return null;
        }

        $route->setContent($entity);
        $route->generateRouteName();

        $translationRoute = new TranslationRoute();
        $translationRoute->setLocale($locale);
        $translationRoute->setProperty($property);
        $translationRoute->setRoute($route);

        $this->entityManager->persist($translationRoute);

        return $translationRoute;
    }

    private function deleteTranslationData($entity)
    {
        $repository = $this->getRepository();

        $translationRoutes = $repository->findTranslationRoutes(
            $this->entityResolver->getName($entity),
            $entity->getId()
        );

        foreach ($translationRoutes as $translationRoute) {
            $this->entityManager->remove($translationRoute);
        }
    }

    private function load($entity, $property, $locale): ?TranslationRoute
    {
        $repository = $this->getRepository();

        return $repository->findTranslationRoute(
            $this->entityResolver->getName($entity),
            $entity->getId(),
            $property,
            $locale
        );
    }

    public function getRepository(): EntityRepository
    {
        return $this->entityManager->getRepository(TranslationRoute::class);
    }

    public function translate($entity, string $property, string $locale, array $options)
    {
        // translation data is stored inside the object
        if ($locale === $this->localeProvider->getDefaultLocale()) {
            return;
        }

        $accessor = PropertyAccess::createPropertyAccessor();

        $newValue = $this->getTranslation($entity, $property, $locale);
        $oldValue = $accessor->getValue($entity, $property);
        $this->originalData->store($entity, $property, null, $oldValue);

        if (null !== $newValue || $options['allow_null']) {
            $accessor->setValue($entity, $property, $newValue);
        }
    }

    public function detach($entity, string $property, string $locale, array $options)
    {
        // translation data is stored inside the object
        if ($locale === $this->localeProvider->getDefaultLocale()) {
            return;
        }

        parent::detach($entity, $property, $locale, $options);
    }
}
