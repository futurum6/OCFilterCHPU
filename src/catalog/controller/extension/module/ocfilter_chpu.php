<?php
class ControllerExtensionModuleOcfilterChpu extends Controller {

    /**
     * AJAX метод для конвертации ocf URL в CHPU
     */
    public function convertUrl() {
        $json = array('success' => false);
        
        if ($this->config->get('ocfilter_chpu_status') && isset($this->request->post['url'])) {
            $url = $this->request->post['url'];
            
            // Парсим URL
            $parsed_url = parse_url($url);
            $query_params = array();
            
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_params);
            }
            
            if (isset($query_params['ocf']) && isset($query_params['path'])) {
                $this->load->library('ocfilter_chpu');
                
                $chpu_path = $this->ocfilter_chpu->generateChpuPath($query_params['ocf']);
                
                if ($chpu_path) {
                    // Получаем базовый URL категории
                    $base_url = $this->url->link('product/category', 'path=' . $query_params['path']);
                    
                    // Убираем другие параметры кроме нужных
                    $clean_params = array();
                    foreach ($query_params as $key => $value) {
                        if (!in_array($key, array('ocf', 'route'))) {
                            $clean_params[$key] = $value;
                        }
                    }
                    
                    if (!empty($clean_params)) {
                        $base_url .= '&' . http_build_query($clean_params);
                    }
                    
                    $json['success'] = true;
                    $json['chpu_url'] = rtrim($base_url, '/') . $chpu_path;
                }
            }
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}