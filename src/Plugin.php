<?php

namespace GeneroWP\GformConversionApi;

use Exception;
use GeneroWP\GformConversionApi\Contracts\EventIntegration;
use GeneroWP\GformConversionApi\Integrations\FacebookForWooCommerce;
use GF_Field;
use GF_Field_Email;
use GF_Field_Phone;
use GFAddOn;
use GFAPI;
use GFForms;

class Plugin
{
    protected static Plugin $instance;

    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        add_action('gform_loaded', [$this, 'loadAddon']);
        add_action('gform_confirmation', [$this, 'onConfirmation'], 10, 4);
    }

    public function loadAddon(): void
    {
        GFForms::include_feed_addon_framework();
        GFAddOn::register(GformAddOn::class);
    }

    public function onConfirmation(string|array $confirmation, array $form, array $entry, bool $ajax): string|array
    {
        $feeds = GFAPI::get_feeds(null, $form['id'], 'gravityforms-conversion-api');
        if (! is_wp_error($feeds)) {
            return $confirmation;
        }

        try {
            $event = $this->buildDefaultSubmissionEvent($form, $entry);
            $integration = $this->getIntegration();
            $eventData = $integration->sendEvent($event);
            if ($eventData !== null) {
                ob_start();
                $integration->injectEvent($eventData);
                $script = ob_get_contents();
                ob_end_clean();

                if (is_string($confirmation)) {
                    $confirmation .= $script;
                }
                GFAPI::add_note($entry['id'], get_current_user_id(), '', 'Conversion API sent: ' . json_encode($event), 'notification', 'success');
            } else {
                GFAPI::add_note($entry['id'], get_current_user_id(), '', 'Conversion API event not sent', 'notification', 'error');
            }
        } catch (Exception $e) {
            GFAPI::add_note($entry['id'], get_current_user_id(), '', 'Conversion API failed to send event: ' . $e->getMessage(), 'notification', 'error');
        }

        return $confirmation;
    }

    protected function buildDefaultSubmissionEvent(array $form, array $entry): array
    {
        $event = [
            'event_name' => 'Lead',
            'custom_data' => [],
            'user_data' => [],
        ];

        foreach ($form['fields'] as $field) {
            $value = $entry[$field->id] ?? null;
            if (! $value) {
                continue;
            }

            $parameter = $this->getInferredParameterFromField($field);
            if ($parameter) {
                $event['user_data'][$parameter->value] = $value;
            }
        }
        return $event;
    }

    protected function getInferredParameterFromField(GF_Field $field): ?CustomerParameters
    {
        $autocomplete = $field->autocompleteAttribute ?? null;
        switch ($autocomplete) {
            case 'given-name':
                return CustomerParameters::FIRST_NAME;
            case 'family-name':
                return CustomerParameters::LAST_NAME;
            case 'locality':
            case 'address-level2':
                return CustomerParameters::CITY;
            case 'postal-code':
                return CustomerParameters::ZIP_CODE;
            case 'address-level1':
                return CustomerParameters::STATE;
            case 'country':
            case 'country-name':
                return CustomerParameters::COUNTRY;
            case 'sex':
                return CustomerParameters::GENDER;
            case 'bday':
                return CustomerParameters::DATE_OF_BIRTH;
        }

        if ($field instanceof GF_Field_Email) {
            return CustomerParameters::EMAIL;
        } elseif ($field instanceof GF_Field_Phone) {
            return CustomerParameters::PHONE;
        }
        return null;
    }

    public function getIntegration(): EventIntegration
    {
        $integrations = [
            FacebookForWooCommerce::class,
        ];
        foreach ($integrations as $integrationClass) {
            /** @var EventIntegration $integration */
            $integration = new $integrationClass;
            if ($integration->isActive()) {
                return $integration;
            }
        }
        throw new Exception('No integration plugin found. This plugin needs one to function.');
    }
}
