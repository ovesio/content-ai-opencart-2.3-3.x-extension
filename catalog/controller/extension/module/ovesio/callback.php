<?php

use Ovesio\OvesioAI;

require_once(modification(DIR_SYSTEM . '../catalog/model/extension/module/ovesio.php'));
require_once(DIR_SYSTEM . 'library/ovesio/sdk/autoload.php');

class ControllerExtensionModuleOvesioCallback extends Controller
{
    private $output = [];
    private $module_key = 'ovesio';

    public function __construct($registry)
    {
        parent::__construct($registry);

        /**
         * Changes needed for v3
         */
        if (version_compare(VERSION, '3.0.0.0') >= 0) {
            $this->module_key = 'module_ovesio';
        }

        $this->model = new ModelExtensionModuleOvesio($registry);
    }

    public function index()
    {
        if (!$this->config->get($this->module_key . '_status')) {
            return $this->setOutput(['error' => 'Module is disabled']);
        }

        $hash = isset($this->request->get['hash']) ? $this->request->get['hash'] : false;
        if (!$hash || $hash !== $this->config->get($this->module_key . '_hash')) {
            return $this->setOutput(['error' => 'Invalid Hash!']);
        }

        // Takes raw data from the request
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        $data = $this->request->clean($data);

        try {
            $this->handle($data);
        } catch (\Exception $e) {
            $this->setOutput(array_merge($this->output, [
                'error' => $e->getMessage()
            ]));
        }
    }

    protected function handle($data)
    {
        if (!$this->config->get($this->module_key . '_status')) {
            return $this->setOutput(['error' => 'Module is disabled']);
        }

        if (empty($data)) {
            throw new Exception('No data received');
        }

        if (empty($data['content'])) {
            throw new Exception('Data received has empty content');
        }

        list($resource, $identifier) = explode('/', $data['ref']);
        $ovesio_language_code = $data['to'];

        $status = 0;
        if (empty($this->request->get['type'])) {
            throw new Exception('Wrong request');
        }

        $activity_type = $this->request->get['type'];
        if ($activity_type == 'generate_content') {
            $status = $this->config->get($this->module_key . '_generate_content_status');
        } elseif ($activity_type == 'generate_seo' || $activity_type == 'metatags') {
            $status = $this->config->get($this->module_key . '_generate_seo_status');
        } elseif ($activity_type == 'translate') {
            $status = $this->config->get($this->module_key . '_translate_status');
        }

        if (empty($activity_type)) {
            throw new Exception('Data received has empty type');
        }

        if (in_array($resource, ['product', 'category', 'attribute_group', 'option']) && !$status) {
            return $this->setOutput(['error' => 'This operation is disabled!']);
        }

        if (empty($identifier)) {
            throw new Exception('Identifier cannot be empty');
        }

        $this->load->library('ovesio');

        $language_id  = $this->ovesio->getDefaultLanguageId();
        $language_settings = $this->config->get($this->module_key . '_language_settings');
        foreach ($language_settings as $match_language_id => $lang) {
            if (!empty($lang['code']) && $lang['code'] == $ovesio_language_code) {
                $language_id = $match_language_id;
                break;
            }
        }

        $query = $this->db->query("SELECT language_id FROM " . DB_PREFIX . "language WHERE language_id = '" . $language_id . "'");
        if (!$query->row) {
            throw new Exception('Language id "' . $ovesio_language_code . '" not found');
        }

        $data['language_id'] = $query->row['language_id'];

        try {
            if ($activity_type == 'generate_content') {
                $this->generateDescription($resource, $identifier, $data);
            } elseif ($activity_type == 'generate_seo' || $activity_type == 'metatags') {
                $activity_type = 'generate_seo'; // backwords compatibility
                $this->generateSeo($resource, $identifier, $data);
            } elseif ($activity_type == 'translate') {
                $this->translate($resource, $identifier, $data);
            } else {
                throw new Exception('Activity of type "' . $activity_type . '" could not be handled');
            }

        } catch (Throwable $e) {
            // stop updating list
            throw new Exception('Error processing ' . $activity_type . ': ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
        }

        // Update log table
        list($resource, $resource_id) = explode('/', $data['ref']);

        $this->model->addList([
            'resource_type' => $resource,
            'resource_id'   => $resource_id,
            'activity_type' => $activity_type,
            'lang'          => $ovesio_language_code,
            'status'        => 'completed',
            'response'      => json_encode($data['content']),
        ]);

        // $resource, $resource_id
        $queue_handler = $this->ovesio->buildQueueHandler();

        if (!defined('OVESIO_CALLBACK_QUEUE_PROCESSING') || !OVESIO_CALLBACK_QUEUE_PROCESSING) {
            if (empty($this->request->get['manual'])) { // daca nu a fost manual facut request-ul
                $queue_handler->processQueue([
                    'resource_type' => $resource,
                    'resource_id'   => $resource_id,
                    'from_callback' => true,
                ]);
            }
        }

        $output = array_merge($this->output, [
            'success' => true,
            'queue'   => $queue_handler->getDebug(),
            'manual' => !empty($this->request->get['manual']),
        ]);

        $this->setOutput($output);
    }

    protected function generateDescription($resource, $identifier, $data)
    {
        if ($resource == 'product') {
            $this->generateDescriptionProduct($identifier, $data);
        } elseif ($resource == 'category') {
            $this->generateDescriptionCategory($identifier, $data);
        } else {
            throw new Exception('Resource of type "' . $resource . '" could not be handled for description generation');
        }
    }

    protected function generateDescriptionProduct($product_id, $data)
    {
        $product_description['description'] = $data['content']['description'];

        $this->model->updateProductDescription($product_id, $data['language_id'], $product_description);

        $this->seoProduct($product_id, $data['language_id'], $product_description);
    }

    protected function generateDescriptionCategory($category_id, $data)
    {
        $category_description['description'] = $data['content']['description'];

        $this->model->updateCategoryDescription($category_id, $data['language_id'], $category_description);

        $this->seoCategory($category_id, $data['language_id'], $category_description);
    }

    protected function generateSeo($resource, $identifier, $data)
    {
        if ($resource == 'product') {
            $this->metatagsProduct($identifier, $data);
        } elseif ($resource == 'category') {
            $this->metatagsCategory($identifier, $data);
        } else {
            throw new Exception('Resource of type "' . $resource . '" could not be handled for SEO generation');
        }
    }

    protected function metatagsProduct($product_id, $data)
    {
        //seo_h1, seo_h2, seo_h3, meta_title, meta_description, meta_keywords

        $language_id = $data['language_id'];
        $seo = $this->model->getProductForSeo($product_id, $language_id);
        $content = $this->populateCompatibilityContent($data['content']);

        $metatags = [];
        foreach ($content as $key => $value) {
            if (isset($seo['product_description'][$language_id][$key])) {
                $metatags[$key] = $value;
            }
        }

        $this->model->updateProductDescription($product_id, $language_id, $metatags);
    }

    protected function metatagsCategory($category_id, $data)
    {
        //seo_h1, seo_h2, seo_h3, meta_title, meta_description, meta_keywords

        $language_id = $data['language_id'];
        $seo = $this->model->getCategoryForSeo($category_id, $language_id);
        $content = $this->populateCompatibilityContent($data['content']);

        $metatags = [];
        foreach ($content as $key => $value) {
            if (isset($seo['category_description'][$language_id][$key])) {
                $metatags[$key] = $value;
            }
        }

        $this->model->updateCategoryDescription($category_id, $language_id, $metatags);
    }

    protected function translate($resource, $identifier, $data)
    {
        if ($resource == 'product') {
            $this->translateProduct($identifier, $data);
        } elseif ($resource == 'category') {
            $this->translateCategory($identifier, $data);
        } elseif ($resource == 'attribute_group') {
            $this->translateAttributeGroup($identifier, $data);
        } elseif ($resource == 'option') {
            $this->translateOption($identifier, $data);
        } else {
            throw new Exception('Resource of type "' . $resource . '" could not be handled for translation');
        }
    }

    protected function translateProduct($product_id, $data)
    {
        $translate_fields = $this->config->get($this->module_key . '_translate_fields');
        $translate_fields = array_filter($translate_fields['products']);

        $product_description = [];
        $attribute_values = [];

        foreach ($data['content'] as $item) {
            // ? if order matters
            if (strpos($item['key'], 'a-') === 0) {
                $attribute_values[str_replace('a-', '', $item['key'])] = $item['value'];
            } elseif (!empty($translate_fields[$item['key']])) {
                $product_description[str_replace('p-', '', $item['key'])] = $item['value'];
            } elseif (!isset($translate_fields[$item['key']])) {
                $this->output['warnings'][] = 'Unknown key "' . $item['key'] . '"';
            }
        }

        if (!empty($product_description)) {
            $this->model->updateProductDescription($product_id, $data['language_id'], $product_description);
        }

        foreach ($attribute_values as $attribute_id => $text) {
            $this->model->updateAttributeValueDescription($product_id, $attribute_id, $data['language_id'], $text);
        }

        if (!empty($product_description)) {
            $this->seoProduct($product_id, $data['language_id'], $product_description);
        }
    }

    protected function translateCategory($category_id, $data)
    {
        $translate_fields = $this->config->get($this->module_key . '_translate_fields');
        $translate_fields = array_filter($translate_fields['categories']);

        $category_description = [];

        foreach ($data['content'] as $item) {
            if (!empty($translate_fields[$item['key']])) {
                $category_description[$item['key']] = $item['value'];
            } elseif (!isset($translate_fields[$item['key']])) {
                $this->output['warnings'][] = 'Unknown key "' . $item['key'] . '"';
            }
        }

        if (!empty($category_description)) {
            $this->model->updateCategoryDescription($category_id, $data['language_id'], $category_description);

            $this->seoCategory($category_id, $data['language_id'], $category_description);
        }
    }

    protected function translateAttributeGroup($attribute_group_id, $data)
    {
        foreach ($data['content'] as $item) {
            if (strpos($item['key'], 'ag-') === 0) {
                $attribute_group_id = str_replace('ag-', '', $item['key']);
                $this->model->updateAttributeGroupDescription($attribute_group_id, $data['language_id'], $item['value']);
            } elseif (strpos($item['key'], 'a-') === 0) {
                $attribute_id = str_replace('a-', '', $item['key']);
                $this->model->updateAttributeDescription($attribute_id, $data['language_id'], $item['value']);
            } else {
                $this->output['warnings'][] = 'Unknown key "' . $item['key'] . '"';
            }
        }
    }

    protected function translateOption($option_id, $data)
    {
        foreach ($data['content'] as $item) {
            if (strpos($item['key'], 'o-') === 0) {
                $option_id = str_replace('o-', '', $item['key']);
                $this->model->updateOptionDescription($option_id, $data['language_id'], $item['value']);
            } elseif (strpos($item['key'], 'ov-') === 0) {
                $option_value_id = str_replace('ov-', '', $item['key']);
                $this->model->updateOptionValueDescription($option_value_id, $data['language_id'], $item['value']);
            } else {
                $this->output['warnings'][] = 'Unknown key "' . $item['key'] . '"';
            }
        }
    }

    protected function populateCompatibilityContent($content)
    {
        // General Mappings
        $content['meta_keyword'] = $content['meta_keywords'];

        // Complete SEO module mappings
        $content['image_title'] = $content['seo_h1'];
        $content['image_alt'] = $content['seo_h2'];
        $content['seo_keyword'] = $content['meta_keywords'];

        // SEO Mega KIT PLUS mappings
        //$content['meta_title_ag'] = $data['meta_title'];
        $content['smp_h1_title'] = $content['seo_h1'];
        $content['smp_alt_images'] = $content['seo_h1'];
        $content['smp_title_images'] = $content['seo_h2'];

        return $content;
    }

    /**
     * Internal SEO methods - compatible with Complete SEO module
     *
     */
    private function seoProduct($product_id, $language_id, $product_description)
    {
        if (1 || !$this->config->get('module_seo_enabled')) {
            return;
        }

        $data = $this->model->getProductForSeo($product_id, $language_id);
        $data['product_description'][$language_id] = array_merge($data['product_description'][$language_id], $product_description);

        // discard not translated fields to re-compose them with seo based on new translation
        $translate_fields = $this->config->get($this->module_key . '_translate_fields');
        $translate_fields = array_filter($translate_fields['products']);

        foreach ($data['product_description'][$language_id] as $field => $value) {
            if (in_array($field, ['product_id', 'language_id', 'seo_keyword'])) continue;

            if (empty($translate_fields[$field])) {
                $data['product_description'][$language_id][$field] = '';
            }
        }

        $this->load->controller('extension/module/complete_seo/event/product/after_model_product_edit', 'editProduct', [$product_id, $data], $product_id, true);
    }

    private function seoCategory($category_id, $language_id, $category_description)
    {
        if (1 || !$this->config->get('module_seo_enabled')) {
            return;
        }

        $data = $this->model->getCategoryForSeo($category_id, $language_id);
        $data['category_description'][$language_id] = array_merge($data['category_description'][$language_id], $category_description);

        // discard not translated fields to re-compose them with seo based on new translation
        $translate_fields = $this->config->get($this->module_key . '_translate_fields');
        $translate_fields = array_filter($translate_fields['categories']);

        foreach ($data['category_description'][$language_id] as $field => $value) {
            if (in_array($field, ['category_id', 'language_id', '_seo_keyword'])) continue;

            if (empty($translate_fields[$field])) {
                $data['category_description'][$language_id][$field] = '';
            }
        }

        $this->load->controller('extension/module/complete_seo/event/category/after_model_category_edit', 'editCategory', [$category_id, $data], $category_id, true);
    }

    /**
     * Custom response
     */
    private function setOutput($response)
    {
        if (is_array($response)) {
            $response = json_encode($response);

            $this->response->addHeader('Content-Type: application/json');
        }

        $this->response->setOutput($response);
    }

    /**
     * Called from activity list, by refresh click
     */
    public function updateActivityStatus()
    {
        $hash = isset($this->request->get['hash']) ? $this->request->get['hash'] : false;
        if (!$hash || $hash !== $this->config->get($this->module_key . '_hash')) {
            return $this->setOutput(['error' => 'Invalid Hash!']);
        }

        $this->load->language('extension/module/ovesio');

        $activity_id = $this->request->post['activity_id'];

        $activity = $this->model->getActivityById($activity_id);

        $payload = null;

        $api_url   = $this->config->get($this->module_key . '_api_url');
        $api_token = $this->config->get($this->module_key . '_api_token');

        $api = new OvesioAI($api_token, $api_url);

        try {
            if ($activity['activity_type'] == 'generate_content') {
                $response = $api->generateDescription()->status($activity['activity_id']);
                $payload = isset($response->data) ? $response->data : null;
            } elseif ($activity['activity_type'] == 'generate_seo') {
                $response = $api->generateSeo()->status($activity['activity_id']);
                $payload = isset($response->data) ? $response->data : null;
            } elseif ($activity['activity_type'] == 'translate') {
                $response = $api->translate()->status($activity['activity_id']);

                if ($response->success) {
                    foreach ($response->data->data as $item) {
                        if ($item->to == $activity['lang']) {
                            $payload = $item;
                            $payload->ref = $response->data->ref;
                            break;
                        }
                    }
                }
            }
        } catch (Throwable $e) {}

        if (empty($payload)) {
            return $this->response->setOutput(json_encode([
                'success' => false,
                'error'   => $this->language->get('error_fetching_status')
            ]));
        }

        $payload = json_decode(json_encode($payload), true);

        if ($payload && $payload['status'] == 'completed') {
            // set data on php://input then call handle
            $this->request->get['type'] = $activity['activity_type'];
            $this->request->get['manual'] = true;
            $this->request->server['REQUEST_METHOD'] = 'POST';

            $payload['to'] = $payload['to'] ?: 'auto';
            $payload['content'] = $payload['content'] ?? $payload['data'];
            unset($payload['data']);

            $payload = $this->request->clean($payload);

            $this->handle($payload);
        }

        $activity = $this->model->getActivityById($activity_id);

        $status_types = [
            'started'   => ['text' => $this->language->get('text_processing'), 'class' => 'ov-status-info'],
            'completed' => ['text' => $this->language->get('text_completed'), 'class' => 'ov-status-success'],
            'error'     => ['text' => $this->language->get('text_error'), 'class' => 'ov-status-danger']
        ];

        $status_display = $status_types[$activity['status']];

        return $this->response->setOutput(json_encode([
            'success'        => true,
            'status'         => $activity['status'],
            'status_display' => $status_display,
            'updated_at'     => $activity['updated_at']
        ]));
    }
}
