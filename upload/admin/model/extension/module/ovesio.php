<?php

class ModelExtensionModuleOvesio extends Model
{
    public function install() {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ovesio_activity` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `resource_type` VARCHAR(50) NOT NULL COLLATE 'utf8mb4_general_ci',
            `resource_id` BIGINT(20) NOT NULL,
            `activity_type` VARCHAR(20) NOT NULL DEFAULT '' COLLATE 'utf8mb4_general_ci',
            `lang` VARCHAR(10) NOT NULL DEFAULT '' COLLATE 'utf8mb4_general_ci',
            `activity_id` BIGINT(20) NOT NULL DEFAULT '0',
            `hash` VARCHAR(119) NOT NULL DEFAULT '' COLLATE 'utf8mb4_general_ci',
            `status` ENUM('started','completed','error','skipped') NULL DEFAULT NULL COLLATE 'utf8mb4_general_ci',
            `request` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb4_general_ci',
            `response` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb4_general_ci',
            `message` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb4_general_ci',
            `stale` TINYINT(4) NOT NULL DEFAULT '0',
            `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
            `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`) USING BTREE,
            UNIQUE INDEX `resource_type_resource_id_activity_type_lang` (`resource_type`, `resource_id`, `activity_type`, `lang`) USING BTREE,
            INDEX `ovesio_activity_id` (`id`) USING BTREE,
            INDEX `resource_type` (`resource_type`) USING BTREE,
            INDEX `resource_id` (`resource_id`) USING BTREE,
            INDEX `lang` (`lang`) USING BTREE,
            INDEX `action` (`activity_type`) USING BTREE,
            INDEX `status` (`status`) USING BTREE,
            INDEX `ovesio_id` (`activity_id`) USING BTREE,
            INDEX `hash` (`hash`) USING BTREE,
            INDEX `updated_at` (`updated_at`) USING BTREE,
            INDEX `created_at` (`created_at`) USING BTREE,
            INDEX `stale` (`stale`) USING BTREE
        )
        COLLATE='utf8mb4_general_ci'
        ENGINE=InnoDB");
    }

    public function setStale($resource_type, $resource_id, $stale)
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "ovesio_activity` SET stale = '" . (int)$stale . "' WHERE resource_type = '" . $this->db->escape($resource_type) . "' AND resource_id = '" . (int)$resource_id . "'");
    }

    public function getActivities($filters = [])
    {
        $page = isset($filters['page']) ? (int)$filters['page'] : 1;
        $page = max($page, 1);

        $limit = isset($filters['limit']) ? (int)$filters['limit'] : 20;
        $offset = ($page - 1) * $limit;

        $sql = "SELECT ova.*, COALESCE(p.name, c.name, i.title, ag.name, o.name) as resource_name FROM `" . DB_PREFIX . "ovesio_activity` ova";

        $sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` p ON (p.product_id = ova.resource_id AND ova.resource_type = 'product')";
        $sql .= " LEFT JOIN `" . DB_PREFIX . "category_description` c ON (c.category_id = ova.resource_id AND ova.resource_type = 'category')";
        $sql .= " LEFT JOIN `" . DB_PREFIX . "information_description` i ON (i.information_id = ova.resource_id AND ova.resource_type = 'information')";
        $sql .= " LEFT JOIN `" . DB_PREFIX . "attribute_group_description` ag ON (ag.attribute_group_id = ova.resource_id AND ova.resource_type = 'attribute_group')";
        $sql .= " LEFT JOIN `" . DB_PREFIX . "option_description` o ON (o.option_id = ova.resource_id AND ova.resource_type = 'option')";

        $sql .= " WHERE 1";

        $sql = $this->applyFilters($sql, $filters);

        $sql .= " GROUP BY ova.id";
        $sql .= " ORDER BY ova.id DESC";
        $sql .= " LIMIT $offset, $limit";

        $query = $this->db->query($sql);

        return $query->rows;
    }

    public function getActivity($activity_id)
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "ovesio_activity` WHERE id = '" . (int)$activity_id . "' LIMIT 1");

        return $query->row;
    }

    public function getActivitiesTotal($filters = [])
    {
        $sql = "SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "ovesio_activity` as ova";

        if (!empty($filters['resource_name'])) {
            $sql .= " LEFT JOIN `" . DB_PREFIX . "product_description` p ON (p.product_id = ova.resource_id AND ova.resource_type = 'product')";
            $sql .= " LEFT JOIN `" . DB_PREFIX . "category_description` c ON (c.category_id = ova.resource_id AND ova.resource_type = 'category')";
            $sql .= " LEFT JOIN `" . DB_PREFIX . "information_description` i ON (i.information_id = ova.resource_id AND ova.resource_type = 'information')";
            $sql .= " LEFT JOIN `" . DB_PREFIX . "attribute_group_description` ag ON (ag.attribute_group_id = ova.resource_id AND ova.resource_type = 'attribute_group')";
            $sql .= " LEFT JOIN `" . DB_PREFIX . "option_description` o ON (o.option_id = ova.resource_id AND ova.resource_type = 'option')";
        }

        $sql .= " WHERE 1";

        $sql = $this->applyFilters($sql, $filters);

        $query = $this->db->query($sql);

        return $query->row['total'];
    }

    private function applyFilters($sql, $filters)
    {
        if (!empty($filters['resource_name'])) {
            $sql .= " AND COALESCE(p.name, c.name, i.title, ag.name, o.name) LIKE '%" . $this->db->escape($filters['resource_name']) . "%'";
        }

        if (!empty($filters['resource_type'])) {
            $sql .= " AND ova.resource_type = '" . $this->db->escape($filters['resource_type']) . "'";
        }

        if (!empty($filters['resource_id'])) {
            $sql .= " AND ova.resource_id = '" . (int)$filters['resource_id'] . "'";
        }

        if (!empty($filters['status'])) {
            $sql .= " AND ova.status = '" . $this->db->escape($filters['status']) . "'";
        }

        if (!empty($filters['activity_type'])) {
            $sql .= " AND ova.activity_type = '" . $this->db->escape($filters['activity_type']) . "'";
        }

        if (!empty($filters['language'])) {
            $sql .= " AND ova.lang = '" . $this->db->escape($filters['language']) . "'";
        }

        if (!empty($filters['date'])) {
            if ($filters['date'] == 'today') {
                $sql .= " AND DATE(ova.updated_at) = CURDATE()";
            } elseif ($filters['date'] == 'yesterday') {
                $sql .= " AND DATE(ova.updated_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            } elseif ($filters['date'] == 'last7days') {
                $sql .= " AND ova.updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            } elseif ($filters['date'] == 'last30days') {
                $sql .= " AND ova.updated_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            } elseif ($filters['date'] == 'thismonth') {
                $sql .= " AND MONTH(ova.updated_at) = MONTH(CURDATE()) AND YEAR(ova.updated_at) = YEAR(CURDATE())";
            } elseif ($filters['date'] == 'lastmonth') {
                $sql .= " AND MONTH(ova.updated_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(ova.updated_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            } elseif ($filters['date'] == 'custom' && !empty($filters['date_from']) && !empty($filters['date_to'])) {
                $sql .= " AND DATE(ova.updated_at) BETWEEN '" . $this->db->escape($filters['date_from']) . "' AND '" . $this->db->escape($filters['date_to']) . "'";
            }
        }

        return $sql;
    }

    public function getAttributeGroupId($attribute_id)
    {
        $query = $this->db->query("SELECT attribute_group_id FROM `" . DB_PREFIX . "attribute` WHERE attribute_id = '" . (int)$attribute_id . "' LIMIT 1");

        return $query->row ? $query->row['attribute_group_id'] : null;
    }
}