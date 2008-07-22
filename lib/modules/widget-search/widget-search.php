<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class SearchWidget extends Widget
{
  public function __construct(Node $node)
  {
    parent::__construct($node);
  }

  public static function getWidgetInfo()
  {
    return array(
      'name' => 'Поиск по сайту',
      'description' => 'Контекстный морфологический поиск по сайту.',
      );
  }

  public static function formGetConfig()
  {
    $form = parent::formGetConfig();

    $form->addControl(new TextLineControl(array(
      'value' => 'config_btngo',
      'label' => t('Текст кнопки поиска'),
      )));
    $form->addControl(new TextLineControl(array(
      'value' => 'config_ispell',
      'label' => t('Путь к словарям'),
      )));
    $form->addControl(new TextLineControl(array(
      'value' => 'config_action',
      'label' => t('Страница с результатами поиска'),
      'description' => t('По умолчанию поиск производится на текущей странице.&nbsp; Если нужно при поиске перебрасывать пользователя на другую страницу, например &mdash; /search/, введите её имя здесь.'),
      )));
    $form->addControl(new TextLineControl(array(
      'value' => 'config_dsn',
      'label' => t('Параметры подключения к БД'),
      'description' => t('Строка формата mysql://mnogouser:pass@server/mnogodb/?dbmode=multi'),
      )));
    $form->addControl(new NumberControl(array(
      'value' => 'config_per_page',
      'label' => t('Количество результатов на странице'),
      )));

    return $form;
  }

  public function getRequestOptions(RequestContext $ctx)
  {
    $options = parent::getRequestOptions($ctx);

    if (empty($this->dsn))
      throw new WidgetHaltedException();

    $options['q'] = $ctx->get('q');
    $options['page'] = $ctx->get('page', 1);
    $options['limit'] = $this->per_page;
    $options['#nocache'] = true;

    return $options;
  }

  public function onGet(array $options)
  {
    $result = array(
      'form' => parent::formRender('search-form', array()),
      );

    if (!empty($options['q'])) {
      $result['results'] = $this->getResults($options);

      $result['pager'] = $this->getPager($result['results']['summary']['total'], $options['page'], $options['limit']);
      if ($result['pager']['pages'] < 2)
        unset($result['pager']);
    }

    return $result;
  }

  private function getResults(array $options)
  {
    $result = array();

    $res = $this->executeSearch($options['q'], $options['page']);

    $result["summary"] = array(
        "total"         => udm_get_res_param($res, UDM_PARAM_FOUND),
        "first"         => udm_get_res_param($res, UDM_PARAM_FIRST_DOC),
        "last"          => udm_get_res_param($res, UDM_PARAM_LAST_DOC),
        "items_on_page" => udm_get_res_param($res, UDM_PARAM_NUM_ROWS),
        "time"          => udm_get_res_param($res, UDM_PARAM_SEARCHTIME),
        "info"          => udm_get_res_param($res, UDM_PARAM_WORDINFO),
        "page"          => $options['page'],
        "query"         => $options['q'],
    );

        if ($result["summary"]["items_on_page"] > 0) {
            if ($result['summary']['first'] == 1) {
                // first page
                $result['summary']['items_per_page'] = $result['summary']['items_on_page'];
                
            } else {
                $result['summary']['items_per_page'] = ($result['summary']['first'] - 1) / $result['summary']['page'];
            }

            $result["summary"]["number_of_pages"] = ceil($result["summary"]["total"] / $result["summary"]["items_per_page"]);
        } else {
            // empty set
            $result['summary']['items_per_page'] = 0;
            $result["summary"]["number_of_pages"] = 0;
        }

        $max = $result["summary"]["last"] - $result["summary"]["first"] + 1;

        $result["results"] = array();
        if ($result["summary"]["total"] > 0) {
            $udm_highlighter = array(chr(2), chr(3));
            $html_highlighter = array('<b style="color:red;">', '</b>');

            for ($i = 0; $i < $max; $i++) {
                //udm_make_excerpt($udm, $res, $i);

                $result["results"][] = array(
                    "title" => str_replace($udm_highlighter, $html_highlighter, udm_get_res_field($res, $i, UDM_FIELD_TITLE)),
                    "url" => udm_get_res_field($res, $i, UDM_FIELD_URL),
                    "type" => udm_get_res_field($res, $i, UDM_FIELD_CONTENT),
                    "date" => strtotime(udm_get_res_field($res, $i, UDM_FIELD_MODIFIED)),
                    "rating" => udm_get_res_field($res, $i, UDM_FIELD_RATING),
                    "context" => str_replace(
                        $udm_highlighter,
                        $html_highlighter,
                        html_entity_decode(udm_get_res_field($res, $i, UDM_FIELD_TEXT), ENT_QUOTES, 'UTF-8')
                    ),
                );
            }
        }

    udm_free_res($res);

    return $result;
  }

  private function executeSearch($query, $page)
  {
    if (!function_exists('udm_alloc_agent'))
      throw new UserErrorException("Поиск не работает", 500, "Поиск временно недоступен", "Функции поиска недоступны серверу, требуется вмешательство администратора сайта.");

    $udm = udm_alloc_agent($this->dsn);
    if ($udm === false or $udm === null)
      throw new UserErrorException("Поиск не работает", 500, "Поиск временно недоступен", "Не удалось подключиться к серверу MnoGoSearch, требуется вмешательство администратора сайта.");

    $params = array(
      UDM_FIELD_CHARSET => 'UTF8',
      UDM_PARAM_CHARSET => 'UTF8',
      UDM_PARAM_LOCAL_CHARSET => 'UTF8',
      UDM_PARAM_BROWSER_CHARSET => 'UTF8',
      UDM_PARAM_SEARCH_MODE => UDM_MODE_ALL,
      UDM_PARAM_PAGE_SIZE => $this->per_page,
      UDM_PARAM_PAGE_NUM => $page - 1,
      UDM_PARAM_QUERY => $query,
    );

    foreach ($params as $key => $value) {
      if (udm_set_agent_param($udm, $key, $value) == false)
        throw new UserErrorException("Поиск не работает", 500, "Поиск временно недоступен", "Не удалось установить параметр {$key}, требуется вмешательство администратора сайта.&nbsp; Текст ошибки: ". udm_error($udm));
    }

    $params_ex = array(
      's' => 'RPD', // sort by rating
      'ExcerptSize' => 1024, // $this->excerpt_size
    );

    foreach ($params_ex as $key => $value) {
      if (udm_set_agent_param_ex($udm, $key, $value) == false)
        throw new UserErrorException("Поиск не работает", 500, "Поиск временно недоступен", "Не удалось установить параметр {$key}, требуется вмешательство администратора сайта.&nbsp; Текст ошибки: ". udm_error($udm));
    }

		$res = udm_add_search_limit($udm, UDM_LIMIT_URL, $_SERVER['HTTP_HOST']);

    if ($res == false)
      throw new UserErrorException("Поиск не работает", 500, "Поиск временно недоступен", "Не удалось установить привязку к домену, требуется вмешательство администратора сайта.");

    // Query logging here
    $tagger = Tagger::getInstance();
    $tagger->logSearchQuery($query);

    if (!empty($this->ispell)) {
      $ispell_langs = array(
        'ru' => array('utf-8', 'russian'),
        'en' => array('iso-8859-1', 'english')
      );

      if (!empty($this->ispell) /* $this->ispell_source == 'fs' */) {
        if (empty($this->ispell))
          throw new InvalidArgumentException("Не задан путь к словарям iSpell");

        if (!is_dir($this->ispell))
          throw new InvalidArgumentException("Путь {$this->ispell} не существует или не является директорией");

        $i = 0;
        foreach ($ispell_langs as $code => $data) {
          $files_path = $this->ispell.'/'.$data[1];

          if (!file_exists($files_path.'.aff') or !file_exists($files_path.'.dict'))
            throw new InvalidArgumentException('Не удалось обнаружить файл со словарём или аффиксами для языка "'.$code.'"');

          $sort = intval(++$i == count($ispell_langs)); // сортировать нужно одновременно с добавлением последнего языка

          if (!udm_load_ispell_data($udm, UDM_ISPELL_TYPE_AFFIX, $code, $data[0], $files_path.'.aff', 0))
            throw new InvalidArgumentException('Ошибка загрузки аффикса "'.$files_path.'.aff": '.udm_error($udm));

          if (!udm_load_ispell_data($udm, UDM_ISPELL_TYPE_SPELL, $code, $data[0], $files_path.'.dict', $sort))
            throw new InvalidArgumentException('Ошибка загрузки словаря "'.$files_path.'.dict": '.udm_error($udm));

        }
      }
    }

    $res = udm_find($udm, $query);

    if ($res === false or $res === null)
      throw new InvalidArgumentException('Ошибка поиска: '.udm_error($udm));

    return $res;
  }

  public function formGet($id)
  {
    switch ($id) {
    case 'search-form':
      $form = new Form(array(
        'action' => empty($this->action) ? null : '/'. trim($this->action, '/') .'/',
        ));

      $form->addControl(new TextLineControl(array(
        'label' => t('Что ищем'),
        'value' => 'search_string',
        )));
      $form->addControl(new SubmitControl(array(
        'text' => $this->btngo,
        )));

      return $form;
    }
  }

  public function formProcess($id, array $data)
  {
    switch ($id) {
    case 'search-form':
      $url = bebop_split_url();
      $url['args'][$this->getInstanceName()]['q'] = $data['search_string'];
      bebop_redirect($url);
    }
  }
};