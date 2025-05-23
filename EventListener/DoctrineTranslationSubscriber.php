<?php

/*
 * This file is part of the enhavo package.
 *
 * (c) WE ARE INDEED GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Enhavo\Bundle\TranslationBundle\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\Proxy;
use Enhavo\Bundle\AppBundle\Locale\LocaleResolverInterface;
use Enhavo\Bundle\TranslationBundle\Exception\TranslationException;
use Enhavo\Bundle\TranslationBundle\Translation\TranslationManager;
use Enhavo\Component\Metadata\MetadataRepository;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * Class DoctrineTranslatorSubscriber
 *
 * This subscriber is listing to doctrine events to call the translator actions
 *
 * @since 03/10/16
 *
 * @author gseidel
 */
class DoctrineTranslationSubscriber implements EventSubscriber
{
    use ContainerAwareTrait;

    public function __construct(
        private AccessControl $accessControl,
        private MetadataRepository $metadataRepository,
        private LocaleResolverInterface $localeResolver,
    ) {
    }

    public function getSubscribedEvents()
    {
        return [
            'preRemove',
            'postLoad',
            'preFlush',
            'postFlush',
        ];
    }

    /**
     * Before flushing the data, we have to check if some translation data was stored for an object.
     *
     * @throws TranslationException
     */
    public function preFlush(PreFlushEventArgs $event)
    {
        if (!$this->accessControl->isAccess()) {
            return;
        }

        $em = $event->getObjectManager();
        $uow = $em->getUnitOfWork();

        /*
         * We need to use the IdentityMap, because the update and persist collection stores entities, that have
         * computed changes, but translation data might have changed without changing its underlying model!
         */
        foreach ($uow->getIdentityMap() as $class => $entities) {
            if ($this->metadataRepository->hasMetadata($class)) {
                foreach ($entities as $entity) {
                    if (!($entity instanceof Proxy)) {
                        $this->getTranslationManager()->detach($entity);
                    }
                }
            }
        }
    }

    /**
     * Check if entity is not up to date an trigger flush again if needed
     *
     * @throws TranslationException
     */
    public function postFlush(PostFlushEventArgs $args)
    {
        if (!$this->accessControl->isAccess()) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getIdentityMap() as $class => $entities) {
            if ($this->metadataRepository->hasMetadata($class)) {
                foreach ($entities as $entity) {
                    if (!($entity instanceof Proxy)) {
                        $this->getTranslationManager()->translate($entity, $this->localeResolver->resolve());
                    }
                }
            }
        }
    }

    /**
     * If entity will be deleted, we need to delete all its translation data as well
     */
    public function preRemove(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();
        if ($this->metadataRepository->hasMetadata($entity)) {
            $this->getTranslationManager()->delete($entity);
        }
    }

    /**
     * Load TranslationData into to entity if it's fetched from the database
     *
     * @throws TranslationException
     */
    public function postLoad(LifecycleEventArgs $args)
    {
        if (!$this->accessControl->isAccess()) {
            return;
        }

        $entity = $args->getObject();
        if ($this->metadataRepository->hasMetadata($entity)) {
            $this->getTranslationManager()->translate($entity, $this->localeResolver->resolve());
        }
    }

    private function getTranslationManager()
    {
        return $this->container->get(TranslationManager::class);
    }
}
