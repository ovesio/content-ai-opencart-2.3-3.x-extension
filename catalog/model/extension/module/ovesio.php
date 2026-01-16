<?php

class ModelExtensionModuleOvesio extends Model
{
    private $default_language_id;
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

        $default_language = $this->config->get($this->module_key . '_default_language');
        $config_language  = $this->config->get('config_language');

        if (stripos($default_language, $config_language) === 0 || $default_language == 'auto') {
            $default_language_id = $this->config->get('config_language_id');
        } else {
            $query = $this->db->query("SELECT language_id FROM " . DB_PREFIX . "language WHERE code LIKE '" . $this->db->escape($default_language) . "%' LIMIT 1");

            if (!$query->row) {
                throw new Exception("Could not detect local default language based on language code '$default_language'");
            }

            $default_language_id = $query->row['language_id'];
        }

        $this->default_language_id = $default_language_id;
    }

    public function getCategories($category_ids, $status)
    {
        $where = '';
        if (!empty($category_ids)) {
            $where = 'AND c.category_id IN (' . implode(',', $category_ids) . ')';
        }

        if (!$status) {
            $where .= " AND c.status != 0";
        }

        $query = $this->db->query("SELECT cd.* FROM " . DB_PREFIX . "category_description as cd
            JOIN " . DB_PREFIX . "category as c ON c.category_id = cd.category_id
            WHERE cd.language_id = '{$this->default_language_id}' $where
            ORDER BY c.category_id");

        return $query->rows;
    }

    public function getCategoriesWithDescriptionDependency($category_ids, $status)
    {
        $where = '';
        if (!empty($category_ids)) {
            $where = 'AND c.category_id IN (' . implode(',', $category_ids) . ')';
        }

        if (!$status) {
            $where .= " AND c.status != 0";
        }

        $query = $this->db->query("SELECT cd.* FROM " . DB_PREFIX . "category_description as cd
        JOIN " . DB_PREFIX . "category as c ON c.category_id = cd.category_id
        JOIN " . DB_PREFIX . "ovesio_activity as ova ON ova.resource_type = 'category' AND ova.resource_id = c.category_id
        WHERE cd.language_id = '{$this->default_language_id}' AND ova.activity_id > 0 AND ova.status = 'completed' $where
        ORDER BY c.category_id");

        return $query->rows;
    }

    public function getProducts($product_id, $status, $out_of_stock)
    {
        $where = '';
        if (!empty($product_id)) {
            $where = ' AND p.product_id IN (' . implode(',', $product_id) . ')';
        }

        if (!$out_of_stock) {
			$where .= " AND p.quantity > '0'";
		}

        if (!$status) {
            $where .= " AND p.status != 0";
        }

        $query = $this->db->query("SELECT pd.* FROM " . DB_PREFIX . "product_description as pd
            JOIN " . DB_PREFIX . "product as p ON p.product_id = pd.product_id
            WHERE pd.language_id = '{$this->default_language_id}' $where
            ORDER BY p.product_id");

        return $query->rows;
    }

    public function getProductsWithDescriptionDependency($product_id, $status, $out_of_stock)
    {
        $where = '';
        if (!empty($product_id)) {
            $where = ' AND p.product_id IN (' . implode(',', $product_id) . ')';
        }

        if (!$out_of_stock) {
			$where .= " AND p.quantity > '0'";
		}

        if (!$status) {
            $where .= " AND p.status != 0";
        }

        $query = $this->db->query("SELECT pd.* FROM " . DB_PREFIX . "product_description as pd
            JOIN " . DB_PREFIX . "product as p ON p.product_id = pd.product_id
            JOIN " . DB_PREFIX . "ovesio_activity as ova ON ova.resource_type = 'product' AND ova.resource_id = p.product_id
            WHERE pd.language_id = '{$this->default_language_id}' AND ova.activity_id > 0 AND ova.status = 'completed' $where
            ORDER BY p.product_id");

        return $query->rows;
    }

    public function getProductsAttributes($product_ids = [])
    {
        $where = '';
        if (!empty($product_ids)) {
            $where = ' AND p.product_id IN (' . implode(',', $product_ids) . ')';
        }

        $query = $this->db->query("SELECT pa.product_id, pa.attribute_id, pa.text
        FROM " . DB_PREFIX . "product_attribute as pa
        JOIN ". DB_PREFIX ."product as p ON p.product_id = pa.product_id
        WHERE pa.language_id = '{$this->default_language_id}' $where
        ORDER BY pa.attribute_id");


        $product_attributes = [];
        foreach ($query->rows as $pa) {
            if (empty($pa['text']))
                continue;

            $product_attributes[$pa['product_id']][$pa['attribute_id']] = trim($pa['text']);
        }

        return $product_attributes;
    }

    public function getAttributes($attribute_ids = [])
    {
        $where = '';
        if (!empty($attribute_ids)) {
            $where = 'AND a.attribute_id IN (' . implode(',', $attribute_ids) . ')';
        }

        $query = $this->db->query("SELECT a.attribute_id, a.attribute_group_id, ad.name FROM " . DB_PREFIX . "attribute_description as ad
        JOIN " . DB_PREFIX . "attribute as a ON a.attribute_id = ad.attribute_id
        WHERE ad.language_id = '{$this->default_language_id}' $where ORDER BY a.attribute_id");

        return $query->rows;
    }

    public function getAttributeGroups($attribute_group_ids = [])
    {
        $where = '';
        if (!empty($attribute_group_ids)) {
            $where = 'AND agd.attribute_group_id IN (' . implode(',', $attribute_group_ids) . ')';
        }

        $query = $this->db->query("SELECT agd.attribute_group_id, agd.name FROM " . DB_PREFIX . "attribute_group_description as agd
        JOIN " . DB_PREFIX . "attribute_group as ag ON ag.attribute_group_id = agd.attribute_group_id
        WHERE agd.language_id = '{$this->default_language_id}' {$where} ORDER BY ag.attribute_group_id");

        return $query->rows;
    }

    public function getGroupsAttributes($attribute_group_ids = [])
    {
        $where = '';
        if (!empty($attribute_group_ids)) {
            $where = 'AND a.attribute_group_id IN (' . implode(',', $attribute_group_ids) . ')';
        }

        $query = $this->db->query("SELECT a.attribute_id, a.attribute_group_id, ad.name FROM " . DB_PREFIX . "attribute_description as ad
        JOIN " . DB_PREFIX . "attribute as a ON a.attribute_id = ad.attribute_id
        WHERE ad.language_id = '{$this->default_language_id}' $where ORDER BY a.attribute_id");

        return $query->rows;
    }

    public function getOptionValues($option_ids = [])
    {
        $where = '';
        if (!empty($option_ids)) {
            $where = 'AND ovd.option_id IN (' . implode(',', $option_ids) . ')';
        }

        $query = $this->db->query("SELECT ovd.option_id, ovd.option_value_id, ovd.name FROM " . DB_PREFIX . "option_value_description as ovd WHERE ovd.language_id = '{$this->default_language_id}' $where");

        return $query->rows;
    }

    public function getOptions($option_ids = [])
    {
        $where = '';
        if (!empty($option_ids)) {
            $where = 'AND o.option_id IN (' . implode(',', $option_ids) . ')';
        }

        $query = $this->db->query("SELECT o.option_id, od.name FROM " . DB_PREFIX . "option_description as od
        JOIN " . DB_PREFIX . "option as o ON o.option_id = od.option_id
        WHERE od.language_id = '{$this->default_language_id}' $where ORDER BY o.option_id");

        return $query->rows;
    }

    public function updateCategoryDescription($category_id, $language_id, $description)
    {
        if (empty($description)) {
            return;
        }

        $fields_sql = [];
        foreach ($description as $key => $value) {
            $fields_sql[] = "`" . $key . "` = '" . $this->db->escape($value) . "'";
        }

        // check if exists first
        $query = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "category_description WHERE category_id = '" . (int)$category_id . "' AND language_id = {$language_id}");

        if ($query->row) {
            $this->db->query("UPDATE " . DB_PREFIX . "category_description SET " . implode(', ', $fields_sql) . " WHERE category_id = {$category_id} AND language_id = {$language_id}");
        } else {
            $this->db->query("INSERT INTO " . DB_PREFIX . "category_description SET category_id = {$category_id}, language_id = {$language_id}, " . implode(', ', $fields_sql));
        }
    }

    public function updateAttributeGroupDescription($attribute_group_id, $language_id, $name)
    {
        // check if exists first
        $query = $this->db->query("SELECT attribute_group_id FROM " . DB_PREFIX . "attribute_group_description WHERE attribute_group_id = '" . (int)$attribute_group_id . "' AND language_id = {$language_id}");

        if ($query->row) {
            $this->db->query("UPDATE " . DB_PREFIX . "attribute_group_description SET name = '" . $this->db->escape($name) . "' WHERE attribute_group_id = {$attribute_group_id} AND language_id = {$language_id}");
        } else {
            $this->db->query("INSERT INTO " . DB_PREFIX . "attribute_group_description SET attribute_group_id = {$attribute_group_id}, language_id = {$language_id}, name = '" . $this->db->escape($name) . "'");
        }
    }

    public function updateAttributeDescription($attribute_id, $language_id, $name)
    {
        // check if exists first
        $query = $this->db->query("SELECT attribute_id FROM " . DB_PREFIX . "attribute_description WHERE attribute_id = '" . (int)$attribute_id . "' AND language_id = {$language_id}");

        if ($query->row) {
            $this->db->query("UPDATE " . DB_PREFIX . "attribute_description SET name = '" . $this->db->escape($name) . "' WHERE attribute_id = {$attribute_id} AND language_id = {$language_id}");
        } else {
            $this->db->query("INSERT INTO " . DB_PREFIX . "attribute_description SET attribute_id = {$attribute_id}, language_id = {$language_id}, name = '" . $this->db->escape($name) . "'");
        }
    }

    public function updateOptionDescription($option_id, $language_id, $name)
    {
        // check if exists first
        $query = $this->db->query("SELECT option_id FROM " . DB_PREFIX . "option_description WHERE option_id = '" . (int)$option_id . "' AND language_id = {$language_id}");

        if ($query->row) {
            $this->db->query("UPDATE " . DB_PREFIX . "option_description SET name = '" . $this->db->escape($name) . "' WHERE option_id = {$option_id} AND language_id = {$language_id}");
        } else {
            $this->db->query("INSERT INTO " . DB_PREFIX . "option_description SET option_id = {$option_id}, language_id = {$language_id}, name = '" . $this->db->escape($name) . "'");
        }
    }

    public function updateProductDescription($product_id, $language_id, $description)
    {
        if (empty($description)) {
            return;
        }

        $fields_sql = [];
        foreach ($description as $key => $value) {
            $fields_sql[] = "`" . $key . "` = '" . $this->db->escape($value) . "'";
        }

        // check if exists first
        $query = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "product_description WHERE product_id = '" . (int)$product_id . "' AND language_id = {$language_id}");

        if ($query->row) {
            $this->db->query("UPDATE " . DB_PREFIX . "product_description SET " . implode(', ', $fields_sql) . " WHERE product_id = {$product_id} AND language_id = {$language_id}");
        } else {
            $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = {$product_id}, language_id = {$language_id}, " . implode(', ', $fields_sql));
        }
    }

    public function updateAttributeValueDescription($product_id, $attribute_id, $language_id, $text)
    {
        // check if exists first
        $query = $this->db->query("SELECT attribute_id FROM " . DB_PREFIX . "product_attribute WHERE product_id = '" . (int)$product_id . "' AND attribute_id = '" . (int)$attribute_id . "' AND language_id = {$language_id}");

        if (!empty($query->row['attribute_id'])) {
            $this->db->query("UPDATE " . DB_PREFIX . "product_attribute SET text = '" . $this->db->escape($text) . "' WHERE product_id = '" . $product_id . "' AND attribute_id = '" . (int)$attribute_id . "' AND language_id = {$language_id}");
        } else {
            $this->db->query("INSERT INTO " . DB_PREFIX . "product_attribute SET product_id = '" . (int)$product_id . "', attribute_id = '" . (int)$attribute_id . "', language_id = {$language_id}, text = '" . $this->db->escape($text) . "'");
        }
    }

    public function updateOptionValueDescription($option_value_id, $language_id, $name)
    {
        // check if exists first
        $query = $this->db->query("SELECT option_value_id FROM " . DB_PREFIX . "option_value_description WHERE option_value_id = '" . (int)$option_value_id . "' AND language_id = {$language_id}");

        if ($query->row) {
            $this->db->query("UPDATE " . DB_PREFIX . "option_value_description SET name = '" . $this->db->escape($name) . "' WHERE option_value_id = {$option_value_id} AND language_id = {$language_id}");
        } else {
            $option_id = $this->db->query("SELECT option_id FROM " . DB_PREFIX . "option_value WHERE option_value_id = '" . (int)$option_value_id . "'")->row['option_id'];

            $this->db->query("INSERT INTO " . DB_PREFIX . "option_value_description SET option_value_id = '" . (int)$option_value_id . "', language_id = {$language_id}, option_id = {$option_id}, name = '" . $this->db->escape($name) . "'");
        }
    }

    public function getProductForSeo($product_id, $language_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE product_id = " . (int)$product_id);
        $data = $query->row;

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_description` WHERE product_id = " . (int)$product_id . " AND language_id = " . (int)$language_id);
        $data['product_description'][$language_id] = $query->row ?? [];

        return $data;
    }

    public function getCategoryForSeo($category_id, $language_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "category` WHERE category_id = " . (int)$category_id);
        $data = $query->row;

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "category_description` WHERE category_id = " . (int)$category_id . " AND language_id = " . (int)$language_id);
        $data['category_description'][$language_id] = $query->row ?? [];

        return $data;
    }

    public function addList(array $data = [])
    {
        $fields = [];
        foreach ($data as $key => $value) {
            $fields[] = "`" . $key . "` = '" . $this->db->escape($value) . "'";
        }

        $fields_sql = implode(', ', $fields);

        $this->db->query("INSERT INTO " . DB_PREFIX . "ovesio_activity SET " . $fields_sql . " ON DUPLICATE KEY UPDATE " . $fields_sql);
    }

    public function getProductCategories($product_ids)
    {
        $product_category_data = [];

        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product_to_category WHERE product_id IN (". implode(',', $product_ids) . ")");

        foreach ($query->rows as $result) {
            $product_category_data[$result['product_id']][] = $result['category_id'];
        }

        return $product_category_data;
    }

    public function getCategory($category_id) {
		$query = $this->db->query("SELECT DISTINCT c.category_id, cd2.name,
        (SELECT GROUP_CONCAT(cd1.name ORDER BY level SEPARATOR '&nbsp;&nbsp;&gt;&nbsp;&nbsp;')
        FROM " . DB_PREFIX . "category_path cp LEFT
        JOIN " . DB_PREFIX . "category_description cd1 ON (cp.path_id = cd1.category_id AND cp.category_id != cp.path_id)
        WHERE cp.category_id = c.category_id AND cd1.language_id = '" . (int)$this->config->get('config_language_id') . "'
        GROUP BY cp.category_id) AS path
        FROM " . DB_PREFIX . "category c
        LEFT JOIN " . DB_PREFIX . "category_description cd2 ON (c.category_id = cd2.category_id)
        WHERE c.category_id = '" . (int)$category_id . "' AND cd2.language_id = '" . (int)$this->config->get('config_language_id') . "'");

		return $query->row;
	}

    public function getCronList($params = [])
    {
        $resource_type = isset($params['resource_type']) ? $this->db->escape($params['resource_type']) : null;
        $resource_id   = isset($params['resource_id']) ? (int) $params['resource_id'] : null;
        $limit         = isset($params['limit']) ? (int) $params['limit'] : 20;

        $limit = max(10, $limit);

        $generate_content_status = (bool) $this->config->get($this->module_key . '_generate_content_status');
        $generate_seo_status     = (bool) $this->config->get($this->module_key . '_generate_seo_status');
        $translate_status        = (bool) $this->config->get($this->module_key . '_translate_status');

        $generate_content_include_disabled = array_filter((array) $this->config->get($this->module_key . '_generate_content_include_disabled'));
        $generate_seo_include_disabled     = array_filter((array) $this->config->get($this->module_key . '_generate_seo_include_disabled'));
        $translate_include_disabled        = array_filter((array) $this->config->get($this->module_key . '_translate_include_disabled'));

        $generate_content_for = array_filter((array) $this->config->get($this->module_key . '_generate_content_for'));
        $generate_seo_for     = array_filter((array) $this->config->get($this->module_key . '_generate_seo_for'));
        $translate_fields     = (array) $this->config->get($this->module_key . '_translate_fields');
        $translate_for        = [];

        foreach ((array) $this->config->get($this->module_key . '_translate_for') as $resource => $status) {
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

        $language_settings = (array) $this->config->get($this->module_key . '_language_settings');

        $translate_languages = [];
        foreach ($language_settings as $lang_id => $ls) {
            if (!empty($ls['translate']) && !empty($ls['code'])) {
                $translate_languages[] = $ls['code'];
            }
        }

        sort($translate_languages);

        if (empty($translate_languages) || empty($translate_for)) { // no translation languages selected => translation = disabled
            $translate_status = false;
        }

        $generate_content_stock_0 = (bool) $this->config->get($this->module_key . '_generate_content_include_stock_0');
        $generate_seo_stock_0     = (bool) $this->config->get($this->module_key . '_generate_seo_include_stock_0');
        $translate_stock_0        = (bool) $this->config->get($this->module_key . '_translate_include_stock_0');

        $resources  = [];
        if ($generate_content_status) {
            $resources = array_merge($resources, $generate_content_for);
        }

        if ($generate_seo_status) {
            $resources = array_merge($resources, $generate_seo_for);
        }

        if ($translate_status) {
            $resources = array_merge($resources, $translate_for);
        }

        $send_disabled_categories = 0;
        $send_disabled_categories += !empty($generate_content_include_disabled['categories']);
        $send_disabled_categories += !empty($generate_seo_include_disabled['categories']);
        $send_disabled_categories += !empty($translate_include_disabled['categories']);

        $send_disabled_products = 0;
        $send_disabled_products += !empty($generate_content_include_disabled['products']);
        $send_disabled_products += !empty($generate_seo_include_disabled['products']);
        $send_disabled_products += !empty($translate_include_disabled['products']);

        $send_stock_0_products = 0;
        $send_stock_0_products += !empty($generate_content_stock_0);
        $send_stock_0_products += !empty($generate_seo_stock_0);
        $send_stock_0_products += !empty($translate_stock_0);


        $union = [];

        if ($translate_status) {
            if (!empty($resources['attributes']) && (!$resource_type || $resource_type == 'attribute_group')) {
                $attributes_sql = "SELECT 'attribute_group' as resource, a.attribute_group_id AS resource_id
                    FROM " . DB_PREFIX . "attribute_description as ad
                    JOIN " . DB_PREFIX . "attribute as a ON a.attribute_id = ad.attribute_id
                    WHERE ad.language_id = '{$this->default_language_id}'";

                    if ($resource_id) {
                        $attributes_sql .= " AND a.attribute_group_id = '" . (int)$resource_id . "'";
                    }

                $union[] = $attributes_sql;
            }

            if (!empty($resources['options']) && (!$resource_type || $resource_type == 'option')) {
                $options_sql = "SELECT 'option' as resource, o.option_id as resource_id
                    FROM " . DB_PREFIX . "option_description as od
                    JOIN " . DB_PREFIX . "option as o ON o.option_id = od.option_id
                    WHERE od.language_id = '{$this->default_language_id}'";

                    if ($resource_id) {
                        $options_sql .= " AND o.option_id = '" . (int)$resource_id . "'";
                    }

                $union[] = $options_sql;
            }
        }

        if (!empty($resources['categories'])) {
            if (!$resource_type || $resource_type == 'category') {
                $categories_sql = "SELECT 'category' as resource, cd.category_id as resource_id
                    FROM " . DB_PREFIX . "category_description as cd
                    JOIN " . DB_PREFIX . "category as c ON c.category_id = cd.category_id
                    WHERE cd.language_id = '{$this->default_language_id}'";

                    if ($send_disabled_categories == 0) {
                        $categories_sql .= " AND c.status = 1";
                    }

                    if ($resource_id) {
                        $categories_sql .= " AND cd.category_id = '" . (int)$resource_id . "'";
                    }

                $union[] = $categories_sql;
            }
        }

        if (!empty($resources['products'])) {
            if (!$resource_type || $resource_type == 'product') {
                $products_sql = "SELECT 'product' as resource, p.product_id as resource_id
                    FROM " . DB_PREFIX . "product as p
                    JOIN " . DB_PREFIX . "product_description as pd ON p.product_id = pd.product_id
                    where pd.language_id = '{$this->default_language_id}'";

                    if ($send_disabled_products == 0) {
                        $products_sql .= " AND p.status = 1";
                    }

                    if ($send_stock_0_products == 0) {
                        $products_sql .= " AND p.quantity > '0'";
                    }

                    if ($resource_id) {
                        $products_sql .= " AND pd.product_id = '" . (int)$resource_id . "'";
                    }

                $union[] = $products_sql;
            }
        }

        if (!$union) {
            return [];
        }

        $translate_languages_hash = implode(',', $translate_languages);

        $union_sql = "\n(" . implode(") \nUNION (", $union) . ')';

        /**
         * Select unstarted activities, or started and not finished activities
         */
        $sql = "SELECT
            r.`resource`,
            r.resource_id,";

            if ($translate_status) {
                $sql .= "\n COUNT(if (ova.activity_type = 'translate', 1, null)) as count_translate,";
                $sql .= "\n GROUP_CONCAT(IF (ova.activity_type = 'translate', ova.lang, null) ORDER BY ova.lang SEPARATOR ',') as lang_hash,";
            }

            if ($generate_content_status) {
                $sql .= "\n COUNT(if (ova.activity_type = 'generate_content', 1, null)) as count_generate_content,";
            }

            if ($generate_seo_status) {
                $sql .= "\n COUNT(if (ova.activity_type = 'generate_seo', 1, null)) as count_generate_seo,";
            }

            $sql .= "\n ova.stale AS stale
            FROM ($union_sql) as r
            LEFT JOIN " . DB_PREFIX . "ovesio_activity as ova ON ova.resource_type = r.resource AND ova.resource_id = r.resource_id
            GROUP BY r.`resource`, r.resource_id";

            $having = [];

            $having[] = "max(stale) = 1";

            if ($params['resource_type'] && $params['resource_id']) {
                $having[] = "stale = 0"; // any existing activity for this resource
            }

            if ($translate_status) {
                $resource_in = $this->resourcesToActivities(array_keys($translate_for));
                $resource_in[] = '-1'; // avoid error

                $resource_in = "'" . implode("', '", $resource_in) . "'";

                $having[] = "(resource in ($resource_in) AND (count_translate = 0 OR lang_hash != '$translate_languages_hash'))";
            }

            if ($generate_content_status) {
                $resource_in = $this->resourcesToActivities(array_keys($generate_content_for));
                $resource_in[] = '-1'; // avoid error

                $resource_in = "'" . implode("', '", $resource_in) . "'";

                $having[] = "(resource in ($resource_in) AND count_generate_content = 0)";
            }

            if ($generate_seo_status) {
                $resource_in = $this->resourcesToActivities(array_keys($generate_seo_for));
                $resource_in[] = '-1'; // avoid error

                $resource_in = "'" . implode("', '", $resource_in) . "'";

                $having[] = "(resource in ($resource_in) AND count_generate_seo = 0)";
            }

            if ($having) {
                $sql .= " HAVING " . implode(' OR ', $having);
            }

            $sql .= " ORDER BY if (ova.id AND ova.status != 'error', 0, 1), RAND() LIMIT $limit";

        $query = $this->db->query($sql);

        $activities = [];
        foreach ($query->rows as $row) {
            $key = $row['resource'] . '/' . $row['resource_id'];

            $activities[$key] = [
                'generate_content' => null,
                'generate_seo'     => null,
                'translate'        => [],
            ];
        }

        if (!$activities) {
            return [];
        }

        /**
         * Update current progress
         */
        $conditions_sql = [];
        foreach ($query->rows as $row) {
            $conditions_sql[] = "(resource_type = '{$row['resource']}' AND resource_id = '{$row['resource_id']}')";
        }

        $conditions_sql = implode(' OR ', $conditions_sql);

        // am obtinut lista elementelor, acum trebuie sa le obtinem la fiecare si activitatile parcurse
        $sql = "SELECT id, resource_type, resource_id, activity_type, lang, activity_id, hash, status, stale, updated_at FROM " . DB_PREFIX . "ovesio_activity WHERE $conditions_sql";

        $query = $this->db->query($sql);

        foreach ($query->rows as $row) {
            $key = $row['resource_type'] . '/' . $row['resource_id'];

            if (is_array($activities[$key][$row['activity_type']])) { // translate
                $activities[$key][$row['activity_type']][] = $row;
            } else {
                $activities[$key][$row['activity_type']] = $row;
            }
        }

        return $activities;
    }

    public function getActivityById($id)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "ovesio_activity WHERE id = " . (int) $id);

        return $query->row;
    }

    private function resourcesToActivities($resources)
    {
        $resource_to_activity_type = [
            'products'   => 'product',
            'categories' => 'category',
            'attributes' => 'attribute_group',
            'options'    => 'option',
        ];

        return array_map(function($res) use ($resource_to_activity_type) {
            return isset($resource_to_activity_type[$res]) ? $resource_to_activity_type[$res] : $res;
        }, $resources);
    }

    public function skipRunningTranslations()
    {
        $this->db->query("UPDATE " . DB_PREFIX . "ovesio_activity SET stale = 0, status = 'skipped' WHERE activity_type = 'translate' AND status = 'started'");
    }
}