<?php
/**
 * @file
 * DOM based implementation of the OAI-PMH parser.
 *
 * The XML response is parsed with Document Object Model (DOM) methods. It is
 * a wrapper of the Omeka classes.
 *
 * @author Király Péter <kirunews@gmail.com>
 *
 * @copyright (c) 2010-2011 eXtensible Catalog Organization
 */

class OAIDomParser implements IOAIParser {

  private $oai;
  private $recordCounter = -1;
  private $recordCount   = -1;
  private $httpCode;
  private $httpErrorMsg;
  private $statistics = array('response' => 0, 'records' => 0);
  private $parserErrors = array();
  private $requestVerb;

  function __construct($content, $httpCode = 200, $requestVerb = "ListRecords") {
    $t0 = microtime(TRUE);
    $this->httpCode = $httpCode;
    $this->requestVerb = $requestVerb;
    if (strlen(trim($content)) == 0) {
      $parserErrors[] =  t('The XML to parse is an empty string.');
      xc_log_error('DOMParser', 'The XML to parse is an empty string.');
      return;
    }
    $oaiDom = new SimpleXMLIterator($content);
    $this->statistics['response'] = microtime(TRUE) - $t0;
    $this->oai = $oaiDom;
    if (!$oaiDom) {
      $lines = explode("\n", $content);
      $errors = libxml_get_errors();

      foreach ($errors as $error) {
        $output  = $lines[$error->line - 1] . "<br />\n";
        $output .= str_repeat('-', $error->column) . "^<br />\n";
        switch ($error->level) {
          case LIBXML_ERR_WARNING: $level = t('Warning'); break;
          case LIBXML_ERR_ERROR: $level = t('Error'); break;
          case LIBXML_ERR_FATAL: $level = t('Fatal error'); break;
        }
        $output .= $level . ' ' . $error->code . ': ' . $error->message . ' (' . $error->line . ':' . $error->column . ')';
        $parserErrors[] = $output;
      }

      libxml_clear_errors();
    }
  }

  function hasErrors() {
    return isset($this->oai->error);
  }

  function listErrors() {
    return $this->oai->error;
  }

  function getNextRecord() {
    $this->recordCounter++;
    if ($this->recordCounter < $this->getRecordCount()) {
      $t0 = microtime(TRUE);
      // $record = $this->parseRecord($this->oai->ListRecords->record[$this->recordCounter]);
      $record = $this->parseRecord($this->oai->{$this->requestVerb}->record[$this->recordCounter]);
      $this->statistics['records'] += (microtime(TRUE) - $t0);
      return $record;
    }
    return FALSE;
  }

  function hasRecords() {
    return $this->getRecordCount() > 0;
  }

  function getRecordCount() {
    // if ($this->recordCount == -1 && isset($this->oai->ListRecords->record)) {
    //  $this->recordCount = $this->oai->ListRecords->record->count();
    if ($this->recordCount == -1 && isset($this->oai->{$this->requestVerb}->record)) {
      $this->recordCount = $this->oai->{$this->requestVerb}->record->count();
    }
    return $this->recordCount;
  }

  function hasResumptionToken() {
    // $token = (string) $this->oai->ListRecords->resumptionToken;
    $token = (string) $this->oai->{$this->requestVerb}->resumptionToken;
    return !empty($token);
  }

  /**
   * Return the resumption token with its attributes
   * @code
   * array (
   *   'attributes' => array ( ),
   *   'text'       => '100/Jm1ldGFkYXRhUHJlZml4PW9haV9kYyZz',
   * )
   * @endcode
   *
   * @see oaiharvester/includes/IOAIParser#get_resumptionToken()
   */
  function getResumptionToken() {
    // $token = $this->oai->ListRecords->resumptionToken;
    $token = $this->oai->{$this->requestVerb}->resumptionToken;
    return array(
      'attributes' => array(
        'completeListSize' => (int) $token->attributes()->completeListSize,
        'cursor'           => (int) $token->attributes()->cursor,
        'expirationDate'   => (string) $token->attributes()->expirationDate,
      ),
      'text' => (string) $token
    );
  }

  function setHttpCode($httpCode) {
    $this->httpCode = $httpCode;
  }

  function getHttpCode() {
    return $this->httpCode;
  }

  function parseRecord($record) {
    $status = isset($record->header->attributes()->status) ? (string)$record->header->attributes()->status : FALSE;
    $identifier = (string) $record->header->identifier[0];

    $specs = array();
    foreach ($record->header->setSpec as $spec) {
      $specs[] = (string) $spec;
    }
    $metadata = null;

    if ($record->metadata) {
      $dom = dom_import_simplexml($record->metadata);
      if ($dom === FALSE) {
        $errorMsq = sprintf('[id: %s, status: %s] Error while parsing XML "%s"', $identifier, $status, $record->metadata);
        $this->parserErrors[] = $errorMsq;
        xc_log_error('DOMParser', $errorMsq);
      }
      elseif (!$dom->hasChildNodes()) {
        $errorMsq = sprintf('No children in metadata: [id: %s, status: %s] xml: "%s"', $identifier, $status, htmlentities($record->metadata));
        $this->parserErrors[] = $errorMsq;
        xc_log_error('DOMParser', $errorMsq);
      }
      else {
        foreach ($dom->childNodes as $childNode) {
          if ($childNode->nodeType == XML_ELEMENT_NODE) {
            $metadata = array(
              'namespaceURI' => preg_replace('/\/$/', '', $childNode->namespaceURI),
              'childNode' => $childNode,
            );
            break;
          }
        }
      }
    }

    return array(
      'header' => array(
        '@status' => $status,
        'identifier' => $identifier,
        'datestamp' => (string) $record->header->datestamp[0],
        'setSpec' => $specs
      ),
      'metadata' => $metadata,
      'about' => isset($record->abouts) ? $record->abouts : FALSE
    );
  }

  function getStatistics($type = 'all') {
    switch ($type) {
      case 'all':
        return $this->statistics; break;
      case 'response':
        return $this->statistics['response']; break;
      case 'records':
        return $this->statistics['records']; break;
      default:
        return;
    }
  }

  function getParserErrors() {
    return $this->parserErrors;
  }
}