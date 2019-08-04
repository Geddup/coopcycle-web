<?php

namespace AppBundle\Service;

use AppBundle\Utils\Settings;
use Craue\ConfigBundle\Util\Config as CraueConfig;
use Craue\ConfigBundle\CacheAdapter\CacheAdapterInterface;
use Doctrine\Common\Persistence\ManagerRegistry;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class SettingsManager
{
    private $craueConfig;
    private $configEntityName;
    private $phoneNumberUtil;
    private $country;
    private $doctrine;
    private $logger;

    private $settings = [
        'brand_name',
        'administrator_email',
        'phone_number',
        'stripe_test_publishable_key',
        'stripe_test_secret_key',
        'stripe_test_connect_client_id',
        'stripe_live_publishable_key',
        'stripe_live_secret_key',
        'stripe_live_connect_client_id',
        'stripe_livemode',
        'google_api_key',
        'latlng',
        'default_tax_category',
        'currency_code',
        'enable_restaurant_pledges'
    ];

    private $secretSettings = [
        'stripe_test_publishable_key',
        'stripe_test_secret_key',
        'stripe_test_connect_client_id',
        'stripe_live_publishable_key',
        'stripe_live_secret_key',
        'stripe_live_connect_client_id',
        'google_api_key',
    ];

    public function __construct(
        CraueConfig $craueConfig,
        string $configEntityName,
        ManagerRegistry $doctrine,
        CacheAdapterInterface $cache,
        PhoneNumberUtil $phoneNumberUtil,
        string $country,
        LoggerInterface $logger)
    {
        $this->craueConfig = $craueConfig;
        $this->configEntityName = $configEntityName;
        $this->doctrine = $doctrine;
        $this->cache = $cache;
        $this->phoneNumberUtil = $phoneNumberUtil;
        $this->country = $country;
        $this->logger = $logger;
    }

    public function getSettings()
    {
        return $this->settings;
    }

    public function isSecret($name)
    {
        return in_array($name, $this->secretSettings);
    }

    public function get($name)
    {
        switch ($name) {
            case 'stripe_publishable_key':
                $name = $this->isStripeLivemode() ? 'stripe_live_publishable_key' : 'stripe_test_publishable_key';
                break;
            case 'stripe_secret_key':
                $name = $this->isStripeLivemode() ? 'stripe_live_secret_key' : 'stripe_test_secret_key';
                break;
            case 'stripe_connect_client_id':
                $name = $this->isStripeLivemode() ? 'stripe_live_connect_client_id' : 'stripe_test_connect_client_id';
                break;
            case 'timezone':
                return ini_get('date.timezone');
        }

        try {

            $value = $this->craueConfig->get($name);

            switch ($name) {
                case 'phone_number':
                    try {
                        $value = $this->phoneNumberUtil->parse($value, strtoupper($this->country));
                    } catch (NumberParseException $e) {}
            }

            return $value;

        } catch (\RuntimeException $e) {}
    }

    public function getBoolean($name)
    {
        return filter_var($this->get($name), FILTER_VALIDATE_BOOLEAN);
    }

    public function isStripeLivemode()
    {
        $livemode = $this->get('stripe_livemode');

        if (!$livemode) {
            return false;
        }

        return filter_var($livemode, FILTER_VALIDATE_BOOLEAN);
    }

    public function canEnableStripeTestmode()
    {
        try {
            $stripeTestPublishableKey = $this->craueConfig->get('stripe_test_publishable_key');
            $stripeTestSecretKey = $this->craueConfig->get('stripe_test_secret_key');
            $stripeTestConnectClientId = $this->craueConfig->get('stripe_test_connect_client_id');

            return !empty($stripeTestPublishableKey) && !empty($stripeTestSecretKey) && !empty($stripeTestConnectClientId);

        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function canEnableStripeLivemode()
    {
        try {
            $stripeLivePublishableKey = $this->craueConfig->get('stripe_live_publishable_key');
            $stripeLiveSecretKey = $this->craueConfig->get('stripe_live_secret_key');
            $stripeLiveConnectClientId = $this->craueConfig->get('stripe_live_connect_client_id');

            return !empty($stripeLivePublishableKey) && !empty($stripeLiveSecretKey) && !empty($stripeLiveConnectClientId);

        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function set($name, $value, $section = null)
    {
        $className = $this->configEntityName;

        $params = [
            'name' => $name,
        ];

        if (!empty($section)) {
            $params['section'] = $section;
        }

        $setting = $this->doctrine
            ->getRepository($className)
            ->findOneBy($params);

        if (!$setting) {

            $setting = new $className();
            $setting->setName($name);
            $setting->setSection($section);

            $this->doctrine
                ->getManagerForClass($className)
                ->persist($setting);
        }

        $setting->setValue($value);
        $this->cache->set($name, $value);
    }

    public function flush()
    {
        $this->doctrine->getManagerForClass($this->configEntityName)->flush();
    }

    public function isFullyConfigured()
    {
        foreach ($this->settings as $name) {
            try {
                $this->craueConfig->get($name);
            } catch (\RuntimeException $e) {
                return false;
            }
        }

        return true;
    }

    public function asEntity()
    {
        $settings = new Settings();

        foreach ($this->settings as $name) {
            try {
                $value = $this->craueConfig->get($name);
                $settings->$name = $value;
            } catch (\RuntimeException $e) {}
        }

        return $settings;
    }
}
