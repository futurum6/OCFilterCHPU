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
            
            // Пытаемся загрузить модель OCFilter
            try {
                $registry->get('load')->model('extension/module/ocfilter');
                if (isset($registry->get('load')->model_extension_module_ocfilter)) {
                    $this->ocfilter_model = $registry->get('load')->model_extension_module_ocfilter;
                }
            } catch (Exception $e) {
                error_log('OCFilter model not found: ' . $e->getMessage());
            }
        }
    }

    public function isEnabled() {
        return $this->config && $this->config->get('ocfilter_chpu_status') && $this->isOCFilterAvailable();
    }

    /**
     * Проверяет доступность OCFilter
     */
    private function isOCFilterAvailable() {
        // Проверяем наличие таблиц OCFilter
        $result = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "ocfilter_option'");
        return $result->num_rows > 0;
    }

    /**
     * Обрабатывает входящий URL и преобразует ЧПУ в ocf параметр
     */
    public function processIncomingUrl() {
        if (!$this->request || !isset($this->request->get['_route_']) || !$this->isEnabled()) {
            return;
        }

        $route = $this->request->get['_route_'];
        
        // Разбираем URL на части
        $parts = explode('/', trim($route, '/'));
        
        if (count($parts) < 2) {
            return; // Слишком короткий URL
        }

        // Ищем где начинаются фильтры
        $filter_start_index = $this->findFilterStartIndex($parts);
        
        if ($filter_start_index === false) {
            return; // Фильтры не найдены
        }

        // Разделяем на категорию и фильтры  
        $category_parts = array_slice($parts, 0, $filter_start_index);
        $filter_parts = array_slice($parts, $filter_start_index);
        
        // Парсим фильтры
        $ocf_code = $this->parseChpuToOcf($filter_parts);
        
        if ($ocf_code) {
            $category_path = implode('/', $category_parts);
            
            // Устанавливаем правильные параметры
            $this->request->get['_route_'] = $category_path;
            $this->request->get['path'] = $this->getCategoryIdByPath($category_path);
            $this->request->get['ocf'] = $ocf_code;
            $this->request->get['route'] = 'product/category';
            
            // Делаем 301 редирект если пришли с обычного URL
            if (isset($this->request->server['HTTP_REFERER'])) {
                $referer = parse_url($this->request->server['HTTP_REFERER']);
                if (isset($referer['query']) && strpos($referer['query'], 'ocf=') !== false) {
                    $new_url = $this->url->link('product/category', 'path=' . $this->request->get['path'] . '&ocf=' . $ocf_code);
                    header('Location: ' . str_replace('&ocf=' . $ocf_code, '', $new_url) . $this->generateChpuPath($ocf_code), true, 301);
                    exit;
                }
            }
        }
    }

    /**
     * Ищет индекс начала фильтров в массиве частей URL
     */
    private function findFilterStartIndex($parts) {
        $separator_main = $this->config->get('ocfilter_chpu_separator') ?: '-';
        $separator_values = $separator_main . $separator_main; // Разделитель значений "--"
        
        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];
            
            // Проверяем есть ли разделители фильтров
            if (strpos($part, $separator_values) !== false || 
                (strpos($part, $separator_main) !== false && $this->isFilterPart($part))) {
                return $i;
            }
        }
        
        return false;
    }

    /**
     * Проверяет является ли часть URL фильтром
     */
    private function isFilterPart($part) {
        $separator = $this->config->get('ocfilter_chpu_separator') ?: '-';
        
        if (strpos($part, $separator) === false) {
            return false;
        }
        
        list($filter_name, ) = explode($separator, $part, 2);
        
        // Проверяем существует ли такой фильтр в базе
        return $this->getFilterBySlug($filter_name) !== null;
    }

    /**
     * Парсит ЧПУ части в ocf код
     */
    private function parseChpuToOcf($filter_parts) {
        $separator_main = $this->config->get('ocfilter_chpu_separator') ?: '-';
        $separator_values = $separator_main . $separator_main; // "--"
        $url_format = $this->config->get('ocfilter_chpu_url_format') ?: 'keyword';
        
        $filters_array = array();
        
        foreach ($filter_parts as $part) {
            if (strpos($part, $separator_values) !== false) {
                // Формат: filter--value1-value2
                list($filter_name, $values_str) = explode($separator_values, $part, 2);
                $value_names = explode($separator_main, $values_str);
            } elseif (strpos($part, $separator_main) !== false) {
                // Формат: filter-value (один параметр)
                list($filter_name, $value_name) = explode($separator_main, $part, 2);
                $value_names = array($value_name);
            } else {
                continue;
            }
            
            $filter_data = $this->getFilterBySlug($filter_name, $url_format);
            
            if ($filter_data) {
                $value_ids = array();
                
                foreach ($value_names as $value_name) {
                    $value_data = $this->getFilterValueBySlug($filter_data['option_id'], $value_name, $url_format);
                    if ($value_data) {
                        $value_ids[] = $value_data['option_value_id'];
                    }
                }
                
                if (!empty($value_ids)) {
                    $filters_array[$filter_data['option_id']] = $value_ids;
                }
            }
        }
        
        return $this->buildOcfString($filters_array);
    }

    /**
     * Генерирует ЧПУ путь из ocf кода
     */
    public function generateChpuPath($ocf_code) {
        if (!$this->isEnabled() || empty($ocf_code)) {
            return '';
        }

        $filters_array = $this->parseOcfString($ocf_code);
        
        if (empty($filters_array)) {
            return '';
        }

        $separator_main = $this->config->get('ocfilter_chpu_separator') ?: '-';
        $separator_values = $separator_main . $separator_main; // "--"
        $url_format = $this->config->get('ocfilter_chpu_url_format') ?: 'keyword';
        
        $path_parts = array();
        
        foreach ($filters_array as $option_id => $value_ids) {
            $filter_data = $this->getFilterById($option_id);
            
            if ($filter_data) {
                $filter_slug = $this->getFilterSlug($filter_data, $url_format);
                $value_slugs = array();
                
                foreach ($value_ids as $value_id) {
                    $value_data = $this->getFilterValueById($value_id);
                    if ($value_data) {
                        $value_slugs[] = $this->getValueSlug($value_data, $url_format);
                    }
                }
                
                if ($filter_slug && !empty($value_slugs)) {
                    if (count($value_slugs) == 1) {
                        // Один параметр: filter-value
                        $path_parts[] = $filter_slug . $separator_main . $value_slugs[0];
                    } else {
                        // Несколько параметров: filter--value1-value2
                        $path_parts[] = $filter_slug . $separator_values . implode($separator_main, $value_slugs);
                    }
                }
            }
        }
        
        return !empty($path_parts) ? '/' . implode('/', $path_parts) : '';
    }

    /**
     * Получает данные фильтра по slug
     */
    private function getFilterBySlug($slug, $format = 'keyword') {
        if ($format === 'id') {
            return $this->getFilterById((int)$slug);
        }
        
        $cache_key = 'ocfilter_chpu.filter.slug.' . $slug . '.' . $this->config->get('config_language_id');
        $filter_data = $this->cache->get($cache_key);
        
        if ($filter_data === null) {
            // Ищем по названию (транслитерированному)
            $query = $this->db->query("
                SELECT o.option_id, od.name 
                FROM " . DB_PREFIX . "ocfilter_option o
                LEFT JOIN " . DB_PREFIX . "ocfilter_option_description od ON (o.option_id = od.option_id)
                WHERE od.language_id = '" . (int)$this->config->get('config_language_id') . "'
                AND o.status = '1'
                LIMIT 50
            ");
            
            $filter_data = false;
            foreach ($query->rows as $row) {
                if ($this->createSlug($row['name']) === $slug) {
                    $filter_data = $row;
                    break;
                }
            }
            
            $this->cache->set($cache_key, $filter_data);
        }
        
        return $filter_data;
    }

    /**
     * Получает данные значения фильтра по slug
     */
    private function getFilterValueBySlug($option_id, $slug, $format = 'keyword') {
        if ($format === 'id') {
            return $this->getFilterValueById((int)$slug);
        }
        
        $cache_key = 'ocfilter_chpu.value.slug.' . $option_id . '.' . $slug . '.' . $this->config->get('config_language_id');
        $value_data = $this->cache->get($cache_key);
        
        if ($value_data === null) {
            $query = $this->db->query("
                SELECT ov.option_value_id, ovd.name
                FROM " . DB_PREFIX . "ocfilter_option_value ov
                LEFT JOIN " . DB_PREFIX . "ocfilter_option_value_description ovd ON (ov.option_value_id = ovd.option_value_id)
                WHERE ov.option_id = '" . (int)$option_id . "'
                AND ovd.language_id = '" . (int)$this->config->get('config_language_id') . "'
                LIMIT 100
            ");
            
            $value_data = false;
            foreach ($query->rows as $row) {
                if ($this->createSlug($row['name']) === $slug) {
                    $value_data = $row;
                    break;
                }
            }
            
            $this->cache->set($cache_key, $value_data);
        }
        
        return $value_data;
    }

    /**
     * Получает данные фильтра по ID
     */
    private function getFilterById($option_id) {
        $query = $this->db->query("
            SELECT o.option_id, od.name
            FROM " . DB_PREFIX . "ocfilter_option o
            LEFT JOIN " . DB_PREFIX . "ocfilter_option_description od ON (o.option_id = od.option_id)
            WHERE o.option_id = '" . (int)$option_id . "'
            AND od.language_id = '" . (int)$this->config->get('config_language_id') . "'
            AND o.status = '1'
            LIMIT 1
        ");
        
        return $query->num_rows ? $query->row : null;
    }

    /**
     * Получает данные значения по ID
     */
    private function getFilterValueById($value_id) {
        $query = $this->db->query("
            SELECT ov.option_value_id, ovd.name
            FROM " . DB_PREFIX . "ocfilter_option_value ov
            LEFT JOIN " . DB_PREFIX . "ocfilter_option_value_description ovd ON (ov.option_value_id = ovd.option_value_id)
            WHERE ov.option_value_id = '" . (int)$value_id . "'
            AND ovd.language_id = '" . (int)$this->config->get('config_language_id') . "'
            LIMIT 1
        ");
        
        return $query->num_rows ? $query->row : null;
    }

    /**
     * Генерирует slug для фильтра
     */
    private function getFilterSlug($filter_data, $format) {
        return $format === 'id' ? $filter_data['option_id'] : $this->createSlug($filter_data['name']);
    }

    /**
     * Генерирует slug для значения
     */
    private function getValueSlug($value_data, $format) {
        return $format === 'id' ? $value_data['option_value_id'] : $this->createSlug($value_data['name']);
    }

    /**
     * Создает SEO-friendly slug из названия (использует методы OpenCart)
     */
    private function createSlug($text) {
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
        
        // Используем встроенные методы OpenCart если доступны
        if (function_exists('utf8_strtolower')) {
            $text = utf8_strtolower($text);
        } else {
            $text = mb_strtolower($text, 'UTF-8');
        }
        
        // Транслитерация
        $text = $this->transliterate($text);
        
        // Заменяем не-алфавитно-цифровые символы на дефисы
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        
        return trim($text, '-');
    }

    /**
     * Транслитерация кириллицы
     */
    private function transliterate($text) {
        $transliterationTable = array(
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts',
            'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu',
            'я'=>'ya','і'=>'i','ї'=>'yi','є'=>'ye'
        );
        
        return strtr($text, $transliterationTable);
    }

    /**
     * Получает ID категории по пути (исправленная версия)
     */
    private function getCategoryIdByPath($path) {
        $query = $this->db->query("
            SELECT `query`
            FROM " . DB_PREFIX . "seo_url 
            WHERE keyword = '" . $this->db->escape($path) . "' 
            AND store_id = '" . (int)$this->config->get('config_store_id') . "'
            AND language_id = '" . (int)$this->config->get('config_language_id') . "'
            LIMIT 1
        ");
        
        if ($query->num_rows) {
            // Парсим query вида "category_id=20"
            parse_str($query->row['query'], $params);
            return isset($params['category_id']) ? $params['category_id'] : null;
        }
        
        return null;
    }

    /**
     * Парсит ocf строку в массив
     */
    private function parseOcfString($ocf_code) {
        if (!$ocf_code) return array();
        
        // Пытаемся использовать методы OCFilter если доступны
        if ($this->ocfilter_model && method_exists($this->ocfilter_model, 'parseOcfString')) {
            return $this->ocfilter_model->parseOcfString($ocf_code);
        }
        
        // Fallback парсер
        $filters = array();
        $parts = explode(',', $ocf_code);
        
        foreach ($parts as $part) {
            if (preg_match('/F(\d+)V(.+)/', $part, $matches)) {
                $option_id = (int)$matches[1];
                $values_str = $matches[2];
                $value_ids = preg_split('/V/', $values_str, -1, PREG_SPLIT_NO_EMPTY);
                
                $filters[$option_id] = array_map('intval', $value_ids);
            }
        }
        
        return $filters;
    }

    /**
     * Собирает ocf строку из массива
     */
    private function buildOcfString($filters_array) {
        if (empty($filters_array)) return '';
        
        // Пытаемся использовать методы OCFilter если доступны
        if ($this->ocfilter_model && method_exists($this->ocfilter_model, 'buildOcfString')) {
            return $this->ocfilter_model->buildOcfString($filters_array);
        }
        
        // Fallback сборщик
        $ocf_parts = array();
        
        foreach ($filters_array as $option_id => $value_ids) {
            if (!empty($value_ids)) {
                $ocf_parts[] = 'F' . $option_id . 'V' . implode('V', $value_ids);
            }
        }
        
        return implode(',', $ocf_parts);
    }
}