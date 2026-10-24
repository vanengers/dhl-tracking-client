<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

/**
 * Phrases the status rules look for, in English, Dutch and German. Matching ignores case and punctuation and only
 * needs a word start ("locker" also matches "lockers"; "pick-up" and "PICK_UP" read as "pick up").
 *
 * DHL documents no list of texts, so these come from DHL's public tracking wording. When a real response is
 * classified wrongly, add its phrase here (or pass it to TextSignals) and add the response as a test fixture.
 */
final class Keywords
{
    public const LOCKER = 'locker';
    public const POST_OFFICE = 'post_office';
    public const SERVICE_POINT = 'service_point';
    public const DEPOT = 'depot';
    public const READY = 'ready';
    public const ARRIVED = 'arrived';
    public const FUTURE = 'future';
    public const COLLECTED = 'collected';
    public const NOT_COLLECTED = 'not_collected';
    public const SENDER = 'sender';
    public const RETURNED = 'returned';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const NEIGHBOUR = 'neighbour';
    public const MAILBOX = 'mailbox';
    public const SAFE_PLACE = 'safe_place';
    public const DELIVERED = 'delivered';
    public const PRE_TRANSIT = 'pre_transit';

    /**
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        return [
            self::LOCKER => [
                'packstation', 'parcel locker', 'parcellocker', 'locker', 'paketautomat', 'pakketautomaat', 'pakketkluis',
                'poststation', 'parcel machine', 'parcel station', 'parcelstation', 'pickup station', 'pick up station',
            ],
            self::POST_OFFICE => ['post office', 'postoffice', 'postfiliale', 'filiale', 'postkantoor', 'postagentur', 'retail outlet'],
            self::SERVICE_POINT => [
                'servicepoint', 'service point', 'parcelshop', 'parcel shop', 'paketshop', 'pakketshop', 'pakketpunt',
                'servicepunt', 'afhaalpunt', 'ophaalpunt', 'pickup point', 'pick up point', 'collection point', 'access point',
                'dhl point', 'parcel point', 'parcelpoint', 'pickup location', 'pick up location', 'pickup shop',
            ],
            self::DEPOT => ['depot', 'service center', 'service centre', 'servicecenter', 'service counter'],
            self::READY => [
                'ready for pickup', 'ready for pick up', 'ready for collection', 'ready to be picked up', 'ready to be collected',
                'available for pickup', 'available for pick up', 'available for collection', 'awaiting collection',
                'awaiting pickup', 'awaiting pick up', 'waiting to be picked up', 'waiting to be collected', 'can be picked up',
                'can be collected', 'can now be picked up', 'can now be collected', 'held for collection', 'held for pickup',
                'held for pick up', 'klaar om op te halen', 'klaar voor ophalen', 'ligt klaar', 'kan worden opgehaald',
                'kan opgehaald worden', 'kan nu worden opgehaald', 'kan worden afgehaald', 'kan afgehaald worden',
                'abholbereit', 'zur abholung', 'kann abgeholt werden', 'abholung bereit',
            ],
            self::ARRIVED => [
                'delivered to', 'delivered at', 'delivered in', 'arrived at', 'has arrived', 'now at', 'is at the', 'placed in',
                'deposited in', 'bezorgd bij', 'bezorgd in', 'bezorgd op', 'afgeleverd bij', 'afgeleverd in', 'aangekomen bij',
                'aangekomen in', 'geplaatst in', 'ligt bij', 'ligt in', 'zugestellt', 'eingetroffen', 'liegt in', 'liegt im',
            ],
            self::FUTURE => [
                'will be', 'being delivered', 'being transported', 'being brought', 'wordt', 'zal', 'expected', 'on its way',
                'on the way', 'onderweg', 'unterwegs', 'forwarded',
                'doorgestuurd', 'weitergeleitet', 'redirected', 'rerouted',
            ],
            self::COLLECTED => [
                'picked up by the recipient', 'picked up by recipient', 'picked up by the consignee', 'picked up by the customer',
                'picked up by the receiver', 'collected by the recipient', 'collected by recipient', 'collected by the consignee',
                'collected by the customer', 'collected by the receiver', 'has been collected', 'was collected',
                'has been picked up from', 'was picked up from', 'picked up at', 'collected from', 'collected at',
                'opgehaald door de ontvanger', 'opgehaald door ontvanger', 'door de ontvanger opgehaald', 'is opgehaald',
                'afgehaald', 'vom empfänger abgeholt', 'wurde abgeholt', 'abgeholt',
            ],
            self::NOT_COLLECTED => [
                'not collected', 'not been collected', 'not picked up', 'not been picked up', 'uncollected',
                'niet opgehaald', 'niet afgehaald', 'nicht abgeholt',
            ],
            self::SENDER => [
                'by the sender', 'by sender', 'from the sender', 'from sender', 'by the shipper', 'from the shipper',
                'door de afzender', 'door afzender', 'bij de afzender', 'van de afzender', 'vom absender', 'beim absender',
                'durch den absender', 'handed in', 'dropped off by', 'posted by', 'ingeleverd', 'eingeliefert',
            ],
            self::RETURNED => [
                'returned to sender', 'returned to the sender', 'return to sender', 'returning to sender', 'returned to shipper',
                'returned to the shipper', 'return to shipper', 'return to origin', 'returned to origin', 'being returned',
                'has been returned', 'was returned', 'retour afzender', 'retour naar afzender', 'retour naar de afzender',
                'terug naar afzender', 'terug naar de afzender', 'teruggestuurd', 'retour gezonden', 'wordt geretourneerd',
                'is geretourneerd', 'rücksendung', 'zurück an absender', 'zurück an den absender', 'zurückgesendet',
                'zurückgeschickt', 'retoure',
            ],
            self::OUT_FOR_DELIVERY => [
                'out for delivery', 'with delivery courier', 'with the delivery courier', 'with courier for delivery',
                'on the delivery vehicle', 'onto the delivery vehicle', 'loaded onto the delivery vehicle',
                'loaded on the delivery vehicle', 'in delivery', 'being delivered', 'will be delivered today', 'on its way to you',
                'on the way to you', 'onderweg naar jou', 'onderweg naar je', 'onderweg naar u', 'wordt vandaag bezorgd',
                'vandaag bezorgd', 'bezorger is onderweg', 'chauffeur is onderweg', 'in bezorging', 'in zustellung',
                'zustellfahrzeug', 'wird heute zugestellt', 'heute zugestellt',
            ],
            self::NEIGHBOUR => ['neighbour', 'neighbor', 'buren', 'buurman', 'buurvrouw', 'buurtgenoot', 'nachbar'],
            self::MAILBOX => ['mailbox', 'mail box', 'letterbox', 'letter box', 'brievenbus', 'briefkasten'],
            self::SAFE_PLACE => [
                'safe place', 'safe location', 'safeplace', 'veilige plek', 'veilige plaats', 'afgesproken plek',
                'ablageort', 'wunschablageort',
            ],
            self::DELIVERED => [
                'has been delivered', 'was delivered', 'successfully delivered', 'is bezorgd', 'is afgeleverd',
                'wurde zugestellt', 'ist zugestellt',
            ],
            self::PRE_TRANSIT => [
                'label created', 'shipment information received', 'shipping information received', 'data received',
                'electronically announced', 'electronic notification', 'awaiting shipment', 'gegevens ontvangen',
                'zending is aangemeld', 'aangemeld', 'zending aangemaakt', 'elektronisch angekündigt', 'auftragsdaten',
                'daten übermittelt',
            ],
        ];
    }
}
