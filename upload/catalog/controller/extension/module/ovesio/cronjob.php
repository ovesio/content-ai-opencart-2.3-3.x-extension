<?php

use Ovesio\QueueHandler;

require_once(modification(DIR_SYSTEM . '../catalog/model/extension/module/ovesio.php'));

class ControllerExtensionModuleOvesioCronjob extends Controller
{
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

        $this->load->library('ovesio');
    }

    public function index()
    {
        if (!$this->config->get($this->module_key . '_status')) {
            return $this->setOutput(['error' => 'Module is disabled']);
        }

        $resource_type = !empty($this->request->get['resource_type']) ? $this->request->get['resource_type'] : null;
        $resource_id   = !empty($this->request->get['resource_id']) ? (int) $this->request->get['resource_id'] : null;
        $limit         = !empty($this->request->get['limit']) ? (int) $this->request->get['limit'] : 20;

        $status = 0;
        $status += (bool) $this->config->get($this->module_key . '_generate_content_status');
        $status += (bool) $this->config->get($this->module_key . '_generate_seo_status');
        $status += (bool) $this->config->get($this->module_key . '_translate_status');

        if ($status == 0) {
            return $this->setOutput(['error' => 'All operations are disabled']);
        }

        /**
         * @var QueueHandler
         */
        $queue_handler = $this->ovesio->buildQueueHandler();

        $list = $queue_handler->processQueue([
            'resource_type' => $resource_type,
            'resource_id'   => $resource_id,
            'limit'         => $limit,
        ]);

        $queue_handler->showDebug();

        echo "Entries found: " . count($list);
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
}
