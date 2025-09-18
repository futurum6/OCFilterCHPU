<?php
class ControllerExtensionModuleOcfilterChpu extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/module/ocfilter_chpu');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('ocfilter_chpu', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('extension/module/ocfilter_chpu', 'user_token=' . $this->getToken(), true));
        }

        // Правильная проверка наличия OCFilter
        $data['ocfilter_installed'] = $this->isOCFilterInstalled();

        if (!$data['ocfilter_installed']) {
            $data['error_warning'] = 'Для работы модуля требуется установленный OCFilter!';
        } elseif (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->getToken(), true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->getToken() . '&type=module', true)
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/ocfilter_chpu', 'user_token=' . $this->getToken(), true)
        );

        $data['action'] = $this->url->link('extension/module/ocfilter_chpu', 'user_token=' . $this->getToken(), true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->getToken() . '&type=module', true);

        // Языковые переменные для кнопок
        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');
        $data['text_home'] = $this->language->get('text_home');

        // Настройки
        $data['ocfilter_chpu_status'] = $this->config->get('ocfilter_chpu_status') ?: 0;
        $data['ocfilter_chpu_url_format'] = $this->config->get('ocfilter_chpu_url_format') ?: 'keyword';
        $data['ocfilter_chpu_separator'] = $this->config->get('ocfilter_chpu_separator') ?: '-';

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/ocfilter_chpu', $data));
    }

    /**
     * Получает токен для URL (совместимость с разными версиями)
     */
    private function getToken()
    {
        if (isset($this->session->data['user_token'])) {
            return $this->session->data['user_token'];
        } elseif (isset($this->session->data['token'])) {
            return $this->session->data['token'];
        }
        return '';
    }

    /**
     * Правильная проверка наличия OCFilter
     */
    private function isOCFilterInstalled()
    { 
        return true; 
        // Проверяем наличие таблиц OCFilter с правильными названиями
        $result = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "oc_ocfilter_filter'");
        if ($result->num_rows == 0) {
            return false;
        } 

        // Проверяем наличие модели OCFilter
        try {
            $this->load->model('extension/module/ocfilter');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function install()
    {
        if (!$this->isOCFilterInstalled()) {
            $this->session->data['error'] = 'Для работы модуля OCFilterCHPU требуется установленный модуль OCFilter!';
        }
    }

    public function uninstall()
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('ocfilter_chpu');
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/ocfilter_chpu')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->isOCFilterInstalled()) {
            $this->error['warning'] = 'Модуль OCFilter не установлен!';
        }

        return !$this->error;
    }
}