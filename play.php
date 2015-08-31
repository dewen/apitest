<?php

use Assert\Assertion;
use Assert\AssertionFailedException;

require __DIR__.'/vendor/autoload.php';

/**
 * Run
 *  >/tmp/play_cookie_jar.txt;php notes/playnotes.php > notes/playnotes.yml ; php play.php
 *
 * Required:
 *  - beberlei/assert
 *    read https://packagist.org/packages/beberlei/assert
 */

$playnotes = yaml_parse_file('notes/playnotes.yml');
$notes = $playnotes['notes'];
$options = $playnotes['options'];
$notify = $playnotes['notification']['email'];
$assert_result = array();

foreach ($notes as $note) {
  play_assert($note);
}

function play_assert($note) {
  static $persist = array();
  global $assert_result;

  $note_name = $note['name'];

  $res = curl_init();
  $url = $note['url'];

  curl_setopt($res, CURLOPT_URL, $url);

  // curl_setopt($res, CURLOPT_TIMEOUT, $this->_timeout);
  // curl_setopt($res, CURLOPT_MAXREDIRS, $this->_maxRedirects);
  // curl_setopt($res, CURLOPT_FOLLOWLOCATION, $this->_followlocation);
  // curl_setopt($res, CURLOPT_USERAGENT, $this->_useragent);
  // curl_setopt($res, CURLOPT_REFERER, $this->_referer);
  // curl_setopt($res,CURLOPT_NOBODY,true);

  curl_setopt($res, CURLOPT_COOKIEJAR, '/tmp/play_cookie_jar.txt');
  curl_setopt($res, CURLOPT_COOKIEFILE, '/tmp/play_cookie_jar.txt');
  curl_setopt($res, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($res, CURLINFO_HEADER_OUT, TRUE);
  curl_setopt($res, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt($res, CURLOPT_HEADER, TRUE);

  if (isset($note['authentication']) && $note['authentication']){
    curl_setopt($res, CURLOPT_USERPWD, $note['authentication']['auth_name'].':'.$note['authentication']['auth_pass']);
  }

  if ($note['method'] == 'POST') {
    curl_setopt($res, CURLOPT_POST, true);
    $fields_string = (isset($note['fields'])) ? http_build_query($note['fields']) : '';
    curl_setopt($res, CURLOPT_POSTFIELDS, $fields_string);
  }


  if (isset($note['http_headers'])) {
    $headers = array();
    foreach ($note['http_headers'] as $n => $v) {
      if (preg_match('/{{([a-z0-9_]+)}}/i', $v, $_)) {
        $hv = $_[1];
        if (isset($persist[$hv])) {
          $headers[] = $n . ': ' . $persist[$hv];
        }
        else {
          $headers[] = $n . ': ' . $v;
        }
      }
    }

    curl_setopt($res, CURLOPT_HTTPHEADER, $headers);
  }

  $response = curl_exec($res);
  $text_header = substr($response, 0, strpos($response, "\r\n\r\n"));
  $text_body = substr($response, strpos($response, "\r\n\r\n") + 4);

  // check if content contains user defined variable
  if (isset($note['persist'])) {
    foreach ($note['persist'] as $p) {
      $content = (isset($p['is_header']) && $p['is_header']) ? $text_header : $text_body;
      switch ($p['content_type']) {
      case 'string':
        if (preg_match($p['value'], $content, $m)) {
          $persist[$p['name']] = $m[1];
        }
        break;
      case 'object':
        if (is_object($content_object = json_decode($content))) {
          eval('$persist[$p["name"]] = $content_object->' . $p['value'] . ';');
        }
        break;
      }
    }
  }

  if(!curl_errno($res)) {
    $info = curl_getinfo($res);
    // assertion
    if (isset($note['assertions']) && is_array($assertions = $note['assertions'])) {
      foreach ($assertions as $assertion) {
        /**
         * available types:
         *
         *    curl info keys:
         *                  url
         *                  content_type
         *                  http_code
         *                  header_size
         *                  request_size
         *                  filetime
         *                  ssl_verify_result
         *                  redirect_count
         *                  total_time
         *                  namelookup_time
         *                  connect_time
         *                  pretransfer_time
         *                  size_upload
         *                  size_download
         *                  speed_download
         *                  speed_upload
         *                  download_content_length
         *                  upload_content_length
         *                  starttransfer_time
         *                  redirect_time
         *                  redirect_url
         *                  primary_ip
         *                  certinfo
         *                  primary_port
         *                  local_ip
         *                  local_port
         *                  request_header
         *
         */

        $assert_type = isset($assertion['assert_type']) ? $assertion['assert_type'] : 'eq';
        $section = $assertion['section'];
        $format = $assertion['format'];
        $info_name = isset($assertion['name']) ? $assertion['name'] : '';

        switch ($section) {
          case 'info':
            if (isset($info[$info_name])) {
              $actual = $info[$info_name];
            }
            else {
              throw new \Exception('Invalid curl info name ['.$info_name.']');
            }
            break;
          case 'body':
          case 'header':
            $actual = ($section == 'header') ? $text_header : $text_body;
            break;
          default:
            throw new \Exception('Invalid assertion section [' . $section . ']');
        }

        try {
          // check content format
          if ($format == 'json') {
            $actual = json_decode($actual);
            if (!is_object($actual)) {
              throw new AssertionFailedException('Invalid return format, expecting json', 1000);
            }
          }
          switch ($assert_type) {
            case 'eq':
              if (is_object($actual)) {
                eval('$actual_value = $actual->' . $assertion['name'] . ';');
              }
              else {
                $actual_value = $actual;
              }
              Assertion::eq($actual_value, $assertion['value'], $assertion['desc']);
              $assert_result[] = array(
                'title' => $note['name'],
                'status' => 'SUCCESS',
                'message' => $assertion['desc'],
              );
              break;
            case 'regex':
              Assertion::regex($actual, $assertion['value'], $assertion['desc']);
              $assert_result[] = array(
                'title' => $note['name'],
                'status' => 'SUCCESS',
                'message' => $assertion['desc'],
              );
              break;
            default:
              throw new \Exception('Invalid assertion method [' . $method . ']');
          }
        }
        catch (AssertionFailedException $e) {
          $assert_result[] = array(
            'title' => $note['name'],
            'status' => 'FAILED',
            'message' => 'FAILED >>> ' . $e->getMessage() . " actual: " . implode(';', (array)$e->getValue()),
          );
        }
      }
    }
  }
  else {
    echo 'Curl error: ' . curl_error($res);
  }
  if (isset($note['debug']) && $note['debug']) {
    var_dump($response);
  }
  curl_close($res);
}

function play_shutdown() {
  global $notify, $assert_result, $options;
  if (!$assert_result) {
    return;
  }
  $body = '';
  foreach ($assert_result as $_) {
    if (($options['assert_report'] == 'FAILED_ONLY') && ('FAILED' !== $_['status'])) {
      continue;
    }
    $body .= " - " . $_['title'] . ': ' . $_['message'] . "\n";
  }
  if ($body) {
    $body = "IIN Play Notes Assertions:\n" . $body;
  }
  if ($options['email_notification']) {
    mail($notify, 'Playnotes report', $body);
    echo "Email sent\n";
  }
  echo "$body\n";
}

register_shutdown_function('play_shutdown');
