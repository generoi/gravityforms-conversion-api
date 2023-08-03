<?php

namespace GeneroWP\GformConversionApi;

use Exception;
use GeneroWP\GformConversionApi\Contracts\EventIntegration;
use GeneroWP\GformConversionApi\Integrations\FacebookForWooCommerce;
use GFFeedAddOn;
use GFCommon;

class GformAddOn extends GFFeedAddOn
{
    // phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
    protected $_version = '0.1';
    protected $_min_gravityforms_version = '1.9';
    protected $_slug = 'gravityforms-conversion-api';
    protected $_path = 'gravityforms-conversion-api/gravityforms-conversion-api.php';
    protected $_full_path = __FILE__;
    protected $_title = 'Gravity Forms Conversion API Add-On';
    protected $_short_title = 'Conversion API';

    protected static GformAddOn $instance;

    public static function get_instance(): self
    {
        if (! isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return array<mixed>
     */
    public function feed_settings_fields()
    {
        return [
            [
                'title' => __('Feed Settings', 'gravityforms-conversion-api'),
                'fields' => [
                    [
                        'name' => 'feedName',
                        'label' => __('Name', 'gravityforms-conversion-api'),
                        'type' => 'text',
                        'required' => true,
                        'class' => 'medium',
                    ],
                ],
            ],
            [
                'fields' => [
                    [
                        'name' => ServerEventParameters::EVENT_NAME->value,
                        'label' => __('Event Name', 'gravityforms-conversion-api'),
                        'type' => 'text',
                    ],
                    [
                        'name' => 'mappedFields',
                        'label' => __('Map Fields', 'gravityforms-conversion-api'),
                        'type' => 'field_map',
                        'field_map' => $this->field_map(),
                        'tooltip' => sprintf(
                            '<h6>%s</h6>%s',
                            __('Map Fields', 'gravityforms-conversion-api'),
                            __('Associate your conversion-api properties to the appropriate Gravity Form fields by selecting the appropriate form field from the list.', 'gravityforms-conversion-api')
                        ),
                    ],
                    [
                        'name' => 'custom_data',
                        'label' => __('Custom Data', 'gravityforms-conversion-api'),
                        'type' => 'textarea',
                        'class' => 'medium merge-tag-support mt-position-right',
                        'tooltip' => sprintf(
                            '<h6>%s</h6>%s',
                            __('Custom data object', 'gravityforms-conversion-api'),
                            __('Return a custom JSON object, eg: \'{"content_name": "Category"}\'. Note for now you have to surroudn your value with \'', 'gravityforms-conversion-api')
                        ),
                    ],
                    [
                        'name' => 'optinCondition',
                        'label' => __('Conditional Logic', 'gravityforms-conversion-api'),
                        'type' => 'feed_condition',
                        'tooltip' => sprintf(
                            '<h6>%s</h6>%s',
                            __('Conditional Logic', 'gravityforms-conversion-api'),
                            __('When conditional logic is enabled, form submissions will only be exported to conversion-api when the conditions are met. When disabled all form submissions will be exported.', 'gravityforms-conversion-api')
                        ),
                    ],
                    ['type' => 'save'],
                ],
            ],
        ];
    }

    public function can_create_feed()
    {
        try {
            $this->getIntegration();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @return array<string,string>
     */
    public function feed_list_columns()
    {
        return [
            'feedName' => __('Name', 'gravityforms-conversion-api'),
        ];
    }

    /**
     * @return array<mixed[]>
     */
    public function get_conditional_logic_fields()
    {
        $fields = [];
        $form = $this->get_current_form();

        foreach ($form['fields'] as $field) {
            if (!$field->is_conditional_logic_supported()) {
                continue;
            }

            $inputs = $field->get_entry_inputs();
            if ($inputs && 'checkbox' !== $field->get_input_type()) {
                foreach ($inputs as $input) {
                    if (rgar($input, 'isHidden')) {
                        continue;
                    }

                    $fields[] = [
                        'value' => $input['id'],
                        'label' => GFCommon::get_label($field, $input['id']),
                    ];
                }
            } else {
                $fields[] = [
                  'value' => $field->id,
                  'label' => GFCommon::get_label($field),
                ];
            }
        }

        return $fields;
    }

    /**
     * @param array<mixed> $feed
     * @param array<mixed> $entry
     * @param array<mixed> $form
     * @return array<mixed>
     */
    public function process_feed($feed, $entry, $form)
    {
        $feedMeta = $feed['meta'];

        $eventName = $feedMeta[ServerEventParameters::EVENT_NAME->value];
        $customData = GFCommon::replace_variables($feedMeta['custom_data'] ?? '', $form, $entry, false, false, true, 'text');
        $customData = json_decode(trim($customData, '\''), true) ?: [];

        $event = [
            'event_name' => $eventName,
            'custom_data' => $customData,
            'user_data' => [],
        ];

        foreach (CustomerParameters::cases() as $param) {
            $key = sprintf('mappedFields_%s', $param->value);
            $fieldId =  $feedMeta[$key] ?? null;
            if (! empty($fieldId)) {
                $value = $this->get_field_value($form, $entry, $fieldId);
                $event['user_data'][$param->value] = $value;
            }
        }

        $this->log_debug(sprintf("%s(): Starting process: %s", __METHOD__, json_encode($event)));

        try {
            $integration = $this->getIntegration();
            $eventData = $integration->sendEvent($event);
            if ($eventData !== null) {
                $integration->injectEvent($eventData);
                $this->log_debug(sprintf("%s(): Sent event: %s", __METHOD__, json_encode($eventData)));
            } else {
                $this->log_debug(sprintf("%s(): Event not sent", __METHOD__));
            }
        } catch (Exception $e) {
            $this->add_feed_error(sprintf(
                __('Unable to send Pixel event: %s', 'gravityforms-conversion-api'),
                $e->getMessage()
            ), $feed, $entry, $form);
        }

        return $entry;
    }

    /**
     * @see https://developers.facebook.com/docs/marketing-api/conversions-api/parameters
     * @return array<string,mixed>
     */
    protected function field_map()
    {
        $fields = [
            'em' => [
                'name' => CustomerParameters::EMAIL->value,
                'label' => __('Email', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['email', 'hidden', 'text'],
            ],
            'ph' => [
                'name' => CustomerParameters::PHONE->value,
                'label' => __('Phone', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['phone', 'hidden', 'text'],
            ],
            'fn' => [
                'name' => CustomerParameters::FIRST_NAME->value,
                'label' => __('First name', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['hidden', 'text', 'name'],
            ],
            'ln' => [
                'name' => CustomerParameters::LAST_NAME->value,
                'label' => __('Last name', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['hidden', 'text', 'name'],
            ],
            'ge' => [
                'name' => CustomerParameters::GENDER->value,
                'label' => __('Gender', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['hidden', 'text', 'select', 'radio'],
            ],
            'db' => [
                'name' => CustomerParameters::DATE_OF_BIRTH->value,
                'label' => __('Date of Birth', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['hidden', 'text', 'date'],
            ],
            'ct' => [
                'name' => CustomerParameters::CITY->value,
                'label' => __('City', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['hidden', 'text'],
            ],
            'st' => [
                'name' => CustomerParameters::STATE->value,
                'label' => __('State', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['hidden', 'text', 'select'],
            ],
            'zp' => [
                'name' => CustomerParameters::ZIP_CODE->value,
                'label' => __('Zip Code', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['hidden', 'text'],
            ],
            'country' => [
                'name' => CustomerParameters::COUNTRY->value,
                'label' => __('Country', 'gravityforms-conversion-api'),
                'required' => false,
                'field_type' => ['hidden', 'text', 'select'],
            ],
        ];

        return $fields;
    }

    protected function getIntegration(): EventIntegration
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
