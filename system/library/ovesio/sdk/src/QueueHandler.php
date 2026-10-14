<?php

namespace Ovesio;

/**
 * QueueHandler
 *
 * This class handles queue operations such as enqueueing, dequeueing,
 * and processing queued items. It manages the lifecycle of items in
 * the queue and provides methods for interacting with the queue system.
 *
 * Common responsibilities might include:
 * - Adding items to the queue
 * - Retrieving items from the queue
 * - Processing queued jobs or tasks
 * - Managing queue priorities or delays
 */
class QueueHandler
{
    private $model;
    private $api;
    private $options;
    private $log;
    private $debug = [];
    private $to_langs = [];
    private $force_stale = false;

    private $from_callback = false;

    /**
     * The list of activities that will be processed in order of priority
     */
    private $activity_groups = [
        'generate_content' => [],
        'generate_seo'     => [],
        'translate'        => [],
    ];

    private $activity_hash = [
        'generate_content' => [],
        'generate_seo'     => [],
        'translate'        => [],
    ];

    public function __construct($model, OvesioAI $api, $options = [], $log = null)
    {
        $this->model = $model;
        $this->api = $api;
        $this->options = $options;
        $this->log = $log;

        $languages = $this->getOption('language_settings', []);
        $from_lang = $this->getOption('default_language');

        $to_langs = [];
        foreach ($languages as $language) {
            if (empty($language['translate']) || $language['code'] === $from_lang || empty($language['translate_from'])) continue;

            $to_langs[] = $language['code'];
        }

        $this->to_langs = $to_langs;
    }

    /**
     * Get option value
     */
    private function getOption($key, $default = null)
    {
        $options = $this->options;

        return isset($options[$key]) ? $options[$key] : $default;
    }

    public function processQueue($params = [])
    {
        $this->force_stale = !empty($params['force_stale']);

        $list = $this->model->getCronList($params);

        $this->from_callback = !empty($params['from_callback']);

        // setup activity groups
        foreach ($list as $resource => $activities) {
            foreach ($activities as $activity_type => $activity) {
                $this->activity_groups[$activity_type][$resource] = $activity;
            }
        }

        if (!empty($params['activity_type'])) {
            if ($params['activity_type'] == 'generate_content') {
                $this->handleGenerateContentQueue();
            } elseif ($params['activity_type'] == 'generate_seo') {
                $this->handleGenerateSeoQueue();
            } elseif ($params['activity_type'] == 'translate') {
                $this->handleTranslateQueue();
            }
        } else {
            $this->handleGenerateContentQueue();
            $this->handleGenerateSeoQueue();
            $this->handleTranslateQueue();
        }

        $this->activity_groups = array_fill_keys(array_keys($this->activity_groups), []); // reset

        return $list;
    }

    /**
     * Add message to debug
     *
     * @return void
     */
    public function debug($resource, $resource_ids, $event_type, $code, $status = '')
    {
        foreach ((array) $resource_ids as $resource_id) {
            $this->debug["$resource/$resource_id"][$event_type] = [
                'code'   => $code,
                'status' => $status
            ];
        }
    }

    /**
     * Show debug messages
     *
     * @return void
     */
    public function showDebug()
    {
        if (empty($this->debug)) {
            echo "<p>No debug information available.</p>";
            return;
        }

        echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; margin: 20px 0; font-family: monospace;'>";
        echo "<thead>";
        echo "<tr style='background-color: #333; color: #fff;'>";
        echo "<th>Resource</th>";
        echo "<th>Generate Content</th>";
        echo "<th>Generate SEO</th>";
        echo "<th>Translate</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";

        $code_colors = [
            'not_found'         => '#999',      // Gray - initial state
            'new'               => '#0066cc',   // Blue - new item
            'changed'           => '#ff9800',   // Orange - content changed
            'unchanged'         => '#666',      // Dark gray - no changes
            'min_length_not_met'=> '#9c27b0',   // Purple - validation issue
            'translate'         => '#4caf50',   // Green - ready for translation
            'skipped'           => '#ff5722',   // Deep orange - skipped due to settings
        ];

        foreach ($this->debug as $resource => $events) {
            echo "<tr>";
            echo "<td style='font-weight: bold;'>{$resource}</td>";

            // Generate Content column
            if (isset($events['generate_content'])) {
                $code   = $events['generate_content']['code'];
                $status = $events['generate_content']['status'];
                $color = isset($code_colors[$code]) ? $code_colors[$code] : '#000';
                echo "<td style='color: {$color};'>{$code} {$status}</td>";
            } else {
                echo "<td><span style='color: #999;'>-</span></td>";
            }

            // Generate SEO column
            if (isset($events['generate_seo'])) {
                $code   = $events['generate_seo']['code'];
                $status = $events['generate_seo']['status'];
                $color = isset($code_colors[$code]) ? $code_colors[$code] : '#000';
                echo "<td style='color: {$color};'>{$code} {$status}</td>";
            } else {
                echo "<td><span style='color: #999;'>-</span></td>";
            }

            // Translate column
            if (isset($events['translate'])) {
                $code = $events['translate']['code'];
                $status = $events['translate']['status'];
                $color = isset($code_colors[$code]) ? $code_colors[$code] : '#000';
                echo "<td style='color: {$color};'>{$code} {$status}</td>";
            } else {
                echo "<td><span style='color: #999;'>-</span></td>";
            }

            echo "</tr>";
        }

        echo "</tbody>";
        echo "</table>";
    }

    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * Decode HTML content
     */
    private function decode($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->decode($value);
            }
        } else {
            $data = html_entity_decode($data, ENT_COMPAT, 'UTF-8');
        }

        return $data;
    }

    protected function ignoreMoveOnNextEvent($resource_type, $resource_ids, $activity_type, $message = null, $status = 'completed', $request = null)
    {
        $resource_ids = (array) $resource_ids;

        if ($activity_type == 'translate') { // scalar
            foreach ($this->to_langs as $lang) {
                foreach ($resource_ids as $resource_id) {
                    $list_item = [
                        'resource_type' => $resource_type,
                        'resource_id'   => $resource_id,
                        'lang'          => $lang,
                        'activity_type' => $activity_type,
                        'stale'         => 0,
                        'updated_at'    => date('Y-m-d H:i:s')
                    ];

                    if($request) {
                        $list_item['request'] = json_encode($request);
                    }

                    if ($message) {
                        $list_item['message'] = $message;
                    }

                    if ($status) {
                        $list_item['status'] = $status;
                    }

                    $this->model->addList($list_item);
                }
            }

        } else {

            $default_language = $this->getOption('default_language');

            foreach ($resource_ids as $resource_id) {
                $list_item = [
                    'resource_type' => $resource_type,
                    'resource_id'   => $resource_id,
                    'lang'          => $default_language,
                    'activity_type' => $activity_type,
                    'stale'         => 0,
                    'updated_at'    => date('Y-m-d H:i:s')
                ];

                if($request) {
                    $list_item['request'] = json_encode($request);
                }

                if ($message) {
                    $list_item['message'] = $message;
                }

                if ($status) {
                    $list_item['status'] = $status;
                }

                $this->model->addList($list_item);
            }
        }
    }

    /*********************************** PAYLOAD BUILDERS ***********************************/

    /**
     * Continutul trimis la Ovesio pentru fiecare produs, cu hash-ul lui. Folosit de coada si de
     * migrarea care recalculeaza hash-urile, ca ambele sa dea acelasi hash pentru acelasi continut.
     *
     * @param string $activity_type generate_content | generate_seo | translate
     * @param array $products randuri din model->getProducts()
     * @return array product_id => ['ref', 'content', 'hash', 'description' => text fara taguri]
     */
    public function buildProductPayloads($activity_type, array $products)
    {
        if (empty($products)) {
            return [];
        }

        $product_ids = array_column($products, 'product_id');

        $translate_fields = [];
        $with_attributes  = true;
        $categories_ids   = [];

        if ($activity_type == 'translate') {
            $fields           = (array) $this->getOption('translate_fields', []);
            $translate_fields = isset($fields['products']) ? (array) $fields['products'] : [];
            $with_attributes  = !empty($fields['attributes']['value']);
        } else {
            $categories_ids = $this->model->getProductCategories($product_ids);
        }

        $product_attributes = [];
        $attributes         = [];

        if ($with_attributes) {
            $product_attributes = $this->model->getProductsAttributes($product_ids);

            $attribute_ids = [];
            foreach ($product_attributes as $attributes_list) {
                $attribute_ids = array_merge($attribute_ids, array_keys($attributes_list));
            }

            if ($attribute_ids) {
                $attributes = array_column($this->model->getAttributes(array_unique($attribute_ids)), 'name', 'attribute_id');
            }
        }

        $payloads = [];

        foreach ($products as $product) {
            $product_id = $product['product_id'];
            $ref        = 'product/' . $product_id;

            if ($activity_type == 'translate') {
                $content = [];

                foreach ($translate_fields as $key => $send) {
                    if (!$send || empty($product[$key])) continue;

                    $content[] = [
                        'key'   => $key,
                        'value' => $product[$key]
                    ];
                }

                foreach (($product_attributes[$product_id] ?? []) as $attribute_id => $attribute_text) {
                    $content[] = [
                        'key'     => 'a-' . $attribute_id,
                        'value'   => $attribute_text,
                        'context' => isset($attributes[$attribute_id]) ? $attributes[$attribute_id] : ''
                    ];
                }

                $content = $this->decode($content);

                $payloads[$product_id] = [
                    'ref'         => $ref,
                    'content'     => $content,
                    'hash'        => $content ? $this->contentHash($content) : '',
                    'description' => '',
                ];

                continue;
            }

            $content = [
                'name' => $product['name']
            ];

            // only if is different from name...usual mistake
            $description = strip_tags($this->decode($product['description']));
            if (trim(strtolower(preg_replace("/[^A-Za-z0-9 ]/", '', $description))) != trim(strtolower(preg_replace("/[^A-Za-z0-9 ]/", '', strip_tags($this->decode($product['name'])))))) {
                $content['description'] = $product['description'];
            }

            foreach (($categories_ids[$product_id] ?? []) as $category_id) {
                $category_info = $this->model->getCategory($category_id);

                if ($category_info) {
                    $content['categories'][] = ($category_info['path']) ? $category_info['path'] . ' &gt; ' . $category_info['name'] : $category_info['name'];
                }
            }

            foreach (($product_attributes[$product_id] ?? []) as $attribute_id => $attribute_text) {
                $content['additional'][] = (isset($attributes[$attribute_id]) ? $attributes[$attribute_id] : '') . ': ' . $attribute_text;
            }

            $content = $this->decode($content);

            foreach ($content as $k => $v) {
                if (is_array($v)) {
                    sort($content[$k]);
                }
            }

            // remove description from hash to avoid recreating it everytime
            $hashable = $content;
            if ($activity_type == 'generate_content') {
                unset($hashable['description']);
            }

            $payloads[$product_id] = [
                'ref'         => $ref,
                'content'     => $content,
                'hash'        => $this->contentHash($hashable),
                'description' => $description,
            ];
        }

        return $payloads;
    }

    /**
     * Continutul trimis la Ovesio pentru fiecare categorie, cu hash-ul lui.
     *
     * @param string $activity_type generate_content | generate_seo | translate
     * @param array $categories randuri din model->getCategories()
     * @return array category_id => ['ref', 'content', 'hash', 'description' => text fara taguri]
     */
    public function buildCategoryPayloads($activity_type, array $categories)
    {
        $translate_fields = [];

        if ($activity_type == 'translate') {
            $fields           = (array) $this->getOption('translate_fields', []);
            $translate_fields = isset($fields['categories']) ? (array) $fields['categories'] : [];
        }

        $payloads = [];

        foreach ($categories as $category) {
            $category_id = $category['category_id'];
            $ref         = 'category/' . $category_id;

            if ($activity_type == 'translate') {
                $content = [];

                foreach ($translate_fields as $key => $send) {
                    if (!$send || empty($category[$key])) continue;

                    $content[] = [
                        'key'   => $key,
                        'value' => $category[$key]
                    ];
                }

                $content = $this->decode($content);

                $payloads[$category_id] = [
                    'ref'         => $ref,
                    'content'     => $content,
                    'hash'        => $content ? $this->contentHash($content) : '',
                    'description' => '',
                ];

                continue;
            }

            $content = [
                'name' => $category['name']
            ];

            // only if is different from name...usual mistake
            $description = strip_tags($this->decode($category['description']));
            if (trim(strtolower(preg_replace("/[^A-Za-z0-9 ]/", '', $description))) != trim(strtolower(preg_replace("/[^A-Za-z0-9 ]/", '', strip_tags($this->decode($category['name'])))))) {
                $content['description'] = $category['description'];
            }

            $content = $this->decode($content);

            // remove description from hash to avoid recreating it everytime
            $hashable = $content;
            if ($activity_type == 'generate_content') {
                unset($hashable['description']);
            }

            $payloads[$category_id] = [
                'ref'         => $ref,
                'content'     => $content,
                'hash'        => $this->contentHash($hashable),
                'description' => $description,
            ];
        }

        return $payloads;
    }

    /**
     * Continutul de tradus pentru grupuri de atribute (numele grupului + numele atributelor).
     *
     * @return array attribute_group_id => ['ref', 'content', 'hash']
     */
    public function buildAttributeGroupPayloads(array $attribute_group_ids)
    {
        if (empty($attribute_group_ids)) {
            return [];
        }

        $attribute_groups = array_column($this->model->getAttributeGroups($attribute_group_ids), null, 'attribute_group_id');

        if (empty($attribute_groups)) {
            return [];
        }

        $groups = [];
        foreach ($attribute_group_ids as $attribute_group_id) {
            if (!isset($attribute_groups[$attribute_group_id])) continue;

            $groups[$attribute_group_id] = [
                [
                    'key'   => 'ag-' . $attribute_group_id,
                    'value' => $attribute_groups[$attribute_group_id]['name']
                ]
            ];
        }

        foreach ($this->model->getGroupsAttributes($attribute_group_ids) as $attribute) {
            if (!isset($groups[$attribute['attribute_group_id']])) continue;

            $groups[$attribute['attribute_group_id']][] = [
                'key'     => 'a-' . $attribute['attribute_id'],
                'context' => $attribute_groups[$attribute['attribute_group_id']]['name'],
                'value'   => $attribute['name'],
            ];
        }

        $payloads = [];

        foreach ($groups as $attribute_group_id => $content) {
            $content = $this->decode($content);

            $payloads[$attribute_group_id] = [
                'ref'     => 'attribute_group/' . $attribute_group_id,
                'content' => $content,
                'hash'    => $this->contentHash($content),
            ];
        }

        return $payloads;
    }

    /**
     * Continutul de tradus pentru optiuni (numele optiunii + valorile ei).
     *
     * @return array option_id => ['ref', 'content', 'hash']
     */
    public function buildOptionPayloads(array $option_ids)
    {
        if (empty($option_ids)) {
            return [];
        }

        $options = $this->model->getOptions($option_ids);

        if (empty($options)) {
            return [];
        }

        $option_values = [];
        foreach ($this->model->getOptionValues($option_ids) as $option_value) {
            $option_values[$option_value['option_id']][] = $option_value;
        }

        $payloads = [];

        foreach ($options as $option) {
            $content = [
                [
                    'key'   => 'o-' . $option['option_id'],
                    'value' => $option['name'],
                ]
            ];

            foreach (($option_values[$option['option_id']] ?? []) as $option_value) {
                $content[] = [
                    'key'     => 'ov-' . $option_value['option_value_id'],
                    'context' => $option['name'],
                    'value'   => $option_value['name'],
                ];
            }

            $content = $this->decode($content);

            $payloads[$option['option_id']] = [
                'ref'     => 'option/' . $option['option_id'],
                'content' => $content,
                'hash'    => $this->contentHash($content),
            ];
        }

        return $payloads;
    }

    /*********************************** GENERATE CONTENT ***********************************/

    protected function handleGenerateContentQueue()
    {
        $default_language                  = $this->getOption('default_language');
        $generate_content_status           = (bool) $this->getOption('generate_content_status');
        $generate_content_include_disabled = array_filter((array) $this->getOption('generate_content_include_disabled', []));
        $generate_content_for              = array_filter((array) $this->getOption('generate_content_for', []));
        $send_stock_0_products             = (bool) $this->getOption('generate_content_include_stock_0');

        if (!$generate_content_status) {
            return;
        }

        $activities = $this->activity_groups['generate_content'];
        $product_activities  = [];
        $category_activities = [];

        foreach ($activities as $resource => $started_activity) {
            list($resource_type, $resource_id) = explode('/', $resource);

            if (!$this->getOption('manual') && $started_activity) {
                if ($started_activity['status'] == 'started') { // if activity is started, we keep it staled until completion
                    $this->debug($resource_type, $resource_id, 'generate_content', 'in_progress', 'started');
                    $this->discardNextEvents('generate_content', $resource);
                    continue;
                } elseif ($started_activity['status'] == 'error' && !$started_activity['stale']) {
                    // allow re-processing errored activities only if staled
                    $this->debug($resource_type, $resource_id, 'generate_content', 'error', 'is in error status and not staled');
                    $this->discardNextEvents('generate_content', $resource);
                    continue;
                }
            }

            if ($resource_type == 'product') {
                $product_activities[$resource_id] = $started_activity;
            } elseif ($resource_type == 'category') {
                $category_activities[$resource_id] = $started_activity;
            }
        }

        $hash         = $this->getOption('hash');
        $server       = $this->getOption('server_url', '');
        $workflow     = $this->getOption('generate_content_workflow');

        if (empty($workflow['id'])) {
            if ($this->log) {
                $this->log->write('Ovesio QueueHandler: no workflow selected for generate_content, nothing sent');
            }
            $this->debug('total', '', 'generate_content', 'no_workflow');
            return;
        }

        $callback_url = $server . 'index.php?route=extension/module/ovesio/callback&type=generate_content&hash=' . $hash;
        if ($this->getOption('manual')) {
            $callback_url .= '&manual=true';
        }

        $to_lang      = $this->getOption('default_language');

        $request = $this->api->generateDescription()
        ->workflow($workflow['id'])
        ->callbackUrl($callback_url);
        if ($to_lang != 'auto') {
            $request = $request->to($to_lang);
        }

        if (!empty($product_activities)) {
            if (!empty($generate_content_for['products'])) {
                $this->pushGenerateProductDescriptionRequests($request, $product_activities, !empty($generate_content_include_disabled['products']), $send_stock_0_products);
            }
        }

        if (!empty($category_activities)) {
            if (!empty($generate_content_for['categories'])) {
                $this->pushGenerateCategoryDescriptionRequests($request, $category_activities, !empty($generate_content_include_disabled['categories']));
            }
        }

        if (!$request->getData()) {
            $this->debug('total', '', 'generate_content', 0);
            return;
        }

        $request_data = array_column($request->getData(), 'content', 'ref');

        // unset further processing for these resources
        foreach ($request_data as $_resource => $item) {
            $this->discardNextEvents('generate_content', $_resource);
        }

        try {
            $response = $request->request();
        } catch (\Exception $e) {
            return $this->log->write('Ovesio QueueHandler generate content error: ' . $e->getMessage());
        }

        $response = json_decode(json_encode($response), true);

        if (!empty($response['success'])) {
            foreach ($response['data'] as $item) {
                $hash = $this->activity_hash['generate_content'][$item['ref']];

                list($resource_type, $resource_id) = explode('/', $item['ref']);

                $this->model->addList([
                    'resource_type' => $resource_type,
                    'resource_id'   => $resource_id,
                    'lang'          => $default_language,
                    'activity_type' => 'generate_content',
                    'activity_id'   => $item['id'],
                    'hash'          => $hash,
                    'status'        => 'started',
                    'request'       => json_encode($request_data[$item['ref']]),
                    'response'      => json_encode($item),
                    'stale'         => 0,
                    'updated_at'    => date('Y-m-d H:i:s')
                ]);
            }

            $this->debug('total', '', 'generate_content', count($response['data']));
        } else {
            $this->massLogErrors($response, $request->getData(), 'generate_content');
            $this->debug('total', '', 'generate_content', 0);
        }
    }

    protected function pushGenerateProductDescriptionRequests($request, $activities, $include_disabled = false, $include_stock_0 = true)
    {
        $product_ids = array_keys($activities);
        $this->debug('product', $product_ids, 'generate_content', 'not_found'); // we make presence known on each iteration

        $products = $this->model->getProducts($product_ids, $include_disabled, $include_stock_0);

        if (empty($products)) {
            $this->ignoreMoveOnNextEvent('product', $product_ids, 'generate_content', "Not found, disabled or out of stock", 'skipped');
            return;
        }

        $description_lengths_min = $this->getOption('generate_content_when_description_length');

        foreach ($this->buildProductPayloads('generate_content', $products) as $product_id => $payload) {
            $content = $payload['content'];

            $this->activity_hash['generate_content'][$payload['ref']] = $payload['hash'];

            if (!$this->activityIsStaled($activities, 'product', $product_id, $payload['hash'], 'generate_content')) {
                continue;
            }

            // try to save resource from being ignored by minimum 25 chars limit
            if ($this->getAiRequestPayloadLength($content) <= 25) { // fallback
                if (isset($content['description'])) {
                    $content['description'] = $content['name'] . "\n" . $content['description'];
                } else {
                    $content['description'] = $content['name'];
                }
            }

            if (strlen($payload['description']) > $description_lengths_min['products']) {
                $this->debug('product', $product_id, 'generate_content', 'max_length_not_met');
                $this->ignoreMoveOnNextEvent('product', $product_id, 'generate_content', "Description exceeds the " . $description_lengths_min['products'] . ' character limit' , 'skipped');
                continue;
            }

            $this->debug('product', $product_id, 'generate_content', 'new');

            $this->discardNextEvents('generate_content', $payload['ref']);

            $request->data($content, $payload['ref']);
        }
    }

    protected function pushGenerateCategoryDescriptionRequests($request, $activities, $include_disabled = false)
    {
        $category_ids = array_keys($activities);
        $this->debug('category', $category_ids, 'generate_content', 'not_found'); // we make presence known on each iteration

        $categories = $this->model->getCategories($category_ids, $include_disabled);

        if (empty($categories)) {
            $this->ignoreMoveOnNextEvent('category', $category_ids, 'generate_content', "Not found or disabled", 'skipped');
            return;
        }

        $description_lengths_min = $this->getOption('generate_content_when_description_length');

        foreach ($this->buildCategoryPayloads('generate_content', $categories) as $category_id => $payload) {
            $content = $payload['content'];

            $this->activity_hash['generate_content'][$payload['ref']] = $payload['hash'];

            if (!$this->activityIsStaled($activities, 'category', $category_id, $payload['hash'], 'generate_content')) {
                continue;
            }

            // try to save resource from being ignored by minimum 25 chars limit
            if ($this->getAiRequestPayloadLength($content) <= 25) { // fallback
                if (isset($content['description'])) {
                    $content['description'] = $content['name'] . "\n" . $content['description'];
                } else {
                    $content['description'] = $content['name'];
                }
            }

            if (strlen($payload['description']) > $description_lengths_min['categories']) {
                $this->debug('category', $category_id, 'generate_content', 'max_length_not_met');
                $this->ignoreMoveOnNextEvent('category', $category_id, 'generate_content', "Description exceeds the " . $description_lengths_min['categories'] . ' character limit' , 'skipped');
                continue;
            }

            $this->debug('category', $category_id, 'generate_content', 'new');

            $this->discardNextEvents('generate_content', $payload['ref']);

            $request->data($content, $payload['ref']);
        }
    }

    /*********************************** GENERATE SEO ***********************************/

    protected function handleGenerateSeoQueue()
    {
        $default_language              = $this->getOption('default_language');
        $generate_seo_status           = (bool) $this->getOption('generate_seo_status');
        $generate_seo_include_disabled = array_filter((array) $this->getOption('generate_seo_include_disabled', []));
        $generate_seo_for              = array_filter((array) $this->getOption('generate_seo_for', []));
        $send_stock_0_products         = (bool) $this->getOption('generate_seo_include_stock_0');

        if (!$generate_seo_status) {
            return;
        }

        $activities = $this->activity_groups['generate_seo'];
        $product_activities  = [];
        $category_activities = [];

        foreach ($activities as $resource => $started_activity) {
            list($resource_type, $resource_id) = explode('/', $resource);

            if (!$this->getOption('manual') && $started_activity) { // if activity is started, we keep it staled until completion
                if ($started_activity['status'] == 'started') {
                    $this->debug($resource_type, $resource_id, 'generate_seo', 'in_progress', 'started');
                    $this->discardNextEvents('generate_seo', $resource);
                    continue;
                } elseif ($started_activity['status'] == 'error' && !$started_activity['stale']) {
                    // allow re-processing errored activities only if staled
                    $this->debug($resource_type, $resource_id, 'generate_seo', 'error', 'is in error status and not staled');
                    $this->discardNextEvents('generate_seo', $resource);
                    continue;
                }
            }

            if ($resource_type == 'product') {
                $product_activities[$resource_id] = $started_activity;
            } elseif ($resource_type == 'category') {
                $category_activities[$resource_id] = $started_activity;
            }
        }

        $hash         = $this->getOption('hash');
        $server       = $this->getOption('server_url', '');
        $workflow     = $this->getOption('generate_seo_workflow');

        if (empty($workflow['id'])) {
            if ($this->log) {
                $this->log->write('Ovesio QueueHandler: no workflow selected for generate_seo, nothing sent');
            }
            $this->debug('total', '', 'generate_seo', 'no_workflow');
            return;
        }

        $callback_url = $server . 'index.php?route=extension/module/ovesio/callback&type=generate_seo&hash=' . $hash;
        if ($this->getOption('manual')) {
            $callback_url .= '&manual=true';
        }

        $to_lang      = $this->getOption('default_language');

        $request = $this->api->generateSeo()
        ->workflow($workflow['id'])
        ->callbackUrl($callback_url);
        if ($to_lang != 'auto') {
            $request = $request->to($to_lang);
        }

        if (!empty($product_activities)) {
            if (!empty($generate_seo_for['products'])) {
                $this->pushGenerateProductSeoRequests($request, $product_activities, !empty($generate_seo_include_disabled['products']), $send_stock_0_products);
            }
        }

        if (!empty($category_activities)) {
            if (!empty($generate_seo_for['categories'])) {
                $this->pushGenerateCategorySeoRequests($request, $category_activities, !empty($generate_seo_include_disabled['categories']));
            }
        }

        if (!$request->getData()) {
            $this->debug('total', '', 'generate_seo', 0);
            return;
        }

        $request_data = array_column($request->getData(), 'content', 'ref');

        // unset further processing for these resources
        foreach ($request_data as $_resource => $item) {
            $this->discardNextEvents('generate_seo', $_resource);
        }

        try {
            $response = $request->request();
        } catch (\Exception $e) {
            return $this->log->write('Ovesio QueueHandler generate SEO error: ' . $e->getMessage());
        }

        $response = json_decode(json_encode($response), true);

        if (!empty($response['success'])) {
            foreach ($response['data'] as $item) {
                $hash = $this->activity_hash['generate_seo'][$item['ref']];

                list($resource_type, $resource_id) = explode('/', $item['ref']);

                $this->model->addList([
                    'resource_type' => $resource_type,
                    'resource_id'   => $resource_id,
                    'lang'          => $default_language,
                    'activity_type' => 'generate_seo',
                    'activity_id'   => $item['id'],
                    'hash'          => $hash,
                    'status'        => 'started',
                    'request'       => json_encode($request_data[$item['ref']]),
                    'response'      => json_encode($item),
                    'stale'         => 0,
                    'updated_at'    => date('Y-m-d H:i:s')
                ]);
            }

            $this->debug('total', '', 'generate_seo', count($response['data']));
        } else {
            $this->debug('total', '', 'generate_seo', 0);
            $this->massLogErrors($response, $request->getData(), 'generate_seo');
        }
    }

    protected function pushGenerateProductSeoRequests($request, $activities, $include_disabled = false, $include_stock_0 = true)
    {
        $product_ids = array_keys($activities);
                $only_for_action = $this->getOption('generate_seo_only_for_action');

        if ($only_for_action) {
            $this->debug('product', $product_ids, 'generate_seo', 'skipped', 'needs_content_generation'); // we make presence known on each iteration
            $products = $this->model->getProductsWithDescriptionDependency($product_ids, $include_disabled, $include_stock_0);
        } else {
            $this->debug('product', $product_ids, 'generate_seo', 'not_found'); // we make presence known on each iteration
            $products = $this->model->getProducts($product_ids, $include_disabled, $include_stock_0);
        }

        if (empty($products)) {
            $this->ignoreMoveOnNextEvent('product', $product_ids, 'generate_seo', $only_for_action ? "Description not generated yet, not found, disabled or out of stock" : "Not found, disabled or out of stock", 'skipped');
            return;
        }

        foreach ($this->buildProductPayloads('generate_seo', $products) as $product_id => $payload) {
            $this->activity_hash['generate_seo'][$payload['ref']] = $payload['hash'];

            if (!$this->activityIsStaled($activities, 'product', $product_id, $payload['hash'], 'generate_seo')) {
                continue;
            }

            $this->debug('product', $product_id, 'generate_seo', 'new');

            $this->discardNextEvents('generate_seo', $payload['ref']);

            $request->data($payload['content'], $payload['ref']);
        }
    }

    protected function pushGenerateCategorySeoRequests($request, $activities, $include_disabled = false)
    {
        $category_ids = array_keys($activities);
        $only_for_action = $this->getOption('generate_seo_only_for_action');

        if ($only_for_action) {
            $this->debug('category', $category_ids, 'generate_seo', 'skipped', 'needs_content_generation'); // we make presence known on each iteration
            $categories = $this->model->getCategoriesWithDescriptionDependency($category_ids, $include_disabled);
        } else {
            $this->debug('category', $category_ids, 'generate_seo', 'not_found'); // we make presence known on each iteration
            $categories = $this->model->getCategories($category_ids, $include_disabled);
        }

        if (empty($categories)) {
            // Marcate skipped ca sa nu ramana la nesfarsit in lista cronului; la generarea descrierii,
            // callback-ul reproceseaza resursa si hash-ul gol le trimite atunci.
            $this->ignoreMoveOnNextEvent('category', $category_ids, 'generate_seo', $only_for_action ? "Description not generated yet, not found or disabled" : "Not found or disabled", 'skipped');
            return;
        }

        foreach ($this->buildCategoryPayloads('generate_seo', $categories) as $category_id => $payload) {
            $this->activity_hash['generate_seo'][$payload['ref']] = $payload['hash'];

            if (!$this->activityIsStaled($activities, 'category', $category_id, $payload['hash'], 'generate_seo')) {
                continue;
            }

            $this->debug('category', $category_id, 'generate_seo', 'new');

            $this->discardNextEvents('generate_seo', $payload['ref']);

            $request->data($payload['content'], $payload['ref']);
        }
    }

    /*********************************** TRANSLATE ***********************************/

    protected function handleTranslateQueue()
    {
        if (empty($this->to_langs)) {
            $this->model->skipRunningTranslations();
            return;
        }

        $default_language           = $this->getOption('default_language');
        $translate_status           = (bool) $this->getOption('translate_status');
        $translate_include_disabled = array_filter((array) $this->getOption('translate_include_disabled', []));
        $send_stock_0_products      = (bool) $this->getOption('translate_include_stock_0');

        if (!$translate_status) {
            return;
        }

        $translate_for = $this->getTranslateFor();

        if (empty($translate_for)) {
            return;
        }

        $activities = $this->activity_groups['translate'];
        $product_activities         = [];
        $category_activities        = [];
        $attribute_group_activities = [];
        $attribute_activities       = [];
        $option_activities          = [];

        foreach ($activities as $resource => $started_translate_activities) {
            list($resource_type, $resource_id) = explode('/', $resource);

            foreach ($started_translate_activities as $started_activity) {
                if (!$this->getOption('manual')) { // if activity is started, we keep it staled until completion
                    if ($started_activity['status'] == 'started') {
                        $this->debug($resource_type, $resource_id, 'translate', 'in_progress', 'started');
                        continue 2; // we do not start new translations until the current translation session on the respective resource is finished
                    } elseif ($started_activity['status'] == 'error' && !$started_activity['stale']) {
                        // allow re-processing errored activities only if staled
                        $this->debug($resource_type, $resource_id, 'translate', 'error', 'is in error status and not staled');
                        continue 2;
                    }
                }
            }

            if ($resource_type == 'product') {
                $product_activities[$resource_id] = $started_translate_activities;
            } elseif ($resource_type == 'category') {
                $category_activities[$resource_id] = $started_translate_activities;
            } elseif ($resource_type == 'attribute_group') {
                $attribute_group_activities[$resource_id] = $started_translate_activities;
            } elseif ($resource_type == 'attribute') {
                $attribute_activities[$resource_id] = $started_translate_activities;
            } elseif ($resource_type == 'option') {
                $option_activities[$resource_id] = $started_translate_activities;
            }
        }

        $hash         = $this->getOption('hash');
        $server       = $this->getOption('server_url', '');
        $workflow     = $this->getOption('translate_workflow');

        if (empty($workflow['id'])) {
            if ($this->log) {
                $this->log->write('Ovesio QueueHandler: no workflow selected for translate, nothing sent');
            }
            $this->debug('total', '', 'translate', 'no_workflow');
            return;
        }

        $callback_url = $server . 'index.php?route=extension/module/ovesio/callback&type=translate&hash=' . $hash;
        if ($this->getOption('manual')) {
            $callback_url .= '&manual=true';
        }

        $from_lang    = $this->getOption('default_language', 'en');

        $languages = $this->getOption('language_settings', []);

        $to_langs = $this->to_langs;
        $conditions = [];

        foreach ($languages as $language) {
            // apply conditions in case the from language is not the default language
            if ($from_lang != $language['translate_from'] && in_array($language['code'], $to_langs)) {
                $conditions[$language['code']] = $language['translate_from'];
            }
        }

        $request = $this->api->translate()
        ->workflow($workflow['id'])
        ->from($from_lang)
        ->to($to_langs)
        ->conditions($conditions)
        ->callbackUrl($callback_url);

        if (!empty($attribute_group_activities)) {
            if (!empty($translate_for['attributes'])) {
                $this->pushTranslateAttributeGroupRequests($request, $attribute_group_activities, !empty($translate_include_disabled['attributes']));
            }
        }

        if (!empty($attribute_activities)) {
            if (!empty($translate_for['attributes'])) {
                $this->pushTranslateAttributeRequests($request, $attribute_activities, !empty($translate_include_disabled['attributes']));
            }
        }

        if (!empty($option_activities)) {
            if (!empty($translate_for['options'])) {
                $this->pushTranslateOptionRequests($request, $option_activities, !empty($translate_include_disabled['options']));
            }
        }

        if (!empty($product_activities)) {
            if (!empty($translate_for['products'])) {
                $this->pushTranslateProductRequests($request, $product_activities, !empty($translate_include_disabled['products']), $send_stock_0_products);
            }
        }

        if (!empty($category_activities)) {
            if (!empty($translate_for['categories'])) {
                $this->pushTranslateCategoryRequests($request, $category_activities, !empty($translate_include_disabled['categories']));
            }
        }

        if (!$request->getData()) {
            $this->debug('total', '', 'translate', 0);
            return;
        }

        $request_data = array_column($request->getData(), 'content', 'ref');

        try {
            $response = $request->request();
        } catch (\Exception $e) {
            return $this->log->write('Ovesio QueueHandler translate error: ' . $e->getMessage());
        }

        $response = json_decode(json_encode($response), true);

        if (!empty($response['success'])) {
            foreach ($response['data'] as $item) {
                $hash = $this->activity_hash['translate'][$item['ref']] ?? '';

                list($resource_type, $resource_id) = explode('/', $item['ref']);

                foreach ($to_langs as $lang) {
                    $this->model->addList([
                        'resource_type' => $resource_type,
                        'resource_id'   => $resource_id,
                        'lang'          => $lang,
                        'activity_type' => 'translate',
                        'activity_id'   => $item['id'],
                        'hash'          => $hash,
                        'status'        => 'started',
                        'request'       => json_encode($request_data[$item['ref']]),
                        'response'      => json_encode($item),
                        'stale'         => 0,
                        'updated_at'    => date('Y-m-d H:i:s')
                    ]);
                }
            }

            $this->debug('total', '', 'translate', count($response['data']));
        } else {
            $this->debug('total', '', 'translate', 0);
            $this->massLogErrors($response, $request->getData(), 'translate');
        }
    }

    protected function pushTranslateCategoryRequests($request, $activities, $include_disabled = false)
    {
        $category_ids = array_keys($activities);
        $this->debug('category', $category_ids, 'translate', 'not_found'); // we make presence known on each iteration

        $translate_for = $this->getTranslateFor();

        if (empty($translate_for['categories'])) {
            $this->debug('category', $category_ids, 'translate', 'skipped', 'disabled');
            return;
        }

        $categories = $this->model->getCategories($category_ids, $include_disabled);

        if (empty($categories)) {
            $this->ignoreMoveOnNextEvent('category', $category_ids, 'translate', "Not found or disabled", 'skipped');
            return;
        }

        foreach ($this->buildCategoryPayloads('translate', $categories) as $category_id => $payload) {
            if (empty($payload['content'])) {
                $this->ignoreMoveOnNextEvent('category', $category_id, 'translate', "Empty content to translate", 'skipped');
                $this->debug('category', $category_id, 'translate', 'skipped', 'empty_content');
                continue;
            }

            $this->activity_hash['translate'][$payload['ref']] = $payload['hash'];

            if (!$this->activityIsStaled($activities, 'category', $category_id, $payload['hash'], 'translate')) {
                continue;
            }

            $this->debug('category', $category_id, 'translate', 'new');

            $this->discardNextEvents('translate', $payload['ref']);

            $request->data($payload['content'], $payload['ref']);
        }
    }

    protected function pushTranslateProductRequests($request, $activities, $include_disabled = false, $include_stock_0 = true)
    {
        $product_ids = array_keys($activities);
        $this->debug('product', $product_ids, 'translate', 'not_found'); // we make presence known on each iteration

        $translate_for = $this->getTranslateFor();

        if (empty($translate_for['products'])) {
            $this->debug('product', $product_ids, 'translate', 'skipped', 'disabled');
            return;
        }

        $products = $this->model->getProducts($product_ids, $include_disabled, $include_stock_0);

        if (empty($products)) {
            $this->ignoreMoveOnNextEvent('product', $product_ids, 'translate', "Not found, disabled or out of stock", 'skipped');
            return;
        }

        foreach ($this->buildProductPayloads('translate', $products) as $product_id => $payload) {
            if (empty($payload['content'])) {
                $this->debug('product', $product_id, 'translate', 'skipped', 'empty_content');
                $this->ignoreMoveOnNextEvent('product', $product_id, 'translate', "Empty content to translate", 'skipped');
                continue;
            }

            $this->activity_hash['translate'][$payload['ref']] = $payload['hash'];

            if (!$this->activityIsStaled($activities, 'product', $product_id, $payload['hash'], 'translate')) {
                continue;
            }

            $this->debug('product', $product_id, 'translate', 'new');

            $this->discardNextEvents('translate', $payload['ref']);

            $request->data($payload['content'], $payload['ref']);
        }
    }

    protected function pushTranslateAttributeGroupRequests($request, $activities, $include_disabled = false)
    {
        $attribute_group_ids = array_keys($activities);
        $this->debug('attribute_group', $attribute_group_ids, 'translate', 'not_found'); // we make presence known on each iteration

        $translate_for = $this->getTranslateFor();

        if (empty($translate_for['attributes'])) {
            $this->debug('attribute_group', $attribute_group_ids, 'translate', 'skipped', 'disabled');
            $this->ignoreMoveOnNextEvent('attribute_group', $attribute_group_ids, 'translate', "Groups and Attributes translation is disabled", 'skipped');
            return;
        }

        $payloads = $this->buildAttributeGroupPayloads($attribute_group_ids);

        if (empty($payloads)) {
            $this->ignoreMoveOnNextEvent('attribute_group', $attribute_group_ids, 'translate', "No attribute groups found", 'skipped');
            return;
        }

        foreach ($payloads as $attribute_group_id => $payload) {
            $this->activity_hash['translate'][$payload['ref']] = $payload['hash'];

            if (!$this->activityIsStaled($activities, 'attribute_group', $attribute_group_id, $payload['hash'], 'translate')) {
                continue;
            }

            $this->debug('attribute_group', $attribute_group_id, 'translate', 'new');

            $this->discardNextEvents('translate', $payload['ref']);

            $request->data($payload['content'], $payload['ref']);
        }
    }

    protected function pushTranslateAttributeRequests($request, $activities, $include_disabled = false)
    {
        $attribute_ids = array_keys($activities);

        $translate_for = $this->getTranslateFor();

        if (empty($translate_for['attributes'])) {
            $this->debug('attribute', $attribute_ids, 'translate', 'skipped', 'disabled');
            $this->ignoreMoveOnNextEvent('attribute', $attribute_ids, 'translate', "Groups and Attributes translation is disabled", 'skipped');
            return;
        }

        // Atributele se traduc pe grupul lor: trimitem grupurile din care fac parte
        $attributes          = $this->model->getAttributes($attribute_ids);
        $attribute_group_ids = array_values(array_unique(array_column($attributes, 'attribute_group_id')));

        $this->debug('attribute_group', $attribute_group_ids, 'translate', 'not_found'); // we make presence known on each iteration

        $payloads = $this->buildAttributeGroupPayloads($attribute_group_ids);

        if (empty($payloads)) {
            $this->ignoreMoveOnNextEvent('attribute', $attribute_ids, 'translate', "No attribute groups found", 'skipped');
            return;
        }

        foreach ($payloads as $attribute_group_id => $payload) {
            $this->activity_hash['translate'][$payload['ref']] = $payload['hash'];

            // activitatile primite sunt pe atribut, nu pe grup: grupul se trimite ca nou
            if (!$this->activityIsStaled([], 'attribute_group', $attribute_group_id, $payload['hash'], 'translate')) {
                continue;
            }

            $this->debug('attribute_group', $attribute_group_id, 'translate', 'new');

            $this->discardNextEvents('translate', $payload['ref']);

            $request->data($payload['content'], $payload['ref']);
        }
    }

    public function pushTranslateOptionRequests($request, $activities, $include_disabled = false)
    {
        $option_ids = array_keys($activities);
        $this->debug('option', $option_ids, 'translate', 'not_found'); // we make presence known on each iteration

        $translate_for = $this->getTranslateFor();

        if (empty($translate_for['options'])) {
            $this->debug('option', $option_ids, 'translate', 'skipped', 'disabled');
            $this->ignoreMoveOnNextEvent('option', $option_ids, 'translate', "Options translation is disabled", 'skipped');
            return;
        }

        $payloads = $this->buildOptionPayloads($option_ids);

        if (empty($payloads)) {
            $this->ignoreMoveOnNextEvent('option', $option_ids, 'translate', "No options found", 'skipped');
            return;
        }

        foreach ($payloads as $option_id => $payload) {
            $this->activity_hash['translate'][$payload['ref']] = $payload['hash'];

            if (!$this->activityIsStaled($activities, 'option', $option_id, $payload['hash'], 'translate')) {
                continue;
            }

            $this->debug('option', $option_id, 'translate', 'new');

            $this->discardNextEvents('translate', $payload['ref']);

            $request->data($payload['content'], $payload['ref']);
        }
    }

    // private function triggerCallback($type, $data)
    // {
    //     // make a curl POST to self server, without SSL verification
    //     $server = $this->getOption('server_url', '');
    //     $url = $server . 'index.php?route=extension/module/ovesio/callback&type=' . $type . '&hash=' . $this->getOption('hash');

    //     $ch = curl_init($url);

    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_POST, true);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //         'Content-Type: application/json'
    //     ]);
    //     curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    //     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    //     $response = curl_exec($ch);
    //     curl_close($ch);

    //     return $response;
    // }

    private function massLogErrors($response, $data, $activity_type)
    {
        // gather errors per item index (avoid duplicate)
        $item_errors = [];
        foreach ($response['errors'] as $key => $error) {
            if (stripos($key, 'data.') === 0) {
                $temp = explode('.', $key);
                $index = $temp[1];

                $item_errors[$index] = $error;
            }
        }

        $default_language = $this->getOption('default_language');

        foreach ($item_errors as $index => $error) {
            $request = $data[$index];

            list($resource_type, $resource_id) = explode('/', $request['ref']);

            $this->model->addList([
                'resource_type' => $resource_type,
                'resource_id'   => $resource_id,
                'lang'          => $default_language,
                'activity_type' => $activity_type,
                'hash'          => $this->activity_hash[$activity_type][$request['ref']] ?? '',
                'status'        => 'error',
                'message'       => $error,
                'request'       => json_encode($request),
                'stale'         => 0,
                'updated_at'    => date('Y-m-d H:i:s')
            ]);

            $this->debug($resource_type, $resource_id, $activity_type, 'error', $error);
        }

        foreach ($data as $index => $_response) {
            if (!isset($item_errors[$index])) {
                list($resource_type, $resource_id) = explode('/', $_response['ref']);

                $this->debug($resource_type, $resource_id, $activity_type, 'not_sent', 'Item is ok, but package sending failed');
            }
        }
    }

    private function activityIsStaled($activities, $resource_type, $resource_id, $hash, $activity_type)
    {
        if ($this->force_stale) {
            $this->debug($resource_type, $resource_id, $activity_type, 'force_stale');
            return true;
        }

        if (empty($activities[$resource_id])) {
            $this->debug($resource_type, $resource_id, $activity_type, 'new');
            return true;
        }

        $activity = $activities[$resource_id];

        if ($activity_type == 'translate') {
            // if a new language has been added, stale is true for sure
            $existing_langs = array_column($activity, 'lang');
            if (array_diff($this->to_langs, $existing_langs)) {
                $this->debug($resource_type, $resource_id, $activity_type, 'changed', 'new_language_added');
                return true;
            }

            $activity = reset($activity);
        }

        $message = null; // do not alter message, only if completed
        if ($activity['status'] == 'completed' && !$this->from_callback) { // da impresia pe callback ca ar face o dubla procesare
            $message = "Hash did not changed";
        }

        $staled = false;
        if ($this->getOption("{$activity_type}_live_update")) {
            $old_hash = $activity['hash'];

            if ($old_hash == $hash) {
                $this->debug($resource_type, $resource_id, $activity_type, 'unchanged', $activity['status']);
                $this->ignoreMoveOnNextEvent($resource_type, $resource_id, $activity_type, $message, null);
            } else {
                $this->debug($resource_type, $resource_id, $activity_type, 'changed');
                $staled = true;
            }
        } else {
            $this->debug($resource_type, $resource_id, $activity_type, 'unchanged', $activity['status']);
            $this->ignoreMoveOnNextEvent($resource_type, $resource_id, $activity_type, $message, null);
        }

        return $staled;
    }

    private function discardNextEvents($activity_type, $resource)
    {
        if ($activity_type == 'generate_content') {
            unset($this->activity_groups['generate_content'][$resource]);
            unset($this->activity_groups['generate_seo'][$resource]);
            unset($this->activity_groups['translate'][$resource]);
        }

        if ($activity_type == 'generate_seo') {
            unset($this->activity_groups['generate_seo'][$resource]);
            unset($this->activity_groups['translate'][$resource]);
        }

        if ($activity_type == 'translate') {
            unset($this->activity_groups['translate'][$resource]);
        }
    }

    public function contentHash($data)
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                sort($data[$k]);

                $data[$k] = implode('___', $data[$k]);
            }
        }

        $separator = ';;;';
        $hash = [];
        foreach ($data as $value) {
            $push = trim(preg_replace('/[\n\r\t\s]+/u', ' ', $value));
            $hash[] = $push;
        }

        $hash_string = implode($separator, $hash);

        // sha256
        $alpha_num = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $hash_string));
        $prefix    = str_pad(substr($alpha_num, 0, 3), 3, "_", STR_PAD_LEFT);
        $suffix    = str_pad(substr($alpha_num, -3), 3, "_", STR_PAD_RIGHT);

        $hash_string = $prefix . '.' . hash('sha256', $hash_string) . '.' . $suffix;

        return $hash_string;
    }

    private function getTranslateFor()
    {
        $translate_fields = (array) $this->getOption('translate_fields', []);

        $translate_for = [];
        foreach ((array) $this->getOption('translate_for', []) as $resource => $status) {
            if (!$status) {
                continue;
            }

            if (in_array($resource, ['categories', 'products'])) {
                $status = array_filter($translate_fields[$resource] ?? []);
            }

            if ($status) {
                $translate_for[$resource] = 1;
            }
        }

        return $translate_for;
    }

    private function getAiRequestPayloadLength($content)
    {
        $payload_length = 0;
        foreach ($content as $key => $val) {
            if (in_array($key, ['name', 'description'])) {
                $payload_length += strlen($val);
            }
        }

        return $payload_length;
    }
}
