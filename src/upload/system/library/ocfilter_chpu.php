<?php
class OCFilterCHPU {
    private $registry;
    private $config;
    private $db;
    private $request;
    private $url;
    private $cache;
    private $ocfilter_model;

    public function __construct($registry) {
        $this->registry = $registry;
        if ($registry) {
            $this->config = $registry->get('config');
            $this->db = $registry->get('db');
            $this->request = $registry->get('request');
            $this->url = $registry->get('url');
            $this->cache = $registry->get('cache');

            try {
                $registry->get('load')->model('extension/module/ocfilter');
                $this->ocfilter_model = $registry->get('load')->model_extension_module_ocfilter;
            } catch (Exception $e) {}
        }
    }

    public function isEnabled() {
        return $this->config && $this->config->get('ocfilter_chpu_status') && $this->isOCFilterAvailable();
    }

    private function isOCFilterAvailable() {
        $result = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "oc_ocfilter_filter'");
        return $result->num_rows > 0;
    }

    public function convertUrlArgs($route, $args) {
        if (!$this->isEnabled() || $route !== 'product/category' || strpos($args, 'ocf=') === false) {
            return $args;
        }

        parse_str($args, $params);

        if (isset($params['ocf'], $params['path'])) {
            $chpu_path = $this->generateChpuPath($params['ocf']);
            if ($chpu_path) {
                $category_keyword = $this->getCategoryKeywordByPath($params['path']);
                if ($category_keyword) {
                    unset($params['ocf'], $params['route'], $params['path']);
                    $params['_route_'] = $category_keyword . $chpu_path;
                }
            }
        }

        return http_build_query($params);
    }

    private function getCategoryKeywordByPath($path) {
        $cache_key = 'ocfilter_chpu.category.keyword.' . $path . '.' . $this->config->get('config_store_id') . '.' . $this->config->get('config_language_id');
        $keyword = $this->cache->get($cache_key);

        if ($keyword === null) {
            $query = $this->db->query("
                SELECT keyword FROM " . DB_PREFIX . "seo_url 
                WHERE `query` = 'category_id=" . (int)$path . "'
                AND store_id = '" . (int)$this->config->get('config_store_id') . "'
                AND language_id = '" . (int)$this->config->get('config_language_id') . "'
                LIMIT 1
            ");
            $keyword = $query->num_rows ? $query->row['keyword'] : false;
            $this->cache->set($cache_key, $keyword);
        }

        return $keyword;
    }

    public function processIncomingUrl() {
        if (!$this->request || !isset($this->request->get['_route_']) || !$this->isEnabled()) {
            return;
        }

        $parts = explode('/', trim($this->request->get['_route_'], '/'));
        if (count($parts) < 2) return;

        $idx = $this->findFilterStartIndex($parts);
        if ($idx === false) return;

        $category_parts = array_slice($parts, 0, $idx);
        $filter_parts = array_slice($parts, $idx);

        $ocf_code = $this->parseChpuToOcf($filter_parts);
        if ($ocf_code) {
            $category_path = implode('/', $category_parts);
            $category_id = $this->getCategoryIdByPath($category_path);

            if ($category_id) {
                $this->request->get['_route_'] = $category_path;
                $this->request->get['path'] = $category_id;
                $this->request->get['ocf'] = $ocf_code;
                $this->request->get['route'] = 'product/category';
            }
        }
    }

    private function findFilterStartIndex($parts) {
        foreach ($parts as $i => $part) {
            if (strpos($part, '--') !== false || (strpos($part, '-') !== false && $this->isFilterPart($part))) {
                return $i;
            }
        }
        return false;
    }

    private function isFilterPart($part) {
        if (strpos($part, '-') === false) return false;
        list($name,) = explode('-', $part, 2);
        return $this->getFilterBySlug($name) !== null;
    }

    private function parseChpuToOcf($parts) {
        $filters = [];

        foreach ($parts as $part) {
            if (strpos($part, '--') !== false) {
                list($f_name, $v_str) = explode('--', $part, 2);
                $v_names = explode('-', $v_str);
            } elseif (strpos($part, '-') !== false) {
                list($f_name, $v_name) = explode('-', $part, 2);
                $v_names = [$v_name];
            } else {
                continue;
            }

            $filter = $this->getFilterBySlug($f_name);
            if ($filter) {
                $values = [];
                foreach ($v_names as $v_name) {
                    $value = $this->getFilterValueBySlug($filter['option_id'], $v_name);
                    if ($value) $values[] = $value['option_value_id'];
                }
                if ($values) {
                    $filters[$filter['option_id']] = ['values' => $values, 'relation' => 1];
                }
            }
        }

        return $this->buildOcfString($filters);
    }

    public function generateChpuPath($ocf) {
        if (!$this->isEnabled() || empty($ocf)) return '';

        $filters = $this->parseOcfString($ocf);
        $parts = [];

        foreach ($filters as $fid => $data) {
            $filter = $this->getFilterById($fid);
            if (!$filter) continue;

            $f_slug = $this->createSlug($filter['name']);
            $v_slugs = [];

            foreach ($data['values'] as $vid) {
                $value = $this->getFilterValueById($vid);
                if ($value) $v_slugs[] = $this->createSlug($value['name']);
            }

            if ($f_slug && $v_slugs) {
                $parts[] = count($v_slugs) == 1 
                    ? "$f_slug-$v_slugs[0]" 
                    : "$f_slug--" . implode('-', $v_slugs);
            }
        }

        return $parts ? '/' . implode('/', $parts) : '';
    }

    private function getFilterBySlug($slug) {
        $key = 'ocfilter_chpu.filter.slug.' . $slug . '.' . $this->config->get('config_language_id');
        $data = $this->cache->get($key);

        if ($data === null) {
            $query = $this->db->query("
                SELECT o.oc_ocfilter_filter_id as option_id, od.name 
                FROM " . DB_PREFIX . "oc_ocfilter_filter o
                LEFT JOIN " . DB_PREFIX . "oc_ocfilter_filter_description od ON o.oc_ocfilter_filter_id = od.oc_ocfilter_filter_id
                WHERE od.language_id = '" . (int)$this->config->get('config_language_id') . "' AND o.status = '1' LIMIT 50
            ");
            $data = false;
            foreach ($query->rows as $row) {
                if ($this->createSlug($row['name']) === $slug) {
                    $data = $row;
                    break;
                }
            }
            $this->cache->set($key, $data);
        }

        return $data;
    }

    private function getFilterValueBySlug($oid, $slug) {
        $key = 'ocfilter_chpu.value.slug.' . $oid . '.' . $slug . '.' . $this->config->get('config_language_id');
        $data = $this->cache->get($key);

        if ($data === null) {
            $query = $this->db->query("
                SELECT ov.oc_ocfilter_filter_value_id as option_value_id, ovd.name
                FROM " . DB_PREFIX . "oc_ocfilter_filter_value ov
                LEFT JOIN " . DB_PREFIX . "oc_ocfilter_filter_value_description ovd ON ov.oc_ocfilter_filter_value_id = ovd.oc_ocfilter_filter_value_id
                WHERE ov.oc_ocfilter_filter_id = '" . (int)$oid . "' AND ovd.language_id = '" . (int)$this->config->get('config_language_id') . "' LIMIT 100
            ");
            $data = false;
            foreach ($query->rows as $row) {
                if ($this->createSlug($row['name']) === $slug) {
                    $data = $row;
                    break;
                }
            }
            $this->cache->set($key, $data);
        }

        return $data;
    }

    private function getFilterById($id) {
        $q = $this->db->query("
            SELECT o.oc_ocfilter_filter_id as option_id, od.name
            FROM " . DB_PREFIX . "oc_ocfilter_filter o
            LEFT JOIN " . DB_PREFIX . "oc_ocfilter_filter_description od ON o.oc_ocfilter_filter_id = od.oc_ocfilter_filter_id
            WHERE o.oc_ocfilter_filter_id = '" . (int)$id . "'
            AND od.language_id = '" . (int)$this->config->get('config_language_id') . "'
            AND o.status = '1' LIMIT 1
        ");
        return $q->num_rows ? $q->row : null;
    }

    private function getFilterValueById($id) {
        $q = $this->db->query("
            SELECT ov.oc_ocfilter_filter_value_id as option_value_id, ovd.name
            FROM " . DB_PREFIX . "oc_ocfilter_filter_value ov
            LEFT JOIN " . DB_PREFIX . "oc_ocfilter_filter_value_description ovd ON ov.oc_ocfilter_filter_value_id = ovd.oc_ocfilter_filter_value_id
            WHERE ov.oc_ocfilter_filter_value_id = '" . (int)$id . "'
            AND ovd.language_id = '" . (int)$this->config->get('config_language_id') . "' LIMIT 1
        ");
        return $q->num_rows ? $q->row : null;
    }

    private function createSlug($text) {
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
        $text = function_exists('utf8_strtolower') ? utf8_strtolower($text) : mb_strtolower($text, 'UTF-8');
        $text = $this->transliterate($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    private function transliterate($text) {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh','з'=>'z','и'=>'i',
            'й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t',
            'у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y',
            'ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya','і'=>'i','ї'=>'yi','є'=>'ye'
        ];
        return strtr($text, $map);
    }

    private function getCategoryIdByPath($path) {
        $q = $this->db->query("
            SELECT `query` FROM " . DB_PREFIX . "seo_url 
            WHERE keyword = '" . $this->db->escape($path) . "'
            AND store_id = '" . (int)$this->config->get('config_store_id') . "'
            AND language_id = '" . (int)$this->config->get('config_language_id') . "' LIMIT 1
        ");
        if ($q->num_rows) {
            parse_str($q->row['query'], $p);
            return isset($p['category_id']) ? $p['category_id'] : null;
        }
        return null;
    }

    private function parseOcfString($code) {
        $filters = [];
        if (preg_match_all('/F(\d+)S(\d+)((?:V\d+)+)/', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $fid = (int)$m[1];
                $rel = (int)$m[2];
                preg_match_all('/V(\d+)/', $m[3], $vm);
                $vals = array_map('intval', $vm[1]);
                if ($vals) {
                    $filters[$fid] = ['values' => $vals, 'relation' => $rel];
                }
            }
        }
        return $filters;
    }

    private function buildOcfString($filters) {
        $parts = [];
        foreach ($filters as $fid => $data) {
            if (!empty($data['values'])) {
                $rel = isset($data['relation']) ? $data['relation'] : 1;
                $vals = 'V' . implode('V', $data['values']);
                $parts[] = 'F' . $fid . 'S' . $rel . $vals;
            }
        }
        return implode('', $parts);
    }
}