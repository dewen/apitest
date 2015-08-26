<?php

// >/tmp/play_cookie_jar.txt;php playnotes.php > playnotes.yml ; php play.php

$playnotes = yaml_parse_file('playnotes.yml');
$notes = $playnotes['notes'];
$notify = $playnotes['notification']['email'];
assert_options(ASSERT_CALLBACK, 'play_callback');

foreach ($notes as $note) {
  play_assert($note);
}

function play_assert($note) {
  static $persist = array();

  $res = curl_init();
  $url = $note['protocol'] . '://' . $note['host'] . '/' . $note['path'];
  $url .= ($note['qs']) ? '?' . $note['qs'] : '';

  curl_setopt($res, CURLOPT_URL, $url);

  // curl_setopt($res, CURLOPT_TIMEOUT, $this->_timeout);
  // curl_setopt($res, CURLOPT_MAXREDIRS, $this->_maxRedirects);
  // curl_setopt($res, CURLOPT_FOLLOWLOCATION, $this->_followlocation);
  // curl_setopt($res, CURLOPT_USERAGENT, $this->_useragent);
  // curl_setopt($res, CURLOPT_REFERER, $this->_referer);
  // curl_setopt($res,CURLOPT_NOBODY,true);

  curl_setopt($res, CURLOPT_COOKIEJAR, '/tmp/play_cookie_jar.txt');
  curl_setopt($res, CURLOPT_COOKIEFILE, '/tmp/play_cookie_jar.txt');
  curl_setopt($res, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($res, CURLINFO_HEADER_OUT, true);
  curl_setopt($res, CURLOPT_SSL_VERIFYPEER, false);

  if (isset($note['authentication']) && $note['authentication']){
    curl_setopt($res, CURLOPT_USERPWD, $note['authentication']['auth_name'].':'.$note['authentication']['auth_pass']);
  }

  if ($note['method'] == 'POST') {
    curl_setopt($res, CURLOPT_POST, true);
    $fields_string = (isset($note['fields'])) ? http_build_query($note['fields']) : '';
    curl_setopt($res, CURLOPT_POSTFIELDS, $fields_string);
  }

  curl_setopt($res, CURLOPT_HEADER, false);

  if (isset($note['http_headers'])) {
    $headers = array();
    foreach ($note['http_headers'] as $n => $v) {
      if (preg_match('/{{([a-z0-9_]+)}}/i', $v, $_)) {
        $hv = $_[1];
        if (isset($persist[$hv])) {
          $headers[] = $n . ': ' . $persist[$hv];
        }
      }
    }

    curl_setopt($res, CURLOPT_HTTPHEADER, $headers);
  }

  $result = curl_exec($res);
  if (isset($note['persist'])) {
    foreach ($note['persist'] as $key => $pattern) {
      if (preg_match($pattern, $result, $m)) {
        $persist[$key] = $m[1];
      }
    }
  }
  if(!curl_errno($res)) {
    $info = curl_getinfo($res);
    $code = $info['http_code'];
    $desc = $note['assertions']['desc'];
    $expected = $note['assertions']['code'];
    assert_options(ASSERT_ACTIVE, 1);
    assert_options(ASSERT_WARNING, 0);
    assert_options(ASSERT_QUIET_EVAL, 1);
    assert('$code == $expected', $desc);
  }
  else {
    echo 'Curl error: ' . curl_error($res);
  }
  if (isset($note['debug'])) {
    var_dump($result);
    var_dump($info);
  }
  curl_close($res);
}

function play_callback($file, $line, $code) {
  global $notify;
  $body = "IIN Play Notes Failed:\n
    File '$file'\n
    Line '$line'\n
    Code '$code'";
  mail($notify, 'Playnotes failed', $body);
}
