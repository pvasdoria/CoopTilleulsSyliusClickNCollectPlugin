<?php

/*
 * This file is part of Les-Tilleuls.coop's Click 'N' Collect project.
 *
 * (c) Les-Tilleuls.coop <contact@les-tilleuls.coop>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace CoopTilleuls\SyliusClickNCollectPlugin\EventListener;

use CoopTilleuls\SyliusClickNCollectPlugin\Entity\ClickNCollectShipmentInterface;
use CoopTilleuls\SyliusClickNCollectPlugin\Entity\ClickNCollectShippingMethodInterface;
use CoopTilleuls\SyliusClickNCollectPlugin\Repository\CollectionTimeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Resource\Exception\RaceConditionException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

/**
 * Prevents concurrent insertion of collection times.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class ShipmentCollectionTimeLockListener
{
    private EntityManagerInterface $entityManager;
    private CollectionTimeRepositoryInterface $collectionTimeRepository;
    private ?LockInterface $lock= null;
    private string $shipmentClass;

    public function __construct(
        EntityManagerInterface $entityManager,
        CollectionTimeRepositoryInterface $collectionTimeRepository
    )
    {
        $this->entityManager = $entityManager;
        $this->collectionTimeRepository = $collectionTimeRepository;
    }

    protected function getLock() : LockInterface {
        if(!$this->lock instanceof LockInterface) {
            if (SemaphoreStore::isSupported()) {
                $store = new SemaphoreStore();
            } else {
                $store = new FlockStore();
            }
            $this->lock = (new LockFactory($store))->createLock(ShipmentCollectionTimeLockListener::class);;
        }
        return $this->lock;
    }
    /**
     * @throws RaceConditionException
     */
    public function onPreSelectShipping(ResourceControllerEvent $event): void
    {
        if (!$shipments = $this->getShipmentToChecks($event->getSubject())) {
            return;
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();

        $this->getLock()->acquire(true);
        foreach ($shipments as $shipment) {
            if ($shipment->isClickNCollect()) {
                $previousCollectionTime = $unitOfWork->getOriginalEntityData($shipment)['collectionTime'] ?? null;
                $newCollectionTime = $shipment->getCollectionTime();

                if ($previousCollectionTime !== $newCollectionTime && $this->collectionTimeRepository->isSlotFull($shipment->getLocation(), $shipment->getCollectionTime())) {
                    $this->getLock()->release();
                    throw new RaceConditionException();
                }
            }
        }
    }

    public function onPostSelectShipping(): void
    {
        if ($this->getLock()->isAcquired()) {
            $this->getLock()->release();
        }
    }

    /**
     * @return ClickNCollectShipmentInterface[]
     */
    private function getShipmentToChecks($order): array
    {
        if (!$order instanceof OrderInterface) {
            return $order;
        }

        $filteredShipments = [];
        foreach ($order->getShipments() as $shipment) {
            if (
                $shipment instanceof ClickNCollectShipmentInterface
                && null !== $shipment->getCollectionTime()
                && $shipment->getMethod() instanceof ClickNCollectShippingMethodInterface
                && $shipment->getMethod()->isClickNCollect()
            ) {
                $filteredShipments[] = $shipment;
            }
        }

        return $filteredShipments;
    }
}
