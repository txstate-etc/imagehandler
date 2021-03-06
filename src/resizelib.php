<?php
date_default_timezone_set('GMT');
$GLOBALS['auth_map'] = json_decode($_ENV['IMAGEHANDLERAUTHJSON'], TRUE);
$GLOBALS['stats'] = array();

function get_mime_type($imagedata) {
  $info = new finfo(FILEINFO_MIME);
  return $info->buffer($imagedata);
}

function check_magic_bytes($mimetype) {
  return (0===strpos($mimetype, 'image/'));
}

function crop_image($image, $width, $height, $x, $y) {
  if ($image->getImageWidth() == $width && $image->getImageHeight() == $height) return $image;
  for ($i = 0; $i < $image->getNumberImages(); $i++) {
    $image->setimageindex($i);
    $image->cropImage($width, $height, $x, $y);
    $image->setImagePage($width, $height, 0, 0);
  }
  return $image;
}

function resize_image($image, $width, $height) {
  if ($image->getNumberImages() > 1 && $width * $height > ($image->getImageWidth() * $image->getImageHeight() * 0.8)) return $image;
  if ($image->getImageWidth() == $width && $image->getImageHeight() == $height) return $image;
  for ($i = 0; $i < $image->getNumberImages(); $i++) {
    $image->setimageindex($i);
    $image->resizeImage($width, $height, Gmagick::FILTER_MITCHELL, 1);
  }
  return $image;
}

function benchmark($start) {
  return round((microtime(TRUE) - $start)*1000);
}

function get_raw_image($requesturl, $lastmod='') {
  $path = get_raw_cache_path($requesturl);
  $etagpath = $path.'/etag';
  if (file_exists($etagpath)) $fileetag = file_get_contents($etagpath);

  $imglocation = request_target($requesturl);
  $urlinfo = parse_url($imglocation);
  $info = $GLOBALS['auth_map'][$urlinfo['host']];

  $headers = "User-Agent: TxState Image Handler\r\n";
  if ($lastmod) $headers .= "If-Modified-Since: ".$lastmod." GMT\r\n";
  if ($info[0]) $headers .= "Authorization: Basic " . base64_encode($info[0].':'.$info[1])."\r\n";

  $context = array(
    'http' => array(
      'method' => "GET",
      'header' => $headers
    )
  );
  $imgdata = file_get_contents($imglocation, false, stream_context_create($context));
  if ($lastmod) {
    foreach ($http_response_header as $header) {
      if (preg_match('/HTTP\/.*304/', $header)) return false;
    }
    if (md5($imgdata) == $fileetag) return false;
  }

  $mimetype = get_mime_type($imgdata);
  if (!check_magic_bytes($mimetype)) {
    // extra layer of security against exploits
    // imagemagick exploit found in the wild 2016-05-06
    http_response_code(400);
    exit;
  }

  $GLOBALS['stats']['filesize_input'] = strlen($imgdata);
  if (!file_exists($path)) mkdir($path, 0755, true);
  file_put_contents($etagpath, md5($imgdata));

  $image = new Gmagick();
  $image->readImageBlob($imgdata);

  $exif = exif_read_data("data://".explode(';',$mimetype)[0].";base64," . base64_encode($imgdata));
  if ($exif) {
    $rotation = $exif['Orientation'];
    if ($rotation == 2) {
      $image->flipimage();
      $image->rotateimage('#000000', 180);
    }
    if ($rotation == 3) $image->rotateimage('#000000', 180);
    if ($rotation == 4) $image->flipimage();
    if ($rotation == 5) {
      $image->flipimage();
      $image->rotateimage('#000000', 90);
    }
    if ($rotation == 6) $image->rotateimage('#000000', 90);
    if ($rotation == 7) {
      $image->flipimage();
      $image->rotateimage('#000000', -90);
    }
    if ($rotation == 8) $image->rotateimage('#000000', -90);
  }

  return $image;
}

function request_without_query($requesturl) {
  $ret = $requesturl;
  if (strpos($requesturl, '?') !== false) $ret = implode('?', explode('?', $requesturl, -1));
  return $ret;
}

function base64url_decode($data) {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

function request_target($requesturl) {
  $path = explode('/', request_without_query($requesturl));
  if ($path[2] == 'scaler_base64') {
    return base64url_decode($path[3]);
  } else {
    $location = implode('/', array_slice($path, 3));
    $urlinfo = parse_url("http://".$location);
    $info = $GLOBALS['auth_map'][$urlinfo['host']];
    error_log(($info[2] == 'SSL' ? 'https://' : 'http://').$location);
    return ($info[2] == 'SSL' ? 'https://' : 'http://').$location;
  }
}

function get_raw_cache_path($requesturl) {
  list ($requestwithoutquery) = preg_split('/\?/', $requesturl, 2);
  return get_cache_path($requestwithoutquery);
}

function get_cache_path($requesturl) {
  $info = explode('/',$requesturl);
  $signature = implode('/', array_slice($info, 4));
  if ($info[2] == 'scaler_base64') $signature = implode('/', array_slice($info, 3));

  $hash = md5($signature);
  $cachepath = substr($hash,0,2).'/'.substr($hash,2,2).'/'.substr($hash,4,2).'/'.substr($hash,6);
  $fullcachepath = '/var/cache/resize/'.$cachepath;
  return $fullcachepath;
}

function store_image($path, $blob, $etag) {
  $filepath = $path.'/data';
  $etagpath = $path.'/etag';
  if (!file_exists($path)) mkdir($path, 0755, true);
  $tmppath = $path.'/tmp'.substr( md5(rand()), 0, 7);
  file_put_contents($tmppath, $blob);
  rename($tmppath, $filepath);
  file_put_contents($etagpath, $etag);
}

function store_resized_image($requesturl, $blob, $etag) {
  $start = microtime(TRUE);
  store_image(get_cache_path($requesturl), $blob, $etag);
  $GLOBALS['stats']['time_cachewrite'] = benchmark($start);
}

function print_cache_image_or_return_original($requesturl, $etag, $lastmodified) {
  $path = get_cache_path($requesturl);
  $filepath = $path.'/data';
  $etagpath = $path.'/etag';
  // if we have a cache entry for this request url, we'll send an
  // If-Modified-Since header to the original source.
  // If we get back a 304, we'll know our cache entry is still valid
  if (file_exists($filepath)) {
    $filelmtime =  filemtime($filepath);
    $filelm = gmdate("D, d M Y H:i:s", $filelmtime);
  } else {
    $filelm = '';
  }

  // if $filelm is empty, get_raw_image won't send the If-Modified-Since
  // and we will definitely get binary back
  $start = microtime(TRUE);
  $image = get_raw_image($requesturl, $filelm);
  $GLOBALS['stats']['time_retrieve'] = benchmark($start);

  // if we got binary back, it wasn't a 304 so our cache is no good
  // return the source image so that it can be processed by the rest of resize.php
  if ($image) return $image;

  // if we get this far, we have a cache hit, let's figure out whether to return
  // the binary or a 304 Not Modified header
  $GLOBALS['stats']['cache_hit'] = TRUE;

  // grab the etag for this cache entry, so that we can compare it against
  // the etag a client may have sent us
  // (we are supporting both If-Modified-Since and If-None-Match)
  if ($etag && file_exists($etagpath)) $fileetag = trim(file_get_contents($etagpath));

  if ((!$etag && !$lastmodified) || ($etag && trim($etag) != $fileetag) || ($lastmodified && strtotime($lastmodified) < $filelmtime)) {
    // client's cache is out of date (but our cache entry is good), send them the binary
    $start = microtime(TRUE);
    $cachedimage = file_get_contents($filepath);
    $GLOBALS['stats']['time_cacheread'] = benchmark($start);
    print_blob($cachedimage, $filelm, $fileetag);
  } else {
    print_304($filelm, $fileetag);
  }
  return false;
}

function print_blob($blob, $lastmod, $etag) {
  $start = microtime(TRUE);
  $blobsize = strlen($blob);
  header('Content-Type: '.get_mime_type($blob));
  header('Last-Modified: '.$lastmod." GMT");
  header('Content-Length: '.$blobsize);
  if ($etag) header("Etag: $etag");
  echo $blob;
  flush();
  $GLOBALS['stats']['filesize_output'] = $blobsize;
  $GLOBALS['stats']['time_stream'] = benchmark($start);
}

function print_304($lastmod, $etag) {
  $GLOBALS['stats']['not_modified'] = TRUE;
  $start = microtime(TRUE);
  header('HTTP/1.1 304 Not Modified');
  header('Last-Modified: '.$lastmod.' GMT');
  if ($etag) header("Etag: $etag");
  flush();
  $GLOBALS['stats']['time_stream'] = benchmark($start);
}

function log_event_statistics() {
  $stats = $GLOBALS['stats'];
  $stats['request_uri'] = $_SERVER['REQUEST_URI'];
  $stats['referer'] = $_SERVER['HTTP_REFERER'];
  $stats['hostname'] = gethostname();
  $stats['environment'] = $_ENV['IMAGEHANDLERSTAGE'] ?: 'development';
  if ($stats['environment'] == 'development') error_log(print_r($stats, TRUE));
}
?>
