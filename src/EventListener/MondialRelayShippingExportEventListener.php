<?php
/**
 * @author    Matthieu Vion
 * @copyright 2018 Magentix
 * @license   https://opensource.org/licenses/GPL-3.0 GNU General Public License version 3
 * @link      https://github.com/magentix/mondial-relay-plugin
 */
declare(strict_types=1);

namespace Waaz\SyliusMondialRelayPlugin\EventListener;

use Waaz\SyliusMondialRelayPlugin\Repository\PickupRepository;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use BitBag\SyliusShippingExportPlugin\Entity\ShippingExportInterface;
use BitBag\SyliusShippingExportPlugin\Entity\ShippingGatewayInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Magentix\SyliusPickupPlugin\Entity\Shipment;
use Doctrine\ORM\EntityManager;
use Webmozart\Assert\Assert;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Filesystem\Filesystem;

final class MondialRelayShippingExportEventListener
{
    /** @var FlashBagInterface */
    private $flashBag;

    /**
     * @var PickupRepository $pickupRepository
     */
    private $pickupRepository;

    /**
     * @var EntityManagerInterface|EntityManager $shipmentManager
     */
    private $shipmentManager;

    /** @var ObjectManager */
    private $shippingExportManager;

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param PickupRepository $pickupRepository
     * @param EntityManagerInterface|EntityManager $shipmentManager
     */
    public function __construct(
        FlashBagInterface $flashBag,
        PickupRepository $pickupRepository,
        EntityManagerInterface $shipmentManager,
        string $shippingLabelsPath,
        Filesystem $filesystem,
        ObjectManager $shippingExportManager
    ) {
        $this->flashBag = $flashBag;
        $this->pickupRepository = $pickupRepository;
        $this->shipmentManager = $shipmentManager;
        $this->shippingLabelsPath = $shippingLabelsPath;
        $this->filesystem = $filesystem;
        $this->shippingExportManager = $shippingExportManager;
    }

    /**
     * @param ResourceControllerEvent $event
     * @return void
     */
    public function exportShipment(ResourceControllerEvent $event): void
    {
        /** @var ShippingExportInterface $shippingExport */
        $shippingExport = $event->getSubject();
        Assert::isInstanceOf($shippingExport, ShippingExportInterface::class);

        $shippingGateway = $shippingExport->getShippingGateway();
        Assert::notNull($shippingGateway);

        $configuration = $shippingGateway->getConfig();

        if ('mondial_relay_shipping_gateway' !== $shippingGateway->getCode()) {
            return;
        }

        $shipment = $shippingExport->getShipment();
        $order    = $shipment->getOrder();

        if ($configuration['label_generate'] === 1) {
            /* Create Shipment in Mondial Relay database */
            $result = $this->createShipping($order, $shipment, $configuration);

            if ($result['error']) {
                $this->flashBag->add('error', 'mondial_relay.pickup.list.error.' . $result['error']);
                return;
            }

            $shipment->setTracking($result['response']->ExpeditionNum);

            /* Retrieve Shipping Label from shipment */
            $result = $this->getShippingLabel(
                $result['response']->ExpeditionNum,
                $order->getShippingAddress()->getCountryCode(),
                $configuration
            );

            if ($result['error']) {
                $this->flashBag->add('error', 'mondial_relay.pickup.list.error.' . $result['error']);
                return;
            }

            $labelSize = $configuration['label_size'];
            $label = file_get_contents('https://www.mondialrelay.fr' . $result['response']->$labelSize);

            //$event->saveShippingLabel($label, 'pdf');
            $this->saveShippingLabel($shippingExport, $label, 'pdf'); // Save label
        }

        $shipment->setState(ShipmentInterface::STATE_SHIPPED);
        $order->setShippingState(ShipmentInterface::STATE_SHIPPED);

        $this->shipmentManager->flush($shipment);
        $this->shipmentManager->flush($order);

        $this->flashBag->add('success', 'bitbag.ui.shipment_data_has_been_exported'); // Add success notification
        $this->markShipmentAsExported($shippingExport); // Mark shipment as "Exported"
    }

    /**
     * Retrieve Shipping Label content
     *
     * @param OrderInterface $order
     * @param Shipment|ShipmentInterface $shipment
     * @param array $configuration
     * @return array
     */
    protected function createShipping(OrderInterface $order, ShipmentInterface $shipment, array $configuration): array
    {
        $shippingAddress = $order->getShippingAddress();

        list($id, $code, $country) = explode('-', $shipment->getPickupId());

        if (!isset($configuration['product_weight'])) {
            $configuration['product_weight'] = 1;
        }

        $weight = ($shipment->getShippingWeight() * 1000) / $configuration['product_weight'];

        $data = [
            'ModeCol'      => 'CCC',
            'ModeLiv'      => $code,
            'NDossier'     => $order->getNumber(),
            'NClient'      => $order->getCustomer()->getId(),
            'Expe_Langage' => $this->getLanguage($configuration['label_shipper_country_code']),
            'Expe_Ad1'     => trim($this->removeaccents($configuration['label_shipper_company'])),
            'Expe_Ad2'     => '',
            'Expe_Ad3'     => trim($this->removeaccents($configuration['label_shipper_street'])),
            'Expe_Ad4'     => trim($this->removeaccents('')),
            'Expe_Ville'   => trim($this->removeaccents($configuration['label_shipper_city'])),
            'Expe_CP'      => $configuration['label_shipper_postcode'],
            'Expe_Pays'    => trim($this->removeaccents($configuration['label_shipper_country_code'])),
            'Expe_Tel1'    => $configuration['label_shipper_phone_number'],
            'Expe_Tel2'    => '',
            'Expe_Mail'    => $configuration['label_shipper_email'],
            'Dest_Langage' => $this->getLanguage($shippingAddress->getCountryCode()),
            'Dest_Ad1'     => trim($this->removeaccents($shippingAddress->getFullName())),
            'Dest_Ad2'     => trim($this->removeaccents($shippingAddress->getCompany())),
            'Dest_Ad3'     => trim($this->removeaccents($shippingAddress->getStreet())),
            'Dest_Ad4'     => trim($this->removeaccents('')),
            'Dest_Ville'   => trim($this->removeaccents($shippingAddress->getCity())),
            'Dest_CP'      => $shippingAddress->getPostcode(),
            'Dest_Pays'    => trim($this->removeaccents($shippingAddress->getCountryCode())),
            'Dest_Tel1'    => $shippingAddress->getPhoneNumber(),
            'Dest_Tel2'    => '',
            'Dest_Mail'    => $order->getCustomer()->getEmail(),
            'Poids'        => $weight,
            'NbColis'      => 1,
            'CRT_Valeur'   => 0,
            'CRT_Devise'   => '',
            'Exp_Valeur'   => '',
            'Exp_Devise'   => '',
            'COL_Rel_Pays' => $configuration['label_shipper_country_code'],
            'COL_Rel'      => 0,
            'LIV_Rel_Pays' => $country,
            'LIV_Rel'      => $id,
            'TAvisage'     => '',
            'TReprise'     => '',
            'Montage'      => '',
            'TRDV'         => '',
            'Assurance'    => 0,
            'Instructions' => '',
        ];

        $this->pickupRepository->setConfig($configuration);

        return $this->pickupRepository->createShipping($data);
    }

    /**
     * Retrieve country language
     *
     * @param string $country
     * @return string
     */
    protected function getLanguage(string $country): string
    {
        $languages = [
            'FR' => 'FR',
            'BE' => 'NL',
            'ES' => 'ES',
        ];

        $language = 'FR';
        if (isset($languages[$country])) {
            $language = $languages[$country];
        }

        return $language;
    }

    /**
     * Retrieve Shipping Label content
     *
     * @param string $tracking
     * @param string $countryCode
     * @param array $configuration
     * @return array
     */
    protected function getShippingLabel(string $tracking, string $countryCode, array $configuration): array
    {
        $data = [
            'Expeditions' => $tracking,
            'Langue'      => $countryCode,
        ];

        $this->pickupRepository->setConfig($configuration);

        return $this->pickupRepository->getLabel($data);
    }

    public function saveShippingLabel(
        ShippingExportInterface $shippingExport,
        string $labelContent,
        string $labelExtension
    ): void {
        $labelPath = $this->shippingLabelsPath
            . '/' . $this->getFilename($shippingExport)
            . '.' . $labelExtension;

        $this->filesystem->dumpFile($labelPath, $labelContent);
        $shippingExport->setLabelPath($labelPath);

        $this->shippingExportManager->persist($shippingExport);
        $this->shippingExportManager->flush();
    }

    private function getFilename(ShippingExportInterface $shippingExport): string
    {
        $shipment = $shippingExport->getShipment();
        Assert::notNull($shipment);

        $order = $shipment->getOrder();
        Assert::notNull($order);

        $orderNumber = $order->getNumber();

        $shipmentId = $shipment->getId();

        return implode(
            '_',
            [
                $shipmentId,
                preg_replace('~[^A-Za-z0-9]~', '', $orderNumber),
            ]
        );
    }

    private function markShipmentAsExported(ShippingExportInterface $shippingExport): void
    {
        $shippingExport->setState(ShippingExportInterface::STATE_EXPORTED);
        $shippingExport->setExportedAt(new \DateTime());

        $this->shippingExportManager->persist($shippingExport);
        $this->shippingExportManager->flush();
    }

    function removeaccents(string $string){ 
        $stringToReturn = str_replace( 
        ['à','á','â','ã','ä', 'ç', 'è','é','ê','ë', 'ì','í','î','ï', 'ñ', 'ò','ó','ô','õ','ö', 'ù','ú','û','ü', 'ý','ÿ', 'À','Á','Â','Ã','Ä', 'Ç', 'È','É','Ê','Ë', 'Ì','Í','Î','Ï', 'Ñ', 'Ò','Ó','Ô','Õ','Ö', 'Ù','Ú','Û','Ü', 'Ý','/','\xa8'], 
        ['a','a','a','a','a', 'c', 'e','e','e','e', 'i','i','i','i', 'n', 'o','o','o','o','o', 'u','u','u','u', 'y','y', 'A','A','A','A','A', 'C', 'E','E','E','E', 'I','I','I','I', 'N', 'O','O','O','O','O', 'U','U','U','U', 'Y',' ','e'], $string);
        // Remove all remaining other unknown characters
        $stringToReturn = preg_replace('/[^a-zA-Z0-9\-]/', ' ', $stringToReturn);
        $stringToReturn = preg_replace('/^[\-]+/', '', $stringToReturn);
        $stringToReturn = preg_replace('/[\-]+$/', '', $stringToReturn);
        $stringToReturn = preg_replace('/[\-]{2,}/', ' ', $stringToReturn);
        return $stringToReturn;
    } 
}
