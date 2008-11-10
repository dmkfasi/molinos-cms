<?php

$sax = new SaxParser();
$sax->parse('siteprofile.xml');

class SaxParser {

  private $data = null;
  private $parser = null;

  public function __construct()
  {
    $this->parser = $ns ?
      xml_parser_create_ns($encoding, $separator) :
      xml_parser_create($encoding);

    xml_set_object($this->parser, $this);
    xml_set_default_handler($this->parser, 'default');
    xml_set_element_handler($this->parser, 'start_element', 'end_element');
    xml_set_character_data_handler($this->parser, 'cdata');
    xml_set_start_namespace_decl_handler($this->parser, 'nsStart');
    xml_set_end_namespace_decl_handler($this->parser, 'nsEnd');
    xml_set_external_entity_ref_handler($this->parser, 'entityRef');
    xml_set_processing_instruction_handler($this->parser, 'parsei');
    xml_set_notation_decl_handler($this->parser, 'notation');
    xml_set_unparsed_entity_decl_handler($this->parser, 'unparsedEntity');
  }

  public function start_element($parser, $name, $attr)
  {
    // var_dump($name, $attr);
  }

  public function end_element($parser, $name)
  {
    // var_dump($name);
  }

  public function cdata($parser, $cdata)
  {
    var_dump($cdata);
  }

  public function parse($file)
  {
    $fp = fopen($file, 'rb');

    while (($data = fread($fp, 8192))) {
      if (!xml_parse($this->parser, $data, feof($fp))) {
        throw RuntimeException(t(sprintf('XML error at line %d column %d',
          xml_get_current_line_number($this->parser),
          xml_get_current_column_number($this->parser))));
      }
    }

    fclose($fp);
  }
}

