<?php

class ControllerExtensionModuleOvesioManual extends Controller
{
    public function index()
    {
        $this->load->language('extension/module/ovesio');

        if (empty($this->request->post['selected'])) {
            return $this->response->setOutput(json_encode([
                'success' => false,
                'message' => $this->language->get('error_no_items_selected'),
            ]));
        }

        $this->forceSettings();

        $this->load->library('ovesio');

        $ovesio_route_resource_type = [
            'catalog/product'         => 'product',
            'catalog/category'        => 'category',
            'catalog/attribute_group' => 'attribute',
            'catalog/attribute'       => 'attribute',
            'catalog/option'          => 'option',
        ];

        $activity_type = $this->request->post['activity_type'];

        if (empty($ovesio_route_resource_type[$this->request->get['from']])) {
            return $this->response->setOutput(json_encode([
                'success' => false,
                'message' => $this->language->get('error_invalid_resource_type'),
            ]));
        }

        $resource_type = $ovesio_route_resource_type[$this->request->get['from']];

        $queue_handler = $this->ovesio->buildQueueHandler(true);

        $debug = [];
        foreach ($this->request->post['selected'] as $resource_id) {
            $queue_handler->processQueue([
                'force_stale'   => true,
                'resource_type' => $resource_type,
                'resource_id'   => $resource_id,
                'activity_type' => $activity_type,
            ]);

            $debug = array_merge($debug, $queue_handler->getDebug());
        }

        $started = 0;
        foreach ($debug as $resource => $activities) {
            foreach ($activities as $at => $activity) {
                if ($activity_type != $at) {
                    continue;
                }

                if ($activity['status'] == 'started' || $activity['code'] == 'new') {
                    $started++;
                }
            }
        }

        $activity_type_map = [
            'generate_content' => $this->language->get('text_generate_content_item'),
            'generate_seo'     => $this->language->get('text_generate_seo_item'),
            'translate'        => $this->language->get('text_translate_item'),
        ];

        $resource_type_map = [
            'product'   => $this->language->get('text_products'),
            'category'  => $this->language->get('text_categories'),
            'attribute' => $this->language->get('text_attributes'),
            'option'    => $this->language->get('text_options'),
        ];

        $r = [
            '{started}'       => $started,
            '{resource_type}' => strtolower($resource_type_map[$resource_type]),
            '{activity_type}' => strtolower($activity_type_map[$activity_type]),
        ];

        $message = str_replace(array_keys($r), array_values($r), $this->language->get('text_resources_request_submitted'));

        $this->response->setOutput(json_encode([
            'success' => true,
            'message' => $message
        ]));
    }

    private function forceSettings()
    {
        if (version_compare(VERSION, '3.0.0.0') >= 0) {
            $module_key = 'module_ovesio';
        } else {
            $module_key = 'ovesio';
        }

        $this->config->set($module_key . '_generate_content_include_disabled', [
            'products'   => true,
            'categories' => true,
        ]);

        $this->config->set($module_key . '_generate_content_for', [
            'products'   => true,
            'categories' => true,
        ]);

        $this->config->set($module_key . '_generate_content_include_stock_0', true);

        $this->config->set($module_key . '_generate_content_when_description_length', [
            'products'   => 999999999,
            'categories' => 999999999,
        ]);

        $this->config->set($module_key . '_generate_seo_include_disabled', [
            'products'   => true,
            'categories' => true,
        ]);

        $this->config->set($module_key . '_generate_seo_for', [
            'products'   => true,
            'categories' => true,
        ]);

        $this->config->set($module_key . '_generate_seo_include_stock_0', true);

        $this->config->set($module_key . '_translate_include_disabled', [
            'products'   => true,
            'categories' => true,
        ]);

        $this->config->set($module_key . '_generate_seo_only_for_action', false);

        $this->config->set($module_key . '_translate_include_stock_0', true);

        $this->config->set($module_key . '_translate_for', [
            'products'   => true,
            'categories' => true,
            'attributes' => true,
            'options'    => true,
        ]);
    }
}