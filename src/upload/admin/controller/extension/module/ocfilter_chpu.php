<?php
class ControllerExtensionModuleOCFilterChpu extends Controller
{
    private $error = [];

    public function index()
    {
        $this->load->language('extension/module/ocfilter_chpu');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if ($this->isPostRequest() && $this->validate()) {
            $this->model_setting_setting->editSetting('module_ocfilter_chpu', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
        }

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_home'] = $this->language->get('text_home');
        $data['text_extension'] = $this->language->get('text_extension');
        $data['entry_status'] = $this->language->get('entry_status');


        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/ocfilter_chpu', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['action'] = $this->url->link('extension/module/ocfilter_chpu', 'user_token=' . $this->session->data['user_token']);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

        if (isset($this->request->post['module_ocfilter_chpu'])) {
            $data['module_ocfilter_chpu'] = $this->request->post['module_ocfilter_chpu'];
        } else {
            $data['module_ocfilter_chpu'] = $this->config->get('module_ocfilter_chpu');
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/ocfilter_chpu', $data));
    }

    private function isPostRequest(): bool
    {
        return $this->request->server['REQUEST_METHOD'] == 'POST';
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/ocfilter_chpu')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }
}
