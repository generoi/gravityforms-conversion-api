<?php

namespace GeneroWP\GformConversionApi\Integrations;

use GeneroWP\GformConversionApi\Contracts\EventIntegration;
use GeneroWP\GformConversionApi\ConversionException;
use GeneroWP\GformConversionApi\ServerEventParameters;
use WC_Facebookcommerce_Pixel;
use WC_Facebookcommerce_Utils;
use WooCommerce\Facebook\Events\AAMSettings;
use WooCommerce\Facebook\Events\Event;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;

class FacebookForWooCommerce implements EventIntegration
{
    public function sendEvent(array $eventData): ?array
    {
        $pixelId = facebook_for_woocommerce()->get_integration()->get_facebook_pixel_id();
        if (! $pixelId) {
            return null;
        }

        // Merge in customer details if available
        // Note that browser agent and ip details are handled in the Event class.
        $eventData['user_data'] = array_merge($this->getBaseUserInfo(), $eventData['user_data'] ?? []);
        $event = new Event($eventData);

        try {
            facebook_for_woocommerce()
                ->get_api()
                ->send_pixel_events(
                    $pixelId,
                    [$event],
                );
        } catch (ApiException $e) {
            throw new ConversionException($e->getMessage());
        }

        $eventData['event_id'] = $event->get_id();
        return $eventData;
    }

    public function injectEvent(array $eventData): void
    {
        $eventName = $eventData[ServerEventParameters::EVENT_NAME->value];
        $params = array_replace(Event::get_version_info(), $eventData);
        $params = apply_filters('wc_facebook_pixel_params', $params, $eventName);

        $pixel = new WC_Facebookcommerce_Pixel($this->getBaseUserInfo());

        echo $pixel->pixel_base_code() . PHP_EOL;
        echo $pixel->get_event_script($eventName, $params, 'track');
    }

    /**
     * @return array<string,string>
     */
    protected function getBaseUserInfo(): array
    {
        return WC_Facebookcommerce_Utils::get_user_info($this->getPixelSettings());
    }

    /**
     * @see https://github.com/woocommerce/facebook-for-woocommerce/blob/c779bcde2b7e93223990a03caf912d28325af782/facebook-commerce.php#L429C1-L461C3
     */
    protected function getPixelSettings(): ?AAMSettings
    {
        $pixelId = facebook_for_woocommerce()->get_integration()->get_facebook_pixel_id();

        $configKey = 'wc_facebook_aam_settings';
        $savedValue = get_transient($configKey);
        $refreshInterval = 20 * MINUTE_IN_SECONDS;
        $aamSettings = null;

        if ($savedValue !== false) {
            $cachedAamSettings = new AAMSettings(json_decode($savedValue, true));
            if ($cachedAamSettings->get_pixel_id() === $pixelId) {
                $aamSettings = $cachedAamSettings;
            }
        }

        if (! $aamSettings) {
            $aamSettings = AAMSettings::build_from_pixel_id($pixelId);
            if ($aamSettings) {
                set_transient($configKey, strval($aamSettings), $refreshInterval);
            }
        }
        return $aamSettings;
    }

    public function isActive(): bool
    {
        return function_exists('facebook_for_woocommerce');
    }
}
