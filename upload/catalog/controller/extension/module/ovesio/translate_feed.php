<?php

set_time_limit(180);
ini_set('memory_limit','4G');

require_once(modification($_SERVER['DOCUMENT_ROOT'] . '/catalog/model/extension/module/ovesio.php'));

/**
 * Feed serving entire Catalog to be translated by difference
 */
class ControllerExtensionModuleOvesioTranslateFeed extends Controller
{
    private $output = [
        'from' => 'ro',
        'delta_mode' => true,
        'to' => [],
        'conditions' => [],
        'data' => []
    ];

    private $module_key = 'ovesio';

    public function __construct($registry)
    {
        parent::__construct($registry);

        /**
         * Changes needed for v3
         */
        if(version_compare(VERSION, '3.0.0.0') >= 0) {
            $this->module_key = 'module_ovesio';
        }

        $this->model = new ModelExtensionModuleOvesio($registry);

        $from_language_id = $this->config->get($this->module_key . '_catalog_language_id');
        $this->model->setLanguageId($from_language_id);
    }

    public function index()
    {
        if (!$this->config->get($this->module_key . '_status')) {
            return $this->setOutput(['error' => 'Module is disabled!']);
        }

        if(!$this->config->get($this->module_key . '_translation_status')) {
            return $this->setOutput(['error' => 'Translation status is disabled!']);
        }

        $hash = isset($this->request->get['hash']) ? $this->request->get['hash'] : false;
        if (!$hash || $hash !== $this->config->get($this->module_key . '_hash')) {
            if (ENVIRONMENT != 'development') {
                return $this->setOutput(['error' => 'Invalid Hash!']);
            }
        }

        $from_language_id = $this->config->get($this->module_key . '_catalog_language_id');
        $languages = $this->config->get($this->module_key . '_language_match');
        $this->output['from'] = $languages[$from_language_id]['code'];

        foreach ($languages as $language) {
            if (empty($language['status']) || $language['code'] === $this->output['from']) continue;

            $this->output['to'][] = $language['code'];

            //apply conditions in case the from language is not the default language
            if($this->output['from'] != $languages[$language['from_language_id']]['code'])
            {
                $this->output['conditions'][$language['code']] = $languages[$language['from_language_id']]['code'];
            }
        }

        //TODO: Feed should be created based on list table !!!

        // call the feed
        $type = $this->request->get['type'] ?? null;
        if (!empty($type) && method_exists($this, $type)) {
            $this->{$type}();
        }

        // html_entity_decode content
        foreach ($this->output['data'] as $i => $data) {
            foreach ($data['content'] as $j => $content) {
                if (!empty($content['context'])) {
                    $this->output['data'][$i]['content'][$j]['context'] = html_entity_decode($content['context'], ENT_QUOTES, 'UTF-8');
                }

                $this->output['data'][$i]['content'][$j]['value'] = html_entity_decode($content['value'], ENT_QUOTES, 'UTF-8');
            }
        }

        // ! TESTS:
        // $this->request->post = $this->output['data'][0];
        // $this->request->post['to'] = 'en';
        // $this->load->controller('extension/module/ovesio/translate/callback');
        // vdd($this->response->getOutput());

        $this->setOutput($this->output);
    }

    protected function category()
    {
        $categories = $this->model->getCategories(
            [],
            $this->config->get($this->module_key . '_send_disabled')
        );

        $translate_fields = (array)$this->config->get($this->module_key . '_translate_fields');

        foreach ($categories as $i => $category) {
            $push = [
                'ref' => 'category/' . $category['category_id'],
                'content' => []
            ];

            foreach ($translate_fields['category'] as $key => $send) {
                if (!$send || empty($category[$key])) continue;

                $push['content'][] = [
                    'key' => $key,
                    'value' => $category[$key]
                ];
            }

            if (!empty($push['content'])) {
                $this->output['data'][] = $push;
            }
        }
    }

    protected function product()
    {
        $products = $this->model->getProducts(
            [],
            $this->config->get($this->module_key . '_send_disabled'),
            $this->config->get($this->module_key . '_send_stock_0')
        );

        // chunk get attributes based on product_id
        $attributes = $this->model->getAttributes();
        $attributes = array_column($attributes, 'name', 'attribute_id');

        $product_attributes = $this->model->getProductsAttributes();

        $translate_fields = (array)$this->config->get($this->module_key . '_translate_fields');

        foreach ($products as $i => $product) {
            $push = [
                'ref' => 'product/' . $product['product_id'],
                'content' => []
            ];

            foreach ($translate_fields['product'] as $key => $send) {
                if (!$send || empty($product[$key])) continue;

                $push['content'][] = [
                    'key' => $key,
                    'value' => $product[$key]
                ];
            }

            foreach (($product_attributes[$product['product_id']] ?? []) as $attribute_id => $attribute_text) {
                $push['content'][] = [
                    'key' => 'a-' . $attribute_id,
                    'value' => $attribute_text,
                    'context' => $attributes[$attribute_id]
                ];
            }

            if (!empty($push['content'])) {
                $this->output['data'][] = $push;
            }
        }
    }

    protected function attribute()
    {
        $attribute_groups = $this->model->getAttributeGroups();
        $attributes = [];

        $a = $this->model->getAttributes();
        foreach ($a as $a) {
            $attributes[$a['attribute_group_id']][] = $a;
        }

        foreach ($attribute_groups as $i => $attribute_group) {
            $push = [
                'ref' => 'attribute_group/' . $attribute_group['attribute_group_id'],
                'content' => []
            ];

            $push['content'][] = [
                'key' => 'ag-' . $attribute_group['attribute_group_id'],
                'value' => $attribute_group['name'],
            ];

            foreach (($attributes[$attribute_group['attribute_group_id']] ?? []) as $attribute) {
                $push['content'][] = [
                    'key' => 'a-' . $attribute['attribute_id'],
                    'context' => $attribute_group['name'],
                    'value' => $attribute['name'],
                ];
            }

            if (!empty($push['content'])) {
                $this->output['data'][] = $push;
            }
        }
    }

    protected function option()
    {
        $options = $this->model->getOptions();
        $option_values = $this->model->getOptionValues();

        $_option_values = [];
        foreach ($option_values as $option_value) {
            $_option_values[$option_value['option_id']][] = $option_value;
        }

        $option_values = $_option_values;
        unset($_option_values);

        foreach ($options as $option) {
            $push = [
                'ref' => 'option/' . $option['option_id'],
                'content' => []
            ];

            $push['content'][] = [
                'key' => 'o-' . $option['option_id'],
                'value' => $option['name'],
            ];

            foreach (($option_values[$option['option_id']] ?? []) as $option_value) {
                $push['content'][] = [
                    'key' => 'ov-' . $option_value['option_value_id'],
                    'context' => $option['name'],
                    'value' => $option_value['name'],
                ];
            }

            if (!empty($push['content'])) {
                $this->output['data'][] = $push;
            }
        }
    }

    /**
     * Custom response
     */
    private function setOutput($response)
    {
        if(is_array($response))
        {
            $response = json_encode($response);

            $this->response->addHeader('Content-Type: application/json');
        }

        $this->response->setOutput($response);
    }
}