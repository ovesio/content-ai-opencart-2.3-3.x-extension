<?php

use Ovesio\OvesioAI;

/**
 * Name: Ovesio
 * Url: https://ovesio.com/
 * Author: Aweb Design SRL
 * Version: 2.2
 */

require_once(DIR_SYSTEM . '/library/ovesio/sdk/autoload.php');

class ControllerExtensionModuleOvesio extends Controller
{
    private $token = 'token';
    private $module_key = 'ovesio';
    private $event_model = 'extension/event';

    public function __construct($registry)
    {
        parent::__construct($registry);

        /**
         * Changes needed for v3
         */
        if(version_compare(VERSION, '3.0.0.0') >= 0) {
            $this->token = 'user_token';
            $this->module_key = 'module_ovesio';
            $this->event_model = 'setting/event';
        }

		$this->load->model('setting/setting');
    }

	public function index() {
		$data = $this->load->language('extension/module/ovesio');

		$this->document->setTitle(strip_tags($this->language->get('heading_title')));

        $settings = $this->model_setting_setting->getSetting($this->module_key);

        foreach ($settings as $key => $value) {
            $data[str_replace($this->module_key . '_', '', $key)] = $value;
        }

        $data['url_connect']    = $this->url->link('extension/module/ovesio/connect', $this->tokenQs());
        $data['url_disconnect'] = $this->url->link('extension/module/ovesio/disconnect', $this->tokenQs());
        $data['url_list']       = $this->url->link('extension/module/ovesio/activityList', $this->tokenQs());
        $data['url_callback']   = HTTPS_CATALOG . 'index.php?route=extension/module/ovesio/callback&hash=' . $data['hash'];
        $data['url_cron']       = HTTPS_CATALOG . 'index.php?route=extension/module/ovesio/cronjob&hash=' . $data['hash'];

        $client = $this->buildClient();

        $data['errors'] = [];
        try {
            $client->languages()->list();
        } catch (Exception $e) {
            if ($this->config->get($this->module_key . '_api_token')) {
                $data['errors'][] = $e->getMessage();
            }
        }

        if ($this->config->get($this->module_key . '_status')) {
            $data['connected'] = true;
        } else {
            $data['connected'] = false;
        }

        $data['connect_form']          = $this->view('extension/module/ovesio_connect_form', $data);
        $data['generate_content_card'] = $this->generateContentCard(true);
        $data['generate_seo_card']     = $this->generateSeoCard(true);
        $data['translate_card']        = $this->translateCard(true);

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->view('extension/module/ovesio', $data));
	}

    public function install()
    {
        $this->load->model('extension/module/ovesio');
        $this->model_extension_module_ovesio->install();

        $this->load->model($this->event_model);
        $model_name = 'model_' . str_replace('/', '_', $this->event_model);
        $model =  $this->$model_name;

        if(version_compare(VERSION, '3.0.0.0') >= 0) {
            $model->deleteEventByCode($this->module_key);
        } else {
            $model->deleteEvent($this->module_key);
        }

        $events = [
            'admin/model/catalog/category/addCategory/after'               => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/category/editCategory/after'              => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/product/addProduct/after'                 => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/product/editProduct/after'                => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/attribute/addAttribute/after'             => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/attribute/editAttribute/after'            => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/attribute_group/addAttributeGroup/after'  => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/attribute_group/editAttributeGroup/after' => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/option/addOption/after'                   => 'extension/module/ovesio/event/trigger',
            'admin/model/catalog/option/editOption/after'                  => 'extension/module/ovesio/event/trigger',
        ];

        foreach ($events as $key => $value) {
            $model->addEvent($this->module_key, $key, $value);
        }

        $hash = md5(uniqid(rand(), true));

        $config_language = $this->config->get('config_language');

        $defaults = [];
        $defaults['status']           = 0;
        $defaults['hash']             = $hash;
        $defaults['api_url']          = 'https://api.ovesio.com/v1/';
        $defaults['api_token']        = '';
        $defaults['default_language'] = substr($config_language, 0, 2);

        $defaults['generate_content_status']          = '';
        $defaults['generate_content_include_stock_0'] = 1;
        $defaults['generate_content_live_update']     = '';
        $defaults['generate_content_workflow']        = '';
        $defaults['generate_content_when_description_length'] = [
            'products'   => 500,
            'categories' => 300,
        ];
        $defaults['generate_content_include_disabled'] = [
            'products'   => 1,
            'categories' => 1,
        ];
        $defaults['generate_content_for'] = [
            'products'   => 1,
            'categories' => 1,
        ];

        $defaults['generate_seo_status']          = '';
        $defaults['generate_seo_only_for_action'] = 1;
        $defaults['generate_seo_include_stock_0'] = 1;
        $defaults['generate_seo_live_update']     = '';
        $defaults['generate_seo_workflow']        = '';
        $defaults['generate_seo_for'] = [
            'products'   => 1,
            'categories' => 1,
        ];
        $defaults['generate_seo_include_disabled'] = [
            'products'   => 1,
            'categories' => 1,
        ];

        $defaults['translate_status']          = '';
        $defaults['translate_include_stock_0'] = 1;
        $defaults['translate_workflow']        = '';
        $defaults['translate_for'] = [
            'products'   => 1,
            'categories' => 1,
            'attributes' => 1,
            'options'    => 1,
        ];
        $defaults['translate_include_disabled'] = [
            'products'   => 1,
            'categories' => 1,
        ];
        $defaults['translate_fields'] = [
            'products'   => [
                'name'             => 1,
                'description'      => 1,
                'tag'              => 1,
                'meta_title'       => 1,
                'meta_description' => 1,
                'meta_keyword'     => 1,
            ],
            'categories' => [
                'name'             => 1,
                'description'      => 1,
                'meta_title'       => 1,
                'meta_description' => 1,
                'meta_keyword'     => 1,
            ],
        ];

        $settings = [];
        foreach ($defaults as $key => $value) {
            $settings[$this->module_key . '_' . $key] = $value;
        }

        $this->model_setting_setting->editSetting($this->module_key, $settings);
    }

    /**
     * Custom template view
     */
    private function view($template, $data) {
        if(version_compare(VERSION, '3.0.0.0') >= 0) {
            $this->config->set('template_engine', 'template');
            $view = $this->load->view($template, $data);
            $this->config->set('template_engine', 'twig');
        } else {
            $view = $this->load->view($template, $data);
        }

        return $view;
    }

    private function tokenQs()
    {
        return $this->token .'=' . $this->session->data[$this->token];
    }

    public function connect()
    {
        $this->load->language('extension/module/ovesio');

        $api_url   = $this->request->post['api_url'];
        $api_token = $this->request->post['api_token'];

        $default_language = null;
        if (isset($this->request->post['default_language'])) {
            $default_language = $this->request->post['default_language'];
        }

        $settings = $this->model_setting_setting->getSetting($this->module_key);

        if (!$default_language) { // step 1
            $settings[$this->module_key . '_' . 'api_url']   = $api_url;
            $settings[$this->module_key . '_' . 'api_token'] = $api_token;
        } else { // step 2
            $settings[$this->module_key . '_' . 'default_language'] = $default_language;
            $settings[$this->module_key . '_' . 'status']           = 1;
        }

        $this->model_setting_setting->editSetting($this->module_key, $settings);

        $json = [];
        if (!$default_language) { // step 1
            $client = $this->buildClient($api_url, $api_token);

            try {
                $this->load->library('ovesio');

                $response = $client->languages()->list();

                $json = [
                    'success'   => true,
                    'message'   => $this->language->get('text_connection_valid'),
                    'languages' => $response->data,
                ];
            } catch (Exception $e) {
                $json = [
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        } else {
            $json = [
                'success'   => true,
                'connected' => true,
                'message'   => $this->language->get('text_connection_success'),
            ];
        }

        $this->response->setOutput(json_encode($json));
    }

    public function disconnect()
    {
        $this->load->language('extension/module/ovesio');

        $this->model_setting_setting->editSettingValue($this->module_key, $this->module_key . '_status', '');

        $json = [
            'success' => true,
            'message' => $this->language->get('text_disconnection_success'),
        ];

        $this->response->setOutput(json_encode($json));
    }

    public function generateContentCard($return)
    {
        $data = $this->load->language('extension/module/ovesio');

        $data['generate_content_status']                  = $this->config->get($this->module_key . '_generate_content_status');
        $data['generate_content_for']                     = array_filter((array)$this->config->get($this->module_key . '_generate_content_for'));
        $data['generate_content_when_description_length'] = array_filter((array)$this->config->get($this->module_key . '_generate_content_when_description_length'));
        $data['generate_content_include_disabled']        = array_filter((array)$this->config->get($this->module_key . '_generate_content_include_disabled'));
        $data['generate_content_include_stock_0']         = $this->config->get($this->module_key . '_generate_content_include_stock_0');
        $data['generate_content_live_update']             = $this->config->get($this->module_key . '_generate_content_live_update');
        $data['generate_content_workflow']                = $this->config->get($this->module_key . '_generate_content_workflow');

        $data['url_edit'] = $this->url->link('extension/module/ovesio/generateContentForm', $this->tokenQs());

        $data['generate_content_sumary'] = [];
        foreach ($data['generate_content_for'] as $resource => $value) {
            $data['generate_content_sumary'][$resource] = trim(sprintf(
                $this->language->get('text_generate_content_sumary'),
                $this->language->get('text_' . $resource),
                !empty($data['generate_content_include_disabled'][$resource]) ? $this->language->get('text_including_disabled') : $this->language->get('text_excluding_disabled'),
                $data['generate_content_when_description_length'][$resource]
            ), ':');
        }

        $html = $this->view('extension/module/ovesio_generate_content_card', $data);

        if ($return) {
            return $html;
        }

        $this->response->setOutput($html);
    }

    public function generateContentForm()
    {
        $data = $this->load->language('extension/module/ovesio');

        $settings = $this->model_setting_setting->getSetting($this->module_key);
        foreach ($settings as $key => $value) {
            $data[str_replace($this->module_key . '_', '', $key)] = $value;
        }

        $data['resources_list'] = [
            'products'   => $this->language->get('text_products'),
            'categories' => $this->language->get('text_categories'),
        ];

        $data['workflows_list'] = $this->getWorkflows('generate_description');

        $data['action'] = $this->url->link('extension/module/ovesio/generateContentFormSave', $this->tokenQs());

        if ($data['workflows_list']) {
            $html = $this->view('extension/module/ovesio_generate_content_form', $data);
        } else {
            $html = $this->language->get('text_api_error');
        }

        $this->response->setOutput($html);
    }

    public function generateContentFormSave()
    {
        $this->load->language('extension/module/ovesio');

        $post = $this->request->post;

        if (!isset($post['generate_content_when_description_length'])) {
            $post['generate_content_when_description_length'] = [];
        }

        if (!empty($post['generate_content_workflow'])) {
            $temp = explode('@', $post['generate_content_workflow']);
            $post['generate_content_workflow'] = [
                'id'   => $temp[0],
                'name' => isset($temp[1]) ? $temp[1] : '',
            ];
        }

        $errors = [];
        foreach ($post['generate_content_when_description_length'] as $key => $value) {
            if (empty($value) || !is_numeric($value) || $value < 0) {
                $errors['generate_content_when_description_length.' . $key] = $this->language->get('error_invalid_number');
            }
        }

        if ($errors) {
            $json = [
                'success' => false,
                'errors'  => $errors,
            ];
        } else {
            $settings = $this->model_setting_setting->getSetting($this->module_key);
            foreach ($post as $key => $value) {
                $settings[$this->module_key . '_' . $key] = $value;
                $this->config->set($this->module_key . '_' . $key, $value);
            }

            $this->model_setting_setting->editSetting($this->module_key, $settings);

            $json = [
                'success'   => true,
                'message'   => $this->language->get('text_settings_saved'),
                'card_html' => $this->generateContentCard(true),
            ];
        }

        if ($errors) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
        }

        $this->response->setOutput(json_encode($json));
    }

    public function generateSeoCard($return)
    {
        $data = $this->load->language('extension/module/ovesio');

        $data['generate_seo_status']                  = $this->config->get($this->module_key . '_generate_seo_status');
        $data['generate_seo_for']                     = array_filter((array)$this->config->get($this->module_key . '_generate_seo_for'));
        $data['generate_seo_include_disabled']        = array_filter((array)$this->config->get($this->module_key . '_generate_seo_include_disabled'));
        $data['generate_seo_include_stock_0']         = $this->config->get($this->module_key . '_generate_seo_include_stock_0');
        $data['generate_seo_live_update']             = $this->config->get($this->module_key . '_generate_seo_live_update');
        $data['generate_seo_workflow']                = $this->config->get($this->module_key . '_generate_seo_workflow');

        $data['url_edit'] = $this->url->link('extension/module/ovesio/generateSeoForm', $this->tokenQs());

        $data['generate_seo_sumary'] = [];
        foreach ($data['generate_seo_for'] as $resource => $value) {
            $data['generate_seo_sumary'][$resource] = trim(sprintf(
                $this->language->get('text_generate_seo_sumary'),
                $this->language->get('text_' . $resource),
                !empty($data['generate_seo_include_disabled'][$resource]) ? $this->language->get('text_including_disabled') : $this->language->get('text_excluding_disabled'),
            ), ':');
        }

        $html = $this->view('extension/module/ovesio_generate_seo_card', $data);

        if ($return) {
            return $html;
        }

        $this->response->setOutput($html);
    }

    public function generateSeoForm()
    {
        $data = $this->load->language('extension/module/ovesio');

        $settings = $this->model_setting_setting->getSetting($this->module_key);
        foreach ($settings as $key => $value) {
            $data[str_replace($this->module_key . '_', '', $key)] = $value;
        }

        $data['resources_list'] = [
            'products'   => $this->language->get('text_products'),
            'categories' => $this->language->get('text_categories'),
        ];

        $data['workflows_list'] = $this->getWorkflows('generate_seo');

        $data['action'] = $this->url->link('extension/module/ovesio/generateSeoFormSave', $this->tokenQs());

        if ($data['workflows_list']) {
            $html = $this->view('extension/module/ovesio_generate_seo_form', $data);
        } else {
            $html = $this->language->get('text_api_error');
        }

        $this->response->setOutput($html);
    }

    public function generateSeoFormSave()
    {
        $this->load->language('extension/module/ovesio');

        $post = $this->request->post;

        if (!empty($post['generate_seo_workflow'])) {
            $temp = explode('@', $post['generate_seo_workflow']);
            $post['generate_seo_workflow'] = [
                'id'   => $temp[0],
                'name' => isset($temp[1]) ? $temp[1] : '',
            ];
        }

        $errors = [];

        if ($errors) {
            $json = [
                'success' => false,
                'errors'  => $errors,
            ];
        } else {
            $settings = $this->model_setting_setting->getSetting($this->module_key);
            foreach ($post as $key => $value) {
                $settings[$this->module_key . '_' . $key] = $value;
                $this->config->set($this->module_key . '_' . $key, $value);
            }

            $this->model_setting_setting->editSetting($this->module_key, $settings);

            $json = [
                'success'   => true,
                'message'   => $this->language->get('text_seo_settings_saved'),
                'card_html' => $this->generateSeoCard(true),
            ];
        }

        if ($errors) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
        }

        $this->response->setOutput(json_encode($json));
    }

    public function translateCard($return)
    {
        $data = $this->load->language('extension/module/ovesio');

        $data['translate_status']           = $this->config->get($this->module_key . '_translate_status');
        $data['translate_include_disabled'] = array_filter((array)$this->config->get($this->module_key . '_translate_include_disabled'));
        $data['translate_include_stock_0']  = $this->config->get($this->module_key . '_translate_include_stock_0');
        $data['translate_live_update']      = $this->config->get($this->module_key . '_translate_live_update');
        $data['translate_workflow']         = $this->config->get($this->module_key . '_translate_workflow');
        $data['translate_fields']           = array_filter((array)$this->config->get($this->module_key . '_translate_fields'));
        $data['language_settings']          = array_filter((array)$this->config->get($this->module_key . '_language_settings'));

        $translate_for = $this->config->get($this->module_key . '_translate_for');
        $data['translate_for'] = [];

        foreach ($data['translate_fields'] as $resource => $fields) {
            if (empty($translate_for[$resource])) {
                continue;
            }

            $fields = implode(', ', array_keys(array_filter($fields)));

            if ($fields) {
                $data['translate_for'][$resource] = $fields ? 1 : 0;
                $data['translate_fields'][$resource] = $fields;
            }
        }

        $this->load->model('localisation/language');

        $languages = $this->model_localisation_language->getLanguages();
        $languages = array_column($languages, null, 'language_id');

        foreach ($data['language_settings'] as $language_id => $setting) {
            if (!isset($languages[$language_id])) { // lang local sters
                unset($data['language_settings'][$language_id]);
                continue;
            } else {
                $language = $languages[$language_id];
            }
            $data['language_settings'][$language_id]['name'] = $language['name'];
            $data['language_settings'][$language_id]['flag'] = 'language/' . $language['code'] . '/' . $language['code'] . '.png';
        }

        $data['url_edit'] = $this->url->link('extension/module/ovesio/translateForm', $this->tokenQs());

        $data['translate_sumary'] = [];
        foreach ($data['translate_for'] as $resource => $value) {
            $data['translate_sumary'][$resource] = trim(sprintf(
                $this->language->get('text_translate_sumary'),
                $this->language->get('text_' . $resource),
                !empty($data['translate_include_disabled'][$resource]) ? $this->language->get('text_including_disabled') : $this->language->get('text_excluding_disabled'),
                $data['translate_fields'][$resource]
            ), ':');
        }

        if (!empty($translate_for['attributes'])) {
            $data['translate_for']['attributes']    = 1;
            $data['translate_sumary']['attributes'] = $this->language->get('text_attributes');
        }

        if (!empty($translate_for['options'])) {
            $data['translate_for']['options']    = 1;
            $data['translate_sumary']['options'] = $this->language->get('text_options');
        }

        $html = $this->view('extension/module/ovesio_translate_card', $data);

        if ($return) {
            return $html;
        }

        $this->response->setOutput($html);
    }

    public function translateForm()
    {
        $data = $this->load->language('extension/module/ovesio');

        $settings = $this->model_setting_setting->getSetting($this->module_key);
        foreach ($settings as $key => $value) {
            $data[str_replace($this->module_key . '_', '', $key)] = $value;
        }

        $default_language = $data['default_language'];

        $data['language_settings'] = array_filter((array)$this->config->get($this->module_key . '_language_settings'));

        $data['resources_list'] = [
            'products'   => $this->language->get('text_products'),
            'categories' => $this->language->get('text_categories'),
            'attributes' => $this->language->get('text_attributes'),
            'options'    => $this->language->get('text_options'),
        ];

        $data['translate_fields_schema']['products'] = [
            'name'             => $this->language->get('text_name'),
            'description'      => $this->language->get('text_description'),
            'tag'              => $this->language->get('text_tag'),
            'meta_title'       => $this->language->get('text_meta_title'),
            'meta_description' => $this->language->get('text_meta_description'),
            'meta_keyword'     => $this->language->get('text_meta_keyword'),
        ];

        $data['translate_fields_schema']['categories'] = [
            'name'             => $this->language->get('text_name'),
            'description'      => $this->language->get('text_description'),
            'meta_title'       => $this->language->get('text_meta_title'),
            'meta_description' => $this->language->get('text_meta_description'),
            'meta_keyword'     => $this->language->get('text_meta_keyword'),
        ];

        $data['translate_fields_schema']['attributes'] = [
            'name'  => $this->language->get('text_name'),
            'value' => $this->language->get('text_value'),
        ];

        $data['translate_fields_schema']['options'] = [
            'name'  => $this->language->get('text_name'),
            'value' => $this->language->get('text_value'),
        ];

        $this->load->model('localisation/language');

        $system_languages = $this->model_localisation_language->getLanguages();

        usort($system_languages, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $data['system_languages'] = array_column($system_languages, null, 'language_id');

        $client = $this->buildClient();

        $response = $client->languages()->list();
        $response = json_decode(json_encode($response), true);

        $ovesio_languages = [];
        if (!empty($response['data'])) {
            $ovesio_languages = $response['data'];
            $ovesio_languages = array_column($ovesio_languages, null, 'code');
        }

        $data['ovesio_languages'] = $ovesio_languages;

        foreach ($system_languages as $language) {
            $local_code = strtolower(substr($language['code'], 0, 2));
            $ovesio_code = null;

            if (isset($ovesio_languages[$language['code']])) {
                $ovesio_code = $language['code'];
            } elseif (isset($ovesio_languages[$local_code])) {
                $ovesio_code = $local_code;
            }

            if ($ovesio_code == $default_language) {
                unset($data['system_languages'][$language['language_id']]);
                continue;
            }

            if (empty($data['language_settings'][$language['language_id']])) {
                $data['language_settings'][$language['language_id']] = [
                    'name'           => $language['name'],
                    'code'           => $ovesio_code,
                    'translate'      => 0,
                    'translate_from' => $default_language,
                ];
            }
        }

        $data['workflows_list'] = $this->getWorkflows('translate');

        $data['action'] = $this->url->link('extension/module/ovesio/translateFormSave', $this->tokenQs());

        if ($data['workflows_list'] && $data['ovesio_languages']) {
            $html = $this->view('extension/module/ovesio_translate_form', $data);
        } else {
            $html = $this->language->get('text_api_error');
        }

        $this->response->setOutput($html);
    }

    public function translateFormSave()
    {
        $this->load->language('extension/module/ovesio');

        $post = $this->request->post;

        if (!empty($post['translate_workflow'])) {
            $temp = explode('@', $post['translate_workflow']);
            $post['translate_workflow'] = [
                'id'   => $temp[0],
                'name' => isset($temp[1]) ? $temp[1] : '',
            ];
        }

        $default_language = $this->config->get($this->module_key . '_default_language');

        $errors = [];

        if (!isset($post['language_settings'])) {
            $post['language_settings'] = [];
        }

        foreach ($post['language_settings'] as $key => $lang) {
            if (empty($lang['code'])) {
                $errors['language_settings.' . $key . '.code'] = $this->language->get('error_code');
            }

            if ($lang['code'] == $lang['translate_from']) {
                $errors['language_settings.' . $key . '.translate_from'] = $this->language->get('error_from_language');
            }

            if (empty($post['language_settings'][$lang['translate_from']]['translate'])) {
                if ($default_language != $lang['translate_from']) {
                    $errors['language_settings.' . $key . '.translate_from'] = $this->language->get('error_from_language1');
                }
            }
        }

        if ($errors) {
            $json = [
                'success' => false,
                'errors'  => $errors,
            ];
        } else {
            $settings = $this->model_setting_setting->getSetting($this->module_key);
            foreach ($post as $key => $value) {
                $settings[$this->module_key . '_' . $key] = $value;
                $this->config->set($this->module_key . '_' . $key, $value);
            }

            $this->model_setting_setting->editSetting($this->module_key, $settings);

            $json = [
                'success'   => true,
                'message'   => $this->language->get('text_translate_settings_saved'),
                'card_html' => $this->translateCard(true),
            ];
        }

        if ($errors) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
        }

        $this->response->setOutput(json_encode($json));
    }

    /**
     * Ovesio Activity List
     */
    public function activityList()
    {
        $data = $this->load->language('extension/module/ovesio');

        $this->document->setTitle(strip_tags($this->language->get('text_activity_list')));

        $this->load->model('extension/module/ovesio');

        $page = isset($this->request->get['page']) ? (int)$this->request->get['page'] : 1;
        $page = max($page, 1);
        $limit = 20;

        $filters['page']  = $page;
        $filters['limit'] = $limit;
        $filters['resource_name'] = isset($this->request->get['resource_name']) ? $this->request->get['resource_name'] : '';
        $filters['resource_type'] = isset($this->request->get['resource_type']) ? $this->request->get['resource_type'] : '';
        $filters['resource_id']   = isset($this->request->get['resource_id']) ? $this->request->get['resource_id'] : '';
        $filters['status']        = isset($this->request->get['status']) ? $this->request->get['status'] : '';
        $filters['activity_type'] = isset($this->request->get['activity_type']) ? $this->request->get['activity_type'] : '';
        $filters['language']      = isset($this->request->get['language']) ? $this->request->get['language'] : '';
        $filters['date']          = isset($this->request->get['date']) ? $this->request->get['date'] : '';
        $filters['date_from']     = isset($this->request->get['date_from']) ? $this->request->get['date_from'] : '';
        $filters['date_to']       = isset($this->request->get['date_to']) ? $this->request->get['date_to'] : '';

        $project  = explode(':', $this->config->get($this->module_key . '_api_token'));
        $project = $project[0];

        // get domain from api url
        $base_url   = $this->config->get($this->module_key . '_api_url');
        $parsed_url = parse_url($base_url);
        $base_url   = $parsed_url['scheme'] . '://' . str_replace('api.', 'app.', $parsed_url['host']);

        $base_url .= "/account/$project"; // 'app/translate_requests

        $activities = $this->model_extension_module_ovesio->getActivities($filters);
        $activities_total = $this->model_extension_module_ovesio->getActivitiesTotal($filters);

        $data = array_merge($data, $filters);

        $data['activities'] = [];
        $data['total']      = $activities_total;

        // Map resource types to display text and badge classes
        $resource_types = [
            'product'      => ['text' => $this->language->get('text_product'), 'class' => 'ov-badge-primary'],
            'category'     => ['text' => $this->language->get('text_category'), 'class' => 'ov-badge-info'],
            'manufacturer' => ['text' => $this->language->get('text_manufacturers'), 'class' => 'ov-badge-warning'],
            'information'  => ['text' => $this->language->get('text_information'), 'class' => 'ov-badge-secondary']
        ];

        // Map activity types to display text and badge classes
        $activity_types = [
            'generate_content' => ['text' => $this->language->get('activity_generate_content'), 'class' => 'ov-badge-info', 'url_pattern' => $base_url . '/ai/generate_descriptions/%s'],
            'generate_seo'     => ['text' => $this->language->get('activity_generate_seo'), 'class' => 'ov-badge-warning', 'url_pattern' => $base_url . '/ai/generate_seo/%s'],
            'translate'        => ['text' => $this->language->get('activity_translate'), 'class' => 'ov-badge-success', 'url_pattern' => $base_url . '/app/translate_requests/%s']
        ];

        // Map status to display text and badge classes
        $status_types = [
            'started'   => ['text' => $this->language->get('text_processing'), 'class' => 'ov-status-info'],
            'completed' => ['text' => $this->language->get('text_completed'), 'class' => 'ov-status-success'],
            'skipped'   => ['text' => $this->language->get('text_skipped'), 'class' => 'ov-status-warning'],
            'error'     => ['text' => $this->language->get('text_error'), 'class' => 'ov-status-danger']
        ];

        $language_options = [];

        $ovesio_languages = $this->getOvesioLanguages();

        $this->load->model('localisation/language');

        $system_languages = $this->model_localisation_language->getLanguages();
        $ovesio_languages = $ovesio_languages ?: array_column($activities, 'lang');

        $languages_info = [];
        foreach ($system_languages as $language) {
            foreach ($ovesio_languages as $ol) {
                if (stripos($language['code'], $ol) === 0) {
                    $languages_info[$ol] = $language;
                    $language_options[$ol] = $language['name'];
                    break;
                }
            }
        }

        $data['language_options'] = $language_options;
        $data['activity_types']   = $activity_types;
        $data['status_types']     = $status_types;
        $data['resource_types']   = $resource_types;

        foreach ($activities as $activity) {
            // Get display values with fallbacks
            $resource_info = isset($resource_types[$activity['resource_type']]) ?
                $resource_types[$activity['resource_type']] :
                ['text' => ucfirst($activity['resource_type']), 'class' => 'ov-badge-secondary'];

            $activity_info = isset($activity_types[$activity['activity_type']]) ?
                $activity_types[$activity['activity_type']] :
                ['text' => ucfirst($activity['activity_type']), 'class' => 'ov-badge-secondary'];

            $status_info = isset($status_types[$activity['status']]) ?
                $status_types[$activity['status']] :
                ['text' => ucfirst($activity['status']), 'class' => 'ov-status-secondary'];

            // Calculate time ago
            $updated_time = new DateTime($activity['updated_at']);
            $now = new DateTime();
            $diff = $now->diff($updated_time);

            if ($diff->days > 0) {
                if ($diff->days > 7) {
                    $time_ago = $updated_time->format('d-m-Y H:i');
                } else {
                    $time_ago = $diff->days . ' ' . ($diff->days == 1 ? 'day' : 'days') . ' ago';
                }
            } elseif ($diff->h > 0) {
                $time_ago = $diff->h . ' ' . ($diff->h == 1 ? 'hour' : 'hours') . ' ago';
            } elseif ($diff->i > 0) {
                $time_ago = $diff->i . ' ' . $this->language->get('text_minutes_ago');
            } else {
                $time_ago = 'Just now';
            }

            // Add formatted data to activity
            $activity['resource_display']      = $resource_info;
            $activity['activity_display']      = $activity_info;
            $activity['status_display']        = $status_info;
            $activity['time_ago']              = $time_ago;
            $activity['lang_upper']            = strtoupper($activity['lang']);
            $activity['language_name']         = isset($languages_info[$activity['lang']]) ? $languages_info[$activity['lang']]['name'] : $activity['lang'];
            $activity['language_flag']         = isset($languages_info[$activity['lang']]) ? 'language/' . $languages_info[$activity['lang']]['code'] . '/' . $languages_info[$activity['lang']]['code'] . '.png' : '';
            $activity['resource_name_escaped'] = htmlspecialchars($activity['resource_name'], ENT_QUOTES, 'UTF-8');

            if ($activity['activity_id']) {
                $activity['activity_url'] = sprintf($activity_info['url_pattern'], $activity['activity_id']);
            } else {
                $activity['activity_url'] = '';
            }

            // resource url
            if ($activity['resource_type'] == 'product') {
                $activity['resource_url'] = $this->url->link('catalog/product/edit', $this->tokenQs() . '&product_id=' . $activity['resource_id']);
            } elseif ($activity['resource_type'] == 'category') {
                $activity['resource_url'] = $this->url->link('catalog/category/edit', $this->tokenQs() . '&category_id=' . $activity['resource_id']);
            } elseif ($activity['resource_type'] == 'attribute_group') {
                $activity['resource_url'] = $this->url->link('catalog/attribute_group/edit', $this->tokenQs() . '&attribute_group_id=' . $activity['resource_id']);
            } elseif ($activity['resource_type'] == 'attribute') {
                $activity['resource_url'] = $this->url->link('catalog/attribute/edit', $this->tokenQs() . '&attribute_id=' . $activity['resource_id']);
            } elseif ($activity['resource_type'] == 'option') {
                $activity['resource_url'] = $this->url->link('catalog/option/edit', $this->tokenQs() . '&option_id=' . $activity['resource_id']);
            } elseif ($activity['resource_type'] == 'information') {
                $activity['resource_url'] = $this->url->link('catalog/information/edit', $this->tokenQs() . '&information_id=' . $activity['resource_id']);
            } else {
                $activity['resource_url'] = '';
            }

            $data['activities'][] = $activity;
        }

        $hash = $this->config->get($this->module_key . '_hash');
        $data['url_settings']      = $this->url->link('extension/module/ovesio', $this->tokenQs());
        $data['url_update_status'] = HTTPS_CATALOG . 'index.php?route=extension/module/ovesio/callback/updateActivityStatus&hash=' . $hash;

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        // Add URLs for AJAX modal calls
        $data['url_view_request'] = $this->url->link('extension/module/ovesio/viewRequest', $this->tokenQs());
        $data['url_view_response'] = $this->url->link('extension/module/ovesio/viewResponse', $this->tokenQs());

        $this->response->setOutput($this->view('extension/module/ovesio_activity_list', $data));
    }

    /**
     * AJAX method to view request details in modal
     */
    public function viewRequest()
    {
        $this->load->model('extension/module/ovesio');

        $activity = $this->model_extension_module_ovesio->getActivity($this->request->get['activity_id']);

        if ($activity['request']) {
            $request = json_decode($activity['request'], true);
        } else {
            $request = '';
        }

        $this->response->setOutput('<pre class="ov-well">' . htmlspecialchars(json_encode($request, JSON_PRETTY_PRINT)) . '</pre>');
    }

    /**
     * AJAX method to view response details in modal
     */
    public function viewResponse()
    {
        $this->load->language('extension/module/ovesio');

        $this->load->model('extension/module/ovesio');

        $activity = $this->model_extension_module_ovesio->getActivity($this->request->get['activity_id']);

        if ($activity['response']) {
            $response = json_decode($activity['response'], true);
        } else {
            $response = '';
        }

        $this->response->setOutput('<pre class="ov-well">' . htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT)) . '</pre>');
    }

    private function getOvesioLanguages()
    {
        $client = $this->buildClient();

        $ovesio_languages = $this->cache->get('ovesio.languages');

        if (!$ovesio_languages) {
            try {
                $response = $client->languages()->list();
                $response = json_decode(json_encode($response), true);
            } catch (Exception $e) {
                return [];
            }

            if (!empty($response['data'])) {
                $ovesio_languages = array_column($response['data'], 'code');
                $this->cache->set('ovesio.languages', $ovesio_languages);
            }
        }

        return (array)$ovesio_languages;
    }

    private function buildClient($api_url = null, $api_token = null)
    {
        $api_url   = $api_url ?: $this->config->get($this->module_key . '_api_url');
        $api_token = $api_token ?: $this->config->get($this->module_key . '_api_token');

        return new OvesioAI($api_token, $api_url);
    }

    private function getWorkflows($type = null)
    {
        $client = $this->buildClient();

        try {
            $response = $client->workflows()->list();
        } catch (Exception $e) {
            return [];
        }

        $workflows = json_decode(json_encode($response->data), true);

        if ($type) {
            $workflows = array_filter($workflows, function($workflow) use ($type) {
                return $workflow['type'] == $type;
            });
        }

        return $workflows;
    }
}