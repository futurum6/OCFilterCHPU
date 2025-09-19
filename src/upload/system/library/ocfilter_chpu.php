<?php

class OCFilterCHPU
{
    private $config;
    private $db;
    private $params;
    private $opencart;
    private $helper;

    public function __construct($registry)
    {
        $this->config = $registry->get('config');
        $this->db = $registry->get('db');
        $registry->get('load')->library('ocfilter/params');
        $this->params = $registry->get('params');
        $registry->get('load')->library('ocfilter/helper');
        $this->helper = $registry->get('helper');
        $registry->get('load')->library('ocfilter/opencart');
        $this->opencart = $registry->get('opencart');
    }

    public function isEnabled()
    {
        return $this->config && $this->config->get('module_ocfilter_chpu_status') && $this->isOCFilterAvailable();
    }

    private function isOCFilterAvailable()
    {
        $result = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "ocfilter_filter'");
        return $result->num_rows > 0;
    }

    public function rewrite($link)
    {
        $url_info = parse_url(str_replace('&amp;', '&', $link));

        if (!isset($url_info['query'])) {
            return $link;
        }

        parse_str($url_info['query'], $data);

        if (isset($data['ocf'])) {
            $ocf_value = $data['ocf'];
            unset($data['ocf']);

            $url = $url_info['scheme'] . '://' . $url_info['host'];
            if (!empty($url_info['port'])) $url .= ':' . $url_info['port'];
            if (!empty($url_info['path'])) $url .= $url_info['path'];

            if ($ocf_value) {
                $parameters = $this->buildChpuUrlFromOcfCode($ocf_value);
                $url = rtrim($url, '/') . '/' . ltrim($parameters, '/');
            }
            if ($data) $url .= '?' . urldecode(http_build_query($data));
            return $url;
        }
        return $link;
    }

    public function buildChpuUrlFromOcfCode($ocf_code)
    {
        if (!$ocf_code) {
            return '';
        }

        $params = $this->params->decode($ocf_code);

        if (!$params) {
            return '';
        }

        foreach ($params as $filter_key => $values) {
            $filter_info = $this->opencart->model_extension_module_ocfilter->getFilter($filter_key);

            if (!$filter_info) {
                continue;
            }

            $filter_slug = $this->createFilterSlug($filter_info['name']);

            if (!$filter_slug) {
                continue;
            }

            $value_slugs = [];
            foreach ($values as $value_id) {
                if ($this->params->isRange($value_id)) {
                    list($min, $max) = $this->params->parseRange($value_id);
                    $chpu_segments[] = $filter_slug . '-' . $min . '-' . $max;
                    continue 2; 
                }

                $value_name = $this->opencart->model_extension_module_ocfilter->getFilterValueName($filter_key, $value_id);

                if ($value_name) {
                    $value_slug = $this->createFilterSlug($value_name);
                    if ($value_slug) {
                        $value_slugs[] = $value_slug;
                    }
                }
            }
            
            if ($value_slugs) {
                if (count($value_slugs) == 1) {
                    $chpu_segments[] = $filter_slug . '-' . $value_slugs[0];
                } else {
                    $chpu_segments[] = $filter_slug . '--' . implode('-', $value_slugs);
                }
            }
        }
        return implode('/', $chpu_segments);
    }

    private function createFilterSlug($text)
    {
        if (!$text) {
            return '';
        }
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
        $text = function_exists('utf8_strtolower') ? utf8_strtolower($text) : mb_strtolower($text, 'UTF-8');
        $text = $this->helper->translit($text);
        $text = preg_replace('/[^a-z0-9\s\-]/', '', $text);
        $text = preg_replace('/[\s\-]+/', '-', $text);
        $text = trim($text, '-');

        return $text;
    }
}
